<?php

namespace Peppermint\Contacts\Contacts;

/**
 * Was für eine Art Kontakt das ist — vCard `KIND` (RFC 6350, §6.1.4).
 *
 * Die Art ist Ring 1, also Pflicht. Sie ist der Grund, warum ein Kunde
 * keine zweite Zeile braucht: Die Verwaltung führt heute `Customer` mit
 * `company_name`, das CRM `Company` — beides sind Kontakte mit `KIND:org`.
 * Der Ansprechpartner daneben ist `KIND:individual`, verbunden über eine
 * Beziehung statt über ein Freitextfeld.
 */
enum Kind: string
{
    case Individual = 'individual';
    case Org = 'org';
    case Group = 'group';
    case Location = 'location';
}
