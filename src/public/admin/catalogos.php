<?php
/**
 * Fallas Exitosas · Catálogos y reglas (PB-20).
 *
 * Propósito : Mantener categorías, tipos de experiencia, estados del caso,
 *             palabras y frases, destinatarios y parámetros por país sin
 *             cambiar código.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-28
 * Bitácora  : 2026-09-28 Versión inicial.
 *             2026-09-28 Cada catálogo es una sección de Administración con su
 *                        propio título; la navegación pasa al menú lateral.
 *
 * Seguridad : Ver requiere permiso sobre `catalogos`; crear, editar,
 *             habilitar o deshabilitar requiere permiso completo. La
 *             verificación es de servidor. Nada se borra: se deshabilita.
 *             Destinatarios, palabras y parámetros se limitan a los países
 *             del usuario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

$Gar_Usuario = sesion_exigir();
auth_exigir('catalogos');

$Gb_PuedeEditar = auth_puede_editar('catalogos');
$Gar_Entidades  = mant_entidades($Gar_Usuario);

/* --------------------------------------------------------------------------
   Sección, filtro y búsqueda (lista blanca: nunca se usa el texto tal cual)
   -------------------------------------------------------------------------- */
$Gv_Seccion = (string) ($_POST['m'] ?? $_GET['m'] ?? 'categorias');
$Gv_Seccion = isset($Gar_Entidades[$Gv_Seccion]) ? $Gv_Seccion : 'categorias';
$Gar_Spec   = $Gar_Entidades[$Gv_Seccion];

$Gv_Estado = (string) ($_GET['estado'] ?? 'activos');
$Gv_Estado = in_array($Gv_Estado, ['activos', 'deshabilitados', 'todos'], true) ? $Gv_Estado : 'activos';

$Gv_Buscar = mb_substr(mant_texto((string) ($_GET['q'] ?? '')), 0, 100);

$Gi_Editando = (int) ($_GET['editar'] ?? 0);

$Gv_Mensaje = '';
$Gv_Tipo    = 'info';

/** Fecha guardada en UTC mostrada en la hora de Costa Rica. */
function catalogos_fecha(string $Pv_Utc_i, string $Pv_Formato_i): string
{
    return date($Pv_Formato_i, (int) strtotime($Pv_Utc_i . ' UTC'));
}

/** Dirección de esta pantalla conservando sección, filtro y búsqueda. */
function catalogos_url(array $Par_Extra_i = []): string
{
    global $Gv_Seccion, $Gv_Estado, $Gv_Buscar;

    $Lar_Parametros = array_filter(
        ['m' => $Gv_Seccion, 'estado' => $Gv_Estado, 'q' => $Gv_Buscar] + $Par_Extra_i,
        static fn (mixed $Lm_Valor): bool => $Lm_Valor !== '' && $Lm_Valor !== null
    );

    return '/admin/catalogos.php?' . http_build_query($Lar_Parametros);
}

