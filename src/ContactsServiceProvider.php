<?php

namespace Peppermint\Contacts;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Stores\BrainContactStore;
use Peppermint\Contacts\Stores\LocalContactStore;

class ContactsServiceProvider extends ServiceProvider
{
    /** Diese Klasse gibt es nur, wenn ein brauchbarer zentraler Speicher installiert ist. */
    private const BRIDGE = 'Peppermint\\AiBrainBridge\\Facades\\AiBrain';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/contacts.php', 'contacts');

        $this->app->singleton(ContactStore::class, fn (): ContactStore => $this->store());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/contacts.php' => config_path('contacts.php'),
        ], 'contacts-config');

        // Die Tabellen entstehen nur im eigenstaendigen Betrieb.
        //
        // Laeuft das Paket mit AI Brain, liegen die Kontakte dort — und nur
        // dort. Ein Produkt haette sonst eine zweite Datenhaltung, die
        // auseinanderlaufen kann, und niemand saehe, welche der beiden gilt.
        //
        // Ein gewachsenes Produkt kann `run_migrations` zusaetzlich
        // abschalten und stattdessen seine vorhandenen Tabellen abbilden.
        if (config('contacts.store') === 'local' && config('contacts.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }


    /**
     * Den eingestellten Speicher bauen — oder laut auf den lokalen zurueckfallen.
     *
     * `store: brain` ohne die Bruecke ist eine Fehlkonfiguration, kein
     * Randfall. Stillschweigend zurueckzufallen hiesse: Jemand haelt die
     * Kontakte fuer zentral, waehrend sie lokal liegen — und pflegt ab da
     * die falsche Kopie. Bei Kontakten waere das schlimmer als bei
     * Postfaechern, weil hier geschrieben wird: Jede Aenderung landete in
     * einer Zeile, die sonst niemand sieht.
     */
    private function store(): ContactStore
    {
        if (config('contacts.store', 'brain') !== 'brain') {
            return new LocalContactStore;
        }

        if (! class_exists(self::BRIDGE)) {
            Log::warning(
                'contacts.store steht auf "brain", aber peppermint/ai-brain-bridge ist nicht installiert. '
                .'Die Kontakte werden LOKAL gefuehrt — wer sie fuer zentral haelt, pflegt die falsche Kopie.'
            );

            return new LocalContactStore;
        }

        $bridge = self::BRIDGE;

        return new BrainContactStore(
            function (string $capability, array $arguments) use ($bridge): ?array {
                // Vier einzelne Werkzeuge statt eines Sammelwerkzeugs mit
                // Unterbefehl. Das ist nicht Geschmack: Im Brain haengen
                // Rechte am Werkzeugnamen — die Werkzeugauswahl je Channel
                // und der Riegel vor loeschenden Aufrufen. Ein Sammelwerkzeug
                // waere entweder ganz frei oder ganz gesperrt, und
                // „Kontakte lesen ja, zusammenfuehren nein" liesse sich gar
                // nicht ausdruecken.
                $tool = config("contacts.brain_tools.{$capability}");

                if ($tool === null) {
                    Log::warning("Kontakte: Fuer \"{$capability}\" ist kein Brain-Werkzeug eingetragen.");

                    return null;
                }

                try {
                    return $bridge::call((string) $tool, $arguments);
                } catch (\Throwable $e) {
                    // Ein nicht erreichbares Zentralsystem ist keine Ausnahme,
                    // die der Aufrufer behandeln soll — es ist der Fall, fuer
                    // den es den Spiegel gibt. `null` laesst den Speicher auf
                    // den letzten guten Stand zurueckfallen.
                    Log::warning('Kontakte konnten nicht aus AI Brain gelesen werden: '.$e->getMessage());

                    return null;
                }
            },
        );
    }
}
