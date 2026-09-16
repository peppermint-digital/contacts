<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Spur, die ein Zusammenfuehren hinterlaesst.
 *
 * Ohne sie waere das Zusammenfuehren ein Datenverlust mit Ansage: Ein
 * Auftrag, eine Rechnung, ein Unterschriftsauftrag zeigen auf eine
 * Kontakt-Nummer. Verschwindet die Zeile beim Zusammenfuehren, zeigen sie
 * ins Leere — und niemand kann mehr sagen, wer gemeint war.
 *
 * Mit dieser Tabelle bleibt die Frage beantwortbar: „Wohin ist Nummer 41
 * gegangen?" Das ist keine Protokollierung fuer den Notfall, sondern der
 * Weg, auf dem alte Verweise weiter aufloesen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('contacts.tables.merges', 'contact_merges'), function (Blueprint $table): void {
            $table->id();

            // Bewusst OHNE Fremdschluessel auf `contacts`: Die aufgeloeste
            // Zeile gibt es nicht mehr, genau darum geht es. Ein
            // Fremdschluessel wuerde die Spur mitloeschen, die den Verweis
            // retten soll.
            $table->unsignedBigInteger('from_id')->unique();
            $table->string('from_uid', 64)->nullable()->index();

            $table->foreignId('into_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();

            $table->timestamp('merged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('contacts.tables.merges', 'contact_merges'));
    }
};
