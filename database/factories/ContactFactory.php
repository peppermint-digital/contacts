<?php

namespace Peppermint\Contacts\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Peppermint\Contacts\Contacts\Kind;
use Peppermint\Contacts\Models\Contact;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $given = $this->faker->firstName();
        $family = $this->faker->lastName();

        return [
            'kind' => Kind::Individual,
            'formatted_name' => $given.' '.$family,
            'given_name' => $given,
            'family_name' => $family,
        ];
    }

    /**
     * Eine Organisation — eine Firma ist hier kein Sonderfall, sondern ein
     * Kontakt mit einer anderen Art.
     */
    public function organisation(?string $name = null): static
    {
        return $this->state(function () use ($name): array {
            $name ??= $this->faker->company();

            return [
                'kind' => Kind::Org,
                'formatted_name' => $name,
                'organization' => $name,
                'given_name' => null,
                'family_name' => null,
            ];
        });
    }

    /** Nur eine Adresse, kein Name — so entsteht ein Kontakt aus einem Postfach. */
    public function nameless(): static
    {
        return $this->state(fn (): array => [
            'formatted_name' => null,
            'given_name' => null,
            'family_name' => null,
            'organization' => null,
        ]);
    }
}
