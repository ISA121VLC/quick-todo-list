<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Limita los intentos de acceso fallidos por IP, con estado en SQLite.
 *
 * - 3 fallos en menos de 1 minuto: bloqueo de 2 minutos.
 * - Tras un bloqueo, 3 fallos más: la IP queda vetada 1 hora.
 * Mientras una IP está bloqueada o vetada se rechaza todo intento, incluso con
 * la contraseña correcta, y esos intentos no cuentan. Un acceso correcto borra el historial.
 */
final class LoginThrottle
{
    public const MAX_FAILS = 3;
    public const WINDOW_SECONDS = 60;
    public const LOCK_SECONDS = 120;
    public const BAN_SECONDS = 3600;
    /** Tiempo que se recuerda un bloqueo previo para escalar al veto. */
    private const STRIKE_TTL_SECONDS = 3600;

    public const LOCK = 'lock';
    public const BAN = 'ban';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * @return array{blocked: bool, kind: ?string, retryAfter: int, remaining: int}
     */
    public function check(string $ip): array
    {
        return $this->status($this->row($ip), $this->now());
    }

    /**
     * Registra un intento fallido. Si la IP ya estaba bloqueada no cuenta.
     *
     * @return array{blocked: bool, kind: ?string, retryAfter: int, remaining: int}
     */
    public function recordFailure(string $ip): array
    {
        $now = $this->now();
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->purge($now);
            $row = $this->row($ip);

            if ($row['blocked_until'] > $now) {
                $this->pdo->exec('COMMIT');
                return $this->status($row, $now);
            }

            if ($row['strikes'] > 0 && $now - $row['strike_at'] >= self::STRIKE_TTL_SECONDS) {
                $row['strikes'] = 0;
                $row['fails'] = 0;
            }
            // Sin bloqueos previos los fallos deben caer dentro de la misma ventana de 1 minuto.
            if ($row['strikes'] === 0 && $row['fails'] > 0 && $now - $row['window_start'] >= self::WINDOW_SECONDS) {
                $row['fails'] = 0;
            }
            if ($row['fails'] === 0) {
                $row['window_start'] = $now;
            }
            $row['fails']++;

            if ($row['fails'] >= self::MAX_FAILS) {
                $row['fails'] = 0;
                $row['strikes']++;
                $row['strike_at'] = $now;
                if ($row['strikes'] >= 2) {
                    $row['strikes'] = 0;
                    $row['blocked_until'] = $now + self::BAN_SECONDS;
                    $row['blocked_kind'] = self::BAN;
                } else {
                    $row['blocked_until'] = $now + self::LOCK_SECONDS;
                    $row['blocked_kind'] = self::LOCK;
                }
            }

            $this->save($ip, $row);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $ex) {
            $this->pdo->exec('ROLLBACK');
            throw $ex;
        }

        return $this->status($row, $now);
    }

    public function reset(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_throttle WHERE ip = :ip')->execute(['ip' => $ip]);
    }

    /**
     * @param array{fails: int, blocked_until: int, blocked_kind: ?string} $row
     * @return array{blocked: bool, kind: ?string, retryAfter: int, remaining: int}
     */
    private function status(array $row, int $now): array
    {
        $blocked = $row['blocked_until'] > $now;

        return [
            'blocked' => $blocked,
            'kind' => $blocked ? $row['blocked_kind'] : null,
            'retryAfter' => $blocked ? $row['blocked_until'] - $now : 0,
            'remaining' => max(0, self::MAX_FAILS - $row['fails']),
        ];
    }

    /**
     * @return array{fails: int, window_start: int, strikes: int, strike_at: int, blocked_until: int, blocked_kind: ?string}
     */
    private function row(string $ip): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM login_throttle WHERE ip = :ip');
        $stmt->execute(['ip' => $ip]);
        $found = $stmt->fetch();

        return $found === false
            ? ['fails' => 0, 'window_start' => 0, 'strikes' => 0, 'strike_at' => 0, 'blocked_until' => 0, 'blocked_kind' => null]
            : [
                'fails' => (int) $found['fails'],
                'window_start' => (int) $found['window_start'],
                'strikes' => (int) $found['strikes'],
                'strike_at' => (int) $found['strike_at'],
                'blocked_until' => (int) $found['blocked_until'],
                'blocked_kind' => $found['blocked_kind'],
            ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function save(string $ip, array $row): void
    {
        $this->pdo->prepare(
            'INSERT OR REPLACE INTO login_throttle (ip, fails, window_start, strikes, strike_at, blocked_until, blocked_kind)
             VALUES (:ip, :fails, :window_start, :strikes, :strike_at, :blocked_until, :blocked_kind)',
        )->execute(['ip' => $ip] + $row);
    }

    /** Borra el estado de IPs sin actividad reciente. */
    private function purge(int $now): void
    {
        $this->pdo->prepare(
            'DELETE FROM login_throttle WHERE blocked_until < :cut AND strike_at < :cut AND window_start < :cut',
        )->execute(['cut' => $now - self::STRIKE_TTL_SECONDS]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
