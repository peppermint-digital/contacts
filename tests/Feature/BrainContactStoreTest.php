<?php

use Peppermint\Contacts\Exceptions\BrainRejected;
use Peppermint\Contacts\Exceptions\StaleContact;
use Peppermint\Contacts\Exceptions\StoreUnavailable;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Tests\Support\FakeBrain;

/**
 * Der zentrale Speicher — und was passiert, wenn das Zentrum weg ist.
 *
 * Der Anlass ist gemessen, nicht gedacht: Am 15.09.2026 war die
 * Brain-Verbindung ueber zwei Stunden tot. Die Frage „kann die Verwaltung in
 * dieser Lage eine Rechnung schreiben?" beantworten diese Tests.
 */
function kontaktAusDemBrain(array $overrides = []): array
{
    return array_merge([
        'id' => 77,
        'uid' => 'urn:uuid:abc-123',
        'kind' => 'org',
        'formatted_name' => 'Beispiel GmbH',
        'organization' => 'Beispiel GmbH',
        'version' => 3,
        'emails' => [['value' => 'buchhaltung@beispiel.de', 'type' => 'work', 'is_primary' => true]],
        'addresses' => [['type' => 'billing', 'street' => 'Rechnungsweg 1', 'city' => 'Hamburg']],
    ], $overrides);
}

it('spiegelt einen gelesenen Kontakt in die lokalen Tabellen', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);

    $contact = $brain->store()->find(77);

    expect($contact)->not->toBeNull()
        // Der Primaerschluessel des Brains wird uebernommen, sonst haette
        // derselbe Kontakt zwei Nummern.
        ->and($contact->id)->toBe(77)
        ->and($contact->mirrored_at)->not->toBeNull()
        ->and($contact->emails)->toHaveCount(1)
        ->and($contact->addresses)->toHaveCount(1)
        ->and(Contact::query()->count())->toBe(1);
});

it('liest bei Ausfall weiter — aus dem Spiegel', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);

    // Einmal im guten Zustand lesen, damit es etwas zu spiegeln gibt.
    $brain->store()->find(77);

    $brain->goesDown();
    $contact = $brain->store()->find(77);

    // Genau das ist der Punkt: Die Anschrift ist da, also laesst sich die
    // Rechnung schreiben. Ohne Spiegel stuende hier null.
    expect($contact)->not->toBeNull()
        ->and($contact->formatted_name)->toBe('Beispiel GmbH')
        ->and($contact->addressForDocument('invoice')->street)->toBe('Rechnungsweg 1');
});

it('lehnt das Schreiben bei Ausfall ab — mit Grund, nicht stumm', function (): void {
    $brain = (new FakeBrain)->goesDown();
    $store = $brain->store();

    expect(fn () => $store->upsert(['formatted_name' => 'Neu GmbH']))
        ->toThrow(StoreUnavailable::class)
        // Der Mensch davor muss erfahren, dass seine Eingabe NICHT
        // uebernommen ist — sonst tippt er sie kein zweites Mal ein.
        ->and(fn () => $store->upsert(['formatted_name' => 'Neu GmbH']))
        ->toThrow(fn (StoreUnavailable $e) => expect($e->getMessage())->toContain('NICHT uebernommen'));
});

it('nennt den Grund, warum gerade nicht geschrieben werden kann', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);
    $store = $brain->store();

    $store->find(77);
    expect($store->writeBlockedReason())->toBeNull();

    $brain->goesDown();
    $store->find(77);

    expect($store->isWritable())->toBeTrue()
        ->and($store->writeBlockedReason())->toContain('nicht erreichbar');
});

