<?php

namespace Peppermint\Contacts\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Peppermint\Contacts\Contacts\AddressType;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Database\Factories\ContactFactory;
use Peppermint\Contacts\Exceptions\IncompleteContact;
use Peppermint\Contacts\Models\Concerns\GuardsDirectWrites;

/**
 * Ein Kontakt — eine Zeile pro Mensch, und eine pro Firma.
 *
 * ## Warum Firmen hier mit drin sind
 *
 * Der erste Entwurf wollte die Person ins Paket holen und die Firma im
 * Produkt lassen. Das ging nicht auf: `Customer` in der Verwaltung trägt
 * `company_name`, `email`, `phone`, `street` — genau die Ring-2-Felder.
 * Wäre die Firma draußen geblieben, bliebe dort ein halber Kontakt zurück,
 * und die Doppelung wäre halbiert statt aufgelöst.
 *
 * vCard sieht es ohnehin vor: `KIND` kennt `individual` und `org`. Die Firma
 * IST ein Kontakt. Draußen bleibt nur das Kaufmännische an ihr.
 *
 * ## Kein Feld „ist Kunde"
 *
 * Aus dem Interessenten wird ein Kunde ohne neue Zeile. Ein `is_customer`
 * ließe sich setzen, ohne dass ein Kunde existiert — dann glaubt man dem
 * Feld statt der Wirklichkeit. Der Status ergibt sich daraus, ob ein
 * Verwaltungs-Profil auf den Kontakt zeigt.
 *
 * ## Adoption
 *
 * Spaltennamen kommen aus der Konfiguration, nicht aus dem Paket. Das eine
 * Produkt nennt es `formatted_name`, das andere `company_name` — dieselbe
 * Sache unter zwei Namen.
 */
class Contact extends Model
{
    use GuardsDirectWrites;
    use HasFactory;

    protected $guarded = [];

    /**
     * Die Felder, die als Name gelten.
     *
     * `organization` steht bewusst dabei: Bei `KIND:org` IST der Firmenname
     * der Name, und ein Kunde, der nur unter seiner Firma bekannt ist, wäre
     * sonst kein gültiger Kontakt.
     */
    private const NAME_FIELDS = ['formatted_name', 'given_name', 'family_name', 'organization'];

    /**
     * E-Mails, die vor dem ersten Speichern schon bekannt sind.
     *
     * Der Grund ist die Ring-1-Regel: Ein Kontakt, der nur über seine
     * Adresse bekannt ist, hat im Moment des Anlegens noch keine Zeile in
     * `contact_emails` — die kann es erst geben, wenn der Kontakt eine ID
     * hat. Ohne diesen Zwischenschritt wäre genau der Fall ausgeschlossen,
     * für den die Regel absichtlich „Name ODER E-Mail" sagt.
     *
     * @var list<array{value: string, type: string|null, is_primary: bool}>
     */
    private array $stagedEmails = [];

    protected static function newFactory(): Factory
    {
        return ContactFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (self $contact): void {
            if (! $contact->hasIdentifier()) {
                throw IncompleteContact::make();
            }
        });

