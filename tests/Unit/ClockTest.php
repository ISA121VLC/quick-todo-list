<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\FixedClock;
use App\SystemClock;
use DateMalformedStringException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    private const FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function testSystemClockReturnsCurrentTimeInUtc(): void
    {
        $before = new DateTimeImmutable();
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable();

        $this->assertSame('UTC', $now->getTimezone()->getName());
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function testFixedClockAlwaysReturnsTheSameTime(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T10:00:00Z'));

        $this->assertSame('2026-03-10T10:00:00.000000Z', $clock->now()->format(self::FORMAT));
        $this->assertEquals($clock->now(), $clock->now());
    }

    public function testFixedClockAdvancesByOneMicrosecond(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T10:00:00Z'));
        $t0 = $clock->now();

        $clock->advance('+1 microsecond');

        $this->assertSame('2026-03-10T10:00:00.000001Z', $clock->now()->format(self::FORMAT));
        $this->assertSame('2026-03-10T10:00:00.000000Z', $t0->format(self::FORMAT), 'T0 no debe mutar');
    }

    public function testFixedClockAdvancesAcrossDays(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T23:59:59Z'));

        $clock->advance('+1 second');

        $this->assertSame('2026-03-11', $clock->now()->format('Y-m-d'));
    }

    public function testFixedClockRejectsInvalidInterval(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-10T10:00:00Z'));

        $this->expectException(DateMalformedStringException::class);
        $clock->advance('mañana por la tarde');
    }
}
