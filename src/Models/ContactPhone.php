<?php

namespace Peppermint\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Telefonnummer eines Kontakts — vCard `TEL`.
 */
class ContactPhone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function getTable(): string
    {
        return config('contacts.tables.phones', 'contact_phones');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
