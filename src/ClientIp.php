<?php

declare(strict_types=1);

namespace App;

/**
 * IP real del cliente. Detrás del túnel de Cloudflare la conexión llega desde
 * loopback, así que solo en ese caso se confía en las cabeceras del proxy;
 * cualquier otro cliente no puede falsearlas.
 */
final class ClientIp
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    /**
     * @param array<string, mixed> $server Normalmente $_SERVER.
     */
    public static function resolve(array $server): string
    {
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '0.0.0.0';

        if (self::viaTrustedProxy($server)) {
            $forwarded = $server['HTTP_CF_CONNECTING_IP'] ?? null;
            if (is_string($forwarded) && filter_var(trim($forwarded), FILTER_VALIDATE_IP) !== false) {
                return trim($forwarded);
            }
        }

        return $remote;
    }

    /**
     * ¿La petición llegó a través de HTTPS (directamente o por el proxy de confianza)?
     *
     * @param array<string, mixed> $server
     */
    public static function isSecure(array $server): bool
    {
        if (($server['HTTPS'] ?? '') !== '' && $server['HTTPS'] !== 'off') {
            return true;
        }

        return self::viaTrustedProxy($server)
            && is_string($server['HTTP_X_FORWARDED_PROTO'] ?? null)
            && strtolower($server['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function viaTrustedProxy(array $server): bool
    {
        return in_array($server['REMOTE_ADDR'] ?? null, self::LOOPBACK, true);
    }
}
