<?php
/**
 * Fallas Exitosas · Autorización y control de acceso.
 *
 * Propósito : Resolver quién es el usuario dentro del sistema, qué permisos
 *             tiene y sobre qué ámbito geográfico.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 Validación de credencial local (correo y contraseña).
 *
 * Regla     : Entra ID autentica, SQL Server autoriza.Que alguien tenga cuenta
 *             corporativa válida no basta: debe estar dado de alta y activo.
 */

declare(strict_types=1);

/**
 * Roles del sistema. La clave es el valor de fx.usuario.rol.
 * Si se agrega uno, ampliar también ck_usuario_rol en la base.
 */
const AUTH_ROLES = [
    'administrador'       => ['nombre' => 'Administrador',           'descripcion' => 'Acceso completo, incluida la administración de usuarios y catálogos.'],
    'gerente_regional'    => ['nombre' => 'Gerente Regional',        'descripcion' => 'Visibilidad de todos los países habilitados; no administra usuarios.'],
    'operaciones'         => ['nombre' => 'Operaciones',             'descripcion' => 'Gestión de casos, alertas y seguimiento dentro de su ámbito.'],
    'experiencia_cliente' => ['nombre' => 'Experiencia del Cliente', 'descripcion' => 'Análisis de comentarios, causas y tendencias dentro de su ámbito.'],
    'consulta'            => ['nombre' => 'Consulta',                'descripcion' => 'Solo lectura de panel y tendencias dentro de su ámbito.'],
];

/**
 * Matriz de permisos aprobada el 2026-09-24. Niveles: completo | ver.
 * Un permiso ausente no concede acceso. Pendiente de confirmar con
 * Operaciones: 'ver' sobre catalogos para Operaciones y Experiencia del
 * Cliente, y 'ver' sobre casos para Gerente Regional.
 * El historial de esta matriz es el de Git.
 */
const AUTH_PERMISOS = [
    'administrador' => [
        'dashboard' => 'completo', 'comentarios_ia' => 'completo', 'casos'     => 'completo', 'alertas'  => 'completo',
        'exportar'  => 'completo', 'catalogos'      => 'completo', 'usuarios'  => 'completo', 'auditoria' => 'completo',
    ],
    'gerente_regional' => [
        'dashboard' => 'completo', 'comentarios_ia' => 'ver',      'casos'     => 'ver',      'alertas'  => 'completo',
        'exportar'  => 'completo', 'auditoria'      => 'ver',
    ],
    'operaciones' => [
        'dashboard' => 'completo', 'comentarios_ia' => 'completo', 'casos'     => 'completo', 'alertas'  => 'completo',
        'exportar'  => 'completo', 'catalogos'      => 'ver',
    ],
    'experiencia_cliente' => [
        'dashboard' => 'completo', 'comentarios_ia' => 'completo', 'casos'     => 'completo', 'alertas'  => 'completo',
        'exportar'  => 'completo', 'catalogos'      => 'ver',
    ],
    'consulta' => [
        'dashboard' => 'ver',      'comentarios_ia' => 'ver',
    ],
];

/**
 * Países habilitados, con el código ISO-3 que usa TSD.
 * El Salvador (SLV) entra en la fase 2: agregarlo aquí al habilitarlo.
 */
const AUTH_PAISES = [
    'CRI' => 'Costa Rica',
    'GTM' => 'Guatemala',
    'NIC' => 'Nicaragua',
    'PER' => 'Perú',
];

/** Valor de fx.usuario.paises que concede todos los países habilitados. */
const AUTH_TODOS_LOS_PAISES = '*';

/**
 * Convierte el valor guardado en fx.usuario.paises en la lista de países
 * habilitados. Un código que ya no esté en AUTH_PAISES no concede acceso.
 *
 * @return string[] Códigos ISO-3 ordenados.
 */
function auth_paises_efectivos(string $Pv_Paises_i): array
{
    if (trim($Pv_Paises_i) === AUTH_TODOS_LOS_PAISES) {
        return array_keys(AUTH_PAISES);
    }

    $Lar_Codigos = array_filter(
        array_map('trim', explode(',', strtoupper($Pv_Paises_i))),
        static fn (string $Lv_Codigo): bool => isset(AUTH_PAISES[$Lv_Codigo])
    );

    $Lar_Codigos = array_values(array_unique($Lar_Codigos));
    sort($Lar_Codigos);

    return $Lar_Codigos;
}

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

