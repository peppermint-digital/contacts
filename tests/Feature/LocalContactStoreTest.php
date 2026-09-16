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
