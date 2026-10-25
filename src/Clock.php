<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Única fuente de la hora actual. Ningún otro código debe llamar a time() o new DateTime().
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
