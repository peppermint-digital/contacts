<?php

use Peppermint\Contacts\Contacts\StoreGuard;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Exceptions\DirectWriteToMirror;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Tests\Support\FakeBrain;

/**
 * Der Riegel vor der lokalen Kopie.
 *
 * Liegt der Bestand zentral, ist ein `Contact::create()` daneben keine
 * Abkuerzung, sondern eine Zeile, die zentral niemand kennt. Sie sieht echt
 * aus, taucht in der Suche auf — und ist die zweite Wahrheit, gegen die
 * dieses ganze Paket gebaut ist.
 */
it('laesst direktes Schreiben durch, solange der Bestand lokal liegt', function (): void {
    config()->set('contacts.store', 'local');
    app()->forgetInstance(ContactStore::class);

    expect(fn () => Contact::create(['formatted_name' => 'Geht']))->not->toThrow(DirectWriteToMirror::class);
});

it('verriegelt direktes Schreiben, wenn der Bestand zentral liegt', function (): void {
    app()->instance(ContactStore::class, (new FakeBrain)->store());

    expect(fn () => Contact::create(['formatted_name' => 'Geht nicht']))
        ->toThrow(DirectWriteToMirror::class);
});

it('verriegelt auch das Loeschen', function (): void {
    // Eine Zeile, die lokal verschwindet und zentral bleibt, taucht beim
    // naechsten Spiegeln wieder auf — und wer sie geloescht hat, haelt das
    // fuer einen Fehler des Systems statt fuer die Folge des eigenen Wegs.
    $contact = Contact::factory()->create();
    app()->instance(ContactStore::class, (new FakeBrain)->store());

    expect(fn () => $contact->delete())->toThrow(DirectWriteToMirror::class);
});

it('verriegelt auch die Anhaengsel, nicht nur den Kontakt', function (): void {
    $contact = Contact::factory()->create();
    app()->instance(ContactStore::class, (new FakeBrain)->store());

    expect(fn () => $contact->emails()->create(['value' => 'schleichweg@beispiel.de']))
        ->toThrow(DirectWriteToMirror::class);
});

it('laesst den Speicher selbst durch', function (): void {
    $brain = (new FakeBrain)->answers('contacts.get', ['data' => [
        'id' => 5,
        'kind' => 'org',
        'formatted_name' => 'Darf',
    ]]);

    app()->instance(ContactStore::class, $brain->store());

    // Der Speicher muss spiegeln koennen — sonst gaebe es keine Kopie, aus
    // der bei Ausfall gelesen wird.
    expect($brain->store()->find(5)?->formatted_name)->toBe('Darf');
});

it('schliesst den Riegel wieder, wenn das Spiegeln mittendrin wirft', function (): void {
    app()->instance(ContactStore::class, (new FakeBrain)->store());

    try {
        StoreGuard::bypass(function (): void {
            throw new RuntimeException('mittendrin');
        });
    } catch (RuntimeException) {
        // erwartet
    }

    // Ohne `finally` bliebe der Riegel offen — und ab da koennte jeder
    // beliebige Code in die Kopie schreiben, ohne dass noch etwas warnt.
    expect(fn () => Contact::create(['formatted_name' => 'Danach']))
        ->toThrow(DirectWriteToMirror::class);
});

it('laesst sich abschalten, damit ein Produkt umsteigen kann', function (): void {
    config()->set('contacts.guard_direct_writes', false);
    app()->instance(ContactStore::class, (new FakeBrain)->store());

    expect(fn () => Contact::create(['formatted_name' => 'Uebergang']))
        ->not->toThrow(DirectWriteToMirror::class);
});
