<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Reloj de hora fija para tests: solo cambia cuando se llama a advance().
 */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * @param string $interval Modificador relativo, p. ej. '+1 microsecond' o '+2 days'.
     * @throws \DateMalformedStringException si el modificador no es válido.
     */
    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
