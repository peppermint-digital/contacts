<?php

namespace Peppermint\Contacts\Listeners;

use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Stores\BrainContactStore;

/**
 * Das Brain meldet eine Änderung, das Produkt zieht sie nach (#5815).
 *
 * ## Warum es das braucht
 *
 * Der Spiegel frischte bisher nur auf, wenn zufällig jemand über den Speicher
 * las. Wer im Adressbuch eine Adresse korrigierte, sah das in der Verwaltung
 * unter Umständen nie. Solange das so ist, darf kein Produkt seine Anzeige
 * aus dem Spiegel ableiten — und genau das ist der nächste Schritt.
 *
 * ## Warum nur schon bekannte Kontakte
 *
 * Ein Produkt spiegelt, was es benutzt. Zöge es auf jede Meldung hin den
 * Kontakt herunter, füllte sich die Verwaltung mit den Interessenten des CRM —
 * Daten, die sie nie angefragt hat und nicht braucht. Eine Meldung über einen
 * unbekannten Kontakt ist deshalb kein Grund, ihn kennenzulernen.
 *
 * ## Warum die Meldung nichts enthält
 *
 * Sie nennt nur die Nummer. Den Inhalt holt das Produkt selbst über den
 * Speicher — auf dem Weg, der seine Rechte kennt. Ein Ereignis, das den
 * ganzen Menschen mitschickt, verteilt ihn auch an den, der ihn gerade nicht
 * mehr sehen darf.
 */
class KontaktAenderungSpiegeln
{
    /** Die Arten, auf die reagiert wird. */
    public const ARTEN = ['contact.changed', 'contact.merged'];

    public function __construct(private readonly ContactStore $store) {}

    public function handle(object $ereignis): void
    {
        $event = $ereignis->event ?? null;

        if ($event === null || ! in_array($event->type ?? '', self::ARTEN, true)) {
            return;
        }

        // Mit lokalem Speicher gibt es keine zweite Seite, von der
        // nachzuziehen wäre.
        if (! $this->store instanceof BrainContactStore) {
            return;
        }

        $id = data_get((array) ($event->payload ?? []), 'id');

        if ($id === null || ! Contact::query()->whereKey($id)->exists()) {
            return;
        }

        // `find()` liest zentral und spiegelt die Antwort — derselbe Weg, den
        // auch `contacts:spiegel-auffrischen` geht. Beim Zusammenführen kommt
        // der Nachfolger zurück, weil der Speicher der Spur folgt.
        $this->store->find($id);
    }
}
