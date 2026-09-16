<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Jemand anderes hat denselben Kontakt inzwischen geaendert.
 *
 * Die Absage ist der Zweck, nicht die Panne. Zwei Produkte schreiben auf
 * dieselbe Zeile; ohne diese Pruefung gewinnt der Letzte, und was der
 * Erste eingetragen hat, ist weg, ohne dass es jemand merkt. Genau diese
 * Art Verlust soll das Paket beenden — es waere merkwuerdig, ihn beim
 * Schreiben wieder einzubauen.
 *
 * Der aktuelle Stand haengt an der Ausnahme, damit der Aufrufer die beiden
 * Fassungen nebeneinanderlegen kann, statt den Menschen davor nur mit
 * „hat nicht geklappt" stehenzulassen.
 */
class StaleContact extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $current  Der Stand, der jetzt zentral gilt.
     */
    public function __construct(public readonly array $current = [])
    {
        parent::__construct(
            'Der Kontakt wurde zwischenzeitlich an anderer Stelle geaendert. '
            .'Die Aenderung wurde NICHT uebernommen, damit der fremde Stand nicht verlorengeht. '
            .'Bitte den aktuellen Stand ansehen und erneut speichern.'
        );
    }
}
