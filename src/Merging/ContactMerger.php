<?php

namespace Peppermint\Contacts\Merging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Peppermint\Contacts\Events\ContactsMerged;
use Peppermint\Contacts\Exceptions\ProfileConflict;
use Peppermint\Contacts\Models\Contact;

/**
 * Zwei Zeilen, die derselbe Mensch sind, zu einer machen.
 *
 * ## Warum das Pflicht ist und kein Zusatz
 *
 * Dubletten entstehen unvermeidlich — jemand legt an, bevor er sucht. Ohne
 * Zusammenfuehren sammeln sie sich, und das System verliert genau die
 * Eigenschaft, fuer die es gebaut wurde: dass es EINE Zeile pro Mensch gibt.
 * Ein zentraler Kontaktbestand ohne `merge` ist nach einem halben Jahr
 * derselbe Zustand wie vorher, nur an einem anderen Ort.
 *
 * ## Die Richtung
 *
 * `$into` bleibt, `$from` loest sich auf. Bewusst nicht „das Paket waehlt
 * den besseren": Welcher der richtige ist, weiss der Mensch davor — etwa
 * weil an einem die Auftraege haengen.
 *
 * ## Was NICHT passiert: ueberschreiben
 *
 * Gefuellte Felder der bleibenden Zeile bleiben unangetastet. Nur Luecken
 * werden aus der aufgeloesten gefuellt. Andersherum waere das
 * Zusammenfuehren ein Weg, gute Daten durch aeltere zu ersetzen — und
 * niemand saehe, was verschwunden ist.
 *
 * ## Der heikle Teil: die Ring-3-Profile
 *
 * Kundennummer, Zahlungsziel, Pipeline — die haengen in Produkttabellen, die
 * das Paket nicht kennt und nicht kennen soll. Es muss sie trotzdem
 * umhaengen, sonst bleibt die Kundennummer an der aufgeloesten Zeile und ist
 * weg. Tragen BEIDE Seiten ein Profil, wo es nur eines geben darf, sagt das
 * Zusammenfuehren ab, statt eine Seite zu waehlen.
 */
class ContactMerger
{
    /**
     * @throws ProfileConflict Beide Seiten tragen ein Profil, wo nur eines sein darf.
     */
    public function merge(Contact $into, Contact $from): Contact
    {
        if ($into->getKey() === $from->getKey()) {
            throw new \InvalidArgumentException('Ein Kontakt laesst sich nicht mit sich selbst zusammenfuehren.');
        }

        // Vor der Transaktion pruefen, nicht darin: Eine Absage soll gar
        // nichts angefasst haben, nicht etwas anfassen und zuruecknehmen.
        $konflikte = $this->profileConflicts($into, $from);

        if ($konflikte !== []) {
            throw new ProfileConflict($konflikte);
        }

        $fromId = (int) $from->getKey();
        $fromUid = $from->uid;

        $ergebnis = DB::transaction(function () use ($into, $from, $fromId, $fromUid): Contact {
            $this->fillGaps($into, $from);
            $this->moveChildren($into, $from);
            $this->moveRelations($into, $from);
            $this->moveProfiles($into, $from);
            $this->recordTrail($into, $fromId, $fromUid);

            $from->delete();

            $into->save();

            return $into->refresh();
        });

        // Ausserhalb der Transaktion: Ein Zuhoerer, der wirft, darf ein
        // abgeschlossenes Zusammenfuehren nicht zurueckdrehen.
        Event::dispatch(new ContactsMerged($ergebnis, $fromId, $fromUid));

        return $ergebnis;
    }

