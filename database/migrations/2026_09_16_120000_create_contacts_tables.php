<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Kerntabellen für Kontakte — nur für FRISCHE Installationen.
 *
 * Ein gewachsenes Produkt schaltet sie über `contacts.run_migrations => false`
 * ab und bildet stattdessen seine vorhandenen Spalten ab. Genau dafür ist
 * Adoption ein Pflichtfeature und kein Zusatz.
 *
 * ## Wo die Grenze verläuft, und woran sie gemessen ist
 *
 * Kern ist, was sich in eine `.vcf` schreiben lässt — vCard (RFC 6350) ist
 * ein ÄUSSERER Maßstab, kein Geschmack. Dieselbe Rolle wie iCalendar beim
 * Kalender.
 *
 * Draußen bleibt das Kaufmännische: Kundennummer, Zahlungsziel, Pipeline,
 * `use_count`. Das sind Ring-3-Felder und gehören in die Profiltabelle des
 * jeweiligen Produkts. Eine Spalte, die nur ein Abnehmer braucht, wandert
 * sonst in jeden anderen mit, der sie nie benutzt.
 *
 * ## Mehrzahl ist der Punkt, nicht die Kür
 *
 * E-Mails, Telefone und Adressen liegen in eigenen Tabellen, nicht als Spalte
 * am Kontakt. An genau dieser Einzahl scheitert die heutige Übergabe:
 * `buildPayloadFromCompany()` nimmt beim Übergang Firma → Kunde EINEN
 * Ansprechpartner mit und schreibt ihn als Text. Wer drei hat, verliert zwei.
 *
 * ## Warum Zeichenketten statt ENUM
 *
 * `kind`, `type` und Verwandte sind `string`, nicht `enum`. Ein ENUM prüft
 * in SQLite nicht und in MySQL schon: Ein neuer Fall läuft durch die grüne
 * Testsuite und fällt beim Ausrollen mit „Data truncated" um. Die Prüfung
 * gehört ins Modell, wo sie in beiden Fällen gleich wirkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('contacts.tables.contacts', 'contacts'), function (Blueprint $table): void {
            $table->id();

            // Ring 1 — die Art. Ohne sie ist nicht entscheidbar, ob die Zeile
            // einen Menschen oder eine Firma beschreibt, und beide sehen in
            // den Feldern darunter gleich aus.
            $table->string('kind', 20)->default('individual')->index();

            // vCard UID. Die Kennung, unter der derselbe Kontakt zentral und
            // lokal dieselbe Sache ist. Steht schon hier, obwohl der
            // Speicher-Vertrag erst in E2 entsteht: Eine Kennspalte später in
            // einem laufenden System nachzuziehen ist die teurere Variante.
            $table->string('uid', 64)->nullable()->unique();

            // Ring 2 — vCard, alles freiwillig. Die Pflicht „Name ODER
            // E-Mail" prüft das Modell, nicht die Spalte: Sie greift über
            // zwei Tabellen und ist als NOT NULL nicht ausdrückbar.
            $table->string('formatted_name')->nullable();   // FN
            $table->string('given_name')->nullable();       // N
            $table->string('family_name')->nullable();      // N
            $table->string('organization')->nullable();     // ORG
            $table->string('title')->nullable();            // TITLE
            $table->string('url')->nullable();              // URL
            $table->date('birthday')->nullable();           // BDAY
            $table->text('note')->nullable();               // NOTE

            // Der Versionsstempel. Er ist der Grund, warum zwei Produkte
            // denselben Kontakt nicht gegenseitig ueberschreiben koennen:
            // Wer schreibt, sagt, welchen Stand er gesehen hat. Passt der
            // nicht mehr, gibt es eine Absage statt eines stillen
            // Ueberschreibens — und niemand verliert, was er nicht gesehen
            // hat.
            $table->unsignedBigInteger('version')->default(1);

            // Wann diese Zeile zuletzt vom zentralen Stand abgeschrieben
            // wurde. Gesetzt heisst: Das hier ist eine KOPIE. Die Wahrheit
            // liegt im Brain, und wer hier direkt hineinschreibt, baut die
            // zweite Wahrheit, die das Paket verhindern soll.
            $table->timestamp('mirrored_at')->nullable();

            $table->timestamps();

            // Gesucht wird nach Namen — in jedem Produkt, bei jeder
            // Vervollständigung.
            $table->index('formatted_name');
            $table->index('organization');
        });

        Schema::create(config('contacts.tables.emails', 'contact_emails'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();
            $table->string('value');
            $table->string('type', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // Die Adresse ist der Weg, auf dem ein Postfach einen Kontakt
            // findet. Ohne Index wird daraus bei jeder eingehenden Mail ein
            // vollständiger Durchlauf.
            $table->index('value');
            $table->unique(['contact_id', 'value']);
        });

        Schema::create(config('contacts.tables.phones', 'contact_phones'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();
            $table->string('value');
            $table->string('type', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['contact_id', 'value']);
        });

        // Die Struktur existiert bereits als `CustomerAddress` in der
        // Verwaltung — von dort übernommen, nicht neu erfunden. Genau daran
        // hängt die Fachregel „Rechnung → Rechnungsadresse, Lieferschein →
        // Lieferadresse", die der Shop identisch braucht.
        Schema::create(config('contacts.tables.addresses', 'contact_addresses'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();
            $table->string('type', 20)->nullable();
            $table->string('label')->nullable();
            $table->string('street')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city')->nullable();
            // Ausgeschrieben, nicht als Kuerzel: Die Produkte fuehren
            // 'Deutschland', nicht 'DE'. Siehe die Verbreiterungs-Migration.
            $table->string('country')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Person ↔ Organisation — vCard `RELATED`. Das Feld, das heute als
        // `contact_person`-Freitext existiert und deshalb nur einen trägt.
        Schema::create(config('contacts.tables.relations', 'contact_relations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();
            $table->foreignId('related_contact_id')
                ->constrained(config('contacts.tables.contacts', 'contacts'))
                ->cascadeOnDelete();
            $table->string('type', 30)->default('works_for');
            $table->timestamps();

            // Indexname ausdruecklich und KURZ, nicht von Laravel abgeleitet.
            //
            // Abgeleitet hiesse hier
            // `<tabelle>_contact_id_related_contact_id_type_unique` — beim
            // Vorgabenamen `contact_relations` sind das 57 Zeichen und es
            // passt knapp unter die 64, die MySQL fuer Bezeichner erlaubt.
            // Jeder laengere abgebildete Tabellenname sprengt sie, und dann
            // scheitert die Migration mitten im Lauf: Die Tabelle steht schon,
            // der Index fehlt, und die Migration gilt als nicht ausgefuehrt.
            //
            // Genau so passiert beim Anschliessen des CRM, das seine Tabellen
            // `zentrale_kontakt_beziehungen` nennt (70 Zeichen). Ein Fall, den
            // nur ein Abnehmer MIT Tabellen-Abbildung zeigen kann.
            $table->unique(['contact_id', 'related_contact_id', 'type'], 'kontakt_beziehung_eindeutig');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('contacts.tables.relations', 'contact_relations'));
        Schema::dropIfExists(config('contacts.tables.addresses', 'contact_addresses'));
        Schema::dropIfExists(config('contacts.tables.phones', 'contact_phones'));
        Schema::dropIfExists(config('contacts.tables.emails', 'contact_emails'));
        Schema::dropIfExists(config('contacts.tables.contacts', 'contacts'));
    }
};
