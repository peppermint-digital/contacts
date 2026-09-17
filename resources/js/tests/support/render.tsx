import { act, type ReactElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'

/**
 * Eine Komponente in jsdom rendern und den Container zurückgeben.
 *
 * Bewusst ohne Testing-Library: `react-dom` ist ohnehin Peer-Abhängigkeit,
 * und was diese Tests behaupten, ist „steht das da, bleibt jenes weg" —
 * Fragen, die der DOM direkt beantwortet.
 */
export function render(element: ReactElement): {
    container: HTMLElement
    unmount: () => void
    rerender: (next: ReactElement) => void
} {
    const container = document.createElement('div')
    document.body.appendChild(container)

    let root: Root

    act(() => {
        root = createRoot(container)
        root.render(element)
    })

    return {
        container,
        rerender(next: ReactElement) {
            act(() => {
                root.render(next)
            })
        },
        unmount() {
            act(() => {
                root.unmount()
            })
            container.remove()
        },
    }
}

/** Textinhalt des Containers, Leerraum zusammengefasst — für „sagt es X". */
export function textOf(container: HTMLElement): string {
    return (container.textContent ?? '').replace(/\s+/g, ' ').trim()
}

/** Auf ein Element klicken und React nachziehen lassen. */
export function click(element: Element): void {
    act(() => {
        element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    })
}
