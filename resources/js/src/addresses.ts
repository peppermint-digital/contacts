import type { Contact, ContactAddress } from './types'

/**
 * Welche Anschrift gehoert auf welchen Beleg.
 *
 * Dieselbe Regel wie `Contact::addressForDocument()` auf der PHP-Seite, und
 * das ist Absicht statt Versehen: Ein Abnehmer liest die Kontakte ueber den
 * Connector und hat kein Laravel. Ohne diese Zeilen schriebe er die Regel
 * selbst — leicht anders, und der Unterschied faellt erst auf, wenn eine
 * Lieferung an die Rechnungsadresse geht.
 *
 * Die Tests auf beiden Seiten pruefen dieselben Faelle. Wer die Regel
 * aendert, aendert sie zweimal — das ist der Preis dafuer, dass ein
 * Abnehmer nicht in PHP geschrieben ist, und er ist kleiner als eine zweite,
 * unbemerkt abweichende Fassung.
 */
export function addressForDocument(contact: Contact, documentType: string): ContactAddress | null {
    const addresses = contact.addresses ?? []

    const defaultAddress = (): ContactAddress | null =>
        addresses.find((a) => a.is_default === true) ?? addresses[0] ?? null

    const byType = (type: string): ContactAddress | null =>
        addresses.find((a) => a.type === type) ?? null

    switch (documentType) {
        // Die Gutschrift korrigiert eine Rechnung — sie gehoert an dieselbe
        // Anschrift, nicht an die Lieferadresse.
        case 'invoice':
        case 'credit_note':
        case 'reminder':
            return byType('billing') ?? defaultAddress()
        case 'delivery_note':
            return byType('shipping') ?? defaultAddress()
        default:
            return defaultAddress()
    }
}
