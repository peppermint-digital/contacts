import { describe, expect, it } from 'vitest'
import { addressForDocument } from '../src/addresses'
import type { Contact } from '../src/types'

/**
 * Dieselben Faelle wie `tests/Feature/AddressRuleTest.php`. Laufen die beiden
 * Listen auseinander, sind es zwei Regeln geworden.
 */
const kontakt: Contact = {
    kind: 'org',
    formatted_name: 'Beispiel GmbH',
    addresses: [
        { type: 'billing', street: 'Rechnungsweg 1', city: 'Hamburg' },
        { type: 'shipping', street: 'Lieferweg 2', city: 'Bremen' },
        { type: 'work', street: 'Hauptweg 3', city: 'Kiel', is_default: true },
    ],
}

describe('addressForDocument', () => {
    it('schickt die Rechnung an die Rechnungsadresse', () => {
        expect(addressForDocument(kontakt, 'invoice')?.street).toBe('Rechnungsweg 1')
    })

    it('schickt den Lieferschein an die Lieferadresse', () => {
        expect(addressForDocument(kontakt, 'delivery_note')?.street).toBe('Lieferweg 2')
    })

    it('schickt Gutschrift und Mahnung dorthin, wo die Rechnung hinging', () => {
        expect(addressForDocument(kontakt, 'credit_note')?.street).toBe('Rechnungsweg 1')
        expect(addressForDocument(kontakt, 'reminder')?.street).toBe('Rechnungsweg 1')
    })

    it('nimmt die Hauptadresse fuer alles Uebrige', () => {
        expect(addressForDocument(kontakt, 'offer')?.street).toBe('Hauptweg 3')
    })

    it('faellt auf die Hauptadresse zurueck, wenn die passende fehlt', () => {
        const nurEine: Contact = {
            kind: 'org',
            addresses: [{ type: 'work', street: 'Einzige 1', is_default: true }],
        }

        expect(addressForDocument(nurEine, 'invoice')?.street).toBe('Einzige 1')
        expect(addressForDocument(nurEine, 'delivery_note')?.street).toBe('Einzige 1')
    })

    it('gibt nichts zurueck, wenn der Kontakt gar keine Anschrift hat', () => {
        expect(addressForDocument({ kind: 'individual' }, 'invoice')).toBeNull()
    })
})
