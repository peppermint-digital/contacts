<?php

namespace Peppermint\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\Contacts\Models\Concerns\GuardsDirectWrites;

/**
 * Eine Anschrift eines Kontakts — vCard `ADR`.
 *
 * Die Struktur ist nicht neu erfunden: Sie steht seit Jahren als
 * `CustomerAddress` in der Verwaltung, mit `type`, `label` und `is_default`.
 * CRM und Shop haben sie nicht — deshalb steht dort die Adresse einmal am
 * Kunden selbst und lässt sich nicht nach Rechnung und Lieferung trennen.
 *
 * Diese Zeile ist immer die AKTUELLE Anschrift. Was auf einem Beleg steht,
 * ist die damalige, und die bleibt beim Beleg.
 */
class ContactAddress extends Model
{
    use GuardsDirectWrites;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function getTable(): string
    {
        return config('contacts.tables.addresses', 'contact_addresses');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
