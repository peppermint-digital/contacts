import { describe, expect, it, vi } from 'vitest'
import { act } from 'react'

import { ContactForm, type ContactFormLabels } from '../src/ContactForm'
import type { Contact } from '../src/types'
import { click, render } from './support/render'

const labels: ContactFormLabels = {
    kind: 'Art',
    kindIndividual: 'Person',
    kindOrg: 'Firma',
    name: 'Name',
    namePlaceholderIndividual: 'Vor- und Nachname',
    namePlaceholderOrg: 'Firmenname',
    organization: 'Firma',
    title: 'Funktion',
    emails: 'E-Mail-Adressen',
    phones: 'Telefonnummern',
    addresses: 'Anschriften',
    addRow: 'Zeile',
    save: 'Speichern',
    cancel: 'Abbrechen',
}

/** Den Knopf mit dieser Aufschrift finden. */
function knopf(container: HTMLElement, text: string): HTMLButtonElement {
    return [...container.querySelectorAll('button')].find((b) => b.textContent?.trim() === text)!
}

describe('ContactForm', () => {
    it('gibt den Entwurf heraus, statt selbst zu speichern', () => {
        // Ein Baustein, der seine eigene Route kennt, ist in genau einem
        // Produkt richtig.
        const gespeichert = vi.fn()
        const { container } = render(
            <ContactForm contact={{ kind: 'org', formatted_name: 'Beispiel GmbH' }} labels={labels} onSave={gespeichert} />,
        )

        act(() => {
            container.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
        })

        expect(gespeichert).toHaveBeenCalledWith(
            expect.objectContaining({ kind: 'org', formatted_name: 'Beispiel GmbH' }),
        )
    })

    it('nimmt mehrere Adressen auf — daran scheitert die Uebergabe heute', () => {
        const gespeichert = vi.fn()
        const kontakt: Contact = {
            kind: 'individual',
            formatted_name: 'Anke Berg',
            emails: [{ value: 'eins@beispiel.de' }, { value: 'zwei@beispiel.de' }],
        }

        const { container } = render(<ContactForm contact={kontakt} labels={labels} onSave={gespeichert} />)

        // Zwei Eingabefelder je Zeile (Wert + Art) → vier fuer zwei Adressen.
        const werte = [...container.querySelectorAll('input')].filter(
            (i) => (i as HTMLInputElement).value.includes('@'),
        )
        expect(werte).toHaveLength(2)
    })

    it('legt eine leere Zeile an, wenn man eine will', () => {
        const { container } = render(<ContactForm labels={labels} onSave={vi.fn()} />)
        const vorher = container.querySelectorAll('input').length

        click(knopf(container, 'Zeile'))

        expect(container.querySelectorAll('input').length).toBeGreaterThan(vorher)
    })

    it('traegt den gesehenen Stand mit, damit das Produkt absagen kann', () => {
        // Ohne ihn kann niemand feststellen, ob jemand anderes inzwischen
        // geaendert hat — und es wird still ueberschrieben.
        const gespeichert = vi.fn()
        const { container } = render(
            <ContactForm contact={{ id: 7, kind: 'org', formatted_name: 'X', version: 3 }} labels={labels} onSave={gespeichert} />,
        )

        act(() => {
            container.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
        })

        expect(gespeichert).toHaveBeenCalledWith(expect.objectContaining({ version: 3, id: 7 }))
    })

    it('zeigt die Meldungen des Produkts, statt eigene zu erfinden', () => {
        const { container } = render(
            <ContactForm labels={labels} onSave={vi.fn()} errors={['Der Kontakt wurde zwischenzeitlich geändert.']} />,
        )

        expect(container.textContent).toContain('zwischenzeitlich geändert')
    })

    it('zeigt keinen Abbrechen-Knopf, wenn das Produkt keinen anbietet', () => {
        const { container } = render(<ContactForm labels={labels} onSave={vi.fn()} />)

        expect(knopf(container, 'Abbrechen')).toBeUndefined()
    })
})
