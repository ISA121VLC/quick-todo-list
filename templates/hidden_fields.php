<?php
/**
 * Campos ocultos de todo formulario POST: token CSRF y filtro activo (RF12).
 *
 * @var string $csrfToken
 * @var string $filter
 * @var Closure(mixed): string $e
 */

use App\Csrf;

?>
<input type="hidden" name="<?= $e(Csrf::FIELD) ?>" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="estado" value="<?= $e($filter) ?>">
