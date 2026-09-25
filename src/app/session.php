<?php
/**
 * Fallas Exitosas · Manejo de sesión.
 *
 * Propósito : Iniciar la sesión con cookies endurecidas y aplicar el corte por
 *             inactividad (30 min) y el tope absoluto (8 h).
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

/** Inicia la sesión si todavía no está activa. */
function sesion_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('fx_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,      // la aplicación solo opera sobre HTTPS
        'httponly' => true,
        'samesite' => 'Lax',     // Lax permite el retorno desde Entra ID
    ]);

    session_start();
}

/** Cierra la sesión y borra su cookie. */
function sesion_cerrar(string $Pv_Motivo_i = 'salida'): void
{
    sesion_iniciar();

    if (isset($_SESSION['usuario_id'])) {
        audit_registrar('salida', [
            'usuario_id' => (int) $_SESSION['usuario_id'],
            'detalle'    => 'Cierre de sesión: ' . $Pv_Motivo_i,
        ]);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $Lar_Cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $Lar_Cookie['path'], $Lar_Cookie['domain'] ?? '', true, true);
    }

    session_destroy();
}

/**
 * Comprueba que la sesión siga vigente.
 * Devuelve el motivo de expiración, o null si sigue válida.
 */
function sesion_motivo_expiracion(): ?string
{
    if (!isset($_SESSION['usuario_id'], $_SESSION['iniciada_en'], $_SESSION['ultima_actividad'])) {
        return null;
    }

    $Lar_Limites = config_aplicacion()['sesion'];
    $Li_Ahora    = time();

    if (($Li_Ahora - (int) $_SESSION['ultima_actividad']) > $Lar_Limites['inactividad_segundos']) {
        return 'inactividad';
    }

    if (($Li_Ahora - (int) $_SESSION['iniciada_en']) > $Lar_Limites['absoluto_segundos']) {
        return 'maximo';
    }

    return null;
}

/** Registra en la sesión al usuario ya autenticado y autorizado. */
function sesion_establecer_usuario(array $Par_Usuario_i): void
{
    // Evita fijación de sesión: el identificador cambia al autenticarse.
    session_regenerate_id(true);

    $_SESSION['usuario_id']       = (int) $Par_Usuario_i['usuario_id'];
    $_SESSION['correo']           = (string) $Par_Usuario_i['correo'];
    $_SESSION['nombre']           = (string) $Par_Usuario_i['nombre'];
    $_SESSION['iniciada_en']      = time();
    $_SESSION['ultima_actividad'] = time();

    unset(
        $_SESSION['oidc_state'],
        $_SESSION['oidc_nonce'],
        $_SESSION['oidc_verificador'],
        $_SESSION['oidc_iniciada_at']
    );
}

/**
 * Exige sesión válida. Si expiró o no existe, redirige al inicio con el aviso.
 * Debe llamarse al principio de toda página protegida.
 */
function sesion_exigir(): array
{
    sesion_iniciar();

    $Lv_Motivo = sesion_motivo_expiracion();

    if ($Lv_Motivo !== null) {
        sesion_cerrar($Lv_Motivo);
        header('Location: /index.php?aviso=' . urlencode($Lv_Motivo));
        exit;
    }

    if (!isset($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit;
    }

    $_SESSION['ultima_actividad'] = time();

    return auth_usuario_actual();
}

/** Token de un solo origen para formularios que modifican datos. */
function csrf_token(): string
{
    sesion_iniciar();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/**
 * Valida el token del formulario.
 * Sin esta comprobación, otro sitio podría provocar cambios con la sesión
 * abierta del usuario.
 */
function csrf_validar(?string $Pv_Token_i): bool
{
    sesion_iniciar();

    return is_string($Pv_Token_i)
        && $Pv_Token_i !== ''
        && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $Pv_Token_i);
}
