<?php

namespace Peppermint\Contacts;

use Illuminate\Support\ServiceProvider;

class ContactsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/contacts.php', 'contacts');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/contacts.php' => config_path('contacts.php'),
        ], 'contacts-config');

        // Ein gewachsenes Produkt schaltet das ab und bildet stattdessen
        // seine vorhandenen Tabellen ab.
        if (config('contacts.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
