<?php

use Illuminate\Database\QueryException;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactRelation;

/**
 * Person ↔ Organisation — das Feld, das heute `contact_person` als Freitext
 * ist und deshalb genau einen trägt.
 */
it('kennt alle Ansprechpartner einer Organisation, nicht nur einen', function (): void {
    $firma = Contact::factory()->organisation('Beispiel GmbH')->create();

    $personen = collect(['Anke Berg', 'Bernd Cordes', 'Clara Diehl'])
        ->map(fn (string $name) => Contact::create(['formatted_name' => $name]));

    $personen->each(fn (Contact $person) => ContactRelation::create([
        'contact_id' => $person->id,
        'related_contact_id' => $firma->id,
        'type' => ContactRelation::WorksFor,
    ]));

    // Der eigentliche Punkt: DREI, nicht der zuletzt angelegte.
    expect($firma->contactPersons())->toHaveCount(3)
        ->and($firma->contactPersons()->pluck('formatted_name')->sort()->values()->all())
        ->toBe(['Anke Berg', 'Bernd Cordes', 'Clara Diehl']);
});

it('findet von der Person aus die Organisation', function (): void {
    $firma = Contact::factory()->organisation('Beispiel GmbH')->create();
    $person = Contact::create(['formatted_name' => 'Anke Berg']);

    ContactRelation::create([
        'contact_id' => $person->id,
        'related_contact_id' => $firma->id,
        'type' => ContactRelation::WorksFor,
    ]);

    expect($person->organizations()->pluck('formatted_name')->all())->toBe(['Beispiel GmbH'])
        // Die Richtung ist festgelegt und darf nicht beidseitig gelten:
        // Die Person arbeitet für die Firma, nicht die Firma für die Person.
        ->and($person->contactPersons())->toHaveCount(0);
});

it('laesst dieselbe Verbindung nicht zweimal zu', function (): void {
    $firma = Contact::factory()->organisation()->create();
    $person = Contact::factory()->create();

    $daten = [
        'contact_id' => $person->id,
        'related_contact_id' => $firma->id,
        'type' => ContactRelation::WorksFor,
    ];

    ContactRelation::create($daten);

    expect(fn () => ContactRelation::create($daten))
        ->toThrow(QueryException::class);
});