        static::saved(function (self $contact): void {
            $contact->flushStagedEmails();
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => Kind::class,
            // Mit Format, nicht ohne: Ein `date`-Cast ohne Format macht aus
            // einem gefüllten Datumsfeld je nach Treiber ein leeres.
            'birthday' => 'date:Y-m-d',
            'version' => 'integer',
            'mirrored_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('contacts.tables.contacts', 'contacts');
    }

    /**
     * Wie dieses Produkt ein Kernfeld in seiner Tabelle nennt.
     */
    public static function column(string $field): string
    {
        return config("contacts.columns.{$field}", $field);
    }

    /**
     * Ein Kernfeld lesen, ohne zu wissen, wie die Spalte hier heißt.
     */
    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    // -----------------------------------------------------------------
    // Ring 1 — die Pflicht
    // -----------------------------------------------------------------

    /**
     * Hat dieser Kontakt mindestens eine Kennung?
     *
     * Die Reihenfolge ist kein Zufall: Erst die Namensfelder, die ohne
     * Rückfrage an die Datenbank zu haben sind, dann die vorgemerkten
     * Adressen, und erst zuletzt — wenn der Kontakt schon existiert — die
     * gespeicherten. Der häufige Fall (ein Kontakt mit Namen wird
     * gespeichert) kommt so ohne zusätzliche Abfrage aus.
     */
    public function hasIdentifier(): bool
    {
        foreach (self::NAME_FIELDS as $field) {
            if (filled($this->getAttribute(static::column($field)))) {
                return true;
            }
        }

        if ($this->stagedEmails !== []) {
            return true;
        }

        return $this->exists && $this->emails()->exists();
    }

    /**
     * Eine E-Mail vormerken, die zusammen mit dem Kontakt entsteht.
     *
     * Für den Fall aus dem Postfach: Es gibt eine Adresse und sonst nichts.
     * Ohne diesen Weg müsste man den Kontakt erst mit einem erfundenen Namen
     * anlegen — und der erfundene Name bliebe stehen.
     */
    public function withEmail(string $value, ?string $type = null, bool $isPrimary = false): static
    {
        $this->stagedEmails[] = ['value' => $value, 'type' => $type, 'is_primary' => $isPrimary];

        return $this;
    }

    /**
     * Vorgemerkte Adressen anlegen, nachdem der Kontakt eine ID hat.
     */
    private function flushStagedEmails(): void
    {
        if ($this->stagedEmails === []) {
            return;
        }

        $staged = $this->stagedEmails;

        // Vor dem Schreiben leeren, nicht danach: Sonst legt ein zweites
        // `save()` auf demselben Objekt dieselbe Adresse noch einmal an.
        $this->stagedEmails = [];

        foreach ($staged as $email) {
            $this->emails()->firstOrCreate(
                ['value' => $email['value']],
                ['type' => $email['type'], 'is_primary' => $email['is_primary']],
            );
        }
    }

    // -----------------------------------------------------------------
    // Ring 2 — die Mehrzahl, an der die heutige Übergabe scheitert
    // -----------------------------------------------------------------

    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    /** Die Beziehungen, die von diesem Kontakt ausgehen. */
    public function relations(): HasMany
    {
        return $this->hasMany(ContactRelation::class);
    }

    /** Die Beziehungen, die auf diesen Kontakt zeigen. */
    public function inverseRelations(): HasMany
    {
        return $this->hasMany(ContactRelation::class, 'related_contact_id');
    }

    /**
     * Die Ansprechpartner dieser Organisation.
     *
     * Das ist die Frage, die heute niemand beantworten kann: `Customer`
     * kennt einen `contact_person` als Freitext, `SignatureRequest` tippt
     * Name und Adresse jedes Mal neu ein.
     */
    public function contactPersons(): Collection
    {
        return $this->inverseRelations()
            ->where('type', ContactRelation::WorksFor)
            ->with('contact')
            ->get()
            ->pluck('contact')
            ->filter()
            ->values();
    }

    /** Die Organisationen, für die diese Person arbeitet. */
    public function organizations(): Collection
    {
        return $this->relations()
            ->where('type', ContactRelation::WorksFor)
            ->with('related')
            ->get()
            ->pluck('related')
            ->filter()
            ->values();
    }

    public function primaryEmail(): ?ContactEmail
    {
        return $this->emails->firstWhere('is_primary', true) ?? $this->emails->first();
    }

    public function primaryPhone(): ?ContactPhone
    {
        return $this->phones->firstWhere('is_primary', true) ?? $this->phones->first();
    }

    // -----------------------------------------------------------------
    // Adressen — dieselben Namen wie in der Verwaltung, damit E4 passt
    // -----------------------------------------------------------------

    public function defaultAddress(): ?ContactAddress
    {
        return $this->addresses->firstWhere('is_default', true) ?? $this->addresses->first();
    }

    public function billingAddress(): ?ContactAddress
    {
        return $this->addresses->firstWhere('type', AddressType::Billing);
    }

    public function shippingAddress(): ?ContactAddress
    {
        return $this->addresses->firstWhere('type', AddressType::Shipping);
    }

    /**
     * Welche Adresse gehört auf welchen Beleg.
     *
     * Fachlogik, keine Produkteigenheit — heute steht sie in
     * `DocumentService::resolveCustomerAddress()` der Verwaltung, und der
     * Shop bräuchte sie gleich noch einmal. Schriebe er sie neu, wäre sie
     * leicht anders, und der Unterschied fiele erst auf, wenn eine Lieferung
     * an die Rechnungsadresse geht.
     *
     * Die Gutschrift folgt bewusst der Rechnung: Sie korrigiert eine, also
     * gehört sie an dieselbe Anschrift — nicht an die Lieferadresse.
     *
     * Was hier NICHT passiert: den Beleg beschriften. Der Beleg schreibt die
     * gewählte Adresse in sein eigenes Feld und behält sie. Das Paket besitzt
     * die AKTUELLE Adresse, der Beleg die DAMALIGE — eine Rechnung von 2024
     * muss die Anschrift von 2024 zeigen, auch wenn der Kontakt 2026 umzieht.
     */
    public function addressForDocument(string $documentType): ?ContactAddress
    {
        return match ($documentType) {
            'invoice', 'credit_note', 'reminder' => $this->billingAddress() ?? $this->defaultAddress(),
            'delivery_note' => $this->shippingAddress() ?? $this->defaultAddress(),
            default => $this->defaultAddress(),
        };
    }

    public function isOrganisation(): bool
    {
        return $this->field('kind') === Kind::Org;
    }
}
