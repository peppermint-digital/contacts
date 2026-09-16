<?php

namespace Peppermint\Contacts\Models\Concerns;

use Peppermint\Contacts\Contacts\StoreGuard;
use Peppermint\Contacts\Exceptions\DirectWriteToMirror;

/**
 * Schuetzt die lokale Kopie davor, fuer den Bestand gehalten zu werden.
 *
 * Auch beim Loeschen, nicht nur beim Speichern: Eine Zeile, die lokal
 * verschwindet und zentral bleibt, taucht beim naechsten Spiegeln wieder
 * auf — und wer sie geloescht hat, haelt das fuer einen Fehler des Systems
 * statt fuer die Folge des eigenen Wegs.
 */
trait GuardsDirectWrites
{
    public static function bootGuardsDirectWrites(): void
    {
        static::saving(function (): void {
            if (StoreGuard::blocks()) {
                throw DirectWriteToMirror::make();
            }
        });

        static::deleting(function (): void {
            if (StoreGuard::blocks()) {
                throw DirectWriteToMirror::make();
            }
        });
    }
}
