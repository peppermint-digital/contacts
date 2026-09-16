<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Woher die Kontakte kommen
    |--------------------------------------------------------------------------
    |
    | `local` — eigene Tabellen. Damit läuft das Paket für sich allein.
    | `brain` — zentral im AI Brain, lokal gespiegelt. Braucht
    |           peppermint/ai-brain-bridge.
    |
    | Kontakte sind ein Paket vom Typ B: geteilt wird nicht nur der Code,
    | sondern auch die Daten. Ein Mensch existiert, bevor eines unserer
    | Systeme von ihm weiss — alle beschreiben denselben. Deshalb ist `brain`
    | hier die Vorgabe und nicht die Ausnahme; `local` bleibt für den Fall,
    | dass ein Produkt das Paket allein benutzt.
    |
    | Der Speicher-Vertrag selbst entsteht in E2. Der Schlüssel steht schon
    | hier, damit die Produkte ihn nicht später an einer anderen Stelle
    | suchen müssen.
    |
    */
    'store' => env('CONTACTS_STORE', 'brain'),

    /*
    | Wie lange ein zentraler Stand lokal vorgehalten wird. Das ist die
    | Antwort auf „was, wenn das Brain weg ist": Das Lesen friert auf dem
    | letzten guten Stand ein, statt zu scheitern — und eine Rechnung lässt
    | sich weiter schreiben. Nur Erfolg wird gespiegelt.
    */
    'cache_ttl' => (int) env('CONTACTS_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Adoption — damit ein gewachsenes Produkt mitspielen kann
    |--------------------------------------------------------------------------
    |
    | Pflicht, nicht Zusatz: Ein Paket, das nur auf frischen Tabellen läuft,
    | kann ein gewachsenes Produkt nicht übernehmen — und dann wird es ein
    | zweites Mal gebaut.
    |
    | Jedes Kernfeld, das die eigene Tabelle anders nennt, hier abbilden. Was
    | fehlt, behält den Paketnamen.
    |
    | Beispiel Verwaltung (`customers` trägt heute genau die Ring-2-Felder):
    |
    |   'tables'  => ['contacts' => 'customers'],
    |   'columns' => ['formatted_name' => 'company_name'],
    |
    */
    'tables' => [
        'contacts' => 'contacts',
        'emails' => 'contact_emails',
        'phones' => 'contact_phones',
        'addresses' => 'contact_addresses',
        'relations' => 'contact_relations',
    ],

    'columns' => [
        // 'formatted_name' => 'company_name',
        // 'organization'   => 'company_name',
        // 'note'           => 'notes',
    ],

    /*
    | Abschalten, wenn die Tabellen schon existieren und oben abgebildet sind.
    */
    'run_migrations' => env('CONTACTS_RUN_MIGRATIONS', true),

];
