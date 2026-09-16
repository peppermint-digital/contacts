<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Geschrieben werden sollte, aber der zentrale Speicher ist nicht erreichbar.
 *
 * Bewusst ein Wurf und keine stille Rueckgabe: Lesen geht in dieser Lage
 * weiter (der Spiegel traegt), Schreiben pausiert — aber sichtbar. Wer
 * speichert und ein „gespeichert" zurueckbekommt, das nichts gespeichert hat,
 * tippt seine Aenderung nicht noch einmal ein.
 */
class StoreUnavailable extends RuntimeException
{
    public static function forWrite(): self
    {
        return new self(
            'Der Kontakt konnte nicht gespeichert werden: AI Brain ist gerade nicht erreichbar. '
            .'Gelesen wird weiter aus der lokalen Kopie — geaendert werden kann erst wieder, '
            .'wenn die Verbindung steht. Die Aenderung ist NICHT uebernommen.'
        );
    }
}
