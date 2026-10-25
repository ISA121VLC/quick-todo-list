<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\FixedClock;
use App\TaskController;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regla de vencimiento (RF11): pendiente con fecha límite anterior a "hoy" en la zona configurada.
 */
final class OverdueTest extends TestCase
{
    private static function isOverdue(?string $dueDate, int $completed, string $now, string $timezone = 'UTC'): bool
    {
        return TaskController::isOverdue(
            ['due_date' => $dueDate, 'completed' => $completed],
            new FixedClock(new DateTimeImmutable($now)),
            new DateTimeZone($timezone),
        );
    }

    /**
     * @return iterable<string, array{?string, int, bool}>
     */
    public static function cases(): iterable
    {
        yield 'pendiente, ayer' => ['2026-03-09', 0, true];
        yield 'pendiente, hace un año' => ['2025-03-10', 0, true];
        yield 'pendiente, hoy' => ['2026-03-10', 0, false];
        yield 'pendiente, mañana' => ['2026-03-11', 0, false];
        yield 'pendiente, sin fecha' => [null, 0, false];
        yield 'completada, ayer' => ['2026-03-09', 1, false];
    }

    #[DataProvider('cases')]
    public function testOverdueRule(?string $dueDate, int $completed, bool $expected): void
    {
        $this->assertSame($expected, self::isOverdue($dueDate, $completed, '2026-03-10T12:00:00Z'));
    }

    public function testDayChangesAtMidnightOfTheConfiguredTimezone(): void
    {
        // Madrid es UTC+1 en marzo (antes del cambio de hora): medianoche local = 23:00 UTC.
        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T22:59:59Z'));
        $madrid = new DateTimeZone('Europe/Madrid');
        $task = ['due_date' => '2026-03-10', 'completed' => 0];

        $this->assertFalse(TaskController::isOverdue($task, $clock, $madrid), '23:59:59 en Madrid: aún es día 10');

        $clock->advance('+1 second');

        $this->assertTrue(TaskController::isOverdue($task, $clock, $madrid), '00:00:00 en Madrid: ya es día 11');
    }

    public function testSameInstantGivesDifferentResultsDependingOnTimezone(): void
    {
        $now = '2026-03-10T23:30:00Z';

        // En UTC todavía es día 10; en Madrid (UTC+1) ya es día 11.
        $this->assertFalse(self::isOverdue('2026-03-10', 0, $now, 'UTC'));
        $this->assertTrue(self::isOverdue('2026-03-10', 0, $now, 'Europe/Madrid'));

        // A las 03:00 UTC del día 10, en Nueva York (UTC-4 tras su cambio de hora del 8 de marzo) aún es día 9.
        $this->assertTrue(self::isOverdue('2026-03-09', 0, '2026-03-10T03:00:00Z', 'UTC'));
        $this->assertFalse(self::isOverdue('2026-03-09', 0, '2026-03-10T03:00:00Z', 'America/New_York'));
    }
}
