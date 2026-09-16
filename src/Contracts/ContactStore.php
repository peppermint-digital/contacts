<?php

namespace Peppermint\Contacts\Contracts;

use Illuminate\Support\Collection;
use Peppermint\Contacts\Exceptions\StaleContact;
use Peppermint\Contacts\Exceptions\StoreUnavailable;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Stores\BrainContactStore;
use Peppermint\Contacts\Stores\LocalContactStore;

/**
 * Wo die Kontakte liegen.
 *
 * ## Warum ein Vertrag und nicht einfach eine Tabelle
 *
 * Ein Paket kann nicht der Speicher sein — es kann nur entscheiden, WO
 * gespeichert wird. Hielte das Paket die Kontakte selbst, bekaeme jedes
 * einbindende Produkt seine eigene Tabelle mit seinen eigenen Zeilen:
 * derselbe Mensch dreimal, unter drei Adressen. Der doppelte Code waere weg,
 * die doppelte Wahrheit schlimmer als vorher.
 *
 * ## Die zwei Umsetzungen
 *
 * - {@see LocalContactStore} — eigene Tabellen.
 *   Damit laeuft das Paket fuer sich allein, ohne Spur von AI Brain.
 * - {@see BrainContactStore} — zentral im Brain,
 *   lokal gespiegelt, und **schreibend**.
 *
 * Das Schreiben ist der Unterschied zum Mail-Paket. Dort ist der zentrale
 * Speicher ausdruecklich lesend: Zugangsdaten werden gepflegt, nicht im
 * Betrieb erzeugt. Ein Kontakt dagegen entsteht im CRM und wird in der
 * Verwaltung ergaenzt — wer hier nur lesen duerfte, muesste die Person doch
 * wieder lokal anlegen, und genau davon handelt das ganze Paket.
 *
 * ## Das Uebertragungsformat, an das sich die Brain-Seite halten muss
 *
 * Steht hier und nicht erst in E3, damit beide Seiten gegen dieselbe
 * Beschreibung gebaut werden statt gegeneinander:
 *
 * | Fall | Antwort |
 * |---|---|
 * | ein Kontakt | `['data' => array|null]` |
 * | mehrere | `['data' => array[]]` |
 * | Schreiben gelungen | `['data' => array]` — mit erhoehter `version` |
 * | Schreiben veraltet | `['conflict' => true, 'current' => array]` |
 * | Brain nicht erreichbar | der Aufruf gibt `null` zurueck |
 *
 * `null` ist ein Zustand, kein Fehler: Es heisst „nicht erreichbar", und
 * darauf antwortet der Speicher mit dem Spiegel, nicht mit einem Wurf.
 */
interface ContactStore
{
    /**
     * Ein Kontakt ueber seine Kennung, oder null.
     *
     * Wirft NIE, wenn die Gegenstelle weg ist. Der Aufrufer bekommt den
     * letzten guten Stand oder nichts, und die Seite rendert trotzdem. Eine
     * Rechnungsmaske, die sich weigert zu erscheinen, weil ein zweites System
     * down ist, ist schlimmer als eine mit einer Woche alter Anschrift.
     */
    public function find(string|int $id): ?Contact;

    /** Ein Kontakt ueber seine zentrale Kennung (vCard `UID`). */
    public function findByUid(string $uid): ?Contact;

    /**
     * Wer steckt hinter dieser Adresse?
     *
     * Die Frage, die heute niemand beantworten kann: Kommt eine Mail von
     * einem Kunden, findet der CRM-Aufloeser nichts, weil die Person dort
     * kein Kontakt ist.
     */
    public function findByEmail(string $email): ?Contact;

    /**
     * Suche ueber Namen, Organisation und Adressen.
     *
     * @return Collection<int, Contact>
     */
    public function search(string $query, int $limit = 25): Collection;

    /**
     * Anlegen oder aendern.
     *
     * @param  array<string, mixed>  $attributes  Kernfelder; `uid` waehlt den
     *                                            bestehenden Kontakt, `version`
     *                                            sagt, welchen Stand der
     *                                            Aufrufer gesehen hat.
     *
     * @throws StoreUnavailable Gegenstelle weg — mit Grund, nicht stumm.
     * @throws StaleContact Jemand war schneller.
     */
    public function upsert(array $attributes): Contact;

    /**
     * Nimmt dieser Speicher Aenderungen an?
     *
     * Die Oberflaeche muss es wissen, damit sie kein Formular anbietet, dessen
     * Speichern-Knopf ins Leere fuehrt — und damit sie bei Ausfall sagen kann,
     * warum gerade nur gelesen wird.
     */
    public function isWritable(): bool;

    /**
     * Warum gerade nicht geschrieben werden kann, oder null wenn alles geht.
     *
     * Der Unterschied zu `isWritable()`: Der eine Speicher ist dauerhaft
     * lesend, der andere nur jetzt gerade. Das ist fuer den Menschen davor
     * ein voellig anderer Satz.
     */
    public function writeBlockedReason(): ?string;
}
