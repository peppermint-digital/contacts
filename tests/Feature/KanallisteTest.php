<?php

use Peppermint\Contacts\Support\Kanalliste;

/**
 * Die eigene Adresse ersetzen, die übrigen behalten (#870).
 *
 * Ein Produkt kennt genau ein E-Mail-Feld. Schickt es das als ganze Liste,
 * verschwindet alles, was jemand woanders gepflegt hat — gemessen am
 * 17.09.2026 im CRM, ausgelöst durch eine geänderte POSITION.
 */
function zentraleListe(): array
{
    return [
        ['value' => 'anke@bergbau.test', 'type' => 'work', 'is_primary' => true],
        ['value' => 'anke.berg@privat.test', 'type' => 'home', 'is_primary' => false],
    ];
}

it('behaelt die fremde Adresse, wenn sich die eigene aendert', function (): void {
    $liste = Kanalliste::ersetzen(zentraleListe(), 'anke@bergbau.test', 'a.berg@bergbau.test');

    expect(array_column($liste, 'value'))->toBe(['a.berg@bergbau.test', 'anke.berg@privat.test'])
        // Die Art der eigenen Zeile bleibt, was sie zentral war.
        ->and($liste[0]['type'])->toBe('work');
});

it('ruehrt nichts an, wenn sich nichts geaendert hat', function (): void {
    $liste = Kanalliste::ersetzen(zentraleListe(), 'anke@bergbau.test', 'anke@bergbau.test');

    expect(array_column($liste, 'value'))->toBe(['anke@bergbau.test', 'anke.berg@privat.test']);
});

it('haengt an, was das Produkt noch nie beigetragen hat', function (): void {
    $liste = Kanalliste::ersetzen(zentraleListe(), null, 'buchhaltung@bergbau.test');

    expect($liste)->toHaveCount(3)
        ->and($liste[2]['value'])->toBe('buchhaltung@bergbau.test');
});

it('verdoppelt nichts, was schon jemand anders eingetragen hat', function (): void {
    // Zwei Zeilen mit derselben Adresse sind keine zwei Adressen.
    expect(Kanalliste::ersetzen(zentraleListe(), null, 'ANKE.BERG@privat.test'))->toHaveCount(2);
});

it('nimmt nur die eigene Zeile weg, wenn das Feld geleert wird', function (): void {
    $liste = Kanalliste::ersetzen(zentraleListe(), 'anke@bergbau.test', '');

    expect(array_column($liste, 'value'))->toBe(['anke.berg@privat.test']);
});

it('erkennt die eigene Zeile am alten Wert, nicht an der Reihenfolge', function (): void {
    // Zentral steht die private Adresse vorn. Wer die erste Zeile fuer die
    // eigene haelt, ueberschreibt hier den falschen Menschen.
    $liste = Kanalliste::ersetzen([
        ['value' => 'anke.berg@privat.test', 'type' => 'home', 'is_primary' => true],
        ['value' => 'anke@bergbau.test', 'type' => 'work', 'is_primary' => false],
    ], 'anke@bergbau.test', 'a.berg@bergbau.test');

    expect(array_column($liste, 'value'))->toBe(['anke.berg@privat.test', 'a.berg@bergbau.test']);
});

it('macht aus dem ersten Eintrag den bevorzugten', function (): void {
    expect(Kanalliste::ersetzen([], null, 'erste@bergbau.test')[0]['is_primary'])->toBeTrue();
});

it('kommt mit Modellen statt Zeilen zurecht', function (): void {
    $modell = new class
    {
        public string $value = 'anke@bergbau.test';

        public ?string $type = 'work';

        public bool $is_primary = true;
    };

    expect(Kanalliste::ersetzen([$modell], 'anke@bergbau.test', 'neu@bergbau.test')[0]['value'])
        ->toBe('neu@bergbau.test');
});
