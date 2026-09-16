<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Beide Kontakte haben ein Produkt-Profil, und es darf nur eines geben.
 *
 * Der heikle Teil des Zusammenfuehrens, und bewusst eine Absage statt einer
 * Entscheidung: Wenn zwei Kunden je eine Kundennummer, ein Zahlungsziel und
 * eigene Auftraege tragen, kann ein Paket nicht wissen, welche der beiden
 * gilt. Wer hier eine Seite waehlt, wirft die andere weg — und das faellt
 * erst auf, wenn jemand eine Rechnung unter der verschwundenen Nummer sucht.
 *
 * Also: Der Mensch entscheidet, und das Paket sagt ihm, wo.
 */
class ProfileConflict extends RuntimeException
{
    /**
     * @param  list<string>  $tables  Die Profiltabellen, in denen beide Seiten eine Zeile haben.
     */
    public function __construct(public readonly array $tables)
    {
        parent::__construct(
            'Die Kontakte lassen sich nicht ohne Weiteres zusammenfuehren: In '
            .implode(', ', $tables).' haben BEIDE Seiten einen eigenen Eintrag, '
            .'und es darf je Kontakt nur einen geben. Es wurde nichts geaendert. '
            .'Bitte vorher entscheiden, welcher Eintrag gilt — automatisch zu waehlen '
            .'hiesse, den anderen wegzuwerfen.'
        );
    }
}
