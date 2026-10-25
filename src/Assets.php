<?php

declare(strict_types=1);

namespace App;

/**
 * URL de un fichero estático de public/ con la fecha de modificación como versión
 * (`/style.css?v=123`). Así una caché intermedia, como la de Cloudflare, no sirve
 * un CSS o JS antiguo tras desplegar un cambio.
 */
final class Assets
{
    public static function url(string $path): string
    {
        $file = dirname(__DIR__) . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? (int) filemtime($file) : 0;

        return '/' . ltrim($path, '/') . ($version > 0 ? "?v=$version" : '');
    }
}
