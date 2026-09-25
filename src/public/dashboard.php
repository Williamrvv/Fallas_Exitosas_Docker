<?php
/**
 * Fallas Exitosas · Panel ejecutivo.
 *
 * Propósito : Punto de entrada tras el ingreso. Muestra el alcance real del
 *             usuario y el estado de la plataforma.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial. Los indicadores operativos se
 *             incorporan cuando el flujo de análisis alimente la base.
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

<div class="row g-3">
    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-blue"><i class="bi bi-geo-alt-fill"></i></div>
        <div>
            <div class="mini-val tnum"><?= count($Gar_Usuario['paises']) ?></div>
            <div class="mini-lab">Países en tu ámbito</div>
        </div>
    </div></div></div>

    <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
        <div class="mini-ic tint-purple"><i class="bi bi-shield-lock-fill"></i></div>
        <div>
            <div class="mini-val" style="font-size:16px;padding-top:4px">
                <?= e($Gar_Usuario['roles'][0]['nombre'] ?? 'Sin rol') ?>
            </div>
            <div class="mini-lab">Tu rol</div>
        </div>
    </div></div></div>

    <?php if (auth_puede_ver('usuarios')): ?>
        <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
            <div class="mini-ic tint-green"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="mini-val tnum"><?= (int) $Gar_Conteo['activos'] ?></div>
                <div class="mini-lab">Usuarios activos</div>
            </div>
        </div></div></div>

        <div class="col-6 col-xl-3"><div class="card-anc"><div class="mini">
            <div class="mini-ic tint-cel"><i class="bi bi-diagram-3-fill"></i></div>
            <div>
                <div class="mini-val tnum"><?= $Gi_Roles ?></div>
                <div class="mini-lab">Roles definidos</div>
            </div>
        </div></div></div>
    <?php endif; ?>
</div>

<div class="callout mt-3">
    <div class="ic"><i class="bi bi-shield-check"></i></div>
    <div class="t">
        <b>Ingresaste como <?= e($Gar_Usuario['nombre']) ?>.</b>
        Tu acceso se limita a
        <?= count($Gar_Usuario['paises']) > 0
            ? e(implode(' · ', $Gar_Usuario['paises']))
            : 'ningún país todavía' ?>.
        Toda consulta y reporte respeta ese ámbito.
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12"><div class="card-anc">
        <div class="ch">
            <div>
                <h2>Indicadores de calidad de servicio</h2>
                <div class="sub">SQI, penetración, causas y alertas</div>
            </div>
        </div>
        <div class="cb">
            <div class="vacio">
                <i class="bi bi-bar-chart-line"></i>
                Todavía no hay datos analizados.<br>
                Los indicadores aparecen cuando el flujo de análisis empiece a cargar
                los comentarios de TSD en esta base.
            </div>
        </div>
    </div></div>
</div>

<?php
vista_pie();
