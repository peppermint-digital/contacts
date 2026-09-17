<?php

namespace Peppermint\Contacts\Support;

/**
 * Die eigene Adresse in der zentralen Liste ersetzen — nicht die Liste (#870).
 *
 * ## Das Problem, für das es das gibt
 *
 * Ein Produkt kennt genau EINE E-Mail-Adresse eines Menschen: eine Spalte in
 * seiner Tabelle, ein Feld in seiner Maske. Zentral führt das Adressbuch
 * Listen. Schickt das Produkt sein eines Feld als ganze Liste, ersetzt der
 * Speicher die Liste damit — und alles, was jemand woanders gepflegt hat,
 * verschwindet. Gemessen am 17.09.2026: Es genügte, im CRM die POSITION einer
 * Person zu ändern, um ihre Privatadresse zu löschen.
 *
 * Die Antwort ist nicht „jedes Produkt führt Listen". Die eine Adresse der
 * CRM-Person ist eine Spalte mit Eindeutigkeits-Index, die Firmenanschrift der
 * Verwaltung steht am Kunden und geht auf Rechnungen. Beide sollen ihre eine
 * Adresse weiter pflegen — sie dürfen dabei nur nicht die übrigen mitnehmen.
 *
 * ## Woran die eigene Zeile erkannt wird
 *
 * Am ALTEN Wert, den das Produkt vor dem Speichern hatte — nicht an der Art
 * und nicht an der Reihenfolge. Wer die erste Zeile für die eigene hält,
 * trifft nach der ersten Pflege im Adressbuch die falsche.
 */
class Kanalliste
{
    /**
     * @param  iterable<mixed>  $vorhandene  Die zentrale Liste (Modelle oder Zeilen).
     * @param  string|null  $alt  Was das Produkt vorher hatte — die eigene Zeile.
     * @param  string|null  $neu  Was es jetzt hat. Leer heißt: die eigene Zeile fällt weg.
     * @return array<int, array<string, mixed>>
     */
    public static function ersetzen(iterable $vorhandene, ?string $alt, ?string $neu, string $typ = 'work'): array
    {
        $liste = [];

        foreach ($vorhandene as $zeile) {
            $liste[] = [
                'value' => (string) (is_array($zeile) ? ($zeile['value'] ?? '') : $zeile->value),
                'type' => is_array($zeile) ? ($zeile['type'] ?? null) : $zeile->type,
                'is_primary' => (bool) (is_array($zeile) ? ($zeile['is_primary'] ?? false) : $zeile->is_primary),
            ];
        }

        $alt = self::normiert($alt);
        $neu = trim((string) $neu);

        // Die eigene Zeile heraussuchen. Findet sie sich nicht, hat das Produkt
        // sie noch nie beigetragen — dann kommt sie hinzu.
        $eigene = null;

        foreach ($liste as $i => $zeile) {
            if ($alt !== '' && self::normiert($zeile['value']) === $alt) {
                $eigene = $i;

                break;
            }
        }

        if ($neu === '') {
            // Nichts mehr beizutragen: die eigene Zeile geht, der Rest bleibt.
            return $eigene === null ? $liste : array_values(array_filter(
                $liste,
                fn ($_, $i): bool => $i !== $eigene,
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        if ($eigene !== null) {
            $liste[$eigene]['value'] = $neu;

            return $liste;
        }

        // Schon vorhanden, nur von jemand anderem eingetragen? Dann nicht
        // verdoppeln — zwei Zeilen mit derselben Adresse sind keine zwei
        // Adressen.
        foreach ($liste as $zeile) {
            if (self::normiert($zeile['value']) === self::normiert($neu)) {
                return $liste;
            }
        }

        $liste[] = ['value' => $neu, 'type' => $typ, 'is_primary' => $liste === []];

        return $liste;
    }

    private static function normiert(?string $wert): string
    {
        return mb_strtolower(trim((string) $wert));
    }
}
