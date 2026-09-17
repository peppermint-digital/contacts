<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welcher Mensch eine Organisation vertritt (#5695).
 *
 * ## Warum das an der Beziehung hängt und nicht am Kontakt
 *
 * „Hauptansprechpartner" ist keine Eigenschaft eines Menschen — es ist eine
 * Eigenschaft seiner Verbindung zu EINER Organisation. Dieselbe Person kann
 * bei einer Firma die erste Adresse sein und bei einer zweiten nur
 * mitarbeiten. Ein Feld am Kontakt könnte das nicht ausdrücken.
 *
 * ## Warum es überhaupt gebraucht wird
 *
 * Die Verwaltung führt `customer_contacts.is_primary`, und daran hängt, wen
 * ein Beleg als Ansprechpartner nennt. Ohne Entsprechung im Kern zöge die
 * Rechnung nach dem Umzug einen beliebigen Namen — eine stille
 * Verschlechterung, die niemandem auffällt, bis ein Kunde sich wundert.
 *
 * Aufgefallen beim Ablösen von `customer_contacts`: Der Kern konnte die
 * Frage nicht beantworten, die das Produkt bisher beantwortet hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('contacts.tables.relations', 'contact_relations'), function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(config('contacts.tables.relations', 'contact_relations'), function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });
    }
};
