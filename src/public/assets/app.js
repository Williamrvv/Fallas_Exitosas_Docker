/**
 * Fallas Exitosas · Comportamiento común (mejora progresiva).
 *
 * Propósito : Convertir en modal real el diálogo que el servidor ya entrega
 *             abierto, y pedir confirmación en los formularios marcados con
 *             data-confirmar. Sin JavaScript todo sigue funcionando: el
 *             diálogo se ve igual y se cierra con "Cancelar".
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-25 Versión inicial.
 *
 * Nota CSP  : Apache solo permite scripts de este mismo origen. Por eso no se
 *             usan onsubmit ni <script> en línea dentro de las páginas.
 */
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Modal real: fondo inerte, foco atrapado dentro y cierre con Esc.
    document.querySelectorAll('dialog[data-modal][open]').forEach((Lo_Dialogo) => {
        Lo_Dialogo.addEventListener('close', () => {
            // close() de abajo también dispara este evento; se ignora si sigue abierto.
            if (Lo_Dialogo.open) {
                return;
            }
            window.location.assign(Lo_Dialogo.dataset.cerrar || window.location.pathname);
        });

        Lo_Dialogo.close();
        Lo_Dialogo.showModal();
    });

    // Confirmación previa para acciones sensibles.
    document.querySelectorAll('form[data-confirmar]').forEach((Lo_Formulario) => {
        Lo_Formulario.addEventListener('submit', (Lo_Evento) => {
            if (!window.confirm(Lo_Formulario.dataset.confirmar)) {
                Lo_Evento.preventDefault();
            }
        });
    });
});