<?php

use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactRelation;
use Peppermint\Contacts\Tests\Support\FakeBrain;

/**
 * Den Spiegel nachziehen (#5695).
 *
 * Der Anlass war echt: Die Verwaltung hatte 53 Kontakte im Spiegel und EINE
 * Beziehung, weil der Bestand unter einer Fassung überführt wurde, die
 * Beziehungen noch nicht mitspiegelte. Wer dann die Leseseite umstellt,
 * bekommt Masken ohne Ansprechpartner.
 */
it('zieht Beziehungen nach, die beim ersten Spiegeln noch fehlten', function (): void {
    $brain = new FakeBrain;

    // So sieht der Spiegel nach einer alten Fassung aus: Kontakte da,
    // Beziehungen nicht.
    Contact::create(['id' => 77, 'kind' => 'org', 'formatted_name' => 'Beispiel GmbH']);
    Contact::create(['id' => 91, 'kind' => 'individual', 'formatted_name' => 'Anke Berg']);

    expect(ContactRelation::count())->toBe(0);

    $brain->answers('get', fn (array $a): array => match ((int) ($a['id'] ?? 0)) {
        77 => ['data' => ['id' => 77, 'kind' => 'org', 'formatted_name' => 'Beispiel GmbH']],
        91 => ['data' => [
            'id' => 91, 'kind' => 'individual', 'formatted_name' => 'Anke Berg',
            'relations' => ['works_for' => [['id' => 77, 'name' => 'Beispiel GmbH']]],
        ]],
        default => ['data' => null],
    });

    app()->instance(ContactStore::class, $brain->store());

    $this->artisan('contacts:spiegel-auffrischen')->assertSuccessful();

    expect(ContactRelation::count())->toBe(1)
        ->and(Contact::find(77)->contactPersons()->pluck('formatted_name')->all())->toBe(['Anke Berg']);
});

it('meldet, was es zentral nicht mehr gibt, statt es stillschweigend zu loeschen', function (): void {
    // Aufraeumen ist eine eigene Entscheidung, kein Nebeneffekt des
    // Nachziehens.
    Contact::create(['id' => 55, 'kind' => 'org', 'formatted_name' => 'Weg GmbH']);

    $brain = (new FakeBrain)->answers('get', ['data' => null]);
    app()->instance(ContactStore::class, $brain->store());

    $this->artisan('contacts:spiegel-auffrischen')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(Contact::find(55))->not->toBeNull();
});

it('haelt still, wenn der Bestand hier lokal liegt', function (): void {
    // Ohne zentralen Speicher gibt es keinen Spiegel — und nichts zu tun.
    Contact::factory()->create();

    $this->artisan('contacts:spiegel-auffrischen')
        ->expectsOutputToContain('lokal')
        ->assertSuccessful();
});

it('holt die Ansprechpartner einer Organisation mit', function (): void {
    // Ein Produkt spiegelt sonst nur, was es selbst gelesen hat. Eine Person,
    // die ANDERSWO an diese Organisation gehaengt wurde — beim
    // Zusammenfuehren, aus einem anderen Produkt —, taucht hier nie auf.
    Contact::query()->forceCreate(['id' => 41, 'kind' => 'org', 'formatted_name' => 'Wildpark Mueden GmbH']);

    $brain = (new FakeBrain)->answers('get', fn (array $args): array => match ((int) $args['id']) {
        41 => ['data' => [
            'id' => 41, 'kind' => 'org', 'formatted_name' => 'Wildpark Mueden GmbH',
            'relations' => ['contact_persons' => [['id' => 85, 'name' => 'Kasse Besucherservice']]],
        ]],
        85 => ['data' => ['id' => 85, 'kind' => 'individual', 'formatted_name' => 'Kasse Besucherservice']],
        default => ['data' => null],
    });

    app()->instance(ContactStore::class, $brain->store());

    $this->artisan('contacts:spiegel-auffrischen')->assertSuccessful();

    expect(Contact::find(85))->not->toBeNull()
        ->and(Contact::find(85)->formatted_name)->toBe('Kasse Besucherservice');
});
