<?php

declare(strict_types=1);

namespace App;

/**
 * Asocia método HTTP + ruta con un handler. Los patrones admiten parámetros
 * numéricos con la sintaxis {nombre}, p. ej. '/tasks/{id}/toggle'.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @return array{status: 200, handler: callable, params: list<string>}
     *       | array{status: 405, allowed: list<string>}
     *       | array{status: 404}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] === $method) {
                return ['status' => 200, 'handler' => $route['handler'], 'params' => array_slice($m, 1)];
            }
            $allowed[] = $route['method'];
        }

        if ($allowed !== []) {
            return ['status' => 405, 'allowed' => array_values(array_unique($allowed))];
        }

        return ['status' => 404];
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('/\\\\\{[a-z_]+\\\\\}/', '(\d+)', preg_quote($pattern, '#'));
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }
}