/**
 * Valida correo y contraseña contra la credencial local de fx.usuario.
 *
 * Tras 5 intentos fallidos la cuenta queda bloqueada 15 minutos. Si el correo
 * no tiene credencial se verifica igual contra un hash de relleno, para que el
 * tiempo de respuesta no revele qué correos existen.
 *
 * @return array{resultado:string, usuario:?array} resultado: ok | invalido | bloqueado
 */
function auth_validar_clave(string $Pv_Correo_i, string $Pv_Clave_i): array
{
    $Lv_HashRelleno = '$2y$10$OA/1pLgNtdn0g.X9GfR8deusc1X4vKgAqm0H.qfYc3XF3WdQsb8Ya';

    $Lar_Fila = db_fila(
        'SELECT usuario_id, entra_oid, correo, nombre, is_activo, clave_hash,
                CASE WHEN clave_bloqueada_hasta > SYSUTCDATETIME() THEN 1 ELSE 0 END AS is_bloqueado
         FROM fx.usuario
         WHERE LOWER(correo) = :correo AND clave_hash IS NOT NULL',
        [':correo' => strtolower(trim($Pv_Correo_i))]
    );

    if ($Lar_Fila === null) {
        password_verify($Pv_Clave_i, $Lv_HashRelleno);

        return ['resultado' => 'invalido', 'usuario' => null];
    }

    $Lv_Hash      = (string) $Lar_Fila['clave_hash'];
    $Li_UsuarioId = (int) $Lar_Fila['usuario_id'];
    unset($Lar_Fila['clave_hash']);

    if ((int) $Lar_Fila['is_bloqueado'] === 1) {
        return ['resultado' => 'bloqueado', 'usuario' => $Lar_Fila];
    }

    if (!password_verify($Pv_Clave_i, $Lv_Hash)) {
        // Un bloqueo ya vencido reinicia el conteo; al quinto fallo se bloquea 15 min.
        db_ejecutar(
            'UPDATE fx.usuario
             SET clave_intentos        = CASE WHEN clave_bloqueada_hasta IS NOT NULL THEN 1
                                              ELSE clave_intentos + 1 END,
                 clave_bloqueada_hasta = CASE WHEN clave_bloqueada_hasta IS NULL AND clave_intentos + 1 >= 5
                                              THEN DATEADD(MINUTE, 15, SYSUTCDATETIME()) END
             WHERE usuario_id = :usuario_id',
            [':usuario_id' => $Li_UsuarioId]
        );

        return ['resultado' => 'invalido', 'usuario' => $Lar_Fila];
    }

    // Si PHP adopta un algoritmo más fuerte, el hash se actualiza en este ingreso.
    $Lv_HashVigente = password_needs_rehash($Lv_Hash, PASSWORD_DEFAULT)
        ? password_hash($Pv_Clave_i, PASSWORD_DEFAULT)
        : $Lv_Hash;

    db_ejecutar(
        'UPDATE fx.usuario
         SET clave_intentos = 0, clave_bloqueada_hasta = NULL, clave_hash = :clave_hash
         WHERE usuario_id = :usuario_id',
        [':clave_hash' => $Lv_HashVigente, ':usuario_id' => $Li_UsuarioId]
    );

    return ['resultado' => 'ok', 'usuario' => $Lar_Fila];
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
        'SELECT usuario_id, correo, nombre, puesto, rol, paises, is_activo, ultimo_ingreso_at
         FROM fx.usuario WHERE usuario_id = :usuario_id',
        [':usuario_id' => $Li_UsuarioId]
    );

    if ($Lar_Base === null || (int) $Lar_Base['is_activo'] !== 1) {
        // El acceso se revocó durante la sesión: se corta de inmediato.
        sesion_cerrar('revocada');
        header('Location: /index.php?aviso=revocada');
        exit;
    }

    // Un rol desconocido no concede nada: falla cerrado.
    $Lv_Rol = (string) $Lar_Base['rol'];

    // array_merge y no +: 'paises' ya viene de la consulta y debe reemplazarse.
    $Sar_Usuario = array_merge($Lar_Base, [
        // Se conserva la forma de lista que usan view.php y dashboard.php.
        'roles'    => isset(AUTH_ROLES[$Lv_Rol])
            ? [['codigo' => $Lv_Rol, 'nombre' => AUTH_ROLES[$Lv_Rol]['nombre']]]
            : [],
        'permisos' => AUTH_PERMISOS[$Lv_Rol] ?? [],
        'paises'   => auth_paises_efectivos((string) $Lar_Base['paises']),
    ]);

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
