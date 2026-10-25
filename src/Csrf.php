<?php

declare(strict_types=1);

namespace App;

/**
 * Token CSRF guardado en la sesión, uno por sesión.
 */
final class Csrf
{
    /** Nombre del campo oculto de los formularios. */
    public const FIELD = 'csrf_token';

    private const SESSION_KEY = 'csrf_token';

    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session Normalmente $_SESSION; se modifica por referencia.
     */
    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    /**
     * Devuelve el token de la sesión, generándolo la primera vez.
     */
    public function token(): string
    {
        if (!is_string($this->session[self::SESSION_KEY] ?? null)) {
            $this->session[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $this->session[self::SESSION_KEY];
    }

    public function verify(mixed $token): bool
    {
        $expected = $this->session[self::SESSION_KEY] ?? null;

        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }
}
