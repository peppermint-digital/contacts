<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Am Speicher vorbei in die lokale Kopie geschrieben.
 *
 * Steht `contacts.store` auf `brain`, sind die lokalen Tabellen ein Spiegel
 * und nicht der Bestand. Ein `Contact::create()` daneben legt eine Zeile an,
 * die zentral niemand kennt: Sie sieht echt aus, taucht in der Suche auf,
 * und beim naechsten Abgleich ist sie weg — oder schlimmer, sie bleibt und
 * ist die zweite Wahrheit, gegen die dieses ganze Paket gebaut ist.
 *
 * Deshalb ein Wurf beim Schreiben und nicht erst eine Abweichung Wochen
 * spaeter. Abschaltbar ueber `contacts.guard_direct_writes`, fuer den Fall,
 * dass ein Produkt beim Umstieg noch beide Wege braucht.
 */
class DirectWriteToMirror extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Dieser Kontakt liegt zentral (contacts.store = "brain"); die lokalen Tabellen sind '
            .'nur eine Kopie. Schreibende Zugriffe gehoeren ueber den ContactStore '
            .'(z.B. app(ContactStore::class)->upsert([...])) — sonst entsteht eine Zeile, '
            .'die zentral niemand kennt.'
        );
    }
}