/* --------------------------------------------------------------------------
   Acciones
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Solo lectura para quien no tiene permiso completo: 403 y registro.
    auth_exigir('catalogos', true);

    if (!csrf_validar($_POST['csrf_token'] ?? null)) {
        $Gv_Mensaje = 'La solicitud perdió validez. Volvé a enviar el formulario.';
        $Gv_Tipo    = 'error';
    } else {
        $Lv_Accion   = (string) ($_POST['accion'] ?? '');
        $Li_Objetivo = (int) ($_POST['id'] ?? 0);

        try {
            if ($Lv_Accion === 'guardar') {
                // Si algo falla, el modal queda abierto en el mismo registro.
                $Gi_Editando = $Li_Objetivo;

                $Lar_Resultado = mant_guardar($Gar_Spec, $Gar_Usuario, $Li_Objetivo, $_POST);
                $Gi_Editando   = 0;
                $Gv_Mensaje    = $Lar_Resultado['mensaje'];
                $Gv_Tipo       = $Lar_Resultado['tipo'];
            } elseif ($Lv_Accion === 'cambiar_estado') {
                $Lar_Resultado = mant_cambiar_estado($Gar_Spec, $Gar_Usuario, $Li_Objetivo);
                $Gv_Mensaje    = $Lar_Resultado['mensaje'];
                $Gv_Tipo       = $Lar_Resultado['tipo'];
            }
        } catch (InvalidArgumentException $Lo_Error) {
            $Gv_Mensaje = $Lo_Error->getMessage();
            $Gv_Tipo    = 'error';
        } catch (Throwable $Lo_Error) {
            if (mant_es_duplicado($Lo_Error)) {
                // Otra persona guardó lo mismo al mismo tiempo.
                $Gv_Mensaje = 'Ya existe un registro con esos datos. Actualizá la lista y revisalo.';
            } else {
                error_log('Fallas Exitosas · catálogos (' . $Gv_Seccion . '): ' . $Lo_Error->getMessage());
                $Gv_Mensaje = 'No se pudo completar la operación. Revisá el log del contenedor.';
            }
            $Gv_Tipo = 'error';
        }
    }
}

/* --------------------------------------------------------------------------
   Datos de pantalla
   -------------------------------------------------------------------------- */
$Gar_Filas   = mant_listar($Gar_Spec, $Gar_Usuario, $Gv_Estado, $Gv_Buscar);
$Gar_Conteos = mant_conteos($Gar_Spec, $Gar_Usuario);

$Gar_Edicion = $Gi_Editando > 0 ? mant_fila($Gar_Spec, $Gar_Usuario, $Gi_Editando) : null;
$Gi_Editando = $Gar_Edicion === null ? 0 : $Gi_Editando;

$Gv_AccionRechazada = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $Gv_Tipo === 'error'
    ? (string) ($_POST['accion'] ?? '')
    : '';

$Gb_ModalAbierto = $Gb_PuedeEditar
    && ($Gi_Editando > 0 || isset($_GET['nuevo']) || $Gv_AccionRechazada === 'guardar');

/* Valores del formulario: lo reenviado si hubo error, si no lo guardado.
   Un campo que no cambia después del alta siempre muestra lo guardado. */
$Gb_Reenvio     = $Gv_AccionRechazada === 'guardar';
$Gar_Formulario = [];

foreach ($Gar_Spec['campos'] as $Lv_Campo => $Lar_Campo) {
    $Lb_Fijo = $Gar_Edicion !== null && !empty($Lar_Campo['solo_alta']);

    if ($Gb_Reenvio && !$Lb_Fijo) {
        $Lm_Valor = $_POST[$Lv_Campo] ?? ($Lar_Campo['tipo'] === 'casilla' ? '0' : '');
    } elseif ($Gar_Edicion !== null) {
        $Lm_Valor = $Gar_Edicion[$Lv_Campo] ?? '';
    } else {
        $Lm_Valor = $Lar_Campo['defecto'] ?? '';
    }

    $Gar_Formulario[$Lv_Campo] = is_scalar($Lm_Valor) ? (string) $Lm_Valor : '';
}

/**
 * Imprime un campo del formulario según su definición.
 */
