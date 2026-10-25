<?php

declare(strict_types=1);

/**
 * Guarda el hash de la contraseña de acceso en data/password.hash (o APP_PASSWORD_FILE).
 *
 *   php bin/set-password.php              pregunta la contraseña sin mostrarla
 *   echo 'clave' | php bin/set-password.php   la lee de la entrada estándar
 */

const MAX_LENGTH = 20;
const MIN_LENGTH = 6;

$file = getenv('APP_PASSWORD_FILE');
$file = $file !== false && $file !== '' ? $file : dirname(__DIR__) . '/data/password.hash';

$interactive = function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
if ($interactive) {
    fwrite(STDERR, 'Nueva contraseña (' . MIN_LENGTH . '-' . MAX_LENGTH . ' caracteres): ');
    shell_exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDERR, "\n");
} else {
    $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
}

$length = mb_strlen($password);
if ($length < MIN_LENGTH || $length > MAX_LENGTH) {
    fwrite(STDERR, 'La contraseña debe tener entre ' . MIN_LENGTH . ' y ' . MAX_LENGTH . " caracteres.\n");
    exit(1);
}

$dir = dirname($file);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "No se pudo crear $dir\n");
    exit(1);
}

$tmp = $file . '.tmp';
if (file_put_contents($tmp, password_hash($password, PASSWORD_DEFAULT) . "\n") === false
    || !chmod($tmp, 0600) || !rename($tmp, $file)) {
    fwrite(STDERR, "No se pudo escribir $file\n");
    exit(1);
}

fwrite(STDERR, "Contraseña guardada en $file\n");
