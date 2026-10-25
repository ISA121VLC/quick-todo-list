<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Landing pública, entrada con contraseña y salida.
 */
final class AuthController
{
    /** Cookie con el nombre de quien entró; no caduca (en la práctica, lo que permita el navegador). */
    public const NAME_COOKIE = 'quien';
    private const NAME_COOKIE_TTL = 10 * 365 * 86400;

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
        $this->render(isset($_GET['entrar']), name: self::rememberedName());
    }

    public function login(): void
    {
        $ip = ClientIp::resolve($_SERVER);

        $typed = $_POST['nombre'] ?? null;
        $name = Auth::cleanName($typed);
        $shown = $name ?? (is_string($typed) ? mb_substr(trim($typed), 0, Auth::NAME_MAX_LENGTH) : '');

        $state = $this->throttle->check($ip);
        if ($state['blocked']) {
            $this->renderBlocked($state, $shown);
            return;
        }

        if ($name === null) {
            $this->render(true, 'Escribe tu nombre (hasta ' . Auth::NAME_MAX_LENGTH . ' caracteres, sin símbolos raros).', 422, name: $shown, step: 1);
            return;
        }

        if (!$this->auth->verify($_POST['password'] ?? null)) {
            $state = $this->throttle->recordFailure($ip);
            if ($state['blocked']) {
                $this->renderBlocked($state, $name);
                return;
            }
            $left = $state['remaining'];
            $this->render(true, 'Contraseña incorrecta. ' . ($left === 1 ? 'Te queda 1 intento.' : "Te quedan $left intentos."), 401, name: $name);
            return;
        }

        $this->throttle->reset($ip);
        $this->auth->login($name);
        setcookie(self::NAME_COOKIE, $name, [
            'expires' => time() + self::NAME_COOKIE_TTL,
            'path' => '/',
            'secure' => ClientIp::isSecure($_SERVER),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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
    private function renderBlocked(array $state, string $name): void
    {
        $wait = self::duration($state['retryAfter']);
        $message = $state['kind'] === LoginThrottle::BAN
            ? "Demasiados intentos fallidos. Tu IP no puede entrar durante $wait."
            : "Demasiados intentos fallidos. Inténtalo de nuevo dentro de $wait.";

        $this->render(true, $message, 429, $state['retryAfter'], $name);
    }

    /** Nombre guardado en la cookie de una visita anterior, si sigue siendo válido. */
    private static function rememberedName(): string
    {
        return Auth::cleanName($_COOKIE[self::NAME_COOKIE] ?? null) ?? '';
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

    /**
     * @param int $step Paso del diálogo: 1 = nombre, 2 = contraseña (por defecto, el 2 si ya hay nombre).
     */
    private function render(bool $dialogOpen, ?string $error = null, int $status = 200, int $retryAfter = 0, string $name = '', ?int $step = null): void
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vars = [
            'e' => $e,
            'csrfToken' => $this->csrf->token(),
            'dialogOpen' => $dialogOpen,
            'error' => $error,
            'retryAfter' => $retryAfter,
            'maxLength' => Auth::MAX_LENGTH,
            'name' => $name,
            'nameMaxLength' => Auth::NAME_MAX_LENGTH,
            'step' => $step ?? ($error !== null && $name !== '' ? 2 : 1),
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
