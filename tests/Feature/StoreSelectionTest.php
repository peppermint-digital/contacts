<?php

use Illuminate\Support\Facades\Log;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Stores\LocalContactStore;

/**
 * Welcher Speicher gebaut wird — und was passiert, wenn die Einstellung luegt.
 */
it('nimmt den lokalen Speicher, wenn local eingestellt ist', function (): void {
    config()->set('contacts.store', 'local');
    app()->forgetInstance(ContactStore::class);

    expect(app(ContactStore::class))->toBeInstanceOf(LocalContactStore::class)
        ->and(app(ContactStore::class)->isWritable())->toBeTrue()
        ->and(app(ContactStore::class)->writeBlockedReason())->toBeNull();
});

it('faellt ohne Bruecke auf lokal zurueck — und sagt es laut', function (): void {
    // Gepruefft wird nicht der Rueckfall, sondern die Warnung. Still
    // zurueckzufallen hiesse: Jemand haelt die Kontakte fuer zentral,
    // waehrend sie lokal liegen — und pflegt ab da die falsche Kopie. Bei
    // Kontakten ist das schlimmer als bei Postfaechern, weil hier
    // geschrieben wird.
    Log::spy();

    config()->set('contacts.store', 'brain');
    app()->forgetInstance(ContactStore::class);

    expect(app(ContactStore::class))->toBeInstanceOf(LocalContactStore::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'LOKAL'))
        ->once();
});
