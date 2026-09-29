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
 *             2026-09-29 El menú de tema se cierra con Esc o al hacer clic fuera.
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

    // Menú de tema: sin JavaScript se cierra con su propio botón; con él,
    // también con Esc o con un clic fuera.
    const Lo_Tema = document.querySelector('details.fx-tema');
    if (Lo_Tema) {
        document.addEventListener('click', (Lo_Evento) => {
            if (Lo_Tema.open && !Lo_Tema.contains(Lo_Evento.target)) {
                Lo_Tema.open = false;
            }
        });
        document.addEventListener('keydown', (Lo_Evento) => {
            if (Lo_Evento.key === 'Escape' && Lo_Tema.open) {
                Lo_Tema.open = false;
                Lo_Tema.querySelector('summary').focus();
            }
        });
    }

    // Confirmación previa para acciones sensibles.
    document.querySelectorAll('form[data-confirmar]').forEach((Lo_Formulario) => {
        Lo_Formulario.addEventListener('submit', (Lo_Evento) => {
            if (!window.confirm(Lo_Formulario.dataset.confirmar)) {
                Lo_Evento.preventDefault();
            }
        });
    });
});