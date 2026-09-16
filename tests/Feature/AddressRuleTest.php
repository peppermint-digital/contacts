<?php

use Peppermint\Contacts\Contacts\AddressType;
use Peppermint\Contacts\Models\Contact;

/**
 * „Welche Adresse gehört auf welchen Beleg" ist Fachlogik, keine
 * Produkteigenheit. Heute steht die Regel in der Verwaltung; der Shop
 * bräuchte sie gleich noch einmal, und eine zweite Fassung wäre leicht
 * anders — der Unterschied fiele erst auf, wenn eine Lieferung an die
 * Rechnungsadresse geht.
 */
beforeEach(function (): void {
    $this->contact = Contact::factory()->organisation('Beispiel GmbH')->create();

    $this->contact->addresses()->createMany([
        ['type' => AddressType::Billing, 'street' => 'Rechnungsweg 1', 'city' => 'Hamburg'],
        ['type' => AddressType::Shipping, 'street' => 'Lieferweg 2', 'city' => 'Bremen'],
        ['type' => AddressType::Work, 'street' => 'Hauptweg 3', 'city' => 'Kiel', 'is_default' => true],
    ]);

    $this->contact->refresh();
});

it('schickt die Rechnung an die Rechnungsadresse', function (): void {
    expect($this->contact->addressForDocument('invoice')->street)->toBe('Rechnungsweg 1');
});

it('schickt den Lieferschein an die Lieferadresse', function (): void {
    expect($this->contact->addressForDocument('delivery_note')->street)->toBe('Lieferweg 2');
});

it('schickt Gutschrift und Mahnung dorthin, wo die Rechnung hinging', function (): void {
    // Eine Gutschrift korrigiert eine Rechnung — sie gehoert an dieselbe
    // Anschrift, nicht an die Lieferadresse.
    expect($this->contact->addressForDocument('credit_note')->street)->toBe('Rechnungsweg 1')
        ->and($this->contact->addressForDocument('reminder')->street)->toBe('Rechnungsweg 1');
});

it('nimmt die Hauptadresse fuer alles Uebrige', function (): void {
    expect($this->contact->addressForDocument('offer')->street)->toBe('Hauptweg 3');
});

it('faellt auf die Hauptadresse zurueck, wenn die passende fehlt', function (): void {
    $contact = Contact::factory()->organisation()->create();
    $contact->addresses()->create(['type' => AddressType::Work, 'street' => 'Einzige 1', 'is_default' => true]);
    $contact->refresh();

    expect($contact->addressForDocument('invoice')->street)->toBe('Einzige 1')
        ->and($contact->addressForDocument('delivery_note')->street)->toBe('Einzige 1');
});

it('gibt nichts zurueck, wenn der Kontakt gar keine Anschrift hat', function (): void {
    // Kein Fehler: 11 der 30 Kunden in der Verwaltung haben heute keine
    // Adresse. Ein Wurf an dieser Stelle machte aus einem unvollstaendigen
    // Stammsatz einen Ausfall beim Rechnungschreiben.
    $contact = Contact::factory()->create();

    expect($contact->addressForDocument('invoice'))->toBeNull();
});
