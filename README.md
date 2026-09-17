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

## Der Speicher: zentral, lokal gespiegelt, schreibend

```php
$store = app(\Peppermint\Contacts\Contracts\ContactStore::class);

$store->findByEmail('buchhaltung@beispiel.de');
$store->search('Berg');
$store->upsert(['uid' => '…', 'formatted_name' => 'Beispiel AG', 'version' => 3]);
```

**Der Spiegel ist keine Beschleunigung, sondern die Ausfallsicherung.** Am 15.09.2026 war
die Brain-Verbindung über zwei Stunden tot. Läge alles zentral ohne Puffer, könnte die
Verwaltung in so einer Lage keine Rechnung schreiben — die Anschrift fehlt.

| Lage | Lesen | Schreiben |
|---|---|---|
| Brain erreichbar | zentral, Ergebnis wird gespiegelt | geht durch |
| Brain weg | aus der lokalen Kopie | **`StoreUnavailable`** — sichtbar, nicht stumm |
| Fremder Stand neuer | — | **`StaleContact`**, mit dem fremden Stand daran |

**Nur Erfolg wird gespiegelt.** Ein Fehlschlag darf den letzten guten Stand nicht
überschreiben, sonst wird aus einem kurzen Ausfall ein langer.

Anders als beim Mail-Paket sind die lokalen Tabellen selbst der Spiegel, kein
Cache-Eintrag: Eine Autovervollständigung, die pro Anschlag übers Netz geht, ist
unbenutzbar. `mirrored_at` markiert eine Zeile als Kopie, und die Kopie übernimmt den
Primärschlüssel des Brains — sonst hätte derselbe Kontakt zwei Nummern.

### Der Riegel vor der Kopie

Liegt der Bestand zentral, wirft ein `Contact::create()` daneben. Eine solche Zeile kennt
zentral niemand: Sie sieht echt aus, taucht in der Suche auf, und beim nächsten Spiegeln
ist sie weg — oder sie bleibt und ist die zweite Wahrheit, gegen die dieses Paket gebaut
ist. Abschaltbar über `contacts.guard_direct_writes`, für Produkte im Umstieg.

## Zusammenführen ist Pflicht, kein Zusatz

Dubletten entstehen unvermeidlich — jemand legt an, bevor er sucht. Ohne `merge` sammeln
sie sich, und der Bestand ist nach einem halben Jahr derselbe Zustand wie vorher, nur an
einem anderen Ort.

```php
$store->merge(into: $bleibt, from: $geht);
```

- **Gefüllte Felder der bleibenden Zeile bleiben.** Nur Lücken werden gefüllt. Andersherum
  wäre das Zusammenführen ein Weg, gute Daten durch ältere zu ersetzen.
- **Notizen werden angehängt, nicht gewählt** — zwei Bemerkungen zu einem Menschen sind
  beide wahr.
- **Beziehungen wandern in beide Richtungen.** Die aufgelöste Zeile kann Ansprechpartner
  haben *und* selbst einer sein; wer nur eine Richtung umhängt, verliert die andere lautlos.
- **Alte Verweise lösen weiter auf.** `contact_merges` hält fest, wohin eine Nummer
  gegangen ist — sonst zeigt ein Auftrag von vor drei Monaten ins Leere. Ältere Spuren
  werden mitgezogen, damit keine Kette entsteht.
- **Bei doppelten Produkt-Profilen wird abgesagt.** Tragen beide Seiten eine Kundennummer,
  kann ein Paket nicht wissen, welche gilt — eine zu wählen hieße, die andere wegzuwerfen.
  `ProfileConflict` nennt die Tabelle, und es wurde nichts geändert.

Die Profiltabellen trägt jedes Produkt selbst ein:

```php
'profiles' => [
    ['table' => 'customers', 'key' => 'contact_id', 'unique' => true],
],
```

## Der Versionsstempel