it('ueberschreibt den Spiegel NICHT mit einem Fehlschlag', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);
    $brain->store()->find(77);

    $brain->goesDown();
    $brain->store()->find(77);
    $brain->store()->search('Beispiel');

    // Die entscheidende Zeile: Nach zwei Fehlschlaegen steht der gute Stand
    // unveraendert da. Wuerde ein Fehlschlag gespiegelt, waere aus zwei
    // Minuten Ausfall ein dauerhafter Datenverlust geworden.
    $gespiegelt = Contact::query()->find(77);

    expect($gespiegelt)->not->toBeNull()
        ->and($gespiegelt->formatted_name)->toBe('Beispiel GmbH')
        ->and($gespiegelt->emails)->toHaveCount(1)
        ->and($gespiegelt->addresses)->toHaveCount(1);
});

it('sagt Absage bei gleichzeitiger Aenderung — und haengt den fremden Stand an', function (): void {
    $brain = (new FakeBrain)->answers('upsert', [
        'conflict' => true,
        'current' => kontaktAusDemBrain(['formatted_name' => 'Beispiel GmbH & Co. KG', 'version' => 4]),
    ]);

    $store = $brain->store();

    try {
        $store->upsert(['id' => 77, 'formatted_name' => 'Mein Stand', 'version' => 3]);
        $this->fail('Die Absage ist ausgeblieben.');
    } catch (StaleContact $e) {
        // Der fremde Stand haengt an der Ausnahme, damit der Aufrufer beide
        // Fassungen nebeneinanderlegen kann, statt nur „hat nicht geklappt"
        // zu melden.
        expect($e->current['formatted_name'])->toBe('Beispiel GmbH & Co. KG')
            ->and($e->current['version'])->toBe(4);
    }

    // Und nichts davon ist in den Spiegel gelaufen: Die Absage darf den
    // eigenen Stand nicht als Tatsache hinterlassen.
    expect(Contact::query()->count())->toBe(0);
});

it('spiegelt den neuen Stand, wenn das Schreiben durchgeht', function (): void {
    $brain = (new FakeBrain)->answers(
        'upsert',
        fn (array $args): array => ['data' => kontaktAusDemBrain([
            'formatted_name' => $args['formatted_name'],
            'version' => 4,
        ])],
    );

    $contact = $brain->store()->upsert(['id' => 77, 'formatted_name' => 'Beispiel AG', 'version' => 3]);

    expect($contact->formatted_name)->toBe('Beispiel AG')
        ->and($contact->version)->toBe(4)
        ->and(Contact::query()->find(77)->formatted_name)->toBe('Beispiel AG');
});

it('ersetzt beim Spiegeln die Anhaengsel, statt sie anzusammeln', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);
    $brain->store()->find(77);

    // Zentral wird eine Adresse entfernt und eine andere gesetzt.
    $brain->answers('get', ['data' => kontaktAusDemBrain([
        'emails' => [['value' => 'neu@beispiel.de']],
        'addresses' => [],
    ])]);

    $contact = $brain->store()->find(77);

    // Wer nur ergaenzt, sammelt in der Kopie genau die Karteileichen an, die
    // zentral schon aufgeraeumt sind.
    expect($contact->emails)->toHaveCount(1)
        ->and($contact->emails->first()->value)->toBe('neu@beispiel.de')
        ->and($contact->addresses)->toHaveCount(0);
});

it('holt einen zentral geloeschten Kontakt NICHT aus dem Spiegel zurueck', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);
    $brain->store()->find(77);

    // Zentral gibt es ihn nicht mehr. Das ist eine Antwort, kein Ausfall.
    $brain->answers('get', ['data' => null]);

    expect($brain->store()->find(77))->toBeNull();
});

it('spiegelt auch einen Kontakt, der nur eine Adresse hat', function (): void {
    // Der Fall aus dem Postfach. Die Ring-1-Pruefung laeuft beim Speichern,
    // die Adressen entstehen erst danach — ohne Vormerken scheitert
    // ausgerechnet der Fall, fuer den die Regel „Name ODER E-Mail" gemacht ist.
    $brain = (new FakeBrain)->answers('get', ['data' => [
        'id' => 91,
        'kind' => 'individual',
        'formatted_name' => null,
        'emails' => [['value' => 'unbekannt@beispiel.de']],
    ]]);

    $contact = $brain->store()->find(91);

    expect($contact)->not->toBeNull()
        ->and($contact->formatted_name)->toBeNull()
        ->and($contact->emails->first()->value)->toBe('unbekannt@beispiel.de');
});

