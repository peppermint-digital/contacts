<?php

namespace Peppermint\Contacts\Exceptions;

use RuntimeException;

/**
 * Das Brain war erreichbar — und hat abgelehnt.
 *
 * Bewusst getrennt von {@see StoreUnavailable}. Die beiden Faelle fuehlen sich
 * gleich an („es hat nicht geklappt"), verlangen aber das Gegenteil
 * voneinander: Bei einem Ausfall wartet man und versucht es spaeter, bei einer
 * Ablehnung aendert man die Daten. Wer sie zusammenwirft, schickt den
 * Suchenden in die falsche Richtung — und die Ablehnungsbegruendung, die die
 * Gegenseite mitgeschickt hat, geht dabei verloren.
 */
class BrainRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response  Die vollstaendige Antwort, damit nichts verschwindet.
     */
    public function __construct(string $grund, public readonly array $response = [])
    {
        parent::__construct('Das zentrale Adressbuch hat den Vorgang abgelehnt: '.$grund);
    }
}
