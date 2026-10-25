<?php
/**
 * Campos comunes de los formularios de alta y edición.
 *
 * @var array<string, string> $form Valores a mostrar.
 * @var array<string, string> $errors Errores por campo.
 * @var bool|null $autofocus Enfocar el título al mostrarse (formulario de alta).
 * @var Closure(mixed): string $e
 */

use App\TaskValidator;

?>
<p>
    <label for="title">Título</label>
    <input type="text" id="title" name="title" value="<?= $e($form['title']) ?>"<?= ($autofocus ?? false) ? ' autofocus' : '' ?>>
    <?php if (isset($errors['title'])): ?><span class="error"><?= $e($errors['title']) ?></span><?php endif ?>
</p>
<p>
    <label for="description">Descripción</label>
    <textarea id="description" name="description"><?= $e($form['description']) ?></textarea>
    <?php if (isset($errors['description'])): ?><span class="error"><?= $e($errors['description']) ?></span><?php endif ?>
</p>
<p>
    <label for="due_date">Fecha límite</label>
    <input type="date" id="due_date" name="due_date" value="<?= $e($form['due_date']) ?>">
    <?php if (isset($errors['due_date'])): ?><span class="error"><?= $e($errors['due_date']) ?></span><?php endif ?>
</p>
<p>
    <label for="priority">Prioridad</label>
    <select id="priority" name="priority">
        <?php foreach (array_keys(TaskValidator::PRIORITIES) as $label): ?>
            <option value="<?= $e($label) ?>"<?= $form['priority'] === $label ? ' selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach ?>
    </select>
    <?php if (isset($errors['priority'])): ?><span class="error"><?= $e($errors['priority']) ?></span><?php endif ?>
</p>
