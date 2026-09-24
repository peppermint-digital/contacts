<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Exceptions\BrainRejected;
use Peppermint\Contacts\Exceptions\ProfileConflict;
use Peppermint\Contacts\Exceptions\StaleContact;
use Peppermint\Contacts\Exceptions\StoreUnavailable;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactAddress;
use Peppermint\Contacts\Models\ContactEmail;
use Peppermint\Contacts\Models\ContactPhone;

/**
 * Die Kontakte liegen zentral im AI Brain — und NUR dort.
 *
 * ## Warum es keine lokale Kopie mehr gibt
 *
 * Bis zum 24.09.2026 spiegelte dieser Speicher jede gelesene Antwort in
 * lokale Tabellen. Begruendet war das mit Latenz und Ausfallsicherheit.
 * Beides wurde an diesem Tag am Code gemessen, und beides hielt nicht:
 *
 * **Latenz.** `readOne()` rief schon immer ZUERST das Brain und griff nur
 * bei einer leeren Antwort auf die Kopie zurueck. Der Spiegel wurde im
 * Normalbetrieb also nie gelesen — bei jedem Treffer aber neu geschrieben.
 * Er kostete Schreiblast und sparte nichts.
 *
 * **Ausfall.** Die Anmeldung der Produkte laeuft selbst ueber AI Brain. Ist
 * das Brain weg, kommt niemand mehr ins System — die Lage, fuer die der
 * Spiegel gedacht war, ist von aussen gar nicht erreichbar. Gemessen an der
 * Verwaltung: kein Passwortfeld, Sitzungsdauer 120 Minuten, und der Ausfall
 * vom 15.09.2026, der den Spiegel begruendet hatte, dauerte „2+ Stunden" —
 * ungefaehr eine Sitzungslaenge.
 *
 * Geblieben waere eine zweite Datenhaltung, die auseinanderlaufen kann. Genau
 * die soll ein geteiltes Kontaktpaket beseitigen.
 *
 * ## Was stattdessen geschieht
 *
 * `hydrate()` baut aus der Antwort Modelle im Arbeitsspeicher — dieselbe
 * Klasse, dieselben Beziehungen, aber `exists = false` und keine Zeile
 * dahinter. Fuer die Aufrufer aendert sich damit fast nichts; sie lesen
 * weiter `$kontakt->addresses`, nur steht darunter keine Tabelle.
 *
 * **Kein `load()` auf diesen Modellen.** Es fragte die Datenbank, faende
 * nichts und ueberschriebe die gesetzten Anhaengsel mit leeren Sammlungen —
 * aus „laedt mit" wuerde lautlos „ist leer".
 *
 * ## Bei Ausfall: Absage statt alter Stand
 *
 * Es gibt nichts, worauf zurueckzufallen waere, und das ist gewollt. Ein
 * veralteter Stand, den niemand als veraltet erkennt, ist schlimmer als eine
 * ehrliche Absage — siehe das Learning „Fail-closed verbirgt den eigenen
 * Ausfall".
 *
 * ## Die lokalen Tabellen gibt es nur ohne Brain
 *
 * Laeuft das Paket eigenstaendig (`store = local`), traegt es sich selbst mit
 * eigenen Tabellen. Das ist der einzige Fall, fuer den sie existieren.
 *
 * ## Schreiben: das Brain gewinnt
 *
 * Die Produkte schreiben durch — und wer einen veralteten Stand schickt,
 * bekommt eine **Absage** statt eines stillen Ueberschreibens.
 */
class BrainContactStore implements ContactStore
{
    /**
     * Was beim letzten Zugriff zu sehen war. Nicht bei jedem Aufruf frisch
     * gemessen: Eine Pruefanfrage je Seitenaufbau waere teurer als der
     * Nutzen, und die Antwort waere im naechsten Moment ohnehin wieder alt.
     */
    private bool $lastCallFailed = false;

    /**
     * @param  callable(string, array<string, mixed>): ?array  $call  Gibt die
     *                                                                entschluesselte Antwort zurueck — oder `null`, wenn das Brain
     *                                                                nicht erreichbar war. `null` ist ein Zustand, kein Fehler.
     */
    public function __construct(private $call) {}

