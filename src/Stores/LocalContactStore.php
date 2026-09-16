<?php

namespace Peppermint\Contacts\Stores;

use Illuminate\Support\Collection;
use Peppermint\Contacts\Contracts\ContactStore;
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
        return Contact::query()->find($id);
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
        $contact->version = ($contact->version ?? 0) + 1;
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
