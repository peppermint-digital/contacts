<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Peppermint\Contacts\Events\ContactsMerged;
use Peppermint\Contacts\Exceptions\ProfileConflict;
use Peppermint\Contacts\Merging\ContactMerger;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactRelation;
use Peppermint\Contacts\Stores\LocalContactStore;

beforeEach(function (): void {
    $this->merger = new ContactMerger;
});

it('fuellt nur Luecken und ueberschreibt nichts Gefuelltes', function (): void {
    $bleibt = Contact::create(['formatted_name' => 'Anke Berg', 'title' => 'Geschaeftsfuehrerin']);
    $geht = Contact::create(['formatted_name' => 'A. Berg', 'title' => 'Inhaberin', 'organization' => 'Bergbau GmbH']);

    $ergebnis = $this->merger->merge($bleibt, $geht);

    expect($ergebnis->formatted_name)->toBe('Anke Berg')
        // Andersherum waere das Zusammenfuehren ein Weg, gute Daten durch
        // aeltere zu ersetzen — und niemand saehe, was verschwunden ist.
        ->and($ergebnis->title)->toBe('Geschaeftsfuehrerin')
        // Die Luecke dagegen wird gefuellt.
        ->and($ergebnis->organization)->toBe('Bergbau GmbH');
});

it('haengt Notizen aneinander, statt eine zu waehlen', function (): void {
    $bleibt = Contact::create(['formatted_name' => 'Anke Berg', 'note' => 'Ruft lieber an.']);
    $geht = Contact::create(['formatted_name' => 'A. Berg', 'note' => 'Rechnung per Post.']);

    // Zwei Bemerkungen zu einem Menschen sind beide wahr.
    expect($this->merger->merge($bleibt, $geht)->note)
        ->toBe("Ruft lieber an.\n\nRechnung per Post.");
});

it('nimmt Adressen und Telefone mit und erbt keine Dubletten', function (): void {
    $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
    $bleibt->emails()->create(['value' => 'a.berg@beispiel.de', 'is_primary' => true]);
    $bleibt->phones()->create(['value' => '+49 40 1']);

    $geht = Contact::create(['formatted_name' => 'A. Berg']);
    $geht->emails()->createMany([
        ['value' => 'a.berg@beispiel.de'],   // schon da
        ['value' => 'privat@beispiel.de'],   // neu
    ]);
    $geht->phones()->create(['value' => '+49 170 2']);

    $ergebnis = $this->merger->merge($bleibt, $geht)->refresh();

    expect($ergebnis->emails->pluck('value')->sort()->values()->all())
        ->toBe(['a.berg@beispiel.de', 'privat@beispiel.de'])
        ->and($ergebnis->phones)->toHaveCount(2)
        // Der erste Eintrag der bleibenden Seite bleibt der erste — sonst
        // stuende nach dem Zusammenfuehren die Zweitadresse oben, ohne dass
        // jemand etwas geaendert haette.
        ->and($ergebnis->primaryEmail()->value)->toBe('a.berg@beispiel.de');
});

it('erkennt dieselbe Anschrift, auch wenn sie anders geschrieben ist', function (): void {
    $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
    $bleibt->addresses()->create(['type' => 'billing', 'street' => 'Rechnungsweg 1', 'city' => 'Hamburg']);

    $geht = Contact::create(['formatted_name' => 'A. Berg']);
    $geht->addresses()->createMany([
        ['type' => 'billing', 'street' => ' rechnungsweg 1 ', 'city' => 'HAMBURG'],
        ['type' => 'shipping', 'street' => 'Lieferweg 2', 'city' => 'Bremen'],
    ]);

    $ergebnis = $this->merger->merge($bleibt, $geht)->refresh();

    expect($ergebnis->addresses)->toHaveCount(2);
});

it('haengt Beziehungen in BEIDE Richtungen um', function (): void {
    $firma = Contact::factory()->organisation('Bergbau GmbH')->create();

    $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
    $geht = Contact::create(['formatted_name' => 'A. Berg']);

    // Die aufgeloeste Zeile ist Ansprechpartnerin der Firma …
    ContactRelation::create([
        'contact_id' => $geht->id,
        'related_contact_id' => $firma->id,
        'type' => ContactRelation::WorksFor,
    ]);

    // … und hat selbst eine Ansprechpartnerin.
    $assistenz = Contact::create(['formatted_name' => 'Clara Diehl']);
    ContactRelation::create([
        'contact_id' => $assistenz->id,
        'related_contact_id' => $geht->id,
        'type' => ContactRelation::WorksFor,
    ]);

    $ergebnis = $this->merger->merge($bleibt, $geht);

    // Wer nur eine Richtung umhaengt, verliert die andere lautlos.
    expect($ergebnis->organizations()->pluck('formatted_name')->all())->toBe(['Bergbau GmbH'])
        ->and($ergebnis->contactPersons()->pluck('formatted_name')->all())->toBe(['Clara Diehl']);
});

