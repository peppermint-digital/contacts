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
    /*
    | Es gibt bewusst KEINEN Lese-Cache.
    |
    | Bis zum 17.09.2026 stand hier `cache_ttl` — der Speicher nahm den Wert
    | entgegen und benutzte ihn nirgends. Die Einstellung versprach also etwas,
    | das sie nicht tat.
    |
    | Eingebaut wird sie auch nicht mehr: Seit #5815 meldet das Brain jede
    | Aenderung, und das Produkt liest daraufhin zurueck. Ein Cache mit 15
    | Minuten Haltbarkeit wuerde ausgerechnet diese Rueckfrage aus dem Speicher
    | bedienen — die Meldung kaeme an, und gespiegelt wuerde der alte Stand.
    | Ein Cache waere hier nicht Beschleunigung, sondern das Gegenteil der
    | Frische, die der ganze Weg herstellen soll.
    */

    /*
    | Das Brain-Werkzeug, ueber das die Kontakte laufen.
    |
    | Bewusst der Werkzeug-Weg und NICHT der Faehigkeiten-Gateway: Der
    | Gateway leitet an ein anderes PRODUKT weiter, und `contacts.search`
    | gibt es dort womoeglich auch — dann antwortet das falsche System mit
    | seiner eigenen Kontaktliste, und niemand merkt es. Zentrale Kontakte
    | liegen im Brain selbst, also werden sie aus dem Brain selbst gelesen.
    */
    'brain_tools' => [
        'get' => env('CONTACTS_TOOL_GET', 'get-contact-tool'),
        'find-by-email' => env('CONTACTS_TOOL_GET', 'get-contact-tool'),
        'search' => env('CONTACTS_TOOL_SEARCH', 'search-contacts-tool'),
        'list' => env('CONTACTS_TOOL_LIST', 'list-contacts-tool'),
        'upsert' => env('CONTACTS_TOOL_UPSERT', 'upsert-contact-tool'),
        'merge' => env('CONTACTS_TOOL_MERGE', 'merge-contacts-tool'),
        'unlink' => env('CONTACTS_TOOL_UNLINK', 'unlink-contact-tool'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Riegel vor der lokalen Kopie
    |--------------------------------------------------------------------------
    |
    | Steht `store` auf `brain`, sind die lokalen Tabellen ein Spiegel. Ein
    | `Contact::create()` daneben legt eine Zeile an, die zentral niemand
    | kennt: Sie sieht echt aus, taucht in der Suche auf, und beim naechsten
    | Spiegeln ist sie weg — oder sie bleibt und ist die zweite Wahrheit.
    |
    | Deshalb wirft ein direkter Schreibzugriff, statt stillzuhalten.
    | Abschaltbar fuer den Umstieg, wenn ein Produkt uebergangsweise noch
    | beide Wege braucht.
    |
    */
    'guard_direct_writes' => env('CONTACTS_GUARD_DIRECT_WRITES', true),

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
        'merges' => 'contact_merges',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ring-3-Profile: was beim Zusammenfuehren mit umgehaengt werden muss
    |--------------------------------------------------------------------------
    |
    | Das Paket kennt die Profiltabellen der Produkte nicht — und soll sie
    | nicht kennen. Es muss sie beim Zusammenfuehren aber mitnehmen, sonst
    | bleibt die Kundennummer am aufgeloesten Kontakt haengen und ist weg.
    |
    | Jedes Produkt traegt hier seine eigenen ein:
    |
    |   'profiles' => [
    |       ['table' => 'customers',        'key' => 'contact_id', 'unique' => true],
    |       ['table' => 'crm_deal_profiles','key' => 'contact_id'],
    |   ],
    |
    | `unique` heisst: Es kann je Kontakt nur EINE Zeile geben. Haben dann
    | beide Seiten eine, ist das kein Fall, den ein Paket entscheiden darf —
    | es sagt ab und nennt die Tabelle.
    |
    */
    'profiles' => [],

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