`version` steigt bei **jeder** Änderung — im Modell, nicht im Speicher. Sonst gälte er nur
für den Schreibweg, an den man gedacht hat: Ein direktes `$contact->update([...])` ließe
ihn stehen, und ein Produkt mit dem alten Stand dürfte anschließend überschreiben, ohne
dass die Prüfung anschlägt.

Wer `version` selbst mitschickt, meint ihn — das ist der Spiegel, der den zentralen Stand
übernimmt und nicht erhöhen darf.

> **Beim Adoptieren aufpassen:** Eine gewachsene Tabelle bringt keine `version` mit.
> Fehlt die Spalte, greift die Absage bei gleichzeitiger Änderung in diesem Produkt
> **nicht** — dort gewinnt wieder der Letzte. Das ist beim Anschließen zu entscheiden,
> nicht nebenbei: entweder die Spalte kommt dazu, oder man nimmt es bewusst in Kauf.

## Adoption ist Pflicht, kein Zusatz

Ein Paket, das nur auf frischen Tabellen läuft, kann ein gewachsenes Produkt nicht
übernehmen — und dann wird es ein zweites Mal gebaut.

```php
'tables'  => ['contacts' => 'customers'],
'columns' => ['formatted_name' => 'company_name'],
'run_migrations' => false,
```

## Die npm-Hälfte

`@peppermint-digital/contacts` ist ein **eigener Install** — das Composer-Paket bringt
keine Oberfläche mit.

```tsx
import { ContactList, ContactForm, httpContactSource } from '@peppermint-digital/contacts'
```

| Was | Woher |
|---|---|
| Anordnung und Verdrahtung | aus dem Paket |
| Die Wörter | vom Produkt, über `labels` |
| Die Adressen der Endpunkte | vom Produkt, über `ContactRoutes` |
| Wohin gespeichert wird | vom Produkt — `onSave` reicht den Entwurf heraus |

Damit ist dieselbe Liste in drei Produkten dieselbe Liste und unterscheidet sich in dem,
worin sich Produkte wirklich unterscheiden. Ganze **Seiten** bleiben beim Produkt; teilbar
ist die Anordnung, nicht der Seitenaufbau.

Dazu trägt die npm-Hälfte die Typen und **dieselbe** Beleg-Adressregel in TypeScript. Das
ist Absicht: Ein Abnehmer (der Shop) liest über den Connector und hat kein Laravel. Ohne
diese Zeilen schriebe er die Regel selbst — leicht anders. Die Tests auf beiden Seiten
prüfen dieselben Fälle.

Die Primitiven (`Button`, `Input`, `Label`, `Badge`) sind bewusst ohne Radix gebaut: 89
Zeilen statt 421 im Mail-Paket, gleiches Aussehen, weniger Abhängigkeiten im Produkt.

## Tests

```bash
composer install && vendor/bin/pest    # PHP-Kern
npm install && npm test                # TypeScript-Hälfte
```

Die PHP-Suite läuft gegen eine In-Memory-SQLite **mit eingeschalteten Fremdschlüsseln** —
ohne das erzwingt SQLite sie nicht, und ein Test auf „die Anhängsel gehen mit" bliebe grün,
ohne etwas zu messen.

## Stand

Etappen **E1** (Gerüst und Kern), **E2** (Speicher-Vertrag) und der Paket-Teil von **E3**
(Zusammenführen). Offen in E3: die Brain-Seite selbst — Tabellen, MCP-Werkzeuge,
Oberfläche. Danach E4 erster Abnehmer (Verwaltung), E5 zweiter Abnehmer (CRM).

Das Übertragungsformat, an das sich die Brain-Seite in E3 halten muss, steht als Tabelle
im Docblock von `Contracts\ContactStore` — damit beide Seiten gegen dieselbe Beschreibung
gebaut werden statt gegeneinander.

Prüffrage 3 des Paket-Vertrags — *schreibt jedes Produkt denselben Verdrahtungscode?* —
wird bewusst erst beim **zweiten** Abnehmer beantwortet. Bei einer Installation gibt es
nichts zu vergleichen.
