<?php
/**
 * Fallas Exitosas · Administración de usuarios, roles y ámbito (PB-19).
 *
 * Propósito : Autorizar personas, asignarles rol y ámbito geográfico, y
 *             activarlas o desactivarlas sin perder el histórico.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 *
 * Seguridad : Requiere permiso completo sobre `usuarios`. La verificación es
 *             de servidor; el menú solo refleja el resultado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$Gar_Usuario = sesion_exigir();
auth_exigir('usuarios', true);

$Gv_Mensaje = '';
$Gv_Tipo    = 'info';

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
            || preg_match('/\\A(region|pais|zona|oficina):([1-9][0-9]*)\\z/', $Lm_Valor, $Lar_Coincidencia) !== 1) {
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
            if ($Lv_Accion === 'autorizar') {
                $Lv_Correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
                $Lv_Nombre = trim((string) ($_POST['nombre'] ?? ''));
                $Li_RolId  = (int) ($_POST['rol_id'] ?? 0);
                $Lv_Nivel  = (string) ($_POST['nivel_ambito'] ?? '');
                $Li_Ambito = (int) ($_POST['ambito_id'] ?? 0);

                if (!filter_var($Lv_Correo, FILTER_VALIDATE_EMAIL) || $Lv_Nombre === ''
                    || $Li_RolId === 0 || !in_array($Lv_Nivel, ['region', 'pais', 'zona', 'oficina'], true)
                    || $Li_Ambito === 0) {
                    throw new InvalidArgumentException('Revisá los datos: correo, nombre, rol y ámbito son obligatorios.');
                }

                if (db_fila('SELECT usuario_id FROM fx.usuario WHERE LOWER(correo) = :correo', [':correo' => $Lv_Correo]) !== null) {
                    throw new InvalidArgumentException('Ese correo ya está autorizado en la plataforma.');
                }

                $Lo_Conexion = db_conexion();
                $Lo_Conexion->beginTransaction();

                db_ejecutar(
                    'INSERT INTO fx.usuario (correo, nombre, is_activo, created_by)
                     VALUES (:correo, :nombre, 1, :actor)',
                    [':correo' => $Lv_Correo, ':nombre' => $Lv_Nombre, ':actor' => $Gar_Usuario['correo']]
                );

                $Li_NuevoId = (int) $Lo_Conexion->lastInsertId();

                db_ejecutar(
                    'INSERT INTO fx.usuario_rol (usuario_id, rol_id, created_by) VALUES (:usuario_id, :rol_id, :actor)',
                    [':usuario_id' => $Li_NuevoId, ':rol_id' => $Li_RolId, ':actor' => $Gar_Usuario['correo']]
                );

                // Solo la columna del nivel declarado lleva valor (lo exige la restricción de coherencia).
                $Lar_Columnas = ['region' => 'region_id', 'pais' => 'pais_id', 'zona' => 'zona_id', 'oficina' => 'oficina_id'];

                db_ejecutar(
                    sprintf(
                        'INSERT INTO fx.usuario_ambito (usuario_id, nivel_ambito, %s, created_by)
                         VALUES (:usuario_id, :nivel, :ambito_id, :actor)',
                        $Lar_Columnas[$Lv_Nivel]   // valor de lista blanca, nunca entrada libre
                    ),
                    [
                        ':usuario_id' => $Li_NuevoId,
                        ':nivel'      => $Lv_Nivel,
                        ':ambito_id'  => $Li_Ambito,
                        ':actor'      => $Gar_Usuario['correo'],
                    ]
                );

                $Lo_Conexion->commit();

                audit_registrar('alta', [
                    'usuario_id' => (int) $Gar_Usuario['usuario_id'],
                    'entidad'    => 'usuario',
                    'entidad_id' => (string) $Li_NuevoId,
                    'valor_nuevo'=> $Lv_Correo . ' · rol ' . $Li_RolId . ' · ' . $Lv_Nivel,
                    'detalle'    => 'Usuario autorizado.',
                ]);

                $Gv_Mensaje = 'Usuario autorizado. Podrá ingresar con su cuenta corporativa.';
                $Gv_Tipo    = 'info';
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
         u.is_activo                                    AS is_activo,
         u.entra_oid                                    AS entra_oid,
         u.ultimo_ingreso_at                            AS ultimo_ingreso_at,
         STUFF((SELECT ', ' + r2.nombre
                FROM fx.usuario_rol AS ur2
                    INNER JOIN fx.rol AS r2 ON r2.rol_id = ur2.rol_id
                WHERE ur2.usuario_id = u.usuario_id
                ORDER BY r2.orden
                FOR XML PATH('')), 1, 2, '')            AS roles,
         STUFF((SELECT ', ' + vp.pais_codigo
                FROM fx.v_usuario_pais AS vp
                WHERE vp.usuario_id = u.usuario_id
                ORDER BY vp.pais_codigo
                FOR XML PATH('')), 1, 2, '')            AS paises
     FROM fx.usuario AS u
     ORDER BY u.is_activo DESC, u.nombre"
);

$Gar_Roles    = db_filas('SELECT rol_id, codigo, nombre FROM fx.rol WHERE is_activo = 1 ORDER BY orden');
$Gar_Regiones = db_filas('SELECT region_id AS id, nombre FROM fx.region WHERE is_activo = 1 ORDER BY nombre');
$Gar_Paises   = db_filas('SELECT pais_id   AS id, nombre, codigo FROM fx.pais WHERE is_activo = 1 ORDER BY nombre');
$Gar_Zonas    = db_filas('SELECT zona_id   AS id, nombre FROM fx.zona WHERE is_activo = 1 ORDER BY nombre');
$Gar_Oficinas = db_filas('SELECT oficina_id AS id, nombre FROM fx.oficina WHERE is_activo = 1 ORDER BY nombre');

$Gi_Activos = 0;
foreach ($Gar_Usuarios as $Lar_Fila) {
    $Gi_Activos += (int) $Lar_Fila['is_activo'];
}

vista_encabezado([
    'titulo'    => 'Usuarios y roles',
    'subtitulo' => 'Gestión de acceso por rol y ámbito geográfico · autorización con mínimo privilegio',
    'activo'    => 'usuarios',
    'usuario'   => $Gar_Usuario,
]);
?>

<?php if ($Gv_Mensaje !== ''): ?>
    <div class="alerta alerta-<?= e($Gv_Tipo) ?>">
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
        </div>
        <div class="cb">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Usuario</th><th>Rol</th><th>Ámbito</th>
                        <th>Estado</th><th>Último ingreso</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($Gar_Usuarios as $Lar_Fila): ?>
                    <tr>
                        <td>
                            <div class="uname"><?= e($Lar_Fila['nombre']) ?></div>
                            <div class="umail"><?= e($Lar_Fila['correo']) ?></div>
                        </td>
                        <td><span class="role-tag r-consulta"><?= e($Lar_Fila['roles'] ?? 'Sin rol') ?></span></td>
                        <td>
                            <span class="scope">
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
                            <?php if ((int) $Lar_Fila['usuario_id'] !== (int) $Gar_Usuario['usuario_id']): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="usuario_id" value="<?= (int) $Lar_Fila['usuario_id'] ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= (int) $Lar_Fila['is_activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div></div>

    <div class="col-xl-4"><div class="card-anc h-100">
        <div class="ch"><div><h2>Autorizar usuario</h2><div class="sub">Alta individual</div></div></div>
        <div class="cb" style="padding:16px 18px">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="accion" value="autorizar">

                <div class="mb-3">
                    <label class="form-label" for="correo">Correo corporativo (Entra ID)</label>
                    <input class="form-control" type="email" id="correo" name="correo"
                           placeholder="nombre.apellido@grupoanc.com" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="nombre">Nombre completo</label>
                    <input class="form-control" type="text" id="nombre" name="nombre" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="rol_id">Rol funcional</label>
                    <select class="form-select" id="rol_id" name="rol_id" required>
                        <?php foreach ($Gar_Roles as $Lar_Rol): ?>
                            <option value="<?= (int) $Lar_Rol['rol_id'] ?>"><?= e($Lar_Rol['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="nivel_ambito">Nivel de ámbito</label>
                    <select class="form-select" id="nivel_ambito" name="nivel_ambito" required>
                        <option value="region">Región (todos los países)</option>
                        <option value="pais" selected>País</option>
                        <?php if (count($Gar_Zonas) > 0): ?><option value="zona">Zona</option><?php endif; ?>
                        <?php if (count($Gar_Oficinas) > 0): ?><option value="oficina">Oficina</option><?php endif; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="ambito_id">Ámbito asignado</label>
                    <select class="form-select" id="ambito_id" name="ambito_id" required>
                        <optgroup label="Región">
                            <?php foreach ($Gar_Regiones as $Lar_Item): ?>
                                <option value="<?= (int) $Lar_Item['id'] ?>" data-nivel="region"><?= e($Lar_Item['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="País">
                            <?php foreach ($Gar_Paises as $Lar_Item): ?>
                                <option value="<?= (int) $Lar_Item['id'] ?>" data-nivel="pais"><?= e($Lar_Item['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php if (count($Gar_Zonas) > 0): ?>
                        <optgroup label="Zona">
                            <?php foreach ($Gar_Zonas as $Lar_Item): ?>
                                <option value="<?= (int) $Lar_Item['id'] ?>" data-nivel="zona"><?= e($Lar_Item['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (count($Gar_Oficinas) > 0): ?>
                        <optgroup label="Oficina">
                            <?php foreach ($Gar_Oficinas as $Lar_Item): ?>
                                <option value="<?= (int) $Lar_Item['id'] ?>" data-nivel="oficina"><?= e($Lar_Item['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <div class="sample-note mt-1">
                        Elegí un ámbito del mismo nivel seleccionado arriba.
                    </div>
                </div>

                <button class="btn btn-anc w-100" type="submit">
                    <i class="bi bi-check2 me-1"></i>Guardar autorización
                </button>
            </form>

            <div class="sample-note mt-3">
                <i class="bi bi-upload me-1"></i>
                El alta masiva se hace por carga directa a <code>fx.usuario</code>.
            </div>
        </div>
    </div></div>
</div>

<?php
vista_pie();
