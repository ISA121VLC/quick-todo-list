<?php
/**
 * @var int $status
 * @var string $message
 * @var Closure(mixed): string $e
 */
?>
<h2>Error <?= $e($status) ?></h2>
<p><?= $e($message) ?></p>
<p><a href="/">Volver al listado</a></p>
