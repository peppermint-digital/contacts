<?php

use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Stores\LocalContactStore;

beforeEach(function (): void {
    $this->store = new LocalContactStore;
});

it('findet einen Kontakt ueber seine Adresse', function (): void {
    // Die Frage, die heute niemand beantworten kann: Kommt eine Mail von
    // einem Kunden, findet der CRM-Aufloeser nichts, weil die Person dort
    // kein Kontakt ist.
    $contact = Contact::factory()->create(['formatted_name' => 'Anke Berg']);
    $contact->emails()->create(['value' => 'a.berg@beispiel.de']);

    expect($this->store->findByEmail('a.berg@beispiel.de')?->formatted_name)->toBe('Anke Berg')
        ->and($this->store->findByEmail('gibtsnicht@beispiel.de'))->toBeNull();
});

it('sucht ueber Name, Organisation und Adresse', function (): void {
    Contact::factory()->create(['formatted_name' => 'Anke Berg']);
    Contact::factory()->organisation('Bergbau GmbH')->create();

    $ueberAdresse = Contact::factory()->create(['formatted_name' => 'Clara Diehl']);
    $ueberAdresse->emails()->create(['value' => 'c.diehl@bergbau.de']);

    expect($this->store->search('Berg')->pluck('formatted_name')->sort()->values()->all())
        // Alle drei: ueber den Namen, ueber die Organisation, ueber die
        // Adresse. Wer eine Mail vor sich hat, sucht mit der Adresse — den
        // Namen kennt er gerade nicht.
        ->toBe(['Anke Berg', 'Bergbau GmbH', 'Clara Diehl']);
});

it('zaehlt die Version bei jeder Aenderung hoch', function (): void {
    $erst = $this->store->upsert(['uid' => 'urn:uuid:eins', 'formatted_name' => 'Erst GmbH']);
    expect($erst->version)->toBe(1);

    $dann = $this->store->upsert(['uid' => 'urn:uuid:eins', 'formatted_name' => 'Dann GmbH']);

    // Dieselbe Zeile, nicht eine zweite: `uid` waehlt den bestehenden
    // Kontakt aus.
    expect($dann->id)->toBe($erst->id)
        ->and($dann->version)->toBe(2)
        ->and(Contact::query()->count())->toBe(1);
});

it('laesst sich die Version nicht von aussen vorschreiben', function (): void {
    // Sonst koennte ein Aufrufer sie auf einen alten Stand zuruecksetzen und
    // damit die Absage-Pruefung aushebeln, die auf ihr beruht.
    $contact = $this->store->upsert(['uid' => 'urn:uuid:zwei', 'formatted_name' => 'Zwei GmbH', 'version' => 99]);

    expect($contact->version)->toBe(1);
});

it('zaehlt den Stand auch hoch, wenn jemand am Speicher vorbei aendert', function (): void {
    // Der Stempel muss auf JEDEM Schreibweg steigen, nicht nur auf dem, an
    // den man gedacht hat. Im Brain selbst wird direkt am Modell geaendert;
    // bliebe er dort stehen, duerfte ein Produkt mit dem alten Stand
    // anschliessend ueberschreiben, ohne dass die Pruefung anschlaegt — der
    // Stempel waere genau dort blind, wo er gebraucht wird.
    $contact = Contact::create(['formatted_name' => 'Anke Berg']);
    expect($contact->version)->toBe(1);

    $contact->update(['title' => 'Inhaberin']);
    expect($contact->fresh()->version)->toBe(2);

    $contact->update(['title' => 'Geschaeftsfuehrerin']);
    expect($contact->fresh()->version)->toBe(3);
});

it('erhoeht den Stand nicht, wenn sich gar nichts geaendert hat', function (): void {
    $contact = Contact::create(['formatted_name' => 'Anke Berg']);

    $contact->save();
    $contact->save();

    expect($contact->fresh()->version)->toBe(1);
});

it('legt die Beziehung zur Organisation im selben Aufruf mit an', function (): void {
    // Sonst braeuchte jeder Aufrufer zwei Schritte — und ein halb angelegter
    // Ansprechpartner ohne Organisation ist die Zeile, die spaeter niemand
    // mehr zuordnen kann.
    $firma = Contact::factory()->organisation('Bergbau GmbH')->create();

    $person = $this->store->upsert([
        'uid' => 'urn:test:person',
        'formatted_name' => 'Anke Berg',
        'works_for' => $firma->id,
    ]);

    expect($person->organizations()->pluck('formatted_name')->all())->toBe(['Bergbau GmbH']);
});

it('legt dieselbe Beziehung bei einem zweiten Lauf nicht noch einmal an', function (): void {
    $firma = Contact::factory()->organisation()->create();
    $daten = ['uid' => 'urn:test:person', 'formatted_name' => 'Anke Berg', 'works_for' => $firma->id];

    $this->store->upsert($daten);
    $person = $this->store->upsert($daten);

    expect($person->relations()->count())->toBe(1);
});

it('legt Adressen, Telefone und Anschriften mit an — wie der zentrale Speicher', function (): void {
    // Verhielten sich die beiden Speicher hier verschieden, bekaeme ein
    // Produkt beim Umstellen von `local` auf `brain` ohne eine einzige
    // Codeaenderung ein anderes Ergebnis.
    $contact = $this->store->upsert([
        'uid' => 'urn:test:firma',
        'kind' => 'org',
        'formatted_name' => 'Bergbau GmbH',
        'emails' => [['value' => 'info@bergbau.de', 'is_primary' => true]],
        'phones' => [['value' => '+49 40 1']],
        'addresses' => [['type' => 'billing', 'street' => 'Rechnungsweg 1']],
    ]);

    expect($contact->emails)->toHaveCount(1)
        ->and($contact->phones)->toHaveCount(1)
        ->and($contact->addresses)->toHaveCount(1)
        ->and($contact->addressForDocument('invoice')->street)->toBe('Rechnungsweg 1');
});

it('unterscheidet auch hier weglassen von leeren', function (): void {
    $this->store->upsert(['uid' => 'urn:test:x', 'formatted_name' => 'Anke Berg',
        'emails' => [['value' => 'a@b.de']]]);

    $ohne = $this->store->upsert(['uid' => 'urn:test:x', 'title' => 'Inhaberin']);
    expect($ohne->emails)->toHaveCount(1);

    $leer = $this->store->upsert(['uid' => 'urn:test:x', 'emails' => []]);
    expect($leer->emails)->toHaveCount(0);
});

it('nimmt einen Kontakt, der nur ueber seine Adresse bekannt ist', function (): void {
    $contact = $this->store->upsert(['uid' => 'urn:test:y', 'emails' => [['value' => 'nur@adresse.de']]]);

    expect($contact->emails->first()->value)->toBe('nur@adresse.de');
});
