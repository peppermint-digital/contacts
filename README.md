# peppermint/contacts

Kontakte als geteiltes Paket — **eine Zeile pro Mensch, und eine pro Firma.**

Der Zuschnitt steht im AI-Brain-Wiki: *„peppermint/contacts — der Zuschnitt: eine Zeile
pro Mensch, zentral im Brain"*. Er ist hergeleitet und dreimal korrigiert worden; ihn hier
neu zu erfinden wiederholt die Korrekturen.

## Warum es dieses Paket gibt

Nicht wegen Doppelpflege — wegen **Verlust**. Gemessen am 15.09.2026 über den
Produkt-Gateway:

| System | Was dort „Kontakt" heißt | Menge |
|---|---|---|
| CRM | `Contact` an einer `Company` | 200+ |
| Verwaltung | `Customer` + `contact_person` als **Freitext** | 30 |
| Manager | `RecipientContact` — gelernte Adressen | pro Nutzer |

Überschneidung: **0**. Nicht weil sauber getrennt wäre, sondern weil die Umwandlung
Firma → Kunde genau *einen* Ansprechpartner als Text mitnimmt. Wer Kunde wird, hört auf,
als Person zu existieren.

## Typ B: geteilt wird Code **und** Daten

Ein Termin in zwei Systemen sind zwei Termine. Zwei Zeilen für denselben Menschen sind
**ein** Mensch. Deshalb liegt der Kontakt einmal — zentral im AI Brain, lokal gespiegelt —
statt viermal mit Abgleich.

```php
'store' => env('CONTACTS_STORE', 'brain'),
```

Der Speicher-Vertrag selbst entsteht in E2; hier steht bisher nur der Schlüssel.

## Die drei Ringe

**Ring 1 — Pflicht.** Die Art (`individual` | `org`) und **mindestens eine Kennung:
Name ODER E-Mail.** Beides zu verlangen schlösse echte Fälle aus — den Telefonkontakt
ohne Adresse und die Adresse ohne Namen, wie sie aus einem Postfach entsteht. Die Regel
steht im Modell und wirft, statt stumm zu verweigern.

```php
// Nur ein Name: geht.
Contact::create(['formatted_name' => 'Volker Tolksdorf']);

// Nur eine Adresse: geht auch — sie wird vorgemerkt und mit angelegt.
(new Contact)->withEmail('rechnung@beispiel.de')->save();

// Keines von beidem: IncompleteContact.
Contact::create(['kind' => Kind::Individual]);
```

**Ring 2 — vCard (RFC 6350).** Ein *äußerer* Maßstab, kein Geschmack: Was sich in eine
`.vcf` schreiben lässt, ist Kern. `KIND` · `FN` · `N` · `EMAIL` · `TEL` · `ORG` · `TITLE` ·
`ADR` · `URL` · `NOTE` · `BDAY` · `RELATED`.

E-Mails, Telefone und Anschriften liegen **in eigenen Tabellen**. An der Einzahl scheitert
die heutige Übergabe.

**Ring 3 — pro Produkt.** Das Kaufmännische bleibt draußen: Kundennummer, Zahlungsziel,
Pipeline, `use_count`. Eine neue Spalte in der geteilten Kerntabelle ist immer ein Fehler —
sie wandert in jedes Produkt mit, das sie nie braucht.

## Kein Feld „ist Kunde"

Aus dem Interessenten wird ein Kunde **ohne neue Zeile**. Ein `is_customer` ließe sich
setzen, ohne dass ein Kunde existiert — dann glaubt man dem Feld statt der Wirklichkeit.
Der Status ergibt sich daraus, ob ein Verwaltungs-Profil auf den Kontakt zeigt.

## Die Grenze am Beleg

> **Das Paket besitzt die *aktuelle* Anschrift. Der Beleg besitzt die *damalige*.**

Eine Rechnung von 2024 muss die Adresse von 2024 zeigen, auch wenn der Kontakt 2026
umzieht. Ein Beleg, der live nachschlägt, ändert sich rückwirkend — bei Rechnungen ist das
nicht unschön, sondern falsch. Der Beleg schreibt seine Adresse weiterhin in sein eigenes
Feld; das bleibt im Produkt.

Welche Anschrift er dafür **wählt**, ist dagegen Fachlogik und liegt hier:

```php
$contact->addressForDocument('invoice');        // Rechnungsadresse, sonst Hauptadresse
$contact->addressForDocument('delivery_note');  // Lieferadresse, sonst Hauptadresse
```

## Adoption ist Pflicht, kein Zusatz

Ein Paket, das nur auf frischen Tabellen läuft, kann ein gewachsenes Produkt nicht
übernehmen — und dann wird es ein zweites Mal gebaut.

```php
'tables'  => ['contacts' => 'customers'],
'columns' => ['formatted_name' => 'company_name'],
'run_migrations' => false,
```

## Die npm-Hälfte

`@peppermint-digital/contacts` trägt die Typen und **dieselbe** Beleg-Adressregel in
TypeScript. Das ist Absicht: Ein Abnehmer (der Shop) liest über den Connector und hat kein
Laravel. Ohne diese Zeilen schriebe er die Regel selbst — leicht anders. Die Tests auf
beiden Seiten prüfen dieselben Fälle.

## Tests

```bash
composer install && vendor/bin/pest    # PHP-Kern
npm install && npm test                # TypeScript-Hälfte
```

Die PHP-Suite läuft gegen eine In-Memory-SQLite **mit eingeschalteten Fremdschlüsseln** —
ohne das erzwingt SQLite sie nicht, und ein Test auf „die Anhängsel gehen mit" bliebe grün,
ohne etwas zu messen.

## Stand

Etappe **E1** (Gerüst und Kern). Es folgen: E2 Speicher-Vertrag, E3 Brain-Seite mit
MCP-Werkzeugen, E4 erster Abnehmer (Verwaltung), E5 zweiter Abnehmer (CRM).

Prüffrage 3 des Paket-Vertrags — *schreibt jedes Produkt denselben Verdrahtungscode?* —
wird bewusst erst beim **zweiten** Abnehmer beantwortet. Bei einer Installation gibt es
nichts zu vergleichen.
