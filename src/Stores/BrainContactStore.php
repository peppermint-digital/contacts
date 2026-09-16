<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Peppermint\Contacts\Contacts\StoreGuard;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Exceptions\StaleContact;
use Peppermint\Contacts\Exceptions\StoreUnavailable;
use Peppermint\Contacts\Models\Contact;

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
            fn (): ?array => $this->ask('contacts.get', ['id' => $id]),
            fn (): ?Contact => Contact::query()->find($id),
        );
    }

    public function findByUid(string $uid): ?Contact
    {
        return $this->readOne(
            fn (): ?array => $this->ask('contacts.get', ['uid' => $uid]),
            fn (): ?Contact => Contact::query()->where('uid', $uid)->first(),
        );
    }

    public function findByEmail(string $email): ?Contact
    {
        return $this->readOne(
            fn (): ?array => $this->ask('contacts.find-by-email', ['email' => $email]),
            fn (): ?Contact => Contact::query()
                ->whereHas('emails', fn ($e) => $e->where('value', $email))
                ->first(),
        );
    }

    public function search(string $query, int $limit = 25): Collection
    {
        $response = $this->ask('contacts.search', ['query' => $query, 'limit' => $limit]);

        if ($response === null) {
            $this->warnFallback('Suche');

            return (new LocalContactStore)->search($query, $limit);
        }

        return collect($response['data'] ?? [])
            ->map(fn (array $row): ?Contact => $this->mirror($row))
            ->filter()
            ->values();
    }

    public function upsert(array $attributes): Contact
    {
        $response = $this->ask('contacts.upsert', $attributes);

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

        $contact = $this->mirror($response['data'] ?? []);

        if ($contact === null) {
            throw StoreUnavailable::forWrite();
        }

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
     * Den zentralen Stand in die lokale Kopie schreiben.
     *
     * Wird ausschliesslich mit einer ERFOLGREICHEN Antwort aufgerufen — das
     * ist die Regel „nur Erfolg wird gespiegelt", und sie steht hier als
     * Aufrufbedingung statt als Pruefung, weil eine Pruefung auf „war das
     * ein Erfolg?" an dieser Stelle nicht mehr entscheidbar waere.
     *
     * Beziehungen (`contact_relations`) bleiben bewusst aussen vor: Sie
     * zeigen auf einen zweiten Kontakt, den es lokal noch nicht geben muss.
     * Eine halb gespiegelte Beziehung waere schlimmer als keine — sie sähe
     * vollstaendig aus. Das loest E3, wo die Brain-Seite festlegt, wie sie
     * die Gegenseite mitliefert.
     *
     * @param  array<string, mixed>  $row
     */
    private function mirror(array $row): ?Contact
    {
        $id = $row['id'] ?? null;

        if ($id === null) {
            Log::warning('Kontakte: Antwort ohne Kennung erhalten — nicht gespiegelt.');

            return null;
        }

        $kinder = [
            'emails' => $row['emails'] ?? [],
            'phones' => $row['phones'] ?? [],
            'addresses' => $row['addresses'] ?? [],
        ];

        $kern = collect($row)
            ->except(['emails', 'phones', 'addresses', 'relations'])
            ->all();

        return StoreGuard::bypass(fn (): Contact => DB::transaction(function () use ($id, $kern, $kinder): Contact {
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
                foreach ($kinder['emails'] as $email) {
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

            return $contact->refresh();
        }));
    }
}
