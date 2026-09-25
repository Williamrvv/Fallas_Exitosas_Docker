<?php
/**
 * Fallas Exitosas · Retorno de Microsoft Entra ID.
 *
 * Propósito : Validar la respuesta de Entra, confirmar que la persona está
 *             autorizada en la plataforma y abrir la sesión.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 State de un solo uso, 503 seguro y sesión atómica.
 *
 * Secuencia : state → código → id_token validado → autorización en SQL → sesión.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

sesion_iniciar();

/** Vuelve a la pantalla de ingreso con un aviso concreto. */
function callback_rechazar(string $Pv_Aviso_i): never
{
    header('Location: /index.php?aviso=' . urlencode($Pv_Aviso_i));
    exit;
}

/** Responde 503 sin revelar detalles de SQL ni de la identidad. */
function callback_servicio_no_disponible(Throwable $Po_Error_i): never
{
    $Lv_ErrorId = bin2hex(random_bytes(6));

    error_log(sprintf(
        'Fallas Exitosas · autorización no disponible error_id=%s tipo=%s',
        $Lv_ErrorId,
        get_class($Po_Error_i)
    ));

    http_response_code(503);
    header('Retry-After: 30');
    $GLOBALS['Gv_ErrorId'] = $Lv_ErrorId;
    require __DIR__ . '/error-503.php';
    exit;
}

$Gv_Codigo = (string) ($_GET['code'] ?? '');
$Gv_State  = (string) ($_GET['state'] ?? '');
$Gar_Transaccion = oidc_consumir_transaccion($Gv_State);

// El state protege toda respuesta de Entra, incluidos los errores.
if ($Gar_Transaccion === null) {
    error_log('Fallas Exitosas · OIDC etapa=callback resultado=state_invalido');
    callback_rechazar('error');
}

// Entra puede devolver un error explícito (consentimiento denegado, por ejemplo).
if (isset($_GET['error'])) {
    $Lv_ErrorOauth = (string) $_GET['error'];
    $Lar_Permitidos = [
        'access_denied',
        'consent_required',
        'interaction_required',
        'login_required',
        'server_error',
        'temporarily_unavailable',
    ];
    $Lv_ErrorOauth = in_array($Lv_ErrorOauth, $Lar_Permitidos, true)
        ? $Lv_ErrorOauth
        : 'oauth_error';

    error_log('Fallas Exitosas · OIDC etapa=authorize oauth=' . $Lv_ErrorOauth);
    callback_rechazar('error');
}

if ($Gv_Codigo === '') {
    callback_rechazar('error');
}

try {
    $Gar_Tokens = oidc_canjear_codigo($Gv_Codigo, $Gar_Transaccion['verificador']);
    $Gar_Claims = oidc_validar_id_token(
        (string) $Gar_Tokens['id_token'],
        $Gar_Transaccion['nonce']
    );
} catch (Throwable $Lo_Error) {
    $Lv_ErrorId = bin2hex(random_bytes(6));
    error_log(sprintf(
        'Fallas Exitosas · OIDC error_id=%s etapa=validacion tipo=%s',
        $Lv_ErrorId,
        get_class($Lo_Error)
    ));
    callback_rechazar('error');
}

// Entra ya autenticó. Ahora SQL Server decide si la persona entra o no.
try {
    $Gar_Usuario = auth_resolver_usuario($Gar_Claims);

    if ($Gar_Usuario !== null && (int) $Gar_Usuario['is_activo'] === 1) {
        // La escritura termina antes de crear la sesión para evitar sesiones parciales.
        auth_registrar_ingreso((int) $Gar_Usuario['usuario_id']);
    }
} catch (Throwable $Lo_Error) {
    callback_servicio_no_disponible($Lo_Error);
}

if ($Gar_Usuario === null) {
    audit_registrar('acceso_denegado', [
        'correo_actor' => (string) ($Gar_Claims['preferred_username'] ?? ''),
        'entidad'      => 'usuario',
        'detalle'      => 'Cuenta válida en Entra pero sin alta en la plataforma.',
    ]);
    callback_rechazar('no_autorizado');
}

if ((int) $Gar_Usuario['is_activo'] !== 1) {
    audit_registrar('acceso_denegado', [
        'usuario_id'   => (int) $Gar_Usuario['usuario_id'],
        'correo_actor' => (string) $Gar_Usuario['correo'],
        'entidad'      => 'usuario',
        'entidad_id'   => (string) $Gar_Usuario['usuario_id'],
        'detalle'      => 'Usuario inactivo intentó ingresar.',
    ]);
    callback_rechazar('inactivo');
}

sesion_establecer_usuario($Gar_Usuario);

audit_registrar('ingreso', [
    'usuario_id' => (int) $Gar_Usuario['usuario_id'],
    'entidad'    => 'usuario',
    'entidad_id' => (string) $Gar_Usuario['usuario_id'],
    'detalle'    => 'Ingreso correcto con Entra ID.',
]);

header('Location: /dashboard.php');
exit;