it('macht aus einer umgehaengten Beziehung keinen Selbstbezug', function (): void {
    $bleibt = Contact::factory()->organisation('Bergbau GmbH')->create();
    $geht = Contact::factory()->organisation('Bergbau')->create();

    // „Bergbau arbeitet fuer Bergbau GmbH" — nach dem Zusammenfuehren waere
    // das „Bergbau GmbH arbeitet fuer sich selbst".
    ContactRelation::create([
        'contact_id' => $geht->id,
        'related_contact_id' => $bleibt->id,
        'type' => ContactRelation::WorksFor,
    ]);

    $ergebnis = $this->merger->merge($bleibt, $geht);

    expect($ergebnis->relations()->count())->toBe(0)
        ->and($ergebnis->inverseRelations()->count())->toBe(0);
});

it('laesst einen alten Verweis weiter aufloesen', function (): void {
    $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
    $geht = Contact::create(['formatted_name' => 'A. Berg', 'uid' => 'urn:uuid:alt']);
    $alteNummer = $geht->id;

    $this->merger->merge($bleibt, $geht);

    // Ein Auftrag von vor drei Monaten zeigt auf die alte Nummer. Ohne Spur
    // zeigte er ab jetzt ins Leere, und niemand koennte mehr sagen, wer
    // gemeint war.
    expect((new LocalContactStore)->find($alteNummer)?->formatted_name)->toBe('Anke Berg');
});

it('zieht aeltere Spuren mit, sodass keine Kette entsteht', function (): void {
    $a = Contact::create(['formatted_name' => 'A']);
    $b = Contact::create(['formatted_name' => 'B']);
    $c = Contact::create(['formatted_name' => 'C']);
    $cNummer = $c->id;
    $bNummer = $b->id;

    $this->merger->merge($b, $c);   // C → B
    $this->merger->merge($a, $b);   // B → A

    $store = new LocalContactStore;

    // Beide alten Nummern muessen bei A landen. Ohne Mitziehen zeigte C auf
    // B — eine Zeile, die es selbst nicht mehr gibt.
    expect($store->find($bNummer)?->formatted_name)->toBe('A')
        ->and($store->find($cNummer)?->formatted_name)->toBe('A');
});

it('meldet den Vorgang, damit Produkte nachziehen koennen', function (): void {
    Event::fake([ContactsMerged::class]);

    $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
    $geht = Contact::create(['formatted_name' => 'A. Berg']);
    $alteNummer = $geht->id;

    $this->merger->merge($bleibt, $geht);

    Event::assertDispatched(
        ContactsMerged::class,
        fn (ContactsMerged $e): bool => $e->into->is($bleibt) && $e->fromId === $alteNummer,
    );
});

it('weigert sich, einen Kontakt mit sich selbst zusammenzufuehren', function (): void {
    $contact = Contact::factory()->create();

    $this->merger->merge($contact, $contact);
})->throws(InvalidArgumentException::class);

/**
 * Der heikle Teil: die Ring-3-Profile.
 */
describe('Produkt-Profile', function (): void {
    beforeEach(function (): void {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->string('customer_number');
        });

        config(['contacts.profiles' => [
            ['table' => 'customers', 'key' => 'contact_id', 'unique' => true],
        ]]);
    });

    it('haengt das Profil der aufgeloesten Zeile um', function (): void {
        $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
        $geht = Contact::create(['formatted_name' => 'A. Berg']);

        DB::table('customers')->insert(['contact_id' => $geht->id, 'customer_number' => 'K-100']);

        $this->merger->merge($bleibt, $geht);

        // Ohne Umhaengen bliebe die Kundennummer an der aufgeloesten Zeile
        // haengen und waere weg.
        expect(DB::table('customers')->where('contact_id', $bleibt->id)->value('customer_number'))
            ->toBe('K-100');
    });

    it('sagt ab, wenn BEIDE Seiten ein Profil tragen', function (): void {
        $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
        $geht = Contact::create(['formatted_name' => 'A. Berg']);

        DB::table('customers')->insert([
            ['contact_id' => $bleibt->id, 'customer_number' => 'K-100'],
            ['contact_id' => $geht->id, 'customer_number' => 'K-200'],
        ]);

        try {
            $this->merger->merge($bleibt, $geht);
            $this->fail('Die Absage ist ausgeblieben.');
        } catch (ProfileConflict $e) {
            expect($e->tables)->toBe(['customers']);
        }

        // Und nichts ist angefasst worden: Eine Absage soll gar nichts
        // veraendert haben, nicht etwas veraendern und zuruecknehmen.
        expect(Contact::query()->count())->toBe(2)
            ->and(DB::table('customers')->count())->toBe(2)
            ->and(DB::table('customers')->where('contact_id', $geht->id)->value('customer_number'))
            ->toBe('K-200');
    });

    it('stoert sich nicht an einer Profiltabelle, die es noch nicht gibt', function (): void {
        // Ein Produkt kann eine Tabelle eintragen, bevor ihre Migration
        // gelaufen ist. Eine Abfrage dagegen scheitert haerter als ein
        // fehlendes Profil.
        config(['contacts.profiles' => [
            ['table' => 'kommt_erst_noch', 'key' => 'contact_id', 'unique' => true],
        ]]);

        $bleibt = Contact::create(['formatted_name' => 'Anke Berg']);
        $geht = Contact::create(['formatted_name' => 'A. Berg']);

        expect($this->merger->merge($bleibt, $geht)->formatted_name)->toBe('Anke Berg');
    });
});
