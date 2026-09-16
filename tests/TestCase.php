<?php

namespace Peppermint\Contacts\Tests;

use Orchestra\Testbench\TestCase as Base;
use Peppermint\Contacts\ContactsServiceProvider;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [ContactsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Ohne das erzwingt SQLite keine Fremdschluessel — und ein Test
            // auf „die Anhaengsel gehen mit" bleibt gruen, ohne irgendetwas
            // zu messen. In MySQL greift die Regel, hier also auch.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
