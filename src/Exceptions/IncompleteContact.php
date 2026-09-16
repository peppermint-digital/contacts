<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Ein Kontakt ohne jede Kennung — weder Name noch E-Mail.
 *
 * Bewusst eine Ausnahme und keine stille Verweigerung: Eine Zeile, die
 * niemanden bezeichnet, ist später nicht mehr zuzuordnen. Sie lässt sich
 * weder finden noch zusammenführen noch löschen, weil niemand sagen kann,
 * wen sie meinte. Solche Zeilen sammeln sich an, und jede einzelne muss
 * irgendwann von Hand angesehen werden.
 */
class IncompleteContact extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Ein Kontakt braucht mindestens eine Kennung: einen Namen ODER eine E-Mail-Adresse. '
            .'Eine Adresse ohne Namen ist erlaubt (so entsteht sie aus einem Postfach), '
            .'ein Name ohne Adresse ebenso (der Telefonkontakt) — beides zu verlangen '
            .'schlösse echte Fälle aus. Keines von beidem ist kein Kontakt.'
        );
    }
}
