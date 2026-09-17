import { useState } from 'react'

import type { Contact, ContactAddress, ContactEmail, ContactPhone } from './types'
import { Button } from './ui/button'
import { Input } from './ui/input'
import { Label } from './ui/label'

/** Die Wörter des Formulars — sie bleiben beim Produkt. */
export interface ContactFormLabels {
    kind: string
    kindIndividual: string
    kindOrg: string
    name: string
    namePlaceholderIndividual: string
    namePlaceholderOrg: string
    organization: string
    title: string
    emails: string
    phones: string
    addresses: string
    addRow: string
    save: string
    cancel: string
}

/**
 * Kontakt anlegen oder ändern.
 *
 * ## Warum die Listen so gebaut sind
 *
 * E-Mails, Telefone und Anschriften sind je eine Liste mit „Zeile
 * hinzufügen". Das ist der ganze Grund, warum es dieses Paket gibt: An der
 * Einzahl scheitert die Übergabe zwischen den Systemen. Ein Formular mit
 * genau einem Adressfeld baut den Fehler nach, den der Kern beseitigt.
 *
 * ## Was das Formular NICHT tut
 *
 * Es speichert nicht selbst. `onSave` bekommt den Kontakt, und was damit
 * geschieht — welcher Endpunkt, welche Anmeldung, welche Rückmeldung —
 * entscheidet das Produkt. Ein Baustein, der seine eigene Route kennt, ist
 * in genau einem Produkt richtig.
 */
export function ContactForm({
    contact,
    labels,
    onSave,
    onCancel,
    errors = [],
    busy = false,
}: {
    contact?: Contact | null
    labels: ContactFormLabels
    onSave: (contact: Partial<Contact>) => void
    onCancel?: () => void
    /** Meldungen des Produkts — das Paket erfindet keine eigenen. */
    errors?: string[]
    busy?: boolean
}) {
    const [entwurf, setEntwurf] = useState<Partial<Contact>>(() => ({
        kind: contact?.kind ?? 'individual',
        formatted_name: contact?.formatted_name ?? '',
        organization: contact?.organization ?? '',
        title: contact?.title ?? '',
        emails: contact?.emails ?? [],
        phones: contact?.phones ?? [],
        addresses: contact?.addresses ?? [],
        // Der Stand beim Oeffnen. Hat jemand anderes inzwischen geaendert,
        // kann das Produkt absagen, statt still zu ueberschreiben.
        version: contact?.version ?? undefined,
        uid: contact?.uid ?? undefined,
        id: contact?.id,
    }))

    const setze = <K extends keyof Contact>(feld: K, wert: Contact[K]) =>
        setEntwurf((alt) => ({ ...alt, [feld]: wert }))

    return (
        <form
            className="space-y-4 rounded-md border p-4"
            onSubmit={(e) => {
                e.preventDefault()
                onSave(entwurf)
            }}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <Label>{labels.kind}</Label>
                    <select
                        value={entwurf.kind}
                        onChange={(e) => setze('kind', e.target.value as Contact['kind'])}
                        className="border-input bg-background mt-1 h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="individual">{labels.kindIndividual}</option>
                        <option value="org">{labels.kindOrg}</option>
                    </select>
                </div>
                <div>
                    <Label>{labels.name}</Label>
                    <Input
                        className="mt-1"
                        value={entwurf.formatted_name ?? ''}
                        placeholder={
                            entwurf.kind === 'org'
                                ? labels.namePlaceholderOrg
                                : labels.namePlaceholderIndividual
                        }
                        onChange={(e) => setze('formatted_name', e.target.value)}
                    />
                </div>
                <div>
                    <Label>{labels.organization}</Label>
                    <Input
                        className="mt-1"
                        value={entwurf.organization ?? ''}
                        onChange={(e) => setze('organization', e.target.value)}
                    />
                </div>
                <div>
                    <Label>{labels.title}</Label>
                    <Input
                        className="mt-1"
                        value={entwurf.title ?? ''}
                        onChange={(e) => setze('title', e.target.value)}
                    />
                </div>
            </div>

            <Werteliste<ContactEmail>
                titel={labels.emails}
                addRow={labels.addRow}
                zeilen={entwurf.emails ?? []}
                neueZeile={{ value: '', type: null, is_primary: false }}
                felder={[{ key: 'value', platzhalter: 'name@beispiel.de' }, { key: 'type', platzhalter: 'work / home' }]}
                onChange={(z) => setze('emails', z)}
            />

            <Werteliste<ContactPhone>
                titel={labels.phones}
                addRow={labels.addRow}
                zeilen={entwurf.phones ?? []}
                neueZeile={{ value: '', type: null, is_primary: false }}
                felder={[{ key: 'value', platzhalter: '+49 …' }, { key: 'type', platzhalter: 'work / cell' }]}
                onChange={(z) => setze('phones', z)}
            />

            <Werteliste<ContactAddress>
                titel={labels.addresses}
                addRow={labels.addRow}
                zeilen={entwurf.addresses ?? []}
                neueZeile={{
                    type: 'billing',
                    label: null,
                    street: '',
                    zip: '',
                    city: '',
                    country: null,
                    is_default: false,
                }}
                felder={[
                    { key: 'type', platzhalter: 'billing / shipping' },
                    { key: 'street', platzhalter: 'Straße und Nr.' },
                    { key: 'zip', platzhalter: 'PLZ' },
                    { key: 'city', platzhalter: 'Ort' },
                ]}
                onChange={(z) => setze('addresses', z)}
            />

            {errors.map((meldung) => (
                <p key={meldung} className="text-destructive text-sm">
                    {meldung}
                </p>
            ))}

            <div className="flex gap-2">
                <Button type="submit" disabled={busy}>
                    {labels.save}
                </Button>
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        {labels.cancel}
                    </Button>
                )}
            </div>
        </form>
    )
}

