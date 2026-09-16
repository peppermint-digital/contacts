<?php

namespace Peppermint\Contacts\Tests\Support;

use Peppermint\Contacts\Stores\BrainContactStore;

/**
 * Ein Brain, das genau das antwortet, was der Test braucht — und das
 * mitschreibt, wonach gefragt wurde.
 *
 * `null` als Antwort bedeutet „nicht erreichbar". Das ist der Zustand, um
 * den es in der Haelfte dieser Tests geht, und er muss deshalb genauso
 * einfach herstellbar sein wie eine gelungene Antwort.
 */
class FakeBrain
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    public bool $reachable = true;

    /** @param array<string, mixed> $responses */
    public function __construct(private array $responses = []) {}

    public function store(): BrainContactStore
    {
        return new BrainContactStore(function (string $capability, array $arguments): ?array {
            $this->calls[] = [$capability, $arguments];

            if (! $this->reachable) {
                return null;
            }

            $response = $this->responses[$capability] ?? null;

            return is_callable($response) ? $response($arguments) : $response;
        });
    }

    public function answers(string $capability, mixed $response): static
    {
        $this->responses[$capability] = $response;

        return $this;
    }

    public function goesDown(): static
    {
        $this->reachable = false;

        return $this;
    }

    public function comesBack(): static
    {
        $this->reachable = true;

        return $this;
    }

    public function askedFor(string $capability): bool
    {
        return collect($this->calls)->contains(fn (array $call): bool => $call[0] === $capability);
    }
}
