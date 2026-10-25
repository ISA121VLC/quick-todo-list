<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', static fn (): string => 'index');
        $this->router->post('/tasks/{id}', static fn (string $id): string => "update $id");
        $this->router->post('/tasks/{id}/delete', static fn (string $id): string => "delete $id");
    }

    public function testMatchesRouteAndExtractsParameters(): void
    {
        $match = $this->router->match('POST', '/tasks/42/delete');

        $this->assertSame(200, $match['status']);
        $this->assertSame(['42'], $match['params']);
        $this->assertSame('delete 42', ($match['handler'])(...$match['params']));
    }

    public function testMatchesRouteWithoutParameters(): void
    {
        $match = $this->router->match('GET', '/');

        $this->assertSame(200, $match['status']);
        $this->assertSame([], $match['params']);
    }

    public function testKnownPathWithOtherMethodIs405WithAllowedMethods(): void
    {
        $this->assertSame(['status' => 405, 'allowed' => ['POST']], $this->router->match('GET', '/tasks/1/delete'));
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertSame(['status' => 404], $this->router->match('GET', '/no-existe'));
    }

    public function testParametersOnlyMatchDigits(): void
    {
        $this->assertSame(['status' => 404], $this->router->match('POST', '/tasks/abc/delete'));
        $this->assertSame(['status' => 404], $this->router->match('POST', '/tasks/1/delete/extra'));
    }
}
