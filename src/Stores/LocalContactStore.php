<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Peppermint\Contacts\Contracts\ContactStore;
use Peppermint\Contacts\Merging\ContactMerger;
use Peppermint\Contacts\Models\Contact;
use Peppermint\Contacts\Models\ContactEmail;

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

    public function findByEmail(string $email): ?Contact
    {
        $treffer = ContactEmail::query()
            ->where('value', $email)
            ->first();

        return $treffer?->contact;
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

        // `version` ist hier reine Durchreiche: Ohne zweiten Schreiber gibt
        // es niemanden, gegen den sich ein Stand vergleichen liesse. Das Feld
        // wird trotzdem gepflegt, damit ein Produkt beim spaeteren Umstieg
        // auf `brain` nicht ploetzlich ohne Staende dasteht.
        unset($attributes['version']);

        $contact = $uid !== null
            ? Contact::query()->firstOrNew(['uid' => $uid])
            : new Contact;

        $contact->fill($attributes);
        $contact->save();

        return $contact;
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
