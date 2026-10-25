<?php

declare(strict_types=1);

namespace App;

/**
 * Acceso con una única contraseña compartida. El hash vive fuera del repositorio
 * (APP_PASSWORD_HASH o data/password.hash); sin hash configurado nadie puede entrar.
 */
final class Auth
{
    /** Longitud máxima de la contraseña, en caracteres. */
    public const MAX_LENGTH = 20;

    /** Longitud máxima del nombre de quien entra, en caracteres. */
    public const NAME_MAX_LENGTH = 30;

    private const SESSION_KEY = 'authenticated';
    private const USER_KEY = 'user';

    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session Normalmente $_SESSION; se modifica por referencia.
     * @param ?string $hash Hash de password_hash(), o null si no hay contraseña configurada.
     */
    public function __construct(array &$session, private ?string $hash)
    {
        $this->session = &$session;
    }

    /**
     * Lee el hash de APP_PASSWORD_HASH o, si no existe, del fichero de contraseña.
     */
    public static function loadHash(string $file): ?string
    {
        $env = getenv('APP_PASSWORD_HASH');
        if ($env !== false && $env !== '') {
            return $env;
        }

        $contents = is_file($file) ? file_get_contents($file) : false;
        $hash = $contents === false ? '' : trim($contents);

        return $hash === '' ? null : $hash;
    }

    public function isLoggedIn(): bool
    {
        return ($this->session[self::SESSION_KEY] ?? false) === true;
    }

    public function verify(mixed $password): bool
    {
        if ($this->hash === null || !is_string($password)) {
            return false;
        }
        if ($password === '' || mb_strlen($password) > self::MAX_LENGTH) {
            return false;
        }

        return password_verify($password, $this->hash);
    }

    /**
     * Nombre limpio (espacios colapsados, sin caracteres de control) o null si no vale.
     */
    public static function cleanName(mixed $name): ?string
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH || preg_match('/\p{C}/u', $name) === 1) {
            return null;
        }

        return $name;
    }

    /** Nombre con el que entró esta sesión, o null. */
    public function user(): ?string
    {
        $user = $this->session[self::USER_KEY] ?? null;

        return is_string($user) ? $user : null;
    }

    public function login(string $name): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->session[self::SESSION_KEY] = true;
        $this->session[self::USER_KEY] = $name;
    }

    public function logout(): void
    {
        $this->session = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
