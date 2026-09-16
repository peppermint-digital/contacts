<?php

use Illuminate\Database\QueryException;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactAddress;
use Peppermint\Contacts\Models\ContactEmail;

/**
 * Ring 2: Was sich in eine `.vcf` schreiben lässt, ist Kern.
 *
 * Der Schwerpunkt liegt auf der MEHRZAHL. An der Einzahl scheitert die
 * heutige Übergabe: Wer drei Ansprechpartner hat, verliert zwei.
 */
it('haelt mehrere E-Mails, Telefone und Anschriften an einem Kontakt', function (): void {
    $contact = Contact::factory()->create();

    $contact->emails()->createMany([
        ['value' => 'dienstlich@beispiel.de', 'type' => 'work', 'is_primary' => true],
        ['value' => 'privat@beispiel.de', 'type' => 'home'],
    ]);
    $contact->phones()->createMany([
        ['value' => '+49 40 123456', 'type' => 'work', 'is_primary' => true],
        ['value' => '+49 170 123456', 'type' => 'cell'],
    ]);
    $contact->addresses()->createMany([
        ['type' => 'billing', 'street' => 'Rechnungsweg 1', 'zip' => '20095', 'city' => 'Hamburg', 'is_default' => true],
        ['type' => 'shipping', 'street' => 'Lieferweg 2', 'zip' => '20097', 'city' => 'Hamburg'],
    ]);

    $contact->refresh();

    expect($contact->emails)->toHaveCount(2)
        ->and($contact->phones)->toHaveCount(2)
        ->and($contact->addresses)->toHaveCount(2)
        ->and($contact->primaryEmail()->value)->toBe('dienstlich@beispiel.de')
        ->and($contact->primaryPhone()->value)->toBe('+49 40 123456');
});

it('nimmt die erste Adresse, wenn keine als Haupt markiert ist', function (): void {
    $contact = Contact::factory()->create();
    $contact->emails()->create(['value' => 'einzige@beispiel.de']);

    expect($contact->refresh()->primaryEmail()->value)->toBe('einzige@beispiel.de');
});

it('laesst dieselbe Adresse nicht zweimal am selben Kontakt zu', function (): void {
    $contact = Contact::factory()->create();
    $contact->emails()->create(['value' => 'einmal@beispiel.de']);

    expect(fn () => $contact->emails()->create(['value' => 'einmal@beispiel.de']))
        ->toThrow(QueryException::class);
});

it('behaelt ein gefuelltes Geburtsdatum', function (): void {
    // Ein `date`-Cast ohne Format macht aus einem gefuellten Datumsfeld je
    // nach Treiber ein leeres — deshalb wird hier der Wert geprueft und
    // nicht nur, dass die Spalte existiert.
    $contact = Contact::factory()->create(['birthday' => '1985-03-17']);

    expect($contact->fresh()->birthday->format('Y-m-d'))->toBe('1985-03-17');
});

it('speichert die Art als Aufzaehlung und liest sie so zurueck', function (): void {
    $contact = Contact::factory()->organisation('Peppermint Digital')->create();

    expect($contact->fresh()->kind)->toBe(Kind::Org);
});

it('loescht die Anhaengsel mit, wenn der Kontakt geht', function (): void {
    $contact = Contact::factory()->create();
    $contact->emails()->create(['value' => 'weg@beispiel.de']);
    $contact->addresses()->create(['type' => 'billing', 'street' => 'Weg 1']);

    $id = $contact->id;
    $contact->delete();

    expect(ContactEmail::where('contact_id', $id)->count())->toBe(0)
        ->and(ContactAddress::where('contact_id', $id)->count())->toBe(0);
});

it('nimmt einen ausgeschriebenen Laendernamen auf', function (): void {
    // Zwei Zeichen waren eine Annahme (ISO-Kuerzel), keine Messung. Die
    // Produkte fuehren „Deutschland". Auf SQLite faellt so etwas nicht auf —
    // MySQL quittiert es mit „Data too long".
    $contact = Contact::factory()->organisation()->create();
    $contact->addresses()->create(['type' => 'main', 'street' => 'Hauptweg 1', 'country' => 'Deutschland']);

    expect($contact->refresh()->addresses->first()->country)->toBe('Deutschland');
});
