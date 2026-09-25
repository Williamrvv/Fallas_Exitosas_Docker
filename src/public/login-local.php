<?php
/**
 * Fallas Exitosas · Ingreso con correo y contraseña.
 *
 * Propósito : Autenticar contra la credencial local (fx.usuario_clave) y abrir
 *             la sesión con la misma autorización que usa Entra ID.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-25 Versión inicial.
 *
 * Secuencia : CSRF → credencial → usuario activo → sesión.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

/** Vuelve a la pantalla de ingreso con un aviso concreto. */
function login_local_rechazar(string $Pv_Aviso_i): never
{
    header('Location: /index.php?aviso=' . urlencode($Pv_Aviso_i));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /index.php');
    exit;
}

if (!csrf_validar($_POST['csrf_token'] ?? null)) {
    login_local_rechazar('error');
}

$Gv_Correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
$Gv_Clave  = (string) ($_POST['clave'] ?? '');

if ($Gv_Correo === '' || $Gv_Clave === '' || strlen($Gv_Clave) > 256) {
    login_local_rechazar('credenciales');
}

try {
    $Gar_Resultado = auth_validar_clave($Gv_Correo, $Gv_Clave);
    $Gar_Usuario   = $Gar_Resultado['usuario'];

    if ($Gar_Resultado['resultado'] === 'ok' && (int) $Gar_Usuario['is_activo'] === 1) {
        // La escritura termina antes de crear la sesión para evitar sesiones parciales.
        auth_registrar_ingreso((int) $Gar_Usuario['usuario_id']);
    }
} catch (Throwable $Lo_Error) {
    $Lv_ErrorId = bin2hex(random_bytes(6));
    error_log(sprintf(
        'Fallas Exitosas · login local error_id=%s tipo=%s',
        $Lv_ErrorId,
        get_class($Lo_Error)
    ));

    http_response_code(503);
    header('Retry-After: 30');
    $GLOBALS['Gv_ErrorId'] = $Lv_ErrorId;
    require __DIR__ . '/error-500.php';
    exit;
}

if ($Gar_Resultado['resultado'] !== 'ok') {
    $Lar_Evento = [
        'correo_actor' => mb_substr($Gv_Correo, 0, 160),
        'entidad'      => 'usuario',
        'detalle'      => $Gar_Resultado['resultado'] === 'bloqueado'
            ? 'Ingreso local con cuenta bloqueada por intentos fallidos.'
            : 'Correo o contraseña incorrectos en el ingreso local.',
    ];

    if ($Gar_Usuario !== null) {
        $Lar_Evento['usuario_id'] = (int) $Gar_Usuario['usuario_id'];
        $Lar_Evento['entidad_id'] = (string) $Gar_Usuario['usuario_id'];
    }

    audit_registrar('acceso_denegado', $Lar_Evento);
    login_local_rechazar($Gar_Resultado['resultado'] === 'bloqueado' ? 'bloqueado' : 'credenciales');
}

if ((int) $Gar_Usuario['is_activo'] !== 1) {
    audit_registrar('acceso_denegado', [
        'usuario_id'   => (int) $Gar_Usuario['usuario_id'],
        'correo_actor' => (string) $Gar_Usuario['correo'],
        'entidad'      => 'usuario',
        'entidad_id'   => (string) $Gar_Usuario['usuario_id'],
        'detalle'      => 'Usuario inactivo intentó ingresar con contraseña.',
    ]);
    login_local_rechazar('inactivo');
}

sesion_establecer_usuario($Gar_Usuario);

audit_registrar('ingreso', [
    'usuario_id' => (int) $Gar_Usuario['usuario_id'],
    'entidad'    => 'usuario',
    'entidad_id' => (string) $Gar_Usuario['usuario_id'],
    'detalle'    => 'Ingreso correcto con correo y contraseña.',
]);

header('Location: /dashboard.php');
exit;