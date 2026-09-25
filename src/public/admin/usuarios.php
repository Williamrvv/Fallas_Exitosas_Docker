<?php
/**
 * Fallas Exitosas · Administración de usuarios, roles y ámbito (PB-19).
 *
 * Propósito : Autorizar personas, editar sus datos, asignarles uno o varios
 *             roles y uno o varios ámbitos geográficos, activarlas o
 *             desactivarlas y desvincular su identidad de Entra ID, todo sin
 *             perder el histórico.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 Edición completa de usuarios, roles y ámbitos
 *                        múltiples, desvinculación de Entra ID y protección
 *                        del último administrador activo.
 *
 * Seguridad : Requiere permiso completo sobre `usuarios`. La verificación es
 *             de servidor; el menú solo refleja el resultado.
 *             La baja es lógica: la fila se conserva y el historial queda en
 *             la tabla temporal. Nunca se borra, porque la auditoría y las
 *             sesiones referencian al usuario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$Gar_Usuario = sesion_exigir();
auth_exigir('usuarios', true);

$Gv_Mensaje = '';
$Gv_Tipo    = 'info';

/** Columna de `fx.usuario_ambito` que corresponde a cada nivel. */
const USUARIOS_COLUMNA_AMBITO = [
    'region'  => 'region_id',
    'pais'    => 'pais_id',
    'zona'    => 'zona_id',
    'oficina' => 'oficina_id',
];