it('loescht beim Spiegeln nichts, was die Antwort gar nicht mitbringt', function (): void {
    $brain = (new FakeBrain)->answers('get', ['data' => kontaktAusDemBrain()]);
    $brain->store()->find(77);

    // Eine Trefferliste liefert die Kurzform: Name, Organisation, sonst
    // nichts. Wuerde der Spiegel daraus „keine Adressen" ableiten, wischte
    // jede Suche die Anschriften aller Treffer aus der lokalen Kopie — und
    // bei Ausfall stuenden die Kontakte ohne Anschrift da.
    $brain->answers('search', ['data' => [[
        'id' => 77,
        'kind' => 'org',
        'formatted_name' => 'Beispiel GmbH',
    ]]]);

    $brain->store()->search('Beispiel');

    $gespiegelt = Contact::query()->find(77);

    expect($gespiegelt->emails)->toHaveCount(1)
        ->and($gespiegelt->addresses)->toHaveCount(1);
});

it('reicht eine fachliche Absage des Brains durch, statt sie in „nicht erreichbar" zu verwandeln', function (): void {
    // Die beiden Faelle fuehlen sich gleich an, verlangen aber das Gegenteil
    // voneinander: Bei Ausfall wartet man, bei Ablehnung aendert man die Daten.
    $brain = (new FakeBrain)->answers('upsert', [
        'ok' => false,
        'error' => 'Ein Kontakt braucht mindestens eine Kennung.',
    ]);

    expect(fn () => $brain->store()->upsert(['kind' => 'org']))
        ->toThrow(BrainRejected::class)
        ->and(fn () => $brain->store()->upsert(['kind' => 'org']))
        ->toThrow(fn (BrainRejected $e) => expect($e->getMessage())
            ->toContain('mindestens eine Kennung'));
});

it('holt die Ansprechpartner zentral, nicht aus dem Spiegel', function (): void {
    $brain = (new FakeBrain)->answers('get', fn (array $a): array => match ((int) ($a['id'] ?? 0)) {
        77 => ['data' => kontaktAusDemBrain(['relations' => ['contact_persons' => [['id' => 91, 'name' => 'Anke Berg']]]])],
        91 => ['data' => ['id' => 91, 'kind' => 'individual', 'formatted_name' => 'Anke Berg']],
        default => ['data' => null],
    });

    expect($brain->store()->contactPersonsOf(77)->pluck('formatted_name')->all())->toBe(['Anke Berg']);
});

it('beantwortet bei Ausfall aus dem Spiegel, wer fuer die Firma arbeitet', function (): void {
    // Genau dafuer gibt es den Spiegel. Kann ein Produkt das ohne Verbindung
    // nicht beantworten, haelt es doch wieder seine eigene
    // Ansprechpartner-Tabelle daneben — und die Doppelung bleibt.
    $brain = (new FakeBrain)->answers('get', fn (array $a): array => match ((int) ($a['id'] ?? 0)) {
        77 => ['data' => kontaktAusDemBrain()],
        91 => ['data' => [
            'id' => 91, 'kind' => 'individual', 'formatted_name' => 'Anke Berg',
            'relations' => ['works_for' => [['id' => 77, 'name' => 'Beispiel GmbH']]],
        ]],
        default => ['data' => null],
    });

    // Einmal im guten Zustand lesen, damit Firma UND Person gespiegelt sind.
    $brain->store()->find(77);
    $brain->store()->find(91);

    $brain->goesDown();

    expect($brain->store()->contactPersonsOf(77)->pluck('formatted_name')->all())
        ->toBe(['Anke Berg']);
});

