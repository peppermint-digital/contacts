<?php

namespace Peppermint\Contacts\Contacts;

use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Stores\BrainContactStore;

/**
 * Der Riegel vor der lokalen Kopie.
 *
 * Steht `contacts.store` auf `brain`, sind die lokalen Tabellen ein Spiegel.
 * Der Speicher selbst muss hineinschreiben duerfen — sonst koennte er nicht
 * spiegeln —, alle anderen nicht. Genau diese eine Ausnahme verwaltet diese
 * Klasse, und sie tut es an EINER Stelle: Ein Merker je Modell waere
 * fuenfmal derselbe Zustand, und beim sechsten Modell vergisst ihn jemand.
 */
final class StoreGuard
{
    private static bool $bypassed = false;

    /**
     * Den Riegel fuer die Dauer eines Schreibvorgangs oeffnen.
     *
     * Mit `finally`, nicht ohne: Wirft das Spiegeln mittendrin, bliebe der
     * Riegel sonst offen — und ab da koennte jeder beliebige Code in die
     * Kopie schreiben, ohne dass noch etwas warnt.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function bypass(callable $write): mixed
    {
        $before = self::$bypassed;
        self::$bypassed = true;

        try {
            return $write();
        } finally {
            self::$bypassed = $before;
        }
    }

    /**
     * Greift der Riegel bei diesem Schreibzugriff?
     *
     * Gefragt wird der GEBAUTE Speicher, nicht die Einstellung. Der
     * Unterschied ist kein Feinschliff: Steht `store` auf `brain`, fehlt
     * aber die Bruecke, faellt der Provider auf den lokalen Speicher zurueck
     * (laut, mit Warnung). Die Einstellung sagt dann immer noch „brain" —
     * wer sie fragt, verriegelt Tabellen, die in Wahrheit der Bestand sind,
     * und das Produkt kann gar nichts mehr speichern.
     */
    public static function blocks(): bool
    {
        if (self::$bypassed) {
            return false;
        }

        if (! config('contacts.guard_direct_writes', true)) {
            return false;
        }

        if (! app()->bound(ContactStore::class)) {
            return false;
        }

        return app(ContactStore::class) instanceof BrainContactStore;
    }
}
