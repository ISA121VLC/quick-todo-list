<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Landing pública, entrada con contraseña y salida.
 */
final class AuthController
{
    public function __construct(
        private Auth $auth,
        private LoginThrottle $throttle,
        private Csrf $csrf,
        private string $templatesDir,
    ) {
    }

    /** Portada para quien aún no ha entrado; `/?entrar` abre ya el diálogo (sin JS). */
    public function landing(): void
    {
        $this->render(isset($_GET['entrar']));
    }

    public function login(): void
    {
        $ip = ClientIp::resolve($_SERVER);

        $state = $this->throttle->check($ip);
        if ($state['blocked']) {
            $this->renderBlocked($state);
            return;
        }

        if (!$this->auth->verify($_POST['password'] ?? null)) {
            $state = $this->throttle->recordFailure($ip);
            if ($state['blocked']) {
                $this->renderBlocked($state);
                return;
            }
            $left = $state['remaining'];
            $this->render(true, 'Contraseña incorrecta. ' . ($left === 1 ? 'Te queda 1 intento.' : "Te quedan $left intentos."), 401);
            return;
        }

        $this->throttle->reset($ip);
        $this->auth->login();
        header('Location: /', true, 303);
    }

    public function logout(): void
    {
        $this->auth->logout();
        header('Location: /', true, 303);
    }

    /**
     * @param array{kind: ?string, retryAfter: int} $state
     */
    private function renderBlocked(array $state): void
    {
        $wait = self::duration($state['retryAfter']);
        $message = $state['kind'] === LoginThrottle::BAN
            ? "Demasiados intentos fallidos. Tu IP no puede entrar durante $wait."
            : "Demasiados intentos fallidos. Inténtalo de nuevo dentro de $wait.";

        $this->render(true, $message, 429, $state['retryAfter']);
    }

    /** "2 minutos", "1 minuto y 5 segundos", "40 segundos"… */
    public static function duration(int $seconds): string
    {
        $seconds = max(1, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        $parts = [];
        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? '1 minuto' : "$minutes minutos";
        }
        if ($rest > 0) {
            $parts[] = $rest === 1 ? '1 segundo' : "$rest segundos";
        }

        return implode(' y ', $parts);
    }

    private function render(bool $dialogOpen, ?string $error = null, int $status = 200, int $retryAfter = 0): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vars = [
            'e' => $e,
            'csrfToken' => $this->csrf->token(),
            'dialogOpen' => $dialogOpen,
            'error' => $error,
            'retryAfter' => $retryAfter,
            'maxLength' => Auth::MAX_LENGTH,
        ];

        extract($vars);
        ob_start();
        try {
            require $this->templatesDir . '/landing.php';
        } catch (Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
        $page = (string) ob_get_clean();

        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($retryAfter > 0) {
            header("Retry-After: $retryAfter");
        }
        echo $page;
    }
}