it('spiegelt keine Beziehung auf einen Kontakt, den es lokal nicht gibt', function (): void {
    // Sonst zeigt die Zeile auf nichts — oder der Fremdschluessel wirft
    // mitten im Spiegeln.
    $brain = (new FakeBrain)->answers('get', ['data' => [
        'id' => 91, 'kind' => 'individual', 'formatted_name' => 'Anke Berg',
        'relations' => ['works_for' => [['id' => 999999, 'name' => 'Nie gespiegelt GmbH']]],
    ]]);

    $person = $brain->store()->find(91);

    expect($person)->not->toBeNull()
        ->and($person->relations()->count())->toBe(0);
});

it('spiegelt auch die Kurzfassung, in der Adressen blosse Zeichenketten sind', function (): void {
    // So antwortete `search-contacts-tool` bis v0.18.0 — und so antwortet ein
    // Brain, das waehrend eines Rollouts noch nicht nachgezogen ist.
    $brain = (new FakeBrain)->answers('search', ['data' => [[
        'id' => 7,
        'kind' => 'individual',
        'formatted_name' => 'Anke Berg',
        'emails' => ['anke@bergbau.test'],
    ]]]);

    $treffer = $brain->store()->search('Berg')->first();

    expect($treffer->emails->first()->value)->toBe('anke@bergbau.test');
});

/*
|--------------------------------------------------------------------------
| Sammelabruf (v0.24.0)
|--------------------------------------------------------------------------
|
| Ohne ihn braeuchte eine Liste von 40 Kunden 40 Netzaufrufe, um 40 Namen
| anzuzeigen. Er ist die Voraussetzung dafuer, den lokalen Spiegel ueberhaupt
| entfernen zu koennen.
*/

it('holt viele Kontakte in EINEM Aufruf', function (): void {
    $brain = (new FakeBrain)->answers('list', ['data' => [
        kontaktAusDemBrain(['id' => 77, 'formatted_name' => 'Erste GmbH']),
        kontaktAusDemBrain(['id' => 78, 'uid' => 'urn:uuid:def-456', 'formatted_name' => 'Zweite GmbH']),
    ]]);

    $treffer = $brain->store()->findMany([77, 78]);

    expect($treffer)->toHaveCount(2)
        ->and($treffer->pluck('formatted_name')->all())->toBe(['Erste GmbH', 'Zweite GmbH'])
        ->and(collect($brain->calls)->where(0, 'list'))->toHaveCount(1);
});

it('fragt gar nicht erst, wenn die Liste leer ist', function (): void {
    $brain = (new FakeBrain)->answers('list', ['data' => []]);

    expect($brain->store()->findMany([]))->toHaveCount(0)
        ->and($brain->askedFor('list'))->toBeFalse();
});

it('laedt Adressen und Telefone mit, statt sie einzeln nachzuholen', function (): void {
    $brain = (new FakeBrain)->answers('list', ['data' => [kontaktAusDemBrain()]]);

    $treffer = $brain->store()->findMany([77]);

    expect($treffer->first()->relationLoaded('addresses'))->toBeTrue()
        ->and($treffer->first()->addresses->first()->street)->toBe('Rechnungsweg 1');
});

it('faellt beim Sammelabruf auf die lokale Kopie zurueck, wenn das Brain weg ist', function (): void {
    Contact::factory()->organisation('Aus der Kopie')->create(['id' => 77]);

    $brain = (new FakeBrain)->goesDown();

    expect($brain->store()->findMany([77])->pluck('formatted_name')->all())
        ->toBe(['Aus der Kopie']);
});

it('laesst Unbekanntes einfach weg, statt eine Luecke zu melden', function (): void {
    $brain = (new FakeBrain)->answers('list', ['data' => [kontaktAusDemBrain(['id' => 77])]]);

    expect($brain->store()->findMany([77, 999]))->toHaveCount(1);
});
