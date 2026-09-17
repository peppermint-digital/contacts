<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Contacts\StoreGuard;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Exceptions\BrainRejected;
use Peppermint\Contacts\Exceptions\ProfileConflict;
use Peppermint\Contacts\Exceptions\StaleContact;
use Peppermint\Contacts\Exceptions\StoreUnavailable;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactRelation;

/**
 * Die Kontakte liegen zentral im AI Brain — lokal gespiegelt, und schreibend.
 *
 * ## Der Spiegel ist keine Beschleunigung, sondern die Ausfallsicherung
 *
 * Am 15.09.2026 war die Brain-Verbindung ueber zwei Stunden tot. Laege alles
 * zentral ohne Puffer, koennte die Verwaltung in so einer Lage **keine
 * Rechnung schreiben** — die Anschrift fehlt. Mit Spiegel geht das Lesen
 * weiter, nur Anlegen und Aendern pausieren.
 *
 * **Nur Erfolg wird gespiegelt.** Ein Fehlschlag darf den letzten guten Stand
 * nicht ueberschreiben, sonst wird aus einem kurzen Ausfall ein langer: Man
 * verliert nicht die Verbindung, sondern die Daten, die man ohne sie noch
 * haette lesen koennen.
 *
 * ## Der Spiegel sind die lokalen Tabellen, kein Cache-Eintrag
 *
 * Anders als beim Mail-Paket, und mit Absicht. Eine Autovervollstaendigung,
 * die pro Anschlag uebers Netz geht, ist unbenutzbar; ein Suchlauf ueber ein
 * serialisiertes Feld im Cache ebenso. Die Kopie liegt deshalb in denselben
 * indizierten Tabellen, die der lokale Speicher benutzt — mit `mirrored_at`
 * als Merkmal, dass es eben eine Kopie ist.
 *
 * Damit die Kopie dieselbe Sache bezeichnet wie das Original, uebernimmt sie
 * den Primaerschluessel des Brains. Sonst haette derselbe Kontakt zwei
 * Nummern, und jeder Verweis darauf muesste uebersetzt werden.
 *
 * > Achtung fuer E4: Ein Produkt, das von `local` auf `brain` umsteigt, hat
 * > eigene Zeilen mit eigenen IDs. Die koennen mit denen des Brains
 * > kollidieren. Das ist genau der Grund, warum die Verwaltung ueberfuehrt
 * > und nicht bloss umgeschaltet wird.
 *
 * ## Schreiben: das Brain gewinnt
 *
 * Die eine echte Neuerung gegenueber dem Mail-Paket, dessen zentraler
 * Speicher `isWritable() === false` ist. Hier schreiben die Produkte durch —
 * und wer einen veralteten Stand schickt, bekommt eine **Absage** statt eines
 * stillen Ueberschreibens.
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
    public function __construct(
        private $call,
        private readonly int $ttl = 900,
    ) {}

    public function find(string|int $id): ?Contact
    {
        return $this->readOne(
            fn (): ?array => $this->ask('get', ['id' => $id]),
            fn (): ?Contact => Contact::query()->find($id),
        );
    }

    public function findByUid(string $uid): ?Contact
    {
        return $this->readOne(
            fn (): ?array => $this->ask('get', ['uid' => $uid]),
            fn (): ?Contact => Contact::query()->where('uid', $uid)->first(),
        );
    }

    public function findByEmail(string $email, ?Kind $kind = null): ?Contact
    {
        return $this->readOne(
            fn (): ?array => $this->ask('find-by-email', array_filter([
                'email' => $email,
                'kind' => $kind?->value,
            ])),
            fn (): ?Contact => Contact::query()
                ->whereHas('emails', fn ($e) => $e->where('value', $email))
                ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
                ->first(),
        );
    }

    public function search(string $query, int $limit = 25): Collection
    {
        $response = $this->ask('search', ['query' => $query, 'limit' => $limit]);

        if ($response === null) {
            $this->warnFallback('Suche');

            return (new LocalContactStore)->search($query, $limit);
        }

        // Eloquent-Sammlung und nicht `collect()`: Nur sie kann `load()`.
        $treffer = Contact::query()->newModelInstance()->newCollection(
            collect($response['data'] ?? [])
                ->map(fn (array $row): ?Contact => $this->mirror($row))
                ->filter()
                ->values()
                ->all()
        );

        // Einmal nachladen statt je Treffer: Der Aufrufer braucht die Adressen
        // (danach sucht er ja), und ohne diese Zeile wirft der Zugriff dort,
        // wo Lazy Loading abgeschaltet ist.
        //
        // Auf der Sammlung und nicht ueber eine neue Abfrage: Die Reihenfolge
        // kommt vom Brain und ist eine Aussage darueber, was am besten passt.
        // Ein `whereKey(...)->get()` gaebe sie preis und lieferte die der
        // Datenbank — bei Vorschlaegen ist die Reihenfolge der halbe Nutzen.
        return $treffer->load(['emails', 'phones', 'addresses']);
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

        $contact = $this->mirror($response['data'] ?? []);

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
     * Bei Ausfall aus dem Spiegel — seit die Beziehungen mitgespiegelt
     * werden, steht die Antwort dort. Genau dafuer gibt es ihn: Ein Produkt
     * muss auch ohne Verbindung sagen koennen, wer fuer eine Firma arbeitet,
     * sonst haelt es doch wieder eine eigene Tabelle daneben.
     *
     * Fuer das Entdoppeln beim Ueberfuehren ist das unkritisch: Dort wird
     * geschrieben, und Schreiben verlangt ohnehin eine Verbindung.
     */
    public function contactPersonsOf(string|int $organisationId): Collection
    {
        $response = $this->ask('get', ['id' => $organisationId]);

        if ($response === null) {
            $this->warnFallback('Ansprechpartner');

            $org = Contact::query()->find($organisationId);

            return $org === null ? collect() : $org->contactPersons();
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

        // Auch im Spiegel loesen, damit die Oberflaeche nicht bis zum
        // naechsten Auffrischen etwas zeigt, das zentral nicht mehr gilt.
        StoreGuard::bypass(function () use ($contactId, $organisationId): void {
            ContactRelation::query()
                ->where('contact_id', $contactId)
                ->where('related_contact_id', $organisationId)
                ->where('type', ContactRelation::WorksFor)
                ->delete();
        });
    }

    /**
     * Zusammengefuehrt wird zentral.
     *
     * Nicht lokal und dann hochgeschickt: Das Zusammenfuehren loest eine
     * Zeile auf, und wer das an der Kopie tut, hat eine Kopie ohne Zeile und
     * ein Zentrum mit. Beim naechsten Spiegeln waere sie wieder da — und der
     * Mensch davor haelt das fuer einen Fehler.
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

        $contact = $this->mirror($response['data'] ?? []);

        if ($contact === null) {
            throw StoreUnavailable::forWrite();
        }

        // Die aufgeloeste Zeile muss auch aus der Kopie verschwinden, sonst
        // steht die Dublette lokal weiter in der Suche.
        $fromId = $from instanceof Contact ? $from->getKey() : $from;

        StoreGuard::bypass(function () use ($fromId): void {
            Contact::query()->whereKey($fromId)->delete();
        });

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
            .'gerade nur lesen — die Anzeige kommt aus der lokalen Kopie und kann veraltet sein.';
    }

    // -----------------------------------------------------------------

    /**
     * Einen einzelnen Kontakt holen: zentral, sonst aus dem Spiegel.
     *
     * @param  callable(): ?array  $fromBrain
     * @param  callable(): ?Contact  $fromMirror
     */
    private function readOne(callable $fromBrain, callable $fromMirror): ?Contact
    {
        $response = $fromBrain();

        if ($response === null) {
            $this->warnFallback('Kontakt');

            return $fromMirror();
        }

        $row = $response['data'] ?? null;

        // Zentral gibt es ihn nicht. Das ist eine Antwort, kein Ausfall —
        // hier NICHT auf den Spiegel zurueckfallen: Eine geloeschte Zeile
        // kaeme sonst aus der Kopie zurueck und sähe aus, als gaebe es sie
        // noch.
        if (! is_array($row)) {
            return null;
        }

        return $this->mirror($row);
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

    private function warnFallback(string $was): void
    {
        Log::warning(
            "Kontakte ({$was}): AI Brain nicht erreichbar — es wird aus der lokalen Kopie gelesen. "
            .'Aendern ist bis auf Weiteres gesperrt.'
        );
    }

    /**
     * Die Verbindung zur Organisation mit spiegeln — wenn es sie lokal gibt.
     *
     * ## Warum nur diese Richtung
     *
     * Gespiegelt wird `works_for`, also der Blick von der PERSON auf ihre
     * Organisation. Der umgekehrte Blick („wer arbeitet hier?") entsteht
     * daraus von selbst, sobald die Personen gespiegelt sind — er braucht
     * keine eigene Zeile.
     *
     * Andersherum ginge es nicht: Beim Spiegeln einer Organisation sind ihre
     * Ansprechpartner lokal noch nicht da, und eine Beziehung auf einen
     * Kontakt, den es nicht gibt, ist entweder ein Fremdschluesselfehler oder
     * eine Zeile, die auf nichts zeigt.
     *
     * ## Warum ueberhaupt
     *
     * Ohne diese Zeilen kann ein Produkt bei Ausfall nicht beantworten, wer
     * fuer eine Firma arbeitet — und genau deshalb hielten die Produkte
     * bisher ihre eigenen Ansprechpartner-Tabellen daneben. Der Spiegel muss
     * die Frage beantworten koennen, sonst bleibt die Doppelung bestehen.
     *
     * @param  array<string, mixed>  $relations
     */
    private function spiegleBeziehungen(Contact $contact, array $relations): void
    {
        $organisationen = $relations['works_for'] ?? null;

        // Nicht mitgeschickt heisst nicht geloescht — dieselbe Regel wie bei
        // den Anhaengseln.
        if (! is_array($organisationen)) {
            return;
        }

        $vorhanden = collect($organisationen)
            ->pluck('id')
            ->filter()
            ->filter(fn ($id): bool => Contact::query()->whereKey($id)->exists())
            ->values();

        ContactRelation::query()
            ->where('contact_id', $contact->getKey())
            ->where('type', ContactRelation::WorksFor)
            ->whereNotIn('related_contact_id', $vorhanden->all())
            ->delete();

        foreach ($organisationen as $organisation) {
            if (! $vorhanden->contains($organisation['id'] ?? null)) {
                continue;
            }

            ContactRelation::query()->updateOrCreate(
                [
                    'contact_id' => $contact->getKey(),
                    'related_contact_id' => $organisation['id'],
                    'type' => ContactRelation::WorksFor,
                ],
                ['is_primary' => (bool) ($organisation['is_primary'] ?? false)],
            );
        }
    }

    /**
     * Den zentralen Stand in die lokale Kopie schreiben.
     *
     * Wird ausschliesslich mit einer ERFOLGREICHEN Antwort aufgerufen — das
     * ist die Regel „nur Erfolg wird gespiegelt", und sie steht hier als
     * Aufrufbedingung statt als Pruefung, weil eine Pruefung auf „war das
     * ein Erfolg?" an dieser Stelle nicht mehr entscheidbar waere.
     *
     * Beziehungen werden mitgespiegelt — aber nur die, deren Gegenseite es
     * lokal schon gibt. Siehe {@see spiegleBeziehungen()}.
     *
     * @param  array<string, mixed>  $row
     */
    private function mirror(array $row): ?Contact
    {
        $id = $row['id'] ?? null;

        if ($id === null) {
            // Die Antwort mitloggen, nicht nur ihr Fehlen: Ohne sie ist von
            // aussen nicht zu unterscheiden, ob die Gegenseite abgelehnt,
            // etwas anderes geschickt oder schlicht nichts gefunden hat.
            Log::warning('Kontakte: Antwort ohne Kennung erhalten — nicht gespiegelt.', [
                'antwort' => mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '', 0, 500),
            ]);

            return null;
        }

        // Nur, was die Antwort auch WIRKLICH mitbringt.
        //
        // „Nicht mitgeschickt" ist nicht „zentral geloescht". Eine
        // Trefferliste liefert aus gutem Grund die Kurzform ohne Adressen —
        // wuerde der Spiegel daraus eine leere Liste machen, wischte jede
        // Suche die Adressen aller Treffer aus der lokalen Kopie. Bei
        // Ausfall stuenden die Kontakte dann ohne Anschrift da, und genau
        // dafuer gibt es den Spiegel.
        $kinder = array_filter(
            [
                'emails' => $row['emails'] ?? null,
                'phones' => $row['phones'] ?? null,
                'addresses' => $row['addresses'] ?? null,
            ],
            fn (?array $zeilen): bool => $zeilen !== null,
        );

        $kern = collect($row)
            ->except(['emails', 'phones', 'addresses', 'relations'])
            ->all();

        $beziehungen = $row['relations'] ?? [];

        return StoreGuard::bypass(fn (): Contact => DB::transaction(function () use ($id, $kern, $kinder, $beziehungen): Contact {
            $contact = Contact::query()->firstOrNew(['id' => $id]);
            $contact->forceFill($kern);
            $contact->mirrored_at = now();

            // Der namenlose Kontakt — zentral entstanden aus einer Mail, die
            // nur eine Adresse hatte. Er muss die Ring-1-Pruefung bestehen,
            // und die laeuft beim Speichern, waehrend die Adressen erst
            // danach angelegt werden. Ohne Vormerken scheitert ausgerechnet
            // der Fall, fuer den die Regel „Name ODER E-Mail" gemacht ist.
            //
            // Nur wenn noetig: Beim Kontakt mit Namen waere es eine Zeile
            // Arbeit, die gleich darauf wieder ueberschrieben wird.
            if (! $contact->hasIdentifier()) {
                foreach ($kinder['emails'] ?? [] as $email) {
                    if (isset($email['value'])) {
                        $contact->withEmail($email['value']);
                    }
                }
            }

            $contact->save();

            // Ersetzen statt zusammenfuehren: Eine Adresse, die zentral
            // entfernt wurde, muss auch hier verschwinden. Wer nur ergaenzt,
            // sammelt in der Kopie genau die Karteileichen an, die zentral
            // schon aufgeraeumt sind.
            foreach ($kinder as $beziehung => $zeilen) {
                $contact->{$beziehung}()->delete();

                foreach ($zeilen as $zeile) {
                    $contact->{$beziehung}()->create($zeile);
                }
            }

            $this->spiegleBeziehungen($contact, $beziehungen);

            return $contact->refresh();
        }));
    }
}
