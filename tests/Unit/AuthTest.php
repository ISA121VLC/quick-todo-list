<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth;
use App\AuthController;
use App\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private function auth(?string $password = 'secreto-1234'): Auth
    {
        $session = [];

        return new Auth($session, $password === null ? null : password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    public function testCorrectPasswordVerifies(): void
    {
        $this->assertTrue($this->auth()->verify('secreto-1234'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejected(): iterable
    {
        yield 'incorrecta' => ['otra'];
        yield 'vacía' => [''];
        yield 'no es texto' => [['secreto-1234']];
        yield 'null' => [null];
    }

    #[DataProvider('rejected')]
    public function testWrongInputsAreRejected(mixed $input): void
    {
        $this->assertFalse($this->auth()->verify($input));
    }

    public function testPasswordLongerThan20CharactersIsRejectedEvenIfItMatches(): void
    {
        $long = str_repeat('ñ', 21);
        $auth = $this->auth($long);

        $this->assertFalse($auth->verify($long));
    }

    public function testPasswordOfExactly20CharactersIsAccepted(): void
    {
        $exact = str_repeat('ñ', 20);

        $this->assertTrue($this->auth($exact)->verify($exact));
    }

    public function testWithoutConfiguredPasswordNobodyEnters(): void
    {
        $this->assertFalse($this->auth(null)->verify('secreto-1234'));
    }

    public function testLoginAndLogoutChangeSessionState(): void
    {
        $session = [];
        $auth = new Auth($session, null);

        $this->assertFalse($auth->isLoggedIn());
        $auth->login('Ana');
        $this->assertTrue($auth->isLoggedIn());
        $this->assertSame('Ana', $auth->user());
        $auth->logout();
        $this->assertFalse($auth->isLoggedIn());
        $this->assertNull($auth->user());
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function names(): iterable
    {
        yield 'normal' => ['Nacho', 'Nacho'];
        yield 'recorta y colapsa espacios' => ["  Ana   María \n", 'Ana María'];
        yield 'con tildes y ñ' => ['Íñigo', 'Íñigo'];
        yield 'exactamente 30' => [str_repeat('ñ', 30), str_repeat('ñ', 30)];
        yield '31 es demasiado' => [str_repeat('a', 31), null];
        yield 'vacío' => ['', null];
        yield 'solo espacios' => ["   \t ", null];
        yield 'carácter de control' => ["Ana\x07", null];
        yield 'no es texto' => [['Ana'], null];
        yield 'UTF-8 inválido' => ["\xff\xfe", null];
    }

    #[DataProvider('names')]
    public function testCleanName(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, Auth::cleanName($input));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function durations(): iterable
    {
        yield [40, '40 segundos'];
        yield [1, '1 segundo'];
        yield [120, '2 minutos'];
        yield [65, '1 minuto y 5 segundos'];
        yield [3600, '60 minutos'];
    }

    #[DataProvider('durations')]
    public function testDurationIsHumanReadable(int $seconds, string $expected): void
    {
        $this->assertSame($expected, AuthController::duration($seconds));
    }

    public function testClientIpIgnoresForwardedHeaderFromNonLoopbackClients(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'];

        $this->assertSame('203.0.113.9', ClientIp::resolve($server));
    }

    public function testClientIpTrustsCloudflareHeaderFromLoopback(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server));
    }

    public function testClientIpFallsBackWhenHeaderIsNotAnIp(): void
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => 'no-es-ip'];

        $this->assertSame('127.0.0.1', ClientIp::resolve($server));
    }

    public function testSecureOnlyViaTrustedProxyOrHttps(): void
    {
        $this->assertTrue(ClientIp::isSecure(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertFalse(ClientIp::isSecure(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertTrue(ClientIp::isSecure(['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on']));
    }
}