    /**
     * Luecken der bleibenden Zeile aus der aufgeloesten fuellen.
     *
     * `uid` steht bewusst nicht in der Liste: Sie bezeichnet die Zeile, nicht
     * den Menschen. Die alte Kennung wandert in die Spur, wo sie einen
     * Verweis aufloest, statt hier eine zweite Kennung zu werden.
     */
    private function fillGaps(Contact $into, Contact $from): void
    {
        $felder = ['formatted_name', 'given_name', 'family_name', 'organization', 'title', 'url', 'birthday'];

        foreach ($felder as $feld) {
            $spalte = Contact::column($feld);

            if (blank($into->getAttribute($spalte)) && filled($from->getAttribute($spalte))) {
                $into->setAttribute($spalte, $from->getAttribute($spalte));
            }
        }

        // Notizen sind der eine Fall, in dem Anhaengen richtiger ist als
        // Waehlen: Zwei Bemerkungen zu einem Menschen sind beide wahr, und
        // welche die wichtigere ist, entscheidet kein Vergleich.
        $notizSpalte = Contact::column('note');
        $beide = collect([$into->getAttribute($notizSpalte), $from->getAttribute($notizSpalte)])
            ->filter()
            ->unique()
            ->implode("\n\n");

        if ($beide !== '') {
            $into->setAttribute($notizSpalte, $beide);
        }
    }

    /**
     * Adressen, Telefone und Anschriften umhaengen — ohne Dubletten zu erben.
     */
    private function moveChildren(Contact $into, Contact $from): void
    {
        foreach (['emails' => 'value', 'phones' => 'value'] as $beziehung => $schluessel) {
            $vorhanden = $into->{$beziehung}()->pluck($schluessel)->all();

            foreach ($from->{$beziehung}()->get() as $zeile) {
                if (in_array($zeile->{$schluessel}, $vorhanden, true)) {
                    // Schon da. Loeschen statt umhaengen — die eindeutige
                    // Bedingung auf (contact_id, value) wuerde sonst zuschlagen,
                    // und zwar mitten in der Transaktion.
                    $zeile->delete();

                    continue;
                }

                // Der erste Eintrag der bleibenden Seite bleibt der erste.
                // Sonst stuende nach dem Zusammenfuehren die Zweitadresse
                // oben, ohne dass jemand etwas geaendert haette.
                $zeile->contact_id = $into->getKey();
                $zeile->is_primary = false;
                $zeile->save();
            }
        }

        $vorhandeneAnschriften = $into->addresses()->get()
            ->map(fn ($a): string => $this->addressKey($a))
            ->all();

        foreach ($from->addresses()->get() as $anschrift) {
            if (in_array($this->addressKey($anschrift), $vorhandeneAnschriften, true)) {
                $anschrift->delete();

                continue;
            }

            $anschrift->contact_id = $into->getKey();
            $anschrift->is_default = false;
            $anschrift->save();
        }
    }

