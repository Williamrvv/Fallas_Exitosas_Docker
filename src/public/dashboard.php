<?php
/**
 * Fallas Exitosas · Panel ejecutivo.
 *
 * Propósito : Punto de entrada tras el ingreso. Muestra el alcance real del
 *             usuario y el estado de la plataforma.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-24 Versión inicial. Los indicadores operativos se
 *             incorporan cuando el flujo de análisis alimente la base.
 *             2026-09-25 Rediseño visual: rejilla propia en lugar de la de
 *             Bootstrap e íconos SVG en línea. Las consultas no cambian.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$Gar_Usuario = sesion_exigir();
auth_exigir('dashboard');

$Gar_Conteo = db_fila(
    'SELECT
         SUM(CASE WHEN is_activo = 1 THEN 1 ELSE 0 END) AS activos,
         COUNT(*)                                        AS total
     FROM fx.usuario'
) ?? ['activos' => 0, 'total' => 0];

$Gi_Roles = (int) (db_fila('SELECT COUNT(*) AS total FROM fx.rol WHERE is_activo = 1')['total'] ?? 0);

vista_encabezado([
    'titulo'    => 'Panel ejecutivo',
    'subtitulo' => 'Alcance asignado a tu usuario y estado de la plataforma',
    'activo'    => 'dashboard',
    'usuario'   => $Gar_Usuario,
]);
?>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
    <div class="fx-kpi">
        <div class="fx-kpi-icono fx-tinte-azul"><?= icono('pais') ?></div>
        <div>
            <div class="fx-kpi-valor tnum text-tinta"><?= count($Gar_Usuario['paises']) ?></div>
            <div class="fx-kpi-texto">Países en tu ámbito</div>
        </div>
    </div>

    <div class="fx-kpi">
        <div class="fx-kpi-icono fx-tinte-morado"><?= icono('rol') ?></div>
        <div>
            <div class="text-sm font-semibold text-tinta leading-tight">
                <?= e($Gar_Usuario['roles'][0]['nombre'] ?? 'Sin rol') ?>
            </div>
            <div class="fx-kpi-texto">Tu rol</div>
        </div>
    </div>

    <?php if (auth_puede_ver('usuarios')): ?>
        <div class="fx-kpi">
            <div class="fx-kpi-icono fx-tinte-verde"><?= icono('usuarios') ?></div>
            <div>
                <div class="fx-kpi-valor tnum text-tinta"><?= (int) $Gar_Conteo['activos'] ?></div>
                <div class="fx-kpi-texto">Usuarios activos</div>
            </div>
        </div>

        <div class="fx-kpi">
            <div class="fx-kpi-icono fx-tinte-celeste"><?= icono('roles') ?></div>
            <div>
                <div class="fx-kpi-valor tnum text-tinta"><?= $Gi_Roles ?></div>
                <div class="fx-kpi-texto">Roles definidos</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="fx-aviso mt-4">
    <div class="fx-aviso-icono"><?= icono('escudo') ?></div>
    <p class="fx-aviso-texto m-0">
        <b>Ingresaste como <?= e($Gar_Usuario['nombre']) ?>.</b>
        Tu acceso se limita a
        <?= count($Gar_Usuario['paises']) > 0
            ? e(implode(' · ', $Gar_Usuario['paises']))
            : 'ningún país todavía' ?>.
        Toda consulta y reporte respeta ese ámbito.
    </p>
</div>

<div class="fx-tarjeta mt-4">
    <div class="fx-tarjeta-cab">
        <div>
            <h2>Indicadores de calidad de servicio</h2>
            <div class="sub">SQI, penetración, causas y alertas</div>
        </div>
    </div>
    <div class="fx-vacio">
        <?= icono('sin-datos') ?>
        Todavía no hay datos analizados.<br>
        Los indicadores aparecen cuando el flujo de análisis empiece a cargar
        los comentarios de TSD en esta base.
    </div>
</div>

<?php
vista_pie();
