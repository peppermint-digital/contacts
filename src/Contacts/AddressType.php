<?php

namespace Peppermint\Contacts\Contacts;

/**
 * Die Adressarten, die das Paket kennt — bewusst als Konstanten und NICHT
 * als Aufzählung mit Zwang.
 *
 * vCard lässt beim `ADR`-Typ eigene Werte zu, und die Produkte haben schon
 * welche: Die Verwaltung führt `billing` und `shipping` in `CustomerAddress`,
 * ein Shop kennt vielleicht `pickup`. Eine geschlossene Aufzählung würde
 * einen unbekannten Wert beim Lesen zum Fehler machen — und das trifft dann
 * echte Daten in einem laufenden System, nicht den Entwurf.
 *
 * Deshalb: Die Spalte ist eine Zeichenkette, hier stehen nur die Werte, auf
 * die sich das Paket selbst beruft.
 */
final class AddressType
{
    public const Billing = 'billing';

    public const Shipping = 'shipping';

    public const Home = 'home';

    public const Work = 'work';
}
