/**
 * Die Kerntypen — dieselben Felder wie die Migration, nach vCard (RFC 6350).
 *
 * Sie stehen hier, weil nicht jeder Abnehmer Laravel spricht: Der Shop liest
 * Kontakte ueber den Connector und braucht trotzdem eine Form, auf die er
 * sich verlassen kann.
 */

/** vCard `KIND` */
export type ContactKind = 'individual' | 'org' | 'group' | 'location'

export interface ContactEmail {
    value: string
    type?: string | null
    is_primary?: boolean
}

export interface ContactPhone {
    value: string
    type?: string | null
    is_primary?: boolean
}

export interface ContactAddress {
    type?: string | null
    label?: string | null
    street?: string | null
    zip?: string | null
    city?: string | null
    country?: string | null
    is_default?: boolean
}

/** Ein Kontakt in einer Verbindung — nur Nummer und Name, nicht der ganze Satz. */
export interface RelatedContact {
    id: number
    name: string | null
}

export interface Contact {
    id?: number | string
    kind: ContactKind
    uid?: string | null
    formatted_name?: string | null
    given_name?: string | null
    family_name?: string | null
    organization?: string | null
    title?: string | null
    url?: string | null
    birthday?: string | null
    note?: string | null
    version?: number | null
    emails?: ContactEmail[]
    phones?: ContactPhone[]
    addresses?: ContactAddress[]

    /**
     * Die Verbindungen — Spiegelbild von `ContactPayload::toArray()`.
     *
     * Hier lagen die Typen hinter der PHP-Nutzlast zurueck, bis die erste
     * Komponente danach griff. Genau davor sollen sie schuetzen: Wer das
     * Adressbuch ueber den Connector liest, hat nur sie als Beschreibung.
     */
    relations?: {
        works_for?: RelatedContact[]
        contact_persons?: RelatedContact[]
    }
}
