<?php

use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Exceptions\IncompleteContact;
use Peppermint\Contacts\Models\Contact;

/**
 * Ring 1: Die Art und mindestens EINE Kennung — Name ODER E-Mail.
 *
 * Beides zu verlangen schlösse zwei echte Fälle aus, die es in jedem der
 * vier Produkte gibt. Deshalb prüfen die ersten beiden Tests ausdrücklich,
 * dass jede Hälfte für sich reicht.
 */
it('nimmt einen Kontakt, der nur einen Namen hat', function (): void {
    // Der Telefonkontakt: ein Name, eine Nummer, keine Adresse.
    $contact = Contact::create(['formatted_name' => 'Volker Tolksdorf']);

    expect($contact->exists)->toBeTrue()
        ->and($contact->hasIdentifier())->toBeTrue();
});

it('nimmt einen Kontakt, der nur eine E-Mail hat', function (): void {
    // So entsteht ein Kontakt aus einem Postfach: Es gibt eine Adresse und
    // sonst nichts. Müsste hier ein Name stehen, würde einer erfunden — und
    // der erfundene bliebe stehen.
    $contact = (new Contact)->withEmail('rechnung@beispiel.de');
    $contact->save();

    expect($contact->exists)->toBeTrue()
        ->and($contact->emails()->count())->toBe(1)
        ->and($contact->emails()->first()->value)->toBe('rechnung@beispiel.de');
});

it('nimmt eine Organisation, die nur unter ihrem Firmennamen bekannt ist', function (): void {
    // Der Regelfall in der Verwaltung: `company_name` gesetzt, sonst nichts.
    $contact = Contact::create(['kind' => Kind::Org, 'organization' => 'Peppermint Digital']);

    expect($contact->hasIdentifier())->toBeTrue()
        ->and($contact->isOrganisation())->toBeTrue();
});

it('weist einen Kontakt ohne jede Kennung ab', function (): void {
    Contact::create(['kind' => Kind::Individual]);
})->throws(IncompleteContact::class);

it('weist auch leere Zeichenketten ab, nicht nur null', function (): void {
    // Ein Formular schickt keine Nullwerte, es schickt leere Felder. Prüfte
    // die Regel nur auf null, käme die leere Zeile genau auf diesem Weg
    // trotzdem herein — und zwar auf dem einzigen, der in der Praxis zählt.
    Contact::create(['formatted_name' => '', 'given_name' => '  ']);
})->throws(IncompleteContact::class);

it('weist das Leeren des letzten Namens ab, wenn keine E-Mail daneben steht', function (): void {
    $contact = Contact::create(['formatted_name' => 'Volker Tolksdorf']);

    // Die Regel gilt beim Ändern genauso. Sonst legt man einen gültigen
    // Kontakt an und räumt ihn anschliessend leer.
    $contact->update(['formatted_name' => null]);
})->throws(IncompleteContact::class);

it('erlaubt das Leeren des Namens, wenn eine gespeicherte E-Mail bleibt', function (): void {
    $contact = Contact::create(['formatted_name' => 'Volker Tolksdorf']);
    $contact->emails()->create(['value' => 'v.tolksdorf@beispiel.de']);

    $contact->update(['formatted_name' => null]);

    expect($contact->fresh()->formatted_name)->toBeNull()
        ->and($contact->hasIdentifier())->toBeTrue();
});

it('legt eine vorgemerkte E-Mail nicht zweimal an, wenn zweimal gespeichert wird', function (): void {
    $contact = (new Contact)->withEmail('doppelt@beispiel.de');
    $contact->save();
    $contact->save();

    expect($contact->emails()->count())->toBe(1);
});
