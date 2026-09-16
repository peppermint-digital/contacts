<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `country` war zwei Zeichen breit — das war eine Annahme, keine Messung.
 *
 * Ich hatte ISO-3166-Kürzel unterstellt („DE"). Die Verwaltung speichert seit
 * Jahren ausgeschriebene Namen („Deutschland"), und beim ersten Umzug echter
 * Daten quittierte MySQL das mit:
 *
 *     SQLSTATE[22001]: Data too long for column 'country' at row 1
 *
 * ## Warum kein Test das gefunden hat
 *
 * Die Paket-Suite läuft auf SQLite, und SQLite erzwingt Zeichenlängen nicht —
 * `varchar(2)` nimmt dort auch „Deutschland" an. Dieselbe Familie wie der
 * ENUM-Fall: Eine Spaltenbedingung, die in der Testumgebung nicht greift, ist
 * keine Zusicherung, sondern eine Verabredung mit sich selbst.
 *
 * vCard schreibt für `ADR` ohnehin kein Format vor. Der Kern hat hier nichts
 * zu normieren — er hat aufzunehmen, was die Produkte führen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('contacts.tables.addresses', 'contact_addresses'), function (Blueprint $table): void {
            $table->string('country')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Bewusst KEIN Zurück auf zwei Zeichen: Das schnitte vorhandene
        // Ländernamen ab, und zwar ohne Warnung. Eine Rücknahme, die Daten
        // vernichtet, ist keine.
    }
};
