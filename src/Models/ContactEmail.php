<?php

namespace Peppermint\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\Contacts\Models\Concerns\GuardsDirectWrites;

/**
 * Eine E-Mail-Adresse eines Kontakts — vCard `EMAIL`.
 *
 * Eigene Tabelle und nicht eine Spalte am Kontakt, weil Menschen mehrere
 * Adressen haben: die dienstliche, die alte, die aus dem Formular. An der
 * Einzahl scheitert heute jede Zuordnung — kommt eine Mail von der zweiten
 * Adresse, findet sie niemand.
 */
class ContactEmail extends Model
{
    use GuardsDirectWrites;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function getTable(): string
    {
        return config('contacts.tables.emails', 'contact_emails');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
