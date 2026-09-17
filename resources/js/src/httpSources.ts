import type { Contact } from './types'

/**
 * Der übliche Weg, ein Adressbuch über HTTP zu erreichen.
 *
 * ## Warum das hier liegt und nicht im Produkt
 *
 * Dieselbe Lehre wie beim Mail-Paket: Die Quellen sind Verträge, und ein
 * Produkt könnte sie von überall beantworten. In der Praxis beantworten es
 * alle gleich und unterscheiden sich in genau einer Sache — der **Adresse**.
 *
 * Bleibt der Rest bei jedem Produkt, entscheidet jedes dieselben Fragen neu:
 *
 * - **Was ist eine Absage?** Eine Antwort mit `ok: false` ist eine Auskunft
 *   mit Grund. Ein geworfener `fetch` ist keine — da hat niemand geantwortet.
 *   Wer beides zusammenwirft, zeigt „nicht erreichbar" für ein Adressbuch,
 *   das gerade gesagt hat, warum es ablehnt.
 * - **Was ist ein fehlender Schlüssel wert?** `data ?? []` — fehlt er, ist die
 *   Liste leer, nicht kaputt. Ohne diesen Rückfall rendert die Liste
 *   `undefined.length` und nimmt den Bildschirm mit.
 *
 * ## Was beim Produkt bleibt
 *
 * Die Adressen, und nur die. Jede Funktion bekommt, was sie zum Bauen einer
 * URL braucht, und gibt eine zurück. Das Paket rät nie eine Route.
 */

/** Wo dieses Produkt seine Adressbuch-Endpunkte führt. */
export interface ContactRoutes {
    /** Suche über Namen, Organisation und Adressen. */
    search(query: string): string
    /** Ein Kontakt anlegen. */
    store(): string
    /** Einen Kontakt ändern. */
    update(id: number | string): string
}

export interface ContactSource {
    search(query: string): Promise<Contact[]>
    save(contact: Partial<Contact>, id?: number | string): Promise<SaveResult>
}

export type SaveResult =
    | { ok: true; contact: Contact }
    /** Das Gegenüber hat geantwortet und abgelehnt — mit Grund. */
    | { ok: false; reason: string }
    /** Niemand hat geantwortet. Etwas völlig anderes als eine Absage. */
    | { ok: false; unreachable: true; reason: string }

/**
 * Baut die Quelle aus den Adressen des Produkts.
 *
 * `fetcher` ist herausgezogen, damit ein Test ihn ersetzen kann, ohne das
 * globale `fetch` anzufassen — und damit ein Produkt mit eigener
 * Authentifizierung seinen eigenen mitgeben kann.
 */
export function httpContactSource(
    routes: ContactRoutes,
    fetcher: typeof fetch = fetch,
): ContactSource {
    const lies = async (antwort: Response): Promise<unknown> => {
        try {
            return await antwort.json()
        } catch {
            return null
        }
    }

    return {
        async search(query) {
            try {
                const antwort = await fetcher(routes.search(query), {
                    headers: { Accept: 'application/json' },
                })
                const daten = (await lies(antwort)) as { data?: Contact[] } | null

                // Fehlender Schluessel heisst leere Liste, nicht kaputte.
                return daten?.data ?? []
            } catch {
                // Eine Suche, die nicht durchkommt, darf die Seite nicht
                // mitnehmen. Leer ist hier die richtige Antwort.
                return []
            }
        },

        async save(contact, id) {
            const url = id === undefined ? routes.store() : routes.update(id)

            try {
                const antwort = await fetcher(url, {
                    method: id === undefined ? 'POST' : 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(contact),
                })

                const daten = (await lies(antwort)) as
                    | { ok?: boolean; error?: string; data?: Contact }
                    | null

                if (antwort.ok && daten?.data) {
                    return { ok: true, contact: daten.data }
                }

                // Geantwortet, aber abgelehnt — der Grund kommt von drueben
                // und wird nicht durch einen eigenen ersetzt.
                return { ok: false, reason: daten?.error ?? 'Der Kontakt konnte nicht gespeichert werden.' }
            } catch {
                return {
                    ok: false,
                    unreachable: true,
                    reason: 'Das Adressbuch ist gerade nicht erreichbar. Die Änderung wurde NICHT übernommen.',
                }
            }
        },
    }
}
