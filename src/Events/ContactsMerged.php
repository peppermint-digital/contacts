<?php

namespace Peppermint\Contacts\Events;

use Peppermint\Contacts\Models\Contact;

/**
 * Zwei Kontakte sind zu einem geworden.
 *
 * Fuer alles, was das Paket nicht wissen kann: ein Suchindex, ein
 * Zwischenspeicher, eine Verknuepfung, die ein Produkt nur selbst kennt.
 * Die Alternative waere eine Liste von Haken im Paket, die mit jedem
 * Abnehmer laenger wird.
 *
 * Wird NACH dem erfolgreichen Zusammenfuehren ausgeloest, ausserhalb der
 * Transaktion: Ein Zuhoerer, der wirft, darf ein abgeschlossenes
 * Zusammenfuehren nicht zurueckdrehen.
 */
class ContactsMerged
{
    public function __construct(
        public readonly Contact $into,
        public readonly int $fromId,
        public readonly ?string $fromUid,
    ) {}
}
