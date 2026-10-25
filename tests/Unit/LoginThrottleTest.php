<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database;
use App\FixedClock;
use App\LoginThrottle;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private string $dir;
    private FixedClock $clock;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gestor-throttle-' . bin2hex(random_bytes(6));
        $this->clock = new FixedClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $this->throttle = new LoginThrottle(Database::connect($this->dir . '/t.sqlite'), $this->clock);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testTwoFailuresDoNotBlock(): void
    {
        $this->throttle->recordFailure('1.1.1.1');
        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertFalse($state['blocked']);
        $this->assertSame(1, $state['remaining']);
    }

    public function testThreeFailuresInUnderAMinuteLockForTwoMinutes(): void
    {
        $this->throttle->recordFailure('1.1.1.1');
        $this->clock->advance('+20 seconds');
        $this->throttle->recordFailure('1.1.1.1');
        $this->clock->advance('+30 seconds');
        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertTrue($state['blocked']);
        $this->assertSame(LoginThrottle::LOCK, $state['kind']);
        $this->assertSame(120, $state['retryAfter']);
    }

    public function testFailuresSpreadOverMoreThanAMinuteDoNotLock(): void
    {
        $this->throttle->recordFailure('1.1.1.1');
        $this->throttle->recordFailure('1.1.1.1');
        $this->clock->advance('+61 seconds');
        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertFalse($state['blocked']);
        $this->assertSame(2, $state['remaining']);
    }

    public function testLockExpiresAfterTwoMinutes(): void
    {
        $this->lock('1.1.1.1');

        $this->clock->advance('+119 seconds');
        $this->assertTrue($this->throttle->check('1.1.1.1')['blocked']);

        $this->clock->advance('+1 second');
        $this->assertFalse($this->throttle->check('1.1.1.1')['blocked']);
    }

    public function testAttemptsWhileLockedDoNotCountOrExtendTheLock(): void
    {
        $this->lock('1.1.1.1');
        $this->clock->advance('+60 seconds');

        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertSame(60, $state['retryAfter']);
    }

    public function testThreeMoreFailuresAfterALockBanTheIpForAnHour(): void
    {
        $this->lock('1.1.1.1');
        $this->clock->advance('+120 seconds');

        $this->throttle->recordFailure('1.1.1.1');
        $this->throttle->recordFailure('1.1.1.1');
        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertTrue($state['blocked']);
        $this->assertSame(LoginThrottle::BAN, $state['kind']);
        $this->assertSame(3600, $state['retryAfter']);

        $this->clock->advance('+3599 seconds');
        $this->assertTrue($this->throttle->check('1.1.1.1')['blocked']);
        $this->clock->advance('+1 second');
        $this->assertFalse($this->throttle->check('1.1.1.1')['blocked']);
    }

    public function testAfterABanTheIpStartsFromScratch(): void
    {
        $this->lock('1.1.1.1');
        $this->clock->advance('+120 seconds');
        $this->lock('1.1.1.1', false);
        $this->clock->advance('+3600 seconds');

        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertFalse($state['blocked']);
        $this->assertSame(2, $state['remaining']);
    }

    public function testAnOldLockIsForgottenAfterAnHour(): void
    {
        $this->lock('1.1.1.1');
        $this->clock->advance('+3601 seconds');

        for ($i = 0; $i < 2; $i++) {
            $this->throttle->recordFailure('1.1.1.1');
        }
        $state = $this->throttle->recordFailure('1.1.1.1');

        $this->assertSame(LoginThrottle::LOCK, $state['kind'], 'Es un primer bloqueo, no un veto');
    }

    public function testSuccessfulLoginResetsHistory(): void
    {
        $this->throttle->recordFailure('1.1.1.1');
        $this->throttle->recordFailure('1.1.1.1');
        $this->throttle->reset('1.1.1.1');

        $this->assertSame(2, $this->throttle->recordFailure('1.1.1.1')['remaining']);
    }

    public function testIpsAreIndependent(): void
    {
        $this->lock('1.1.1.1');

        $this->assertFalse($this->throttle->check('2.2.2.2')['blocked']);
    }

    private function lock(string $ip, bool $expectLock = true): void
    {
        for ($i = 0; $i < 3; $i++) {
            $state = $this->throttle->recordFailure($ip);
        }
        $this->assertTrue($state['blocked']);
        if ($expectLock) {
            $this->assertSame(LoginThrottle::LOCK, $state['kind']);
        }
    }
}
