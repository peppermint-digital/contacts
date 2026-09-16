<?php

namespace Peppermint\Contacts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Verbindung zwischen zwei Kontakten — vCard `RELATED`.
 *
 * Der Ersatz für `contact_person` als Freitext. Eine Firma hat nicht einen
 * Ansprechpartner, sondern so viele, wie sie hat; heute nimmt die Umwandlung
 * Firma → Kunde genau einen mit (den zuletzt angelegten, nicht den
 * wichtigsten) und schreibt ihn als Text weg.
 *
 * Die Richtung ist festgelegt: `contact_id` arbeitet FÜR
 * `related_contact_id`. Person → Organisation, nicht umgekehrt.
 */
class ContactRelation extends Model
{
    /** Die einzige Art, die das Paket selbst braucht. Weitere sind frei. */
    public const WorksFor = 'works_for';

    protected $guarded = [];

    public function getTable(): string
    {
        return config('contacts.tables.relations', 'contact_relations');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'related_contact_id');
    }
}