    /**
     * Zwei Anschriften, die dasselbe meinen, auf denselben Schluessel bringen.
     *
     * Je Bestandteil getrimmt und nicht erst die fertige Zeichenkette: Die
     * Leerzeichen stehen INNEN, zwischen den Teilen. Wer aussen trimmt,
     * vergleicht „billing| rechnungsweg 1 |hamburg" mit
     * „billing|rechnungsweg 1|hamburg" und haelt zwei gleiche Anschriften
     * fuer verschieden — die Dublette wandert dann mit.
     *
     * Mehrfache Leerzeichen fallen ebenfalls zusammen; „Haupt  str. 1" und
     * „Haupt str. 1" sind von Hand getippt dieselbe Strasse.
     */
    private function addressKey(object $address): string
    {
        $teile = array_map(
            fn (?string $wert): string => preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $wert))),
            [
                $address->type ?? '',
                $address->street ?? '',
                $address->zip ?? '',
                $address->city ?? '',
                $address->country ?? '',
            ],
        );

        return implode('|', $teile);
    }

    /**
     * Beziehungen umhaengen — in beide Richtungen.
     *
     * Die aufgeloeste Zeile kann Ansprechpartner haben UND selbst einer sein.
     * Wer nur eine Richtung umhaengt, verliert die andere lautlos: Die
     * Beziehung faellt mit der Zeile weg, und niemand vermisst sie, weil
     * niemand wusste, dass es sie gab.
     */
    private function moveRelations(Contact $into, Contact $from): void
    {
        $tabelle = config('contacts.tables.relations', 'contact_relations');

        foreach (['contact_id', 'related_contact_id'] as $spalte) {
            foreach (DB::table($tabelle)->where($spalte, $from->getKey())->get() as $zeile) {
                $neu = (array) $zeile;
                $neu[$spalte] = $into->getKey();

                // Aus „Person arbeitet fuer Firma" darf nach dem
                // Zusammenfuehren nicht „Firma arbeitet fuer sich selbst"
                // werden.
                $istSelbstbezug = $neu['contact_id'] === $neu['related_contact_id'];

                $gibtsSchon = DB::table($tabelle)
                    ->where('contact_id', $neu['contact_id'])
                    ->where('related_contact_id', $neu['related_contact_id'])
                    ->where('type', $neu['type'])
                    ->exists();

                if ($istSelbstbezug || $gibtsSchon) {
                    DB::table($tabelle)->where('id', $zeile->id)->delete();

                    continue;
                }

                DB::table($tabelle)->where('id', $zeile->id)->update([$spalte => $into->getKey()]);
            }
        }
    }

    /**
     * Die Produkt-Profile umhaengen.
     *
     * Ohne Konfliktfall — der ist vorher abgefangen. Hier bleibt das
     * schlichte Umschreiben des Schluessels.
     */
    private function moveProfiles(Contact $into, Contact $from): void
    {
        foreach ($this->profiles() as $profil) {
            DB::table($profil['table'])
                ->where($profil['key'], $from->getKey())
                ->update([$profil['key'] => $into->getKey()]);
        }
    }

    /**
     * Die Spur legen, auf der alte Verweise weiter aufloesen.
     *
     * Und die aelteren Spuren mitziehen: Wurde C frueher auf B
     * zusammengefuehrt und geht B jetzt auf A, muss aus C→B ein C→A werden.
     * Sonst zeigt die Spur auf eine Zeile, die es selbst nicht mehr gibt —
     * eine Kette, die genau einen Schritt zu kurz ist.
     */
    private function recordTrail(Contact $into, int $fromId, ?string $fromUid): void
    {
        $tabelle = config('contacts.tables.merges', 'contact_merges');

        if (! Schema::hasTable($tabelle)) {
            return;
        }

        DB::table($tabelle)->where('into_id', $fromId)->update(['into_id' => $into->getKey()]);

        DB::table($tabelle)->insert([
            'from_id' => $fromId,
            'from_uid' => $fromUid,
            'into_id' => $into->getKey(),
            'merged_at' => now(),
        ]);
    }

    /**
     * In welchen Profiltabellen tragen BEIDE Seiten etwas?
     *
     * @return list<string>
     */
    private function profileConflicts(Contact $into, Contact $from): array
    {
        $konflikte = [];

        foreach ($this->profiles() as $profil) {
            if (($profil['unique'] ?? false) !== true) {
                continue;
            }

            $beide = DB::table($profil['table'])->where($profil['key'], $into->getKey())->exists()
                && DB::table($profil['table'])->where($profil['key'], $from->getKey())->exists();

            if ($beide) {
                $konflikte[] = $profil['table'];
            }
        }

        return $konflikte;
    }

    /**
     * Die eingetragenen Profiltabellen — ohne die, die es (noch) nicht gibt.
     *
     * Ein Produkt kann eine Tabelle eintragen, bevor ihre Migration gelaufen
     * ist. Eine Abfrage gegen eine fehlende Tabelle scheitert haerter als
     * ein fehlendes Profil.
     *
     * @return list<array{table: string, key: string, unique?: bool}>
     */
    private function profiles(): array
    {
        return collect(config('contacts.profiles', []))
            ->filter(fn (array $profil): bool => Schema::hasTable($profil['table']))
            ->values()
            ->all();
    }
}
