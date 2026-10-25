/*
 * Comportamiento del formulario de alta en <dialog>.
 *
 * El HTML ya es declarativo: el botón "+" usa command="show-modal" y el de cerrar
 * command="close" (Invoker Commands), y el diálogo lleva closedby="any" (cerrar con
 * clic fuera). Este script solo cubre lo que el navegador no haga por sí mismo:
 *
 * 1. Navegadores sin Invoker Commands: abre/cierra el diálogo al pulsar esos botones.
 * 2. Navegadores sin closedby: cierra el diálogo modal al pulsar fuera de él.
 * 3. Respuesta 422: el servidor envía el diálogo con `open` (visible incluso sin JS,
 *    pero no modal); aquí se reabre como modal para que el foco quede dentro.
 */
(() => {
    'use strict';

    if (!('command' in HTMLButtonElement.prototype)) {
        document.addEventListener('click', (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('button[commandfor][command]')
                : null;
            if (!button) {
                return;
            }
            const dialog = document.getElementById(button.getAttribute('commandfor'));
            if (!(dialog instanceof HTMLDialogElement)) {
                return;
            }
            const command = button.getAttribute('command');
            if (command === 'show-modal' && !dialog.open) {
                dialog.showModal();
            } else if (command === 'close' && dialog.open) {
                dialog.close();
            }
        });
    }

    if (!('closedBy' in HTMLDialogElement.prototype)) {
        // Un clic en el fondo (::backdrop) llega con el propio <dialog> como destino,
        // pero fuera de su rectángulo.
        document.addEventListener('click', (event) => {
            const dialog = event.target;
            if (!(dialog instanceof HTMLDialogElement) || !dialog.open || !dialog.matches(':modal')) {
                return;
            }
            const r = dialog.getBoundingClientRect();
            const inside = event.clientX >= r.left && event.clientX <= r.right
                && event.clientY >= r.top && event.clientY <= r.bottom;
            if (!inside) {
                dialog.close();
            }
        });
    }

    for (const dialog of document.querySelectorAll('dialog[open][data-con-errores]')) {
        dialog.close();
        dialog.showModal();
    }
})();