/**
 * Eine Liste gleichartiger Zeilen — E-Mails, Telefonnummern, Anschriften.
 *
 * Nach außen gegeben, weil sie nicht nur in diesem Formular gebraucht wird:
 * Die Kundenmaske der Verwaltung führt ihre Ansprechpartner als Zeilen INNEN
 * in einem größeren Formular, nicht als eigenes. Ohne diesen Baustein baut
 * sie sich ihre eigene Liste — und dann gibt es zwei, von denen nur eine
 * gepflegt wird.
 *
 * Über `object` und nicht `Record<string, unknown>`: Ein Interface mit
 * bekannten Feldern erfüllt letzteres in TypeScript nicht von selbst, und
 * eine Index-Signatur am Kontakt-Typ hieße, dass jeder Tippfehler in einem
 * Feldnamen typgeprüfte Gültigkeit bekäme.
 */
export function Werteliste<T extends object>({
    titel,
    addRow,
    zeilen,
    neueZeile,
    felder,
    onChange,
}: {
    titel: string
    addRow: string
    zeilen: T[]
    neueZeile: T
    felder: { key: string; platzhalter: string }[]
    onChange: (zeilen: T[]) => void
}) {
    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <Label>{titel}</Label>
                <Button type="button" variant="ghost" onClick={() => onChange([...zeilen, { ...neueZeile }])}>
                    {addRow}
                </Button>
            </div>
            {zeilen.map((zeile, i) => (
                <div key={i} className="flex gap-2">
                    {felder.map((feld) => (
                        <Input
                            key={feld.key}
                            value={((zeile as Record<string, unknown>)[feld.key] as string) ?? ''}
                            placeholder={feld.platzhalter}
                            onChange={(e) =>
                                onChange(
                                    zeilen.map((z, idx) =>
                                        idx === i ? { ...z, [feld.key]: e.target.value } : z,
                                    ),
                                )
                            }
                        />
                    ))}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onChange(zeilen.filter((_, idx) => idx !== i))}
                    >
                        ×
                    </Button>
                </div>
            ))}
        </div>
    )
}
