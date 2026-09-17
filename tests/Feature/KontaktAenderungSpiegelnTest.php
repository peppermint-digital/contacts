<?php

use Peppermint\Contacts\Listeners\KontaktAenderungSpiegeln;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Stores\LocalContactStore;
use Peppermint\Contacts\Tests\Support\FakeBrain;

/**
 * Das Brain meldet, das Produkt zieht nach (#5815).
 */
function meldung(string $type, array $payload = []): object
{
    return new class($type, $payload)
    {
        public object $event;

        public function __construct(string $type, array $payload)
        {
            $this->event = (object) ['type' => $type, 'payload' => $payload];
        }
    };
}

it('holt einen gemeldeten Kontakt neu, wenn er hier schon steht', function (): void {
    Contact::query()->forceCreate(['id' => 77, 'kind' => 'org', 'formatted_name' => 'Alter Stand']);

    $brain = (new FakeBrain)->answers('get', ['data' => [
        'id' => 77, 'kind' => 'org', 'formatted_name' => 'Neuer Stand',
    ]]);

    (new KontaktAenderungSpiegeln($brain->store()))->handle(meldung('contact.changed', ['id' => 77]));

    expect(Contact::find(77)->formatted_name)->toBe('Neuer Stand')
        ->and($brain->calls)->toHaveCount(1);
});

it('zieht sich keinen Kontakt herunter, den es hier nicht gibt', function (): void {
    // Sonst fuellte sich die Verwaltung mit den Interessenten des CRM.
    $brain = (new FakeBrain)->answers('get', ['data' => ['id' => 99, 'formatted_name' => 'Fremd']]);

    (new KontaktAenderungSpiegeln($brain->store()))->handle(meldung('contact.changed', ['id' => 99]));

    expect($brain->calls)->toBeEmpty()
        ->and(Contact::query()->count())->toBe(0);
});

it('ruehrt sich nicht bei einer Meldung, die nichts mit Kontakten zu tun hat', function (): void {
    Contact::query()->forceCreate(['id' => 77, 'kind' => 'org', 'formatted_name' => 'Alter Stand']);
    $brain = (new FakeBrain)->answers('get', ['data' => ['id' => 77, 'formatted_name' => 'Neu']]);

    (new KontaktAenderungSpiegeln($brain->store()))->handle(meldung('invoice.paid', ['id' => 77]));

    expect($brain->calls)->toBeEmpty();
});

it('fragt gar nicht erst, wenn die Kontakte lokal liegen', function (): void {
    Contact::query()->forceCreate(['id' => 77, 'kind' => 'org', 'formatted_name' => 'Alter Stand']);

    // Kein zentrales Gegenueber — es gibt nichts nachzuziehen.
    (new KontaktAenderungSpiegeln(new LocalContactStore))->handle(meldung('contact.changed', ['id' => 77]));

    expect(Contact::find(77)->formatted_name)->toBe('Alter Stand');
});

it('folgt beim Zusammenfuehren der Spur zum Nachfolger', function (): void {
    Contact::query()->forceCreate(['id' => 77, 'kind' => 'individual', 'formatted_name' => 'Anke Berg']);

    $brain = (new FakeBrain)->answers('get', ['data' => [
        'id' => 77, 'kind' => 'individual', 'formatted_name' => 'Anke Bergmann',
    ]]);

    (new KontaktAenderungSpiegeln($brain->store()))->handle(meldung('contact.merged', ['id' => 77]));

    expect($brain->calls[0][0])->toBe('get')
        ->and(Contact::find(77)->formatted_name)->toBe('Anke Bergmann');
});
