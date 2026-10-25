<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprueba que el entorno cumple los requisitos previos de SPEC.md §2.
 */
final class EnvironmentTest extends TestCase
{
    public function testPhpVersionIs84OrNewer(): void
    {
        $this->assertGreaterThanOrEqual(80400, PHP_VERSION_ID, 'Se requiere PHP 8.4');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredExtensions(): iterable
    {
        $extensions = [
            'pdo', 'pdo_sqlite', 'session', 'mbstring',   // app
            'dom', 'json', 'libxml', 'xml', 'xmlwriter',  // PHPUnit
            'curl',                                       // tests funcionales
            'openssl', 'phar', 'zip',                     // Composer
        ];
        foreach ($extensions as $extension) {
            yield $extension => [$extension];
        }
    }

    #[DataProvider('requiredExtensions')]
    public function testExtensionIsLoaded(string $extension): void
    {
        $this->assertTrue(extension_loaded($extension), "Falta la extensión PHP '$extension'");
    }

    public function testSqliteInMemoryWorks(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }
}
