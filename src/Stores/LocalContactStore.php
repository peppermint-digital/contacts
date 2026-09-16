<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Merging\ContactMerger;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactRelation;

/**
 * Die Kontakte liegen in den Tabellen des Produkts.
 *
 * Der Weg fuer ein Produkt, das das Paket allein benutzt — installieren und
 * fertig, ohne eine Spur von AI Brain. Fuer unsere vier Abnehmer ist das
 * nicht der Regelfall: Ein Mensch, der nur in einem System existiert, ist
 * genau die Lage, aus der dieses Paket herausfuehren soll.
 */
class LocalContactStore implements ContactStore
{
    public function find(string|int $id): ?Contact
    {
        $contact = Contact::query()->find($id);

        if ($contact !== null) {
            return $contact;
        }

        // Die Zeile gibt es nicht mehr — vielleicht, weil sie
        // zusammengefuehrt wurde. Ein Auftrag von vor drei Monaten zeigt auf
        // die alte Nummer, und er soll den Menschen weiter finden, statt ins
        // Leere zu zeigen.
        return $this->followMergeTrail($id);
    }

    /**
     * Wohin ist diese Nummer gegangen?
     *
     * Die Spur ist bereits flachgezogen — beim Zusammenfuehren werden
     * aeltere Eintraege mitgezogen, sodass hier ein einzelner Schritt
     * genuegt und keine Kette entstehen kann, die sich im Kreis dreht.
     */
    private function followMergeTrail(string|int $id): ?Contact
    {
        $tabelle = config('contacts.tables.merges', 'contact_merges');

        if (! Schema::hasTable($tabelle)) {
            return null;
        }

        $spur = DB::table($tabelle)->where('from_id', $id)->first();

        return $spur === null ? null : Contact::query()->find($spur->into_id);
    }

    public function contactPersonsOf(string|int $organisationId): Collection
    {
        $org = Contact::query()->find($organisationId);

        return $org === null ? collect() : $org->contactPersons();
    }

    public function merge(Contact|int $into, Contact|int $from): Contact
    {
        $ziel = $into instanceof Contact ? $into : Contact::query()->findOrFail($into);
        $quelle = $from instanceof Contact ? $from : Contact::query()->findOrFail($from);

        return (new ContactMerger)->merge($ziel, $quelle);
    }

    public function findByUid(string $uid): ?Contact
    {
        return Contact::query()->where('uid', $uid)->first();
    }

    public function findByEmail(string $email, ?Kind $kind = null): ?Contact
    {
        return Contact::query()
            ->whereHas('emails', fn ($e) => $e->where('value', $email))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))
            ->first();
    }

    public function search(string $query, int $limit = 25): Collection
    {
        return Contact::query()
            ->where(fn ($q) => $q
                ->where(Contact::column('formatted_name'), 'like', "%{$query}%")
                ->orWhere(Contact::column('organization'), 'like', "%{$query}%")
                // Auch ueber die Adressen: Wer eine Mail vor sich hat, sucht
                // mit der Adresse und nicht mit dem Namen — den kennt er ja
                // gerade nicht.
                ->orWhereHas('emails', fn ($e) => $e->where('value', 'like', "%{$query}%")))
            ->limit($limit)
            ->get();
    }

    public function upsert(array $attributes): Contact
    {
        $uid = $attributes['uid'] ?? null;

        // Die Beziehung reist im selben Aufruf mit — sonst braeuchte jeder
        // Aufrufer zwei Schritte und muesste selbst dafuer sorgen, dass der
        // zweite auch passiert. Ein halb angelegter Ansprechpartner ohne
        // Organisation ist genau die Zeile, die spaeter niemand zuordnen kann.
        $arbeitetFuer = $attributes['works_for'] ?? null;
        unset($attributes['works_for']);

        // Die Anhaengsel sind Beziehungen, keine Spalten — sie duerfen nicht
        // in `fill()` geraten.
        //
        // Das ist nicht nur Hygiene: Der zentrale Speicher reicht genau diese
        // Schluessel an die Brain-Seite weiter, wo sie behandelt werden. Waere
        // es hier anders, verhielten sich zwei Speicher bei identischer
        // Eingabe verschieden — und ein Produkt, das von `local` auf `brain`
        // umstellt, bekaeme ohne eine einzige Codeaenderung ein anderes
        // Ergebnis.
        //
        // `null` heisst weiterhin „nicht angefasst", `[]` heisst „geleert".
        $listen = [];

        foreach (['emails', 'phones', 'addresses'] as $liste) {
            if (array_key_exists($liste, $attributes)) {
                $listen[$liste] = (array) $attributes[$liste];
                unset($attributes[$liste]);
            }
        }

        // `version` ist hier reine Durchreiche: Ohne zweiten Schreiber gibt
        // es niemanden, gegen den sich ein Stand vergleichen liesse. Das Feld
        // wird trotzdem gepflegt, damit ein Produkt beim spaeteren Umstieg
        // auf `brain` nicht ploetzlich ohne Staende dasteht.
        unset($attributes['version']);

        $contact = $uid !== null
            ? Contact::query()->firstOrNew(['uid' => $uid])
            : new Contact;

        $contact->fill($attributes);

        // Ein Kontakt, der nur ueber seine Adresse bekannt ist, muss die
        // Ring-1-Pruefung bestehen — die laeuft beim Speichern, die Adressen
        // entstehen danach.
        if (! $contact->hasIdentifier()) {
            foreach ($listen['emails'] ?? [] as $email) {
                if (filled($email['value'] ?? null)) {
                    $contact->withEmail($email['value'], $email['type'] ?? null, (bool) ($email['is_primary'] ?? false));
                }
            }
        }

        $contact->save();

        foreach ($listen as $liste => $zeilen) {
            $contact->{$liste}()->delete();

            foreach ($zeilen as $zeile) {
                $contact->{$liste}()->create($zeile);
            }
        }

        if ($arbeitetFuer !== null && (int) $arbeitetFuer !== (int) $contact->getKey()) {
            ContactRelation::query()->firstOrCreate([
                'contact_id' => $contact->getKey(),
                'related_contact_id' => $arbeitetFuer,
                'type' => ContactRelation::WorksFor,
            ]);
        }

        return $contact->refresh();
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function writeBlockedReason(): ?string
    {
        return null;
    }
}