/** Convierte una selección de IDs en enteros positivos y únicos. */
function usuarios_ids_seleccionados(mixed $Pm_Valores_i): array
{
    if (!is_array($Pm_Valores_i)) {
        return [];
    }

    $Lar_Resultado = [];

    foreach ($Pm_Valores_i as $Lm_Valor) {
        $Li_Id = filter_var($Lm_Valor, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($Li_Id !== false) {
            $Lar_Resultado[(int) $Li_Id] = (int) $Li_Id;
        }
    }

    return array_values($Lar_Resultado);
}

/** Convierte valores nivel:id en ámbitos válidos y únicos. */
function usuarios_ambitos_seleccionados(mixed $Pm_Valores_i): array
{
    if (!is_array($Pm_Valores_i)) {
        return [];
    }

    $Lar_Resultado = [];

    foreach ($Pm_Valores_i as $Lm_Valor) {
        if (!is_string($Lm_Valor)
            || preg_match('/\A(region|pais|zona|oficina):([1-9][0-9]*)\z/', $Lm_Valor, $Lar_Coincidencia) !== 1) {
            throw new InvalidArgumentException('Uno de los ámbitos seleccionados no es válido.');
        }

        $Lv_Nivel = $Lar_Coincidencia[1];
        $Li_Id    = (int) $Lar_Coincidencia[2];
        $Lv_Clave = $Lv_Nivel . ':' . $Li_Id;

        $Lar_Resultado[$Lv_Clave] = [
            'nivel' => $Lv_Nivel,
            'id'    => $Li_Id,
        ];
    }

    return array_values($Lar_Resultado);
}

/**
 * Comprueba que cada rol y cada ámbito exista y siga activo.
 *
 * El formulario puede quedar abierto mientras alguien desactiva un catálogo;
 * sin esta comprobación el INSERT fallaría contra la llave foránea con un
 * mensaje técnico inútil para quien administra.
 */
function usuarios_validar_seleccion(array $Par_Roles_i, array $Par_Ambitos_i, array $Par_Catalogos_i): void
{
    if ($Par_Roles_i === []) {
        throw new InvalidArgumentException('Asigná al menos un rol funcional.');
    }

    if ($Par_Ambitos_i === []) {
        throw new InvalidArgumentException('Asigná al menos un ámbito geográfico.');
    }

    foreach ($Par_Roles_i as $Li_RolId) {
        if (!in_array($Li_RolId, $Par_Catalogos_i['roles'], true)) {
            throw new InvalidArgumentException('Uno de los roles seleccionados ya no está disponible.');
        }
    }

    foreach ($Par_Ambitos_i as $Lar_Ambito) {
        if (!in_array($Lar_Ambito['id'], $Par_Catalogos_i[$Lar_Ambito['nivel']], true)) {
            throw new InvalidArgumentException('Uno de los ámbitos seleccionados ya no está disponible.');
        }
    }
}

/**
 * Reemplaza los roles del usuario.
 *
 * Se borra y se vuelve a insertar: la tabla tiene versionado de sistema, así
 * que la asignación anterior queda registrada en `fx.usuario_rol_historial`.
 */
function usuarios_reemplazar_roles(int $Pi_UsuarioId_i, array $Par_Roles_i, string $Pv_Actor_i): void
{
    db_ejecutar('DELETE FROM fx.usuario_rol WHERE usuario_id = :usuario_id', [':usuario_id' => $Pi_UsuarioId_i]);

    foreach ($Par_Roles_i as $Li_RolId) {
        db_ejecutar(
            'INSERT INTO fx.usuario_rol (usuario_id, rol_id, created_by)
             VALUES (:usuario_id, :rol_id, :actor)',
            [':usuario_id' => $Pi_UsuarioId_i, ':rol_id' => $Li_RolId, ':actor' => $Pv_Actor_i]
        );
    }
}

/** Reemplaza los ámbitos del usuario. Igual que los roles, deja historial. */
function usuarios_reemplazar_ambitos(int $Pi_UsuarioId_i, array $Par_Ambitos_i, string $Pv_Actor_i): void
{
    db_ejecutar('DELETE FROM fx.usuario_ambito WHERE usuario_id = :usuario_id', [':usuario_id' => $Pi_UsuarioId_i]);

    foreach ($Par_Ambitos_i as $Lar_Ambito) {
        db_ejecutar(
            sprintf(
                'INSERT INTO fx.usuario_ambito (usuario_id, nivel_ambito, %s, created_by)
                 VALUES (:usuario_id, :nivel, :ambito_id, :actor)',
                USUARIOS_COLUMNA_AMBITO[$Lar_Ambito['nivel']]   // lista blanca, nunca entrada libre
            ),
            [
                ':usuario_id' => $Pi_UsuarioId_i,
                ':nivel'      => $Lar_Ambito['nivel'],
                ':ambito_id'  => $Lar_Ambito['id'],
                ':actor'      => $Pv_Actor_i,
            ]
        );
    }
}

/** Cuenta administradores activos, sin contar al usuario indicado. */
function usuarios_administradores_activos(int $Pi_Excluir_i = 0): int
{
    $Lar_Fila = db_fila(
        "SELECT COUNT(DISTINCT u.usuario_id) AS total
         FROM fx.usuario AS u
             INNER JOIN fx.usuario_rol AS ur ON ur.usuario_id = u.usuario_id
             INNER JOIN fx.rol AS r ON r.rol_id = ur.rol_id
         WHERE u.is_activo = 1 AND r.is_activo = 1
           AND r.codigo = 'administrador'
           AND u.usuario_id <> :excluir",
        [':excluir' => $Pi_Excluir_i]
    );

    return (int) ($Lar_Fila['total'] ?? 0);
}

/** Indica si el conjunto de roles elegido incluye el rol administrador. */
function usuarios_incluye_administrador(array $Par_Roles_i, array $Par_Catalogo_i): bool
{
    foreach ($Par_Catalogo_i as $Lar_Rol) {
        if ($Lar_Rol['codigo'] === 'administrador' && in_array((int) $Lar_Rol['rol_id'], $Par_Roles_i, true)) {
            return true;
        }
    }

    return false;
}

/** Texto legible de un conjunto de ámbitos, para la bitácora. */
function usuarios_ambitos_a_texto(array $Par_Ambitos_i): string
{
    $Lar_Partes = [];

    foreach ($Par_Ambitos_i as $Lar_Ambito) {
        $Lar_Partes[] = $Lar_Ambito['nivel'] . ':' . $Lar_Ambito['id'];
    }

    return implode(' ', $Lar_Partes);
}

/* --------------------------------------------------------------------------
   Catálogos

   Se cargan antes de procesar el formulario porque la validación los usa.
   -------------------------------------------------------------------------- */
$Gar_Roles    = db_filas('SELECT rol_id, codigo, nombre, descripcion FROM fx.rol WHERE is_activo = 1 ORDER BY orden');
$Gar_Regiones = db_filas('SELECT region_id  AS id, nombre FROM fx.region  WHERE is_activo = 1 ORDER BY nombre');
$Gar_Paises   = db_filas('SELECT pais_id    AS id, nombre, codigo FROM fx.pais WHERE is_activo = 1 ORDER BY nombre');
$Gar_Zonas    = db_filas('SELECT zona_id    AS id, nombre FROM fx.zona    WHERE is_activo = 1 ORDER BY nombre');
$Gar_Oficinas = db_filas('SELECT oficina_id AS id, nombre FROM fx.oficina WHERE is_activo = 1 ORDER BY nombre');

$Gar_Catalogos = [
    'roles'   => array_map('intval', array_column($Gar_Roles, 'rol_id')),
    'region'  => array_map('intval', array_column($Gar_Regiones, 'id')),
    'pais'    => array_map('intval', array_column($Gar_Paises, 'id')),
    'zona'    => array_map('intval', array_column($Gar_Zonas, 'id')),
    'oficina' => array_map('intval', array_column($Gar_Oficinas, 'id')),
];

/* Usuario abierto en el panel de edición (0 = alta de un usuario nuevo). */
$Gi_Editando = (int) ($_GET['editar'] ?? 0);

/* --------------------------------------------------------------------------
   Acciones
   -------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $Gv_Mensaje = 'La solicitud perdió validez. Volvé a enviar el formulario.';
        $Gv_Tipo    = 'error';
    } else {
        $Lv_Accion = (string) ($_POST['accion'] ?? '');

        try {
            if ($Lv_Accion === 'autorizar' || $Lv_Accion === 'editar') {
                $Li_Objetivo = $Lv_Accion === 'editar' ? (int) ($_POST['usuario_id'] ?? 0) : 0;
                $Lv_Correo   = strtolower(trim((string) ($_POST['correo'] ?? '')));
                $Lv_Nombre   = trim((string) ($_POST['nombre'] ?? ''));
                $Lv_Puesto   = trim((string) ($_POST['puesto'] ?? ''));
                $Lar_Roles   = usuarios_ids_seleccionados($_POST['roles'] ?? []);
                $Lar_Ambitos = usuarios_ambitos_seleccionados($_POST['ambitos'] ?? []);

                // Si algo falla, el panel debe quedar abierto en el mismo usuario.
                $Gi_Editando = $Li_Objetivo;

                if (!filter_var($Lv_Correo, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('El correo corporativo no tiene un formato válido.');
                }

                if ($Lv_Nombre === '') {
                    throw new InvalidArgumentException('El nombre completo es obligatorio.');
                }

                usuarios_validar_seleccion($Lar_Roles, $Lar_Ambitos, $Gar_Catalogos);

                $Lb_SeraAdministrador = usuarios_incluye_administrador($Lar_Roles, $Gar_Roles);

                /* El correo identifica a la persona en el alta y en la búsqueda:
                   no puede repetirse en otra fila.                              */
                $Lar_Duplicado = db_fila(
                    'SELECT usuario_id FROM fx.usuario
                     WHERE LOWER(correo) = :correo AND usuario_id <> :usuario_id',
                    [':correo' => $Lv_Correo, ':usuario_id' => $Li_Objetivo]
                );

                if ($Lar_Duplicado !== null) {
                    throw new InvalidArgumentException('Ese correo ya está autorizado en la plataforma.');
                }

                $Lo_Conexion = db_conexion();
                $Lo_Conexion->beginTransaction();

                if ($Lv_Accion === 'autorizar') {
                    db_ejecutar(
                        'INSERT INTO fx.usuario (correo, nombre, puesto, is_activo, created_by)
                         VALUES (:correo, :nombre, :puesto, 1, :actor)',
                        [
                            ':correo' => $Lv_Correo,
                            ':nombre' => $Lv_Nombre,
                            ':puesto' => $Lv_Puesto !== '' ? $Lv_Puesto : null,
                            ':actor'  => $Gar_Usuario['correo'],
                        ]
                    );

                    $Li_Objetivo = (int) $Lo_Conexion->lastInsertId();
                    $Lv_Detalle  = 'Usuario autorizado.';
                    $Lv_Evento   = 'alta';
                } else {
                    $Lar_Antes = db_fila(
                        'SELECT usuario_id, correo, nombre, puesto, is_activo
                         FROM fx.usuario WHERE usuario_id = :usuario_id',
                        [':usuario_id' => $Li_Objetivo]
                    );

                    if ($Lar_Antes === null) {
                        throw new InvalidArgumentException('El usuario indicado no existe.');
                    }

                    /* Nadie puede quitarse a sí mismo la administración: quedaría
                       fuera de esta pantalla sin forma de volver a entrar.       */
                    if ($Li_Objetivo === (int) $Gar_Usuario['usuario_id'] && !$Lb_SeraAdministrador) {
                        throw new InvalidArgumentException('No podés quitarte a vos mismo el rol de Administrador.');
                    }

                    /* Debe quedar siempre alguien que administre el sistema. */
                    if (!$Lb_SeraAdministrador && usuarios_administradores_activos($Li_Objetivo) === 0) {
                        throw new InvalidArgumentException('Es el último administrador activo: asigná otro antes de quitarle el rol.');
                    }

                    db_ejecutar(
                        'UPDATE fx.usuario
                         SET correo = :correo, nombre = :nombre, puesto = :puesto,
                             updated_at = SYSUTCDATETIME(), updated_by = :actor
                         WHERE usuario_id = :usuario_id',
                        [
                            ':correo'     => $Lv_Correo,
                            ':nombre'     => $Lv_Nombre,
                            ':puesto'     => $Lv_Puesto !== '' ? $Lv_Puesto : null,
                            ':actor'      => $Gar_Usuario['correo'],
                            ':usuario_id' => $Li_Objetivo,
                        ]
                    );

                    $Lv_Detalle = 'Usuario modificado: ' . $Lar_Antes['correo'];
                    $Lv_Evento  = 'cambio';
                }

                usuarios_reemplazar_roles($Li_Objetivo, $Lar_Roles, (string) $Gar_Usuario['correo']);
                usuarios_reemplazar_ambitos($Li_Objetivo, $Lar_Ambitos, (string) $Gar_Usuario['correo']);

                $Lo_Conexion->commit();

                audit_registrar($Lv_Evento, [
                    'usuario_id'  => (int) $Gar_Usuario['usuario_id'],
                    'entidad'     => 'usuario',
                    'entidad_id'  => (string) $Li_Objetivo,
                    'valor_nuevo' => $Lv_Correo
                        . ' · roles ' . implode(',', $Lar_Roles)
                        . ' · ámbitos ' . usuarios_ambitos_a_texto($Lar_Ambitos),
                    'detalle'     => $Lv_Detalle,
                ]);

                $Gi_Editando = 0;
                $Gv_Mensaje  = $Lv_Accion === 'autorizar'
                    ? 'Usuario autorizado. Podrá ingresar con su cuenta corporativa.'
                    : 'Cambios guardados. Se aplican en el próximo ingreso del usuario.';
                $Gv_Tipo     = 'info';
            }

            if ($Lv_Accion === 'cambiar_estado') {
                $Li_Objetivo = (int) ($_POST['usuario_id'] ?? 0);

                if ($Li_Objetivo === (int) $Gar_Usuario['usuario_id']) {
                    throw new InvalidArgumentException('No podés desactivar tu propio usuario.');
                }

                $Lar_Objetivo = db_fila(
                    'SELECT usuario_id, correo, is_activo FROM fx.usuario WHERE usuario_id = :usuario_id',
                    [':usuario_id' => $Li_Objetivo]
                );

                if ($Lar_Objetivo === null) {
                    throw new InvalidArgumentException('El usuario indicado no existe.');
                }

                $Li_Nuevo = (int) $Lar_Objetivo['is_activo'] === 1 ? 0 : 1;

                if ($Li_Nuevo === 0 && usuarios_administradores_activos($Li_Objetivo) === 0) {
                    throw new InvalidArgumentException('Es el último administrador activo: no se puede desactivar.');
                }

                // Baja lógica: la fila se conserva y el historial queda en la tabla temporal.
                db_ejecutar(
                    'UPDATE fx.usuario
                     SET is_activo = :estado, updated_at = SYSUTCDATETIME(), updated_by = :actor
                     WHERE usuario_id = :usuario_id',
                    [':estado' => $Li_Nuevo, ':actor' => $Gar_Usuario['correo'], ':usuario_id' => $Li_Objetivo]
                );

                audit_registrar($Li_Nuevo === 1 ? 'cambio' : 'baja', [
                    'usuario_id'     => (int) $Gar_Usuario['usuario_id'],
                    'entidad'        => 'usuario',
                    'entidad_id'     => (string) $Li_Objetivo,
                    'valor_anterior' => 'is_activo=' . $Lar_Objetivo['is_activo'],
                    'valor_nuevo'    => 'is_activo=' . $Li_Nuevo,
                    'detalle'        => 'Cambio de estado de ' . $Lar_Objetivo['correo'],
                ]);

                $Gv_Mensaje = $Li_Nuevo === 1 ? 'Usuario activado.' : 'Usuario desactivado. El histórico se conserva.';
                $Gv_Tipo    = 'info';
            }

            if ($Lv_Accion === 'desvincular') {
                $Li_Objetivo = (int) ($_POST['usuario_id'] ?? 0);
                $Gi_Editando = $Li_Objetivo;

                if ($Li_Objetivo === (int) $Gar_Usuario['usuario_id']) {
                    throw new InvalidArgumentException('No podés desvincular tu propia identidad mientras la estás usando.');
                }

                $Lar_Objetivo = db_fila(
                    'SELECT usuario_id, correo, entra_oid FROM fx.usuario WHERE usuario_id = :usuario_id',
                    [':usuario_id' => $Li_Objetivo]
                );

                if ($Lar_Objetivo === null) {
                    throw new InvalidArgumentException('El usuario indicado no existe.');
                }

                if ($Lar_Objetivo['entra_oid'] === null) {
                    throw new InvalidArgumentException('Ese usuario todavía no tiene una identidad de Entra enlazada.');
                }

                /* Al soltar el enlace, el próximo ingreso vuelve a enlazar por
                   correo. Sirve cuando la cuenta corporativa se recreó.        */
                db_ejecutar(
                    'UPDATE fx.usuario
                     SET entra_oid = NULL, updated_at = SYSUTCDATETIME(), updated_by = :actor
                     WHERE usuario_id = :usuario_id',
                    [':actor' => $Gar_Usuario['correo'], ':usuario_id' => $Li_Objetivo]
                );

                audit_registrar('cambio', [
                    'usuario_id'     => (int) $Gar_Usuario['usuario_id'],
                    'entidad'        => 'usuario',
                    'entidad_id'     => (string) $Li_Objetivo,
                    'valor_anterior' => 'entra_oid enlazado',
                    'valor_nuevo'    => 'entra_oid NULL',
                    'detalle'        => 'Identidad de Entra desvinculada de ' . $Lar_Objetivo['correo'],
                ]);

                $Gi_Editando = 0;
                $Gv_Mensaje  = 'Identidad desvinculada. Se volverá a enlazar por correo en el próximo ingreso.';
                $Gv_Tipo     = 'info';
            }
        } catch (InvalidArgumentException $Lo_Error) {
            if (db_conexion()->inTransaction()) {
                db_conexion()->rollBack();
            }
            $Gv_Mensaje = $Lo_Error->getMessage();
            $Gv_Tipo    = 'error';
        } catch (Throwable $Lo_Error) {
            if (db_conexion()->inTransaction()) {
                db_conexion()->rollBack();
            }
            error_log('Fallas Exitosas · administración de usuarios: ' . $Lo_Error->getMessage());
            $Gv_Mensaje = 'No se pudo completar la operación. Revisá el log del contenedor.';
            $Gv_Tipo    = 'error';
        }
    }
}