    public function find(string|int $id): ?Contact
    {
        return $this->readOne(fn (): ?array => $this->ask('get', ['id' => $id]));
    }

    public function findByUid(string $uid): ?Contact
    {
        return $this->readOne(fn (): ?array => $this->ask('get', ['uid' => $uid]));
    }

    public function findByEmail(string $email, ?Kind $kind = null): ?Contact
    {
        return $this->readOne(fn (): ?array => $this->ask('find-by-email', array_filter([
            'email' => $email,
            'kind' => $kind?->value,
        ])));
    }

    public function search(string $query, int $limit = 25): Collection
    {
        $response = $this->ask('search', ['query' => $query, 'limit' => $limit]);

        if ($response === null) {
            throw StoreUnavailable::forRead('Suche');
        }

        // Eloquent-Sammlung und nicht `collect()`: Nur sie kann `load()`.
        $treffer = Contact::query()->newModelInstance()->newCollection(
            collect($response['data'] ?? [])
                ->map(fn (array $row): ?Contact => $this->hydrate($row))
                ->filter()
                ->values()
                ->all()
        );

        // KEIN `load()` mehr.
        //
        // Es stand hier, solange die Treffer gespiegelte Zeilen waren. Jetzt
        // sind es Modelle ohne Tabelle dahinter — `load()` fragte die
        // Datenbank, faende nichts und ueberschriebe die eben gesetzten
        // Anhaengsel mit leeren Sammlungen. Aus „laedt mit" wuerde lautlos
        // „ist leer".
        //
        // Die Reihenfolge kommt vom Brain und bleibt: Bei Vorschlaegen ist
        // sie der halbe Nutzen.
        return $treffer;
    }

    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            return Contact::query()->newModelInstance()->newCollection();
        }

        $response = $this->ask('list', ['ids' => array_values($ids)]);

        if ($response === null) {
            throw StoreUnavailable::forRead('Sammelabruf');
        }

        $treffer = Contact::query()->newModelInstance()->newCollection(
            collect($response['data'] ?? [])
                ->map(fn (array $row): ?Contact => $this->hydrate($row))
                ->filter()
                ->values()
                ->all()
        );

        return $treffer;
    }

    public function upsert(array $attributes): Contact
    {
        $response = $this->ask('upsert', $attributes);

        // Nicht erreichbar heisst beim Schreiben etwas anderes als beim
        // Lesen. Lesen kann aus der Kopie bedient werden; Schreiben nicht —
        // eine „lokal gespeicherte" Aenderung waere genau die zweite
        // Wahrheit, gegen die das Paket gebaut ist. Also ein sichtbarer Wurf
        // statt eines stillen Erfolgs.
        if ($response === null) {
            throw StoreUnavailable::forWrite();
        }

        // Jemand war schneller. Die Absage ist der Zweck: Ohne sie gewinnt
        // der Letzte, und was der Erste eingetragen hat, ist weg.
        if (($response['conflict'] ?? false) === true) {
            throw new StaleContact($response['current'] ?? []);
        }

        $this->pruefeAntwort($response);

        $contact = $this->hydrate($response['data'] ?? []);

        if ($contact === null) {
            throw new BrainRejected('Die Antwort enthielt keinen Kontakt.', $response);
        }

        return $contact;
    }

    /**
     * Eine Antwort, die zwar ankam, aber eine Absage ist.
     *
     * Ohne diese Pruefung landete jede fachliche Ablehnung im
     * „nicht erreichbar"-Zweig — und der Satz, den die Gegenseite mitgeschickt
     * hat, verschwand. Wer dann sucht, prueft Netz und Token, waehrend in
     * Wahrheit ein Feld fehlte.
     *
     * @param  array<string, mixed>  $response
     */
    private function pruefeAntwort(array $response): void
    {
        if (($response['ok'] ?? true) !== false) {
            return;
        }

        throw new BrainRejected(
            (string) ($response['error'] ?? 'Kein Grund mitgeteilt.'),
            $response,
        );
    }

    /**
     * Die Ansprechpartner einer Organisation — direkt aus dem Brain.
     *
     * Bei Ausfall gibt es keine Antwort — und das ist richtig so. Es gibt
     * keine lokale Kopie mehr, aus der man sie nehmen koennte.
     */
    public function contactPersonsOf(string|int $organisationId): Collection
    {
        $response = $this->ask('get', ['id' => $organisationId]);

        if ($response === null) {
            throw StoreUnavailable::forRead('Ansprechpartner');
        }

        $row = $response['data'] ?? null;

        if (! is_array($row)) {
            return collect();
        }

        return collect($row['relations']['contact_persons'] ?? [])
            ->map(fn (array $p): ?Contact => isset($p['id']) ? $this->find($p['id']) : null)
            ->filter()
            ->values();
    }

    public function unlinkFrom(string|int $contactId, string|int $organisationId): void
    {
        $response = $this->ask('unlink', [
            'contact_id' => $contactId,
            'organisation_id' => $organisationId,
        ]);

        if ($response === null) {
            throw StoreUnavailable::forWrite();
        }

        $this->pruefeAntwort($response);

    }

    /**
     * Zusammengefuehrt wird zentral.
     *
     * Das Zusammenfuehren loest eine Zeile auf. Es gehoert deshalb dorthin,
     * wo die Zeile lebt — ins Brain.
     */
    public function merge(Contact|int $into, Contact|int $from): Contact
    {
        $response = $this->ask('merge', [
            'into' => $into instanceof Contact ? $into->getKey() : $into,
            'from' => $from instanceof Contact ? $from->getKey() : $from,
        ]);

        if ($response === null) {
            throw StoreUnavailable::forWrite();
        }

        // Die Absage wegen doppelter Profile kommt von der Brain-Seite; das
        // Paket reicht sie durch, statt sie in ein allgemeines „ging nicht"
        // zu verwandeln. Der Mensch muss erfahren, WO es klemmt.
        if (($response['conflict'] ?? false) === true) {
            throw new ProfileConflict($response['tables'] ?? []);
        }

        $contact = $this->hydrate($response['data'] ?? []);

        if ($contact === null) {
            throw StoreUnavailable::forWrite();
        }

        // Kein lokales Nachraeumen mehr: Es gibt keine Kopie, in der die
        // aufgeloeste Zeile stehenbleiben koennte.
        return $contact;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function writeBlockedReason(): ?string
    {
        if (! $this->lastCallFailed) {
            return null;
        }

        return 'AI Brain war beim letzten Zugriff nicht erreichbar. Kontakte lassen sich '
            .'gerade weder lesen noch aendern — es gibt keine lokale Kopie, aus der '
            .'geantwortet werden koennte.';
    }

    // -----------------------------------------------------------------

    /**
     * Einen einzelnen Kontakt holen — zentral, und nur dort.
     *
     * @param  callable(): ?array  $fromBrain
     */
    private function readOne(callable $fromBrain): ?Contact
    {
        $response = $fromBrain();

        // Kein zweiter Weg mehr.
        //
        // Hier stand bis zum 24.09.2026 ein Rueckfall auf die lokale Kopie.
        // Er sicherte einen Fall ab, den es so nicht gibt: Die Anmeldung
        // laeuft selbst ueber AI Brain — ist das Brain weg, kommt ohnehin
        // niemand ins System. Geblieben waere nur eine zweite
        // Datenhaltung, die auseinanderlaufen kann.
        //
        // Eine ehrliche Absage ist besser als ein alter Stand, den niemand
        // als alt erkennt.
        if ($response === null) {
            throw StoreUnavailable::forRead('Kontakt');
        }

        $row = $response['data'] ?? null;

        // Zentral gibt es ihn nicht. Das ist eine Antwort, kein Ausfall —
        // hier NICHT auf den Spiegel zurueckfallen: Eine geloeschte Zeile
        // kaeme sonst aus der Kopie zurueck und sähe aus, als gaebe es sie
        // noch.
        if (! is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function ask(string $capability, array $arguments): ?array
    {
        $response = ($this->call)($capability, $arguments);

        $this->lastCallFailed = $response === null;

        return $response;
    }

    /**
     * Einen Kontakt im Arbeitsspeicher aufbauen — ohne ihn zu speichern.
     *
     * Dieselbe Antwort, dasselbe Modell, nur ohne Zeile dahinter. Im
     * Brain-Betrieb hat ein Produkt seine Kontaktdaten NICHT in der eigenen
     * Datenbank: Was hier entsteht, lebt fuer die Dauer eines Aufrufs und
     * verschwindet danach.
     *
     * Die Anhaengsel werden als Beziehungen GESETZT, nicht geladen: Ein
     * `load()` fragte eine Tabelle, die es hier nicht mehr gibt.
     */
    private function hydrate(array $row): ?Contact
    {
        if (($row['id'] ?? null) === null) {
            // Die Antwort mitloggen, nicht nur ihr Fehlen: Ohne sie ist von
            // aussen nicht zu unterscheiden, ob die Gegenseite abgelehnt,
            // etwas anderes geschickt oder schlicht nichts gefunden hat.
            Log::warning('Kontakte: Antwort ohne Kennung erhalten.', [
                'antwort' => mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '', 0, 500),
            ]);

            return null;
        }

        $kontakt = $this->alsModell(new Contact, collect($row)->except(['emails', 'phones', 'addresses', 'relations'])->all());

        foreach ([
            'emails' => ContactEmail::class,
            'phones' => ContactPhone::class,
            'addresses' => ContactAddress::class,
        ] as $name => $klasse) {
            $kontakt->setRelation($name, new EloquentCollection(
                collect($row[$name] ?? [])
                    ->map(fn (array|string $zeile) => $this->alsModell(
                        new $klasse,
                        [...$this->zeileNormieren($zeile), 'contact_id' => $row['id']],
                    ))
                    ->all()
            ));
        }

        // Die Ansprechpartner kommen als Namen mit. Mehr braucht eine Liste
        // nicht, und mehr zu holen hiesse, je Zeile noch einmal zu fragen.
        return $kontakt->withContactPersons(new EloquentCollection(
            collect($row['relations']['contact_persons'] ?? [])
                ->map(fn (array $person) => $this->alsModell(new Contact, [
                    'id' => $person['id'] ?? null,
                    'formatted_name' => $person['name'] ?? null,
                ]))
                ->all()
        ));
    }

    /**
     * Ein Modell mit Werten fuellen, ohne es als gespeichert auszugeben.
     *
     * `exists = false` ist der entscheidende Teil: Eloquent haelt die Zeile
     * damit fuer neu. Wer trotzdem `save()` aufruft, laeuft in den Riegel
     * aus `GuardsDirectWrites` — er wuerde sonst genau die Kopie anlegen,
     * die hier verschwinden soll.
     *
     * @param  array<string, mixed>  $werte
     *
     * @template TModell of \Illuminate\Database\Eloquent\Model
     */
    private function alsModell(object $modell, array $werte): object
    {
        $modell->forceFill($werte);
        $modell->exists = false;

        return $modell;
    }

    /**
     * Eine Anhaengsel-Zeile, wie sie auch immer geliefert wurde.
     *
     * Die Kurzfassung der Suche gab `emails` als blosse Adressen zurueck
     * (`["a@b.test"]`), die Einzelabfrage als Zeilen (`[{"value": …}]`) —
     * derselbe Schluessel mit zwei Bedeutungen, je nach Endpunkt. Seit
     * v0.18.0 sendet die Brain-Seite beides gleich.
     *
     * Die Duldung bleibt trotzdem, und zwar mit Absender: Waehrend eines
     * Rollouts steht ein Produkt mit neuem Paket vor einem Brain mit altem
     * Stand. Ein Spiegel, der daran zerbricht, macht aus einer
     * Versionsdifferenz einen Ausfall.
     *
     * @param  array<string, mixed>|string  $zeile
     * @return array<string, mixed>
     */
    private function zeileNormieren(array|string $zeile): array
    {
        return is_string($zeile) ? ['value' => $zeile] : $zeile;
    }
}
