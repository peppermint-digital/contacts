<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Geschrieben werden sollte, aber der zentrale Speicher ist nicht erreichbar.
 *
 * Bewusst ein Wurf und keine stille Rueckgabe. Wer speichert und ein
 * „gespeichert" zurueckbekommt, das nichts gespeichert hat, tippt seine
 * Aenderung nicht noch einmal ein.
 *
 * Seit dem 24.09.2026 gilt das auch beim LESEN: Es gibt keine lokale Kopie
 * mehr, auf die man zurueckfallen koennte, und es soll auch keine geben. Ein
 * alter Stand, den niemand als alt erkennt, ist schlimmer als eine Absage —
 * siehe „Fail-closed verbirgt den eigenen Ausfall".
 */
class StoreUnavailable extends RuntimeException
{
    public static function forWrite(): self
    {
        return new self(
            'Der Kontakt konnte nicht gespeichert werden: AI Brain ist gerade nicht erreichbar. '
            .'Die Aenderung ist NICHT uebernommen — bitte spaeter noch einmal versuchen.'
        );
    }

    /**
     * @param  string  $was  Was gelesen werden sollte, damit das Protokoll
     *                       die Stelle nennt und nicht nur den Ausfall.
     */
    public static function forRead(string $was): self
    {
        return new self(
            "Kontakte ({$was}): AI Brain ist gerade nicht erreichbar. "
            .'Es gibt keine lokale Kopie, aus der geantwortet werden koennte — '
            .'das ist so gewollt. Bitte spaeter noch einmal versuchen.'
        );
    }
}
