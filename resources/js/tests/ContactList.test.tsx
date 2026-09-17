import { describe, expect, it, vi } from 'vitest'

import { ContactList, type ContactListLabels } from '../src/ContactList'
import type { Contact } from '../src/types'
import { click, render, textOf } from './support/render'

const labels: ContactListLabels = {
    name: 'Name',
    emails: 'E-Mail',
    phones: 'Telefon',
    addresses: 'Anschrift',
    unnamed: 'ohne Namen',
    worksFor: 'bei',
    primary: 'Haupt',
    edit: 'Bearbeiten',
    empty: 'Noch kein Kontakt.',
}

describe('ContactList', () => {
    it('zeigt ALLE Adressen, Nummern und Anschriften', () => {
        // Der Punkt des ganzen Pakets: An der Einzahl scheitert die Uebergabe
        // zwischen den Systemen. Eine Liste, die nur die erste Adresse zeigt,
        // verdeckt genau das wieder.
        const kontakt: Contact = {
            id: 1,
            kind: 'individual',
            formatted_name: 'Anke Berg',
            emails: [
                { value: 'dienstlich@beispiel.de', type: 'work', is_primary: true },
                { value: 'privat@beispiel.de', type: 'home' },
            ],
            phones: [{ value: '+49 40 1' }, { value: '+49 170 2' }],
            addresses: [
                { type: 'billing', street: 'Rechnungsweg 1', zip: '20095', city: 'Hamburg' },
                { type: 'shipping', street: 'Lieferweg 2', city: 'Bremen' },
            ],
        }

        const { container } = render(<ContactList contacts={[kontakt]} labels={labels} />)
        const text = textOf(container)

        expect(text).toContain('dienstlich@beispiel.de')
        expect(text).toContain('privat@beispiel.de')
        expect(text).toContain('+49 40 1')
        expect(text).toContain('+49 170 2')
        expect(text).toContain('Rechnungsweg 1')
        expect(text).toContain('Lieferweg 2')
    })

    it('nennt die Organisation, fuer die jemand arbeitet', () => {
        const kontakt: Contact = {
            id: 1,
            kind: 'individual',
            formatted_name: 'Anke Berg',
            relations: { works_for: [{ id: 2, name: 'Bergbau GmbH' }] },
        }

        expect(textOf(render(<ContactList contacts={[kontakt]} labels={labels} />).container))
            .toContain('bei Bergbau GmbH')
    })

    it('zeigt einen namenlosen Kontakt als solchen, statt eine leere Zelle', () => {
        // Den gibt es wirklich: So entsteht ein Kontakt aus einem Postfach.
        const kontakt: Contact = { id: 1, kind: 'individual', emails: [{ value: 'wer@dahin.de' }] }

        const text = textOf(render(<ContactList contacts={[kontakt]} labels={labels} />).container)

        expect(text).toContain('ohne Namen')
        expect(text).toContain('wer@dahin.de')
    })

    it('kommt ohne Anhaengsel aus, statt undefined zu lesen', () => {
        // Ein Kontakt aus einer Trefferliste traegt die Kurzform — ohne
        // Adressen, Nummern und Anschriften. Ohne Ruecksicht darauf rendert
        // die Liste `undefined.length` und nimmt den Bildschirm mit.
        const kurzform: Contact = { id: 1, kind: 'org', formatted_name: 'Nur der Name GmbH' }

        expect(textOf(render(<ContactList contacts={[kurzform]} labels={labels} />).container))
            .toContain('Nur der Name GmbH')
    })

    it('sagt es, wenn gar nichts da ist', () => {
        expect(textOf(render(<ContactList contacts={[]} labels={labels} />).container))
            .toContain('Noch kein Kontakt.')
    })

    it('reicht den Kontakt zum Bearbeiten heraus, statt selbst zu wissen wohin', () => {
        const kontakt: Contact = { id: 7, kind: 'org', formatted_name: 'Beispiel GmbH' }
        const bearbeitet = vi.fn()

        const { container } = render(
            <ContactList contacts={[kontakt]} labels={labels} onEdit={bearbeitet} />,
        )
        click(container.querySelector('button')!)

        expect(bearbeitet).toHaveBeenCalledWith(kontakt)
    })

    it('zeigt keinen Bearbeiten-Knopf, wenn das Produkt keinen anbietet', () => {
        const kontakt: Contact = { id: 7, kind: 'org', formatted_name: 'Beispiel GmbH' }

        expect(render(<ContactList contacts={[kontakt]} labels={labels} />).container.querySelector('button'))
            .toBeNull()
    })
})

describe('ContactList — Zeichen fuer die Art', () => {
    it('reicht die Art heraus, statt ein eigenes Symbol mitzubringen', () => {
        // Sonst erbte jedes Produkt die Icon-Bibliothek des Pakets — und zwar
        // eine andere als die, die es ohnehin benutzt.
        const kontakte: Contact[] = [
            { id: 1, kind: 'org', formatted_name: 'Beispiel GmbH' },
            { id: 2, kind: 'individual', formatted_name: 'Anke Berg' },
        ]

        const { container } = render(
            <ContactList
                contacts={kontakte}
                labels={labels}
                renderKind={(art) => <span>{art === 'org' ? '[Firma]' : '[Person]'}</span>}
            />,
        )

        // Ohne Leerzeichen: Das Zeichen und der Name sind Nachbarelemente,
        // und `textOf` fasst nur Leerraum zusammen, es erfindet keinen.
        expect(textOf(container)).toContain('[Firma]Beispiel GmbH')
        expect(textOf(container)).toContain('[Person]Anke Berg')
    })

    it('kommt ohne das Zeichen aus', () => {
        const kontakt: Contact = { id: 1, kind: 'org', formatted_name: 'Beispiel GmbH' }

        expect(textOf(render(<ContactList contacts={[kontakt]} labels={labels} />).container))
            .toContain('Beispiel GmbH')
    })
})
