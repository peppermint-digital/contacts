<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Contacts\Exceptions\IncompleteContact;
use Peppermint\Contacts\Models\Contact;

/**
 * Adoption ist Pflichtfeature, kein Zusatz.
 *
 * Ein Paket, das nur auf frischen Tabellen läuft, kann die Verwaltung mit
 * ihrer jahrealten `customers` nicht übernehmen — und dann wird es ein
 * zweites Mal gebaut. Der Test bildet genau diesen Fall nach: die Tabelle
 * heisst anders, und der Name steht in `company_name`.
 */
it('liest den Kern aus einer fremd benannten Tabelle mit fremden Spalten', function (): void {
    config([
        'contacts.tables.contacts' => 'customers',
        'contacts.columns.formatted_name' => 'company_name',
    ]);

    Schema::create('customers', function (Blueprint $table): void {
        $table->id();
        $table->string('kind', 20)->default('org');
        $table->string('company_name')->nullable();
        $table->timestamps();
    });

    $contact = Contact::create(['company_name' => 'Beispiel GmbH']);

    expect($contact->getTable())->toBe('customers')
        ->and(Contact::column('formatted_name'))->toBe('company_name')
        ->and($contact->field('formatted_name'))->toBe('Beispiel GmbH')
        // Und die Ring-1-Regel muss durch die Abbildung hindurch greifen:
        // Sie darf nicht auf den Paketnamen schauen und deshalb an einem
        // gewachsenen Bestand reihenweise „kein Name" melden.
        ->and($contact->hasIdentifier())->toBeTrue();
});

it('meldet einen leeren Kontakt auch unter fremden Spaltennamen', function (): void {
    config([
        'contacts.tables.contacts' => 'customers',
        'contacts.columns.formatted_name' => 'company_name',
    ]);

    Schema::create('customers', function (Blueprint $table): void {
        $table->id();
        $table->string('kind', 20)->default('org');
        $table->string('company_name')->nullable();
        $table->timestamps();
    });

    Contact::create([]);
})->throws(IncompleteContact::class);

it('behaelt den Paketnamen fuer Felder, die nicht abgebildet sind', function (): void {
    config(['contacts.columns.formatted_name' => 'company_name']);

    expect(Contact::column('formatted_name'))->toBe('company_name')
        ->and(Contact::column('given_name'))->toBe('given_name');
});