/* --------------------------------------------------------------------------
   Datos de pantalla
   -------------------------------------------------------------------------- */
$Gar_Usuarios = db_filas(
    "SELECT
         u.usuario_id                                   AS usuario_id,
         u.correo                                       AS correo,
         u.nombre                                       AS nombre,
         u.puesto                                       AS puesto,
         u.is_activo                                    AS is_activo,
         u.entra_oid                                    AS entra_oid,
         u.ultimo_ingreso_at                            AS ultimo_ingreso_at,
         STUFF((SELECT ', ' + r2.nombre
                FROM fx.usuario_rol AS ur2
                    INNER JOIN fx.rol AS r2 ON r2.rol_id = ur2.rol_id
                WHERE ur2.usuario_id = u.usuario_id
                ORDER BY r2.orden
                FOR XML PATH('')), 1, 2, '')            AS roles,
         STUFF((SELECT ',' + r3.codigo
                FROM fx.usuario_rol AS ur3
                    INNER JOIN fx.rol AS r3 ON r3.rol_id = ur3.rol_id
                WHERE ur3.usuario_id = u.usuario_id
                ORDER BY r3.orden
                FOR XML PATH('')), 1, 1, '')            AS roles_codigos,
         STUFF((SELECT ', ' + vp.pais_codigo
                FROM fx.v_usuario_pais AS vp
                WHERE vp.usuario_id = u.usuario_id
                ORDER BY vp.pais_codigo
                FOR XML PATH('')), 1, 2, '')            AS paises,
         (SELECT COUNT(*)
          FROM fx.usuario_ambito AS ua2
          WHERE ua2.usuario_id = u.usuario_id
            AND ua2.nivel_ambito = 'region')            AS ambitos_region
     FROM fx.usuario AS u
     ORDER BY u.is_activo DESC, u.nombre"
);

