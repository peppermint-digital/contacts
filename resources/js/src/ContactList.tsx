import type { Contact } from './types'
import { Badge } from './ui/badge'
import { Button } from './ui/button'

/**
 * Die Wörter dieser Liste — sie bleiben beim Produkt.
 *
 * Teilbar ist die Anordnung samt Verdrahtung, nicht die Sprache: AI Brain
 * soll auf Englisch erscheinen können, während Verwaltung und CRM auf
 * Deutsch bleiben, ohne dass das Paket davon etwas weiß.
 */
export interface ContactListLabels {
    name: string
    emails: string
    phones: string
    addresses: string
    /** Wenn ein Kontakt weder Namen noch Organisation trägt. */
    unnamed: string
    /** Vor der Organisation, z.B. „bei". */
    worksFor: string
    /** Auf dem Abzeichen der Hauptadresse. */
    primary: string
    edit: string
    empty: string
}

/**
 * Der Kontaktbestand als Liste.
 *
 * ## Was hier steht und was nicht
 *
 * Die Anordnung — Name mit Organisation darunter, daneben die Mehrzahlen:
 * alle Adressen, alle Nummern, alle Anschriften. Genau diese Mehrzahl ist
 * der Punkt des ganzen Pakets; eine Liste, die nur die erste Adresse zeigt,
 * verdeckt wieder, was sie sichtbar machen soll.
 *
 * Nicht hier: die Wörter (kommen über `labels`) und woher die Kontakte
 * stammen (das Produkt reicht sie herein). Damit ist dieselbe Liste in drei
 * Produkten dieselbe Liste — und unterscheidet sich in dem, worin sich
 * Produkte wirklich unterscheiden.
 */
export function ContactList({
    contacts,
    labels,
    onEdit,
}: {
    contacts: Contact[]
    labels: ContactListLabels
    onEdit?: (contact: Contact) => void
}) {
    if (contacts.length === 0) {
        return (
            <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">
                {labels.empty}
            </p>
        )
    }

    return (
        <table className="w-full caption-bottom text-sm">
            <thead className="[&_tr]:border-b">
                <tr className="text-muted-foreground border-b text-left">
                    <th className="h-10 px-2 font-medium">{labels.name}</th>
                    <th className="h-10 px-2 font-medium">{labels.emails}</th>
                    <th className="h-10 px-2 font-medium">{labels.phones}</th>
                    <th className="h-10 px-2 font-medium">{labels.addresses}</th>
                    <th className="h-10 w-16 px-2" />
                </tr>
            </thead>
            <tbody>
                {contacts.map((kontakt) => (
                    <tr key={kontakt.id ?? kontakt.uid} className="border-b">
                        <td className="p-2 align-top">
                            <div className="font-medium">
                                {kontakt.formatted_name ?? kontakt.organization ?? (
                                    <span className="text-muted-foreground italic">{labels.unnamed}</span>
                                )}
                            </div>
                            {kontakt.organization && kontakt.organization !== kontakt.formatted_name && (
                                <div className="text-muted-foreground text-xs">{kontakt.organization}</div>
                            )}
                            {(kontakt.relations?.works_for ?? []).length > 0 && (
                                <div className="text-muted-foreground text-xs">
                                    {labels.worksFor}{' '}
                                    {(kontakt.relations?.works_for ?? []).map((o) => o.name).join(', ')}
                                </div>
                            )}
                        </td>

                        <td className="p-2 align-top">
                            {(kontakt.emails ?? []).map((mail) => (
                                <div key={mail.value} className="flex items-center gap-1">
                                    {mail.value}
                                    {mail.is_primary && (kontakt.emails ?? []).length > 1 && (
                                        <Badge className="text-[10px]">{labels.primary}</Badge>
                                    )}
                                </div>
                            ))}
                        </td>

                        <td className="p-2 align-top">
                            {(kontakt.phones ?? []).map((tel) => (
                                <div key={tel.value}>{tel.value}</div>
                            ))}
                        </td>

                        <td className="p-2 align-top">
                            {(kontakt.addresses ?? []).map((anschrift, i) => (
                                <div key={i}>
                                    {anschrift.type && (
                                        <Badge className="mr-1 text-[10px]">{anschrift.type}</Badge>
                                    )}
                                    {[anschrift.street, [anschrift.zip, anschrift.city].filter(Boolean).join(' ')]
                                        .filter(Boolean)
                                        .join(', ')}
                                </div>
                            ))}
                        </td>

                        <td className="p-2 align-top">
                            {onEdit && (
                                <Button variant="ghost" onClick={() => onEdit(kontakt)}>
                                    {labels.edit}
                                </Button>
                            )}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    )
}
