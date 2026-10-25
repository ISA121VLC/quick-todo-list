<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsGeneratedOnceAndStoredInSession(): void
    {
        $session = [];
        $csrf = new Csrf($session);

        $token = $csrf->token();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $csrf->token());
        $this->assertContains($token, $session);
    }

    public function testDifferentSessionsGetDifferentTokens(): void
    {
        $a = [];
        $b = [];

        $this->assertNotSame((new Csrf($a))->token(), (new Csrf($b))->token());
    }

    public function testVerifyAcceptsOnlyTheSessionToken(): void
    {
        $session = [];
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $this->assertTrue($csrf->verify($token));
        $this->assertFalse($csrf->verify('incorrecto'));
        $this->assertFalse($csrf->verify(''));
        $this->assertFalse($csrf->verify(null));
        $this->assertFalse($csrf->verify([$token]));
    }

    public function testVerifyFailsWhenSessionHasNoToken(): void
    {
        $session = [];

        $this->assertFalse((new Csrf($session))->verify(''));
        $this->assertSame([], $session);
    }
}