/* Datos del usuario abierto en el panel derecho. */
$Gar_Edicion        = null;
$Gar_EdicionRoles   = [];
$Gar_EdicionAmbitos = [];

if ($Gi_Editando > 0) {
    $Gar_Edicion = db_fila(
        'SELECT usuario_id, correo, nombre, puesto, is_activo, entra_oid, ultimo_ingreso_at
         FROM fx.usuario WHERE usuario_id = :usuario_id',
        [':usuario_id' => $Gi_Editando]
    );

    if ($Gar_Edicion === null) {
        $Gi_Editando = 0;
    } else {
        $Gar_EdicionRoles = array_map(
            'intval',
            array_column(
                db_filas(
                    'SELECT rol_id FROM fx.usuario_rol WHERE usuario_id = :usuario_id',
                    [':usuario_id' => $Gi_Editando]
                ),
                'rol_id'
            )
        );

        foreach (db_filas(
            'SELECT nivel_ambito, region_id, pais_id, zona_id, oficina_id
             FROM fx.usuario_ambito WHERE usuario_id = :usuario_id',
            [':usuario_id' => $Gi_Editando]
        ) as $Lar_Fila) {
            $Lv_Nivel   = (string) $Lar_Fila['nivel_ambito'];
            $Lv_Columna = USUARIOS_COLUMNA_AMBITO[$Lv_Nivel] ?? null;

            if ($Lv_Columna !== null && $Lar_Fila[$Lv_Columna] !== null) {
                $Gar_EdicionAmbitos[] = $Lv_Nivel . ':' . (int) $Lar_Fila[$Lv_Columna];
            }
        }
    }
}

