<?php
/**
 * Fallas Exitosas · Cambio de tema.
 *
 * Propósito : Guardar en la sesión el tema elegido con el botón del
 *             encabezado y volver a la misma página.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-29
 * Bitácora  : 2026-09-29 Versión inicial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

$Gv_Tema   = (string) ($_POST['tema'] ?? '');
$Gv_Volver = (string) ($_POST['volver'] ?? '');

// Solo por POST, con token válido y con un tema conocido.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && csrf_validar($_POST['csrf_token'] ?? null)
    && isset(VISTA_TEMAS[$Gv_Tema])) {
    if ($Gv_Tema === 'sistema') {
        unset($_SESSION['tema']);   // Sin valor guardado manda el equipo de la persona.
    } else {
        $_SESSION['tema'] = $Gv_Tema;
    }
}

// Solo se vuelve a una página de este mismo sitio.
if (!str_starts_with($Gv_Volver, '/') || str_starts_with($Gv_Volver, '//') || str_contains($Gv_Volver, '\\')) {
    $Gv_Volver = '/dashboard.php';
}

header('Location: ' . $Gv_Volver, true, 303);
exit;
