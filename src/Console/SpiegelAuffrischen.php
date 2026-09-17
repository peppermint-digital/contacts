<?php

namespace Peppermint\Contacts\Console;

use Illuminate\Console\Command;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Stores\BrainContactStore;

/**
 * Den lokalen Spiegel einmal vollständig nachziehen.
 *
 * ## Wofür man das braucht
 *
 * Der Spiegel füllt sich beim Lesen — wer nie gelesen wurde, steht nicht
 * darin, und was zum Zeitpunkt des Lesens noch nicht gespiegelt WURDE, fehlt
 * ebenfalls.
 *
 * Genau das ist am 17.09.2026 passiert: Die Verwaltung hatte 53 Kontakte im
 * Spiegel, aber **eine** Beziehung. Der Bestand war unter einer Fassung
 * überführt worden, die Beziehungen noch gar nicht mitspiegelte. Wer dann
 * die Leseseite eines Produkts auf den Spiegel umstellt, bekommt Masken
 * ohne Ansprechpartner — und es sieht aus, als wären die Daten weg.
 *
 * Deshalb: nach einer Paket-Aktualisierung, die am Spiegel etwas ändert,
 * einmal hier durch. Der Lauf ist idempotent und gefahrlos wiederholbar.
 *
 * ## Warum nur gelesen wird
 *
 * Der Befehl schreibt nichts zentral. Er liest jeden bekannten Kontakt
 * einmal über den Speicher — und das Spiegeln ist die Nebenwirkung des
 * Lesens, nicht ein eigener Vorgang. Damit kann er auch nichts kaputt
 * machen, was zentral steht.
 */
class SpiegelAuffrischen extends Command
{
    protected $signature = 'contacts:spiegel-auffrischen {--chunk=50 : Wie viele Kontakte je Durchgang}';

    protected $description = 'Liest jeden bekannten Kontakt einmal, damit der lokale Spiegel vollständig ist';

    public function handle(ContactStore $store): int
    {
        if (! $store instanceof BrainContactStore) {
            $this->components->warn(
                'Der Bestand liegt hier lokal (contacts.store ist nicht "brain") — es gibt keinen Spiegel aufzufrischen.'
            );

            return self::SUCCESS;
        }

        $gesamt = Contact::query()->count();

        if ($gesamt === 0) {
            $this->components->warn(
                'Der Spiegel ist leer. Er füllt sich beim Lesen — hier gibt es noch nichts nachzuziehen.'
            );

            return self::SUCCESS;
        }

        $this->components->info("{$gesamt} Kontakte werden nachgezogen.");
        $bar = $this->output->createProgressBar($gesamt);
        $bar->start();

        $gelesen = 0;
        $verschwunden = 0;

        Contact::query()
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($kontakte) use ($store, $bar, &$gelesen, &$verschwunden): void {
                foreach ($kontakte as $kontakt) {
                    // `find()` holt zentral und spiegelt die Antwort — Kern,
                    // Anhaengsel und seit v0.10.0 auch die Beziehungen.
                    $store->find($kontakt->getKey()) === null ? $verschwunden++ : $gelesen++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('nachgezogen', (string) $gelesen);
        $this->components->twoColumnDetail('zentral nicht mehr vorhanden', (string) $verschwunden);

        if ($verschwunden > 0) {
            $this->components->warn(
                "{$verschwunden} Kontakte gibt es zentral nicht mehr. Sie bleiben im Spiegel stehen — "
                .'aufraeumen ist eine eigene Entscheidung, kein Nebeneffekt des Nachziehens.'
            );
        }

        return self::SUCCESS;
    }
}