/* Si el formulario se rechazó, se conserva lo que la persona había marcado. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $Gv_Tipo === 'error') {
    $Gar_EdicionRoles   = usuarios_ids_seleccionados($_POST['roles'] ?? []);
    $Gar_EdicionAmbitos = is_array($_POST['ambitos'] ?? null)
        ? array_values(array_filter($_POST['ambitos'], 'is_string'))
        : [];
}

$Gi_Activos = 0;
foreach ($Gar_Usuarios as $Lar_Fila) {
    $Gi_Activos += (int) $Lar_Fila['is_activo'];
}

/**
 * Imprime un grupo de casillas de un catálogo de ámbito.
 *
 * @param array  $Par_Items_i    Filas con id y nombre.
 * @param string $Pv_Nivel_i     region | pais | zona | oficina
 * @param array  $Par_Marcados_i Claves nivel:id ya asignadas.
 */
function usuarios_casillas_ambito(array $Par_Items_i, string $Pv_Nivel_i, string $Pv_Titulo_i, array $Par_Marcados_i): void
{
    if ($Par_Items_i === []) {
        return;
    }
    ?>
    <div class="chk-group">
        <div class="chk-title"><?= e($Pv_Titulo_i) ?></div>
        <?php foreach ($Par_Items_i as $Lar_Item): ?>
            <?php $Lv_Clave = $Pv_Nivel_i . ':' . (int) $Lar_Item['id']; ?>
            <label class="chk">
                <input type="checkbox" name="ambitos[]" value="<?= e($Lv_Clave) ?>"
                       <?= in_array($Lv_Clave, $Par_Marcados_i, true) ? 'checked' : '' ?>>
                <span><?= e($Lar_Item['nombre']) ?><?= isset($Lar_Item['codigo']) ? ' · ' . e((string) $Lar_Item['codigo']) : '' ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

vista_encabezado([
    'titulo'    => 'Usuarios y roles',
    'subtitulo' => 'Gestión de acceso por rol y ámbito geográfico · autorización con mínimo privilegio',
    'activo'    => 'usuarios',
    'usuario'   => $Gar_Usuario,
]);
?>

<?php if ($Gv_Mensaje !== ''): ?>
    <div class="alerta alerta-<?= e($Gv_Tipo) ?>" role="alert">
        <i class="bi bi-<?= $Gv_Tipo === 'error' ? 'exclamation-triangle' : 'check-circle' ?>"></i>
        <span><?= e($Gv_Mensaje) ?></span>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-blue"><i class="bi bi-people-fill"></i></div>
        <div><div class="mini-val tnum"><?= count($Gar_Usuarios) ?></div><div class="mini-lab">Usuarios autorizados</div></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-green"><i class="bi bi-person-check-fill"></i></div>
        <div><div class="mini-val tnum"><?= $Gi_Activos ?></div><div class="mini-lab">Activos</div></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-purple"><i class="bi bi-shield-lock-fill"></i></div>
        <div><div class="mini-val tnum"><?= count($Gar_Roles) ?></div><div class="mini-lab">Roles definidos</div></div>
    </div></div></div>
    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-cel"><i class="bi bi-geo-alt-fill"></i></div>
        <div><div class="mini-val tnum">4</div><div class="mini-lab">Niveles de ámbito</div></div>
    </div></div></div>
</div>

<div class="callout mt-3">
    <div class="ic"><i class="bi bi-shield-check"></i></div>
    <div class="t">
        <b>Entra ID autentica · SQL Server autoriza.</b>
        Cada persona inicia sesión con su cuenta corporativa; el acceso se concede solo si está
        autorizada aquí. El enlace es por identidad inmutable de Entra, no por el correo.
        Al desactivar, se conserva el histórico.
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-xl-8"><div class="card-anc">
        <div class="ch">
            <div><h2>Usuarios</h2><div class="sub">Rol y ámbito efectivo</div></div>
            <?php if ($Gi_Editando > 0): ?>
                <a class="btn btn-ghost btn-sm" href="/admin/usuarios.php">
                    <i class="bi bi-plus-lg me-1"></i>Nuevo usuario
                </a>
            <?php endif; ?>
        </div>
        <div class="cb">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Usuario</th><th>Rol</th><th>Ámbito</th>
                        <th>Estado</th><th>Último ingreso</th><th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($Gar_Usuarios as $Lar_Fila): ?>
                    <?php
                    $Lb_EsPropio  = (int) $Lar_Fila['usuario_id'] === (int) $Gar_Usuario['usuario_id'];
                    $Lb_EnEdicion = (int) $Lar_Fila['usuario_id'] === $Gi_Editando;
                    $Lar_Codigos  = array_values(array_filter(explode(',', (string) $Lar_Fila['roles_codigos'])));
                    $Lar_Nombres  = array_values(array_filter(explode(', ', (string) $Lar_Fila['roles'])));
                    ?>
                    <tr<?= $Lb_EnEdicion ? ' class="fila-edicion"' : '' ?>>
                        <td>
                            <div class="uname">
                                <?= e($Lar_Fila['nombre']) ?>
                                <?php if ($Lb_EsPropio): ?><span class="sc ms-1">vos</span><?php endif; ?>
                            </div>
                            <div class="umail"><?= e($Lar_Fila['correo']) ?></div>
                            <?php if (!empty($Lar_Fila['puesto'])): ?>
                                <div class="umail"><?= e($Lar_Fila['puesto']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="scope">
                            <?php if ($Lar_Nombres === []): ?>
                                <span class="umail">Sin rol</span>
                            <?php else: ?>
                                <?php foreach ($Lar_Nombres as $Li_Indice => $Lv_NombreRol): ?>
                                    <span class="role-tag r-<?= e($Lar_Codigos[$Li_Indice] ?? 'consulta') ?>"><?= e($Lv_NombreRol) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="scope">
                            <?php if ((int) $Lar_Fila['ambitos_region'] > 0): ?>
                                <span class="sc sc-all">Regional</span>
                            <?php endif; ?>
                            <?php foreach (array_filter(explode(', ', (string) $Lar_Fila['paises'])) as $Lv_Pais): ?>
                                <span class="sc"><?= e($Lv_Pais) ?></span>
                            <?php endforeach; ?>
                            <?php if (($Lar_Fila['paises'] ?? '') === ''): ?>
                                <span class="umail">Sin ámbito</span>
                            <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ((int) $Lar_Fila['is_activo'] === 1): ?>
                                <span class="st st-on"><span class="d"></span>Activo</span>
                            <?php else: ?>
                                <span class="st st-off"><span class="d"></span>Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="umail">
                            <?= $Lar_Fila['ultimo_ingreso_at'] !== null
                                ? e(date('d M Y, H:i', strtotime((string) $Lar_Fila['ultimo_ingreso_at'])))
                                : ($Lar_Fila['entra_oid'] === null ? 'Sin primer ingreso' : '—') ?>
                        </td>
                        <td class="text-end">
                            <div class="acciones">
                                <a class="btn btn-ghost btn-sm"
                                   href="/admin/usuarios.php?editar=<?= (int) $Lar_Fila['usuario_id'] ?>"
                                   aria-label="Editar a <?= e($Lar_Fila['nombre']) ?>">
                                    <i class="bi bi-pencil me-1"></i>Editar
                                </a>
                                <?php if (!$Lb_EsPropio): ?>
                                    <form method="post" action="/admin/usuarios.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="usuario_id" value="<?= (int) $Lar_Fila['usuario_id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"
                                                aria-label="<?= (int) $Lar_Fila['is_activo'] === 1 ? 'Desactivar' : 'Activar' ?> a <?= e($Lar_Fila['nombre']) ?>">
                                            <?= (int) $Lar_Fila['is_activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($Gar_Usuarios === []): ?>
                    <tr><td colspan="6" class="vacio">
                        <i class="bi bi-people"></i>
                        Todavía no hay usuarios autorizados.
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div></div>

    <div class="col-xl-4"><div class="card-anc h-100">
        <div class="ch"><div>
            <h2><?= $Gi_Editando > 0 ? 'Editar usuario' : 'Autorizar usuario' ?></h2>
            <div class="sub"><?= $Gi_Editando > 0 ? e((string) $Gar_Edicion['correo']) : 'Alta individual' ?></div>
        </div></div>
        <div class="cb" style="padding:16px 18px">
            <form method="post" action="/admin/usuarios.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="<?= $Gi_Editando > 0 ? 'editar' : 'autorizar' ?>">
                <?php if ($Gi_Editando > 0): ?>
                    <input type="hidden" name="usuario_id" value="<?= $Gi_Editando ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="correo">Correo corporativo (Entra ID)</label>
                    <input class="form-control" type="email" id="correo" name="correo"
                           value="<?= $Gi_Editando > 0 ? e((string) $Gar_Edicion['correo']) : '' ?>"
                           placeholder="nombre.apellido@grupoanc.com" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="nombre">Nombre completo</label>
                    <input class="form-control" type="text" id="nombre" name="nombre"
                           value="<?= $Gi_Editando > 0 ? e((string) $Gar_Edicion['nombre']) : '' ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="puesto">Puesto <span class="sample-note">(opcional)</span></label>
                    <input class="form-control" type="text" id="puesto" name="puesto"
                           value="<?= $Gi_Editando > 0 ? e((string) ($Gar_Edicion['puesto'] ?? '')) : '' ?>">
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label">Roles funcionales</legend>
                    <div class="chk-list">
                        <?php foreach ($Gar_Roles as $Lar_Rol): ?>
                            <label class="chk">
                                <input type="checkbox" name="roles[]" value="<?= (int) $Lar_Rol['rol_id'] ?>"
                                       <?= in_array((int) $Lar_Rol['rol_id'], $Gar_EdicionRoles, true) ? 'checked' : '' ?>>
                                <span>
                                    <?= e($Lar_Rol['nombre']) ?>
                                    <small class="umail d-block"><?= e((string) ($Lar_Rol['descripcion'] ?? '')) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="sample-note mt-1">Se puede asignar más de un rol; los permisos se suman.</div>
                </fieldset>

                <fieldset class="mb-3">
                    <legend class="form-label">Ámbito geográfico</legend>
                    <div class="chk-list">
                        <?php
                        usuarios_casillas_ambito($Gar_Regiones, 'region',  'Región (todos los países)', $Gar_EdicionAmbitos);
                        usuarios_casillas_ambito($Gar_Paises,   'pais',    'País',                      $Gar_EdicionAmbitos);
                        usuarios_casillas_ambito($Gar_Zonas,    'zona',    'Zona',                      $Gar_EdicionAmbitos);
                        usuarios_casillas_ambito($Gar_Oficinas, 'oficina', 'Oficina',                   $Gar_EdicionAmbitos);
                        ?>
                    </div>
                    <div class="sample-note mt-1">
                        Se pueden combinar niveles. La región concede todos sus países activos.
                    </div>
                </fieldset>

                <div class="d-flex gap-2">
                    <button class="btn btn-anc flex-grow-1" type="submit">
                        <i class="bi bi-check2 me-1"></i><?= $Gi_Editando > 0 ? 'Guardar cambios' : 'Guardar autorización' ?>
                    </button>
                    <?php if ($Gi_Editando > 0): ?>
                        <a class="btn btn-ghost" href="/admin/usuarios.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($Gi_Editando > 0): ?>
                <div class="panel-sep mt-3 pt-3">
                    <div class="sample-note mb-2">
                        <i class="bi bi-person-badge me-1"></i>
                        Identidad de Entra:
                        <?= $Gar_Edicion['entra_oid'] !== null ? 'enlazada' : 'pendiente del primer ingreso' ?>.
                    </div>

                    <?php if ($Gar_Edicion['entra_oid'] !== null
                              && (int) $Gar_Edicion['usuario_id'] !== (int) $Gar_Usuario['usuario_id']): ?>
                        <form method="post" action="/admin/usuarios.php"
                              onsubmit="return confirm('¿Desvincular la identidad de Entra de este usuario?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="accion" value="desvincular">
                            <input type="hidden" name="usuario_id" value="<?= $Gi_Editando ?>">
                            <button class="btn btn-ghost btn-sm w-100" type="submit">
                                <i class="bi bi-link-45deg me-1"></i>Desvincular identidad de Entra
                            </button>
                        </form>
                        <div class="sample-note mt-1">
                            Se vuelve a enlazar por correo en el próximo ingreso. Sirve cuando la
                            cuenta corporativa se recreó.
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="sample-note mt-3">
                    <i class="bi bi-upload me-1"></i>
                    El alta masiva se hace por carga directa a <code>fx.usuario</code>.
                </div>
            <?php endif; ?>
        </div>
    </div></div>
</div>

<?php
vista_pie();