function catalogos_campo(string $Pv_Campo_i, array $Par_Campo_i, string $Pv_Valor_i, ?array $Par_Actual_i): void
{
    $Lv_Id       = 'campo-' . $Pv_Campo_i;
    $Lb_Bloqueado = $Par_Actual_i !== null && !empty($Par_Campo_i['solo_alta']);
    $Lv_Tipo     = $Par_Campo_i['tipo'];
    $Lv_Ancho    = in_array($Lv_Tipo, ['texto_largo', 'casilla'], true) ? ' md:col-span-2' : '';
    $Lv_Requerido = !empty($Par_Campo_i['requerido']) ? ' required' : '';
    $Lv_Max       = isset($Par_Campo_i['max']) ? ' maxlength="' . (int) $Par_Campo_i['max'] . '"' : '';
    ?>
    <div class="<?= e(trim($Lv_Ancho)) ?>">
        <?php if ($Lv_Tipo === 'casilla'): ?>
            <label class="fx-casilla">
                <input type="checkbox" name="<?= e($Pv_Campo_i) ?>" value="1" <?= $Pv_Valor_i === '1' ? 'checked' : '' ?>>
                <span>
                    <?= e($Par_Campo_i['etiqueta']) ?>
                    <?php if (!empty($Par_Campo_i['ayuda'])): ?>
                        <small class="fx-secundario block font-normal mt-0.5"><?= e($Par_Campo_i['ayuda']) ?></small>
                    <?php endif; ?>
                </span>
            </label>
        <?php else: ?>
            <label class="fx-etiqueta" for="<?= e($Lv_Id) ?>">
                <?= e($Par_Campo_i['etiqueta']) ?>
                <?php if (empty($Par_Campo_i['requerido'])): ?><span class="fx-nota">(opcional)</span><?php endif; ?>
            </label>

            <?php if ($Lv_Tipo === 'seleccion'): ?>
                <select class="fx-campo" id="<?= e($Lv_Id) ?>" name="<?= e($Pv_Campo_i) ?>"<?= $Lv_Requerido ?><?= $Lb_Bloqueado ? ' disabled' : '' ?>>
                    <?php if (isset($Par_Campo_i['vacio']) || empty($Par_Campo_i['requerido'])): ?>
                        <option value=""><?= e($Par_Campo_i['vacio'] ?? '— Elegí una opción —') ?></option>
                    <?php else: ?>
                        <option value="" disabled <?= $Pv_Valor_i === '' ? 'selected' : '' ?>>— Elegí una opción —</option>
                    <?php endif; ?>
                    <?php foreach (mant_opciones($Par_Campo_i, $Par_Actual_i) as $Lm_Clave => $Lm_Opcion): ?>
                        <?php if (is_array($Lm_Opcion)): ?>
                            <optgroup label="<?= e((string) $Lm_Clave) ?>">
                                <?php foreach ($Lm_Opcion as $Lm_Sub => $Lv_Texto): ?>
                                    <option value="<?= e((string) $Lm_Sub) ?>" <?= (string) $Lm_Sub === $Pv_Valor_i ? 'selected' : '' ?>><?= e((string) $Lv_Texto) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php else: ?>
                            <option value="<?= e((string) $Lm_Clave) ?>" <?= (string) $Lm_Clave === $Pv_Valor_i ? 'selected' : '' ?>><?= e((string) $Lm_Opcion) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($Lv_Tipo === 'texto_largo'): ?>
                <textarea class="fx-campo" id="<?= e($Lv_Id) ?>" name="<?= e($Pv_Campo_i) ?>" rows="2"<?= $Lv_Max ?><?= $Lv_Requerido ?>><?= e($Pv_Valor_i) ?></textarea>
            <?php elseif ($Lv_Tipo === 'numero'): ?>
                <input class="fx-campo tnum" type="number" id="<?= e($Lv_Id) ?>" name="<?= e($Pv_Campo_i) ?>"
                       value="<?= e($Pv_Valor_i) ?>" min="<?= (int) $Par_Campo_i['min'] ?>" max="<?= (int) $Par_Campo_i['max'] ?>"<?= $Lv_Requerido ?>>
            <?php else: ?>
                <input class="fx-campo" type="<?= $Lv_Tipo === 'correo' ? 'email' : 'text' ?>" id="<?= e($Lv_Id) ?>"
                       name="<?= e($Pv_Campo_i) ?>" value="<?= e($Pv_Valor_i) ?>" autocomplete="off"<?= $Lv_Max ?><?= $Lv_Requerido ?>>
            <?php endif; ?>

            <?php if ($Lb_Bloqueado): ?>
                <p class="fx-nota mt-1.5 mb-0">No se cambia después de crearlo: deshabilitalo y creá otro.</p>
            <?php elseif (!empty($Par_Campo_i['ayuda'])): ?>
                <p class="fx-nota mt-1.5 mb-0"><?= e($Par_Campo_i['ayuda']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

vista_encabezado([
    'titulo'    => $Gar_Spec['titulo'],
    'subtitulo' => $Gar_Spec['descripcion'],
    'activo'    => $Gv_Seccion,
    'usuario'   => $Gar_Usuario,
    'acciones'  => $Gb_PuedeEditar
        ? '<a class="fx-btn fx-btn-primario" href="' . e(catalogos_url(['nuevo' => '1'])) . '">'
          . icono('agregar') . 'Agregar ' . e($Gar_Spec['singular']) . '</a>'
        : '',
]);
?>

<?php if ($Gv_Mensaje !== '' && !$Gb_ModalAbierto): ?>
    <div class="fx-alerta fx-alerta-<?= $Gv_Tipo === 'error' ? 'error' : ($Gv_Tipo === 'ok' ? 'ok' : 'info') ?> mb-4" role="alert">
        <?= icono($Gv_Tipo === 'error' ? 'atencion' : ($Gv_Tipo === 'ok' ? 'correcto' : 'informacion')) ?>
        <span><?= e($Gv_Mensaje) ?></span>
    </div>
<?php endif; ?>

<?php if (!$Gb_PuedeEditar): ?>
    <div class="fx-alerta fx-alerta-info mb-4">
        <?= icono('informacion') ?>
        <span>Tenés acceso de consulta. Para cambiar un catálogo pedíselo a un administrador.</span>
    </div>
<?php endif; ?>

<div class="fx-tarjeta">

    <div class="fx-tarjeta-cab">
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por estado">
            <?php foreach (['activos' => 'Activos', 'deshabilitados' => 'Deshabilitados', 'todos' => 'Todos'] as $Lv_Clave => $Lv_Texto): ?>
                <a class="fx-pastilla<?= $Lv_Clave === $Gv_Estado ? ' fx-pastilla-activa' : '' ?>"
                   href="<?= e(catalogos_url(['estado' => $Lv_Clave])) ?>"
                   <?= $Lv_Clave === $Gv_Estado ? 'aria-current="true"' : '' ?>>
                    <?= e($Lv_Texto) ?> · <span class="tnum"><?= (int) $Gar_Conteos[$Lv_Clave] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="/admin/catalogos.php" class="flex gap-2" role="search">
            <input type="hidden" name="m" value="<?= e($Gv_Seccion) ?>">
            <input type="hidden" name="estado" value="<?= e($Gv_Estado) ?>">
            <label class="sr-only" for="buscar">Buscar en <?= e($Gar_Spec['titulo']) ?></label>
            <input class="fx-campo" type="search" id="buscar" name="q" value="<?= e($Gv_Buscar) ?>"
                   maxlength="100" placeholder="Buscar">
            <button class="fx-btn fx-btn-neutro fx-btn-sm" type="submit">Buscar</button>
        </form>
    </div>

    <div class="fx-tabla-envoltura" tabindex="0" role="region" aria-label="<?= e($Gar_Spec['titulo']) ?>">
        <table class="fx-tabla">
            <caption class="sr-only"><?= e($Gar_Spec['titulo']) ?> y acciones disponibles.</caption>
            <thead>
                <tr>
                    <?php foreach ($Gar_Spec['columnas'] as $Lar_Columna): ?>
                        <th scope="col"><?= e($Lar_Columna['titulo']) ?></th>
                    <?php endforeach; ?>
                    <?php if ($Gb_PuedeEditar): ?>
                        <th scope="col" class="text-right">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($Gar_Filas as $Lar_Fila): ?>
                <?php
                $Lb_Activo = (int) $Lar_Fila['is_activo'] === 1;
                $Lv_Rotulo = (string) $Lar_Fila[$Gar_Spec['rotulo']];
                $Lb_EsPrincipal = $Gv_Seccion === 'categorias' && $Lar_Fila['padre_id'] === null;
                $Lv_Bloqueo = $Lb_Activo && isset($Gar_Spec['bloqueo_baja']) ? $Gar_Spec['bloqueo_baja']($Lar_Fila) : null;
                ?>
                <tr class="<?= trim(((int) $Lar_Fila['id'] === $Gi_Editando ? 'fx-fila-activa ' : '') . ($Lb_EsPrincipal ? 'fx-fila-grupo' : '')) ?>">
                    <?php foreach ($Gar_Spec['columnas'] as $Lar_Columna): ?>
                        <td><?= $Lar_Columna['html']($Lar_Fila) ?></td>
                    <?php endforeach; ?>
                    <?php if ($Gb_PuedeEditar): ?>
                        <td class="text-right">
                            <div class="inline-flex flex-wrap justify-end gap-1.5">
                                <a class="fx-btn fx-btn-neutro fx-btn-sm"
                                   href="<?= e(catalogos_url(['editar' => (string) $Lar_Fila['id']])) ?>"
                                   aria-label="Editar <?= e($Lv_Rotulo) ?>">
                                    <?= icono('editar') ?>Editar
                                </a>
                                <?php if ($Lv_Bloqueo !== null): ?>
                                    <span class="fx-secundario self-center"><?= e($Lv_Bloqueo) ?></span>
                                <?php else: ?>
                                <form method="post" action="<?= e(catalogos_url()) ?>" class="inline"
                                      <?= $Lb_Activo
                                          ? 'data-confirmar="¿Deshabilitar «' . e($Lv_Rotulo) . '»? Deja de usarse en la clasificación y las alertas; su historia se conserva.'
                                            . e(isset($Gar_Spec['aviso_baja']) ? $Gar_Spec['aviso_baja']($Lar_Fila) : '') . '"'
                                          : '' ?>>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="m" value="<?= e($Gv_Seccion) ?>">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="id" value="<?= (int) $Lar_Fila['id'] ?>">
                                    <button class="fx-btn fx-btn-neutro fx-btn-sm" type="submit"
                                            aria-label="<?= $Lb_Activo ? 'Deshabilitar' : 'Habilitar' ?> <?= e($Lv_Rotulo) ?>">
                                        <?= $Lb_Activo ? 'Deshabilitar' : 'Habilitar' ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($Gar_Filas === []): ?>
                <tr><td colspan="<?= count($Gar_Spec['columnas']) + ($Gb_PuedeEditar ? 1 : 0) ?>" class="fx-vacio">
                    <?= icono('catalogos') ?>
                    <?php if ($Gv_Buscar !== ''): ?>
                        Nada coincide con «<?= e($Gv_Buscar) ?>». Probá con otra palabra o con el filtro «Todos».
                    <?php elseif ($Gv_Estado === 'deshabilitados'): ?>
                        No hay registros deshabilitados en <?= e(mb_strtolower($Gar_Spec['titulo'])) ?>.
                    <?php else: ?>
                        Todavía no hay <?= e(mb_strtolower($Gar_Spec['titulo'])) ?>.
                        <?php if ($Gb_PuedeEditar): ?>Usá «Agregar <?= e($Gar_Spec['singular']) ?>» para crear el primero.<?php endif; ?>
                    <?php endif; ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="fx-aviso mt-4">
    <div class="fx-aviso-icono"><?= icono('escudo') ?></div>
    <p class="fx-aviso-texto m-0">
        <b>Nada se borra.</b>
        Deshabilitar saca el registro de la clasificación y de las alertas, pero conserva su historia.
        Si creás algo que ya existía deshabilitado, se vuelve a habilitar el original en lugar de duplicarlo.
        Mayúsculas y tildes no cuentan como diferencia. Cada cambio queda en la auditoría.
    </p>
</div>

<?php if ($Gb_ModalAbierto): ?>
    <?php $Lb_EsNuevo = $Gi_Editando === 0; ?>
    <dialog class="fx-modal" open aria-labelledby="modal-catalogo-titulo"
            data-modal data-cerrar="<?= e(catalogos_url()) ?>">
        <div class="fx-modal-cab">
            <div>
                <h2 id="modal-catalogo-titulo"><?= ($Lb_EsNuevo ? 'Agregar ' : 'Editar ') . e($Gar_Spec['singular']) ?></h2>
                <div class="sub"><?= $Lb_EsNuevo ? e($Gar_Spec['titulo']) : e((string) $Gar_Edicion[$Gar_Spec['rotulo']]) ?></div>
            </div>
            <a class="fx-modal-cerrar" href="<?= e(catalogos_url()) ?>" aria-label="Cerrar sin guardar">
                <?= icono('cerrar') ?>
            </a>
        </div>

        <div class="fx-modal-cuerpo">
            <?php if ($Gv_Mensaje !== ''): ?>
                <div class="fx-alerta fx-alerta-<?= $Gv_Tipo === 'error' ? 'error' : 'ok' ?> mb-4" role="alert">
                    <?= icono($Gv_Tipo === 'error' ? 'atencion' : 'correcto') ?>
                    <span><?= e($Gv_Mensaje) ?></span>
                </div>
            <?php endif; ?>

            <form id="form-catalogo" method="post" action="<?= e(catalogos_url()) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="m" value="<?= e($Gv_Seccion) ?>">
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= $Gi_Editando ?>">

                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach ($Gar_Spec['campos'] as $Lv_Campo => $Lar_Campo): ?>
                        <?php catalogos_campo($Lv_Campo, $Lar_Campo, $Gar_Formulario[$Lv_Campo], $Gar_Edicion); ?>
                    <?php endforeach; ?>
                </div>
            </form>

            <?php if (!$Lb_EsNuevo): ?>
                <div class="mt-6 pt-4 border-t border-linea-sutil">
                    <div class="fx-subtitulo-grupo mb-2">Detalle</div>
                    <dl class="grid grid-cols-[minmax(110px,auto)_1fr] gap-x-4 gap-y-2 m-0 text-xs">
                        <dt class="text-apagado font-normal">Estado</dt>
                        <dd class="m-0 text-tinta font-medium">
                            <?= (int) $Gar_Edicion['is_activo'] === 1
                                ? '<span class="fx-estado fx-estado-activo">Activo</span>'
                                : '<span class="fx-estado fx-estado-inactivo">Deshabilitado</span>' ?>
                        </dd>

                        <dt class="text-apagado font-normal">Creado</dt>
                        <dd class="m-0 text-tinta font-medium">
                            <?= e(catalogos_fecha((string) $Gar_Edicion['created_at'], 'd M Y')) ?>
                            <?php if (!empty($Gar_Edicion['created_by'])): ?>· <?= e((string) $Gar_Edicion['created_by']) ?><?php endif; ?>
                        </dd>

                        <?php if (!empty($Gar_Edicion['updated_at'])): ?>
                            <dt class="text-apagado font-normal">Última modificación</dt>
                            <dd class="m-0 text-tinta font-medium">
                                <?= e(catalogos_fecha((string) $Gar_Edicion['updated_at'], 'd M Y, H:i')) ?>
                                <?php if (!empty($Gar_Edicion['updated_by'])): ?>· <?= e((string) $Gar_Edicion['updated_by']) ?><?php endif; ?>
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            <?php endif; ?>
        </div>

        <div class="fx-modal-pie">
            <a class="fx-btn fx-btn-neutro" href="<?= e(catalogos_url()) ?>">Cancelar</a>
            <button class="fx-btn fx-btn-primario" type="submit" form="form-catalogo">
                <?= icono('confirmar') ?><?= $Lb_EsNuevo ? 'Crear' : 'Guardar cambios' ?>
            </button>
        </div>
    </dialog>
<?php endif; ?>

<?php
vista_pie();
