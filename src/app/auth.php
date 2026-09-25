<?php
/**
 * Fallas Exitosas · Autorización y control de acceso.
 *
 * Propósito : Resolver quién es el usuario dentro del sistema, qué permisos
 *             tiene y sobre qué ámbito geográfico.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *
 * Regla     : Entra ID autentica, SQL Server autoriza. Que alguien tenga cuenta
 *             corporativa válida no basta: debe estar dado de alta y activo.
 */

declare(strict_types=1);

/**
 * Busca al usuario autorizado a partir de los claims de Entra.
 *
 * El enlace preferente es `oid` (identidad inmutable). Si todavía no está
 * enlazado, se busca por correo y se guarda el `oid` en ese primer ingreso.
 *
 * @return array|null Fila del usuario, o null si no está autorizado.
 */
function auth_resolver_usuario(array $Par_Claims_i): ?array
{
    $Lv_Oid    = (string) ($Par_Claims_i['oid'] ?? '');
    $Lv_Correo = strtolower(trim((string) (
        $Par_Claims_i['preferred_username'] ?? $Par_Claims_i['email'] ?? ''
    )));

    if ($Lv_Oid === '') {
        return null;
    }

    $Lar_Usuario = db_fila(
        'SELECT usuario_id, entra_oid, correo, nombre, is_activo
         FROM fx.usuario
         WHERE entra_oid = :oid',
        [':oid' => $Lv_Oid]
    );

    if ($Lar_Usuario === null && $Lv_Correo !== '') {
        // Primer ingreso: el alta se hizo por correo y ahora se enlaza el oid.
        $Lar_Usuario = db_fila(
            'SELECT usuario_id, entra_oid, correo, nombre, is_activo
             FROM fx.usuario
             WHERE LOWER(correo) = :correo AND entra_oid IS NULL',
            [':correo' => $Lv_Correo]
        );

        if ($Lar_Usuario !== null) {
            db_ejecutar(
                'UPDATE fx.usuario
                 SET entra_oid = :oid, updated_at = SYSUTCDATETIME(), updated_by = :actor
                 WHERE usuario_id = :usuario_id',
                [
                    ':oid'        => $Lv_Oid,
                    ':actor'      => $Lv_Correo,
                    ':usuario_id' => $Lar_Usuario['usuario_id'],
                ]
            );

            audit_registrar('alta', [
                'usuario_id' => (int) $Lar_Usuario['usuario_id'],
                'entidad'    => 'usuario',
                'entidad_id' => (string) $Lar_Usuario['usuario_id'],
                'detalle'    => 'Identidad de Entra enlazada en el primer ingreso.',
            ]);
        }
    }

    return $Lar_Usuario;
}

/** Marca el ingreso del usuario. */
function auth_registrar_ingreso(int $Pi_UsuarioId_i): void
{
    db_ejecutar(
        'UPDATE fx.usuario SET ultimo_ingreso_at = SYSUTCDATETIME() WHERE usuario_id = :usuario_id',
        [':usuario_id' => $Pi_UsuarioId_i]
    );
}

/** Datos del usuario en sesión, con sus roles, permisos y países. */
function auth_usuario_actual(): array
{
    static $Sar_Usuario = null;

    if ($Sar_Usuario !== null) {
        return $Sar_Usuario;
    }

    $Li_UsuarioId = (int) ($_SESSION['usuario_id'] ?? 0);

    if ($Li_UsuarioId === 0) {
        throw new RuntimeException('No hay usuario en sesión.');
    }

    $Lar_Base = db_fila(
        'SELECT usuario_id, correo, nombre, puesto, is_activo, ultimo_ingreso_at
         FROM fx.usuario WHERE usuario_id = :usuario_id',
        [':usuario_id' => $Li_UsuarioId]
    );

    if ($Lar_Base === null || (int) $Lar_Base['is_activo'] !== 1) {
        // El acceso se revocó durante la sesión: se corta de inmediato.
        sesion_cerrar('revocada');
        header('Location: /index.php?aviso=revocada');
        exit;
    }

    $Lar_Roles = db_filas(
        'SELECT r.codigo AS codigo, r.nombre AS nombre
         FROM fx.usuario_rol AS ur
             INNER JOIN fx.rol AS r ON r.rol_id = ur.rol_id AND r.is_activo = 1
         WHERE ur.usuario_id = :usuario_id
         ORDER BY r.orden',
        [':usuario_id' => $Li_UsuarioId]
    );

    $Lar_Permisos = [];

    foreach (db_filas(
        'SELECT permiso_codigo, nivel_acceso FROM fx.v_usuario_permiso WHERE usuario_id = :usuario_id',
        [':usuario_id' => $Li_UsuarioId]
    ) as $Lar_Fila) {
        $Lar_Permisos[$Lar_Fila['permiso_codigo']] = $Lar_Fila['nivel_acceso'];
    }

    $Lar_Paises = db_filas(
        'SELECT pais_codigo FROM fx.v_usuario_pais WHERE usuario_id = :usuario_id ORDER BY pais_codigo',
        [':usuario_id' => $Li_UsuarioId]
    );

    $Sar_Usuario = $Lar_Base + [
        'roles'    => $Lar_Roles,
        'permisos' => $Lar_Permisos,
        'paises'   => array_column($Lar_Paises, 'pais_codigo'),
    ];

    return $Sar_Usuario;
}

/** Indica si el usuario tiene al menos lectura sobre un módulo. */
function auth_puede_ver(string $Pv_Permiso_i): bool
{
    return isset(auth_usuario_actual()['permisos'][$Pv_Permiso_i]);
}

/** Indica si el usuario tiene acceso completo sobre un módulo. */
function auth_puede_editar(string $Pv_Permiso_i): bool
{
    return (auth_usuario_actual()['permisos'][$Pv_Permiso_i] ?? '') === 'completo';
}

/**
 * Exige un permiso para continuar. Registra el intento fallido y responde 403.
 * La verificación ocurre en el servidor; ocultar el botón no es control.
 */
function auth_exigir(string $Pv_Permiso_i, bool $Pb_Completo_i = false): void
{
    $Lb_Autorizado = $Pb_Completo_i ? auth_puede_editar($Pv_Permiso_i) : auth_puede_ver($Pv_Permiso_i);

    if ($Lb_Autorizado) {
        return;
    }

    audit_registrar('acceso_denegado', [
        'usuario_id' => (int) ($_SESSION['usuario_id'] ?? 0),
        'entidad'    => 'permiso',
        'entidad_id' => $Pv_Permiso_i,
        'detalle'    => 'Intento de acceso sin permiso suficiente.',
    ]);

    http_response_code(403);
    require __DIR__ . '/../public/error-403.php';
    exit;
}
