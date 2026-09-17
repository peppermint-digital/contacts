import { describe, expect, it, vi } from 'vitest'

import { Werteliste } from '../src/ContactForm'
import type { ContactEmail } from '../src/types'
import { click, render } from './support/render'

/**
 * Die Zeilenliste ist seit v0.19.0 öffentlich.
 *
 * Nicht aus Ordnungsliebe: Die Kundenmaske der Verwaltung führt ihre
 * Ansprechpartner INNEN in einem größeren Formular. Ohne diesen Baustein baut
 * sie sich ihre eigene Liste — und dann gibt es zwei, von denen nur eine
 * gepflegt wird.
 */
describe('Werteliste', () => {
    const felder = [
        { key: 'value', platzhalter: 'name@beispiel.de' },
        { key: 'type', platzhalter: 'work / home' },
    ]

    it('zeigt jede Zeile, nicht nur die erste', () => {
        const { container } = render(
            <Werteliste<ContactEmail>
                titel="E-Mail"
                addRow="Zeile"
                zeilen={[{ value: 'a@b.test' }, { value: 'privat@b.test' }]}
                neueZeile={{ value: '', type: null, is_primary: false }}
                felder={felder}
                onChange={() => {}}
            />,
        )

        const werte = [...container.querySelectorAll('input')].map((i) => (i as HTMLInputElement).value)

        expect(werte).toContain('a@b.test')
        expect(werte).toContain('privat@b.test')
    })

    it('legt eine leere Zeile an und gibt die ganze Liste heraus', () => {
        const onChange = vi.fn()
        const { container } = render(
            <Werteliste<ContactEmail>
                titel="E-Mail"
                addRow="Zeile"
                zeilen={[{ value: 'a@b.test' }]}
                neueZeile={{ value: '', type: null, is_primary: false }}
                felder={felder}
                onChange={onChange}
            />,
        )

        click(container.querySelectorAll('button')[0])

        expect(onChange).toHaveBeenCalledWith([{ value: 'a@b.test' }, { value: '', type: null, is_primary: false }])
    })

    it('entfernt genau die angeklickte Zeile', () => {
        const onChange = vi.fn()
        const { container } = render(
            <Werteliste<ContactEmail>
                titel="E-Mail"
                addRow="Zeile"
                zeilen={[{ value: 'a@b.test' }, { value: 'privat@b.test' }]}
                neueZeile={{ value: '', type: null, is_primary: false }}
                felder={felder}
                onChange={onChange}
            />,
        )

        // 0 = „Zeile hinzufügen", danach je Zeile ein ×.
        click(container.querySelectorAll('button')[1])

        expect(onChange).toHaveBeenCalledWith([{ value: 'privat@b.test' }])
    })
})
