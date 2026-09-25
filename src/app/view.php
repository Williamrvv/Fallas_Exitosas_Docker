<?php
/**
 * Fallas Exitosas · Capa de presentación.
 *
 * Propósito : Estructura común de las páginas (barra superior, menú lateral y
 *             pie), con el menú filtrado según los permisos del usuario.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-24
 * Bitácora  : 2026-09-24 Versión inicial.
 */

declare(strict_types=1);

/** Escapa texto para HTML. */
function e(?string $Pv_Texto_i): string
{
    return htmlspecialchars((string) $Pv_Texto_i, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Iniciales para el avatar. */
function vista_iniciales(string $Pv_Nombre_i): string
{
    $Lar_Partes = preg_split('/\s+/', trim($Pv_Nombre_i)) ?: [];
    $Lv_Iniciales = '';

    foreach (array_slice($Lar_Partes, 0, 2) as $Lv_Parte) {
        $Lv_Iniciales .= mb_strtoupper(mb_substr($Lv_Parte, 0, 1));
    }

    return $Lv_Iniciales !== '' ? $Lv_Iniciales : '·';
}

/** Definición del menú. Cada entrada declara el permiso que la habilita. */
function vista_menu(): array
{
    return [
        ['clave' => 'dashboard',      'texto' => 'Panel ejecutivo',       'icono' => 'speedometer2',      'url' => '/dashboard.php',       'permiso' => 'dashboard'],
        ['clave' => 'casos',          'texto' => 'Casos y seguimiento',   'icono' => 'clipboard2-check',  'url' => '#',                    'permiso' => 'casos'],
        ['clave' => 'comentarios',    'texto' => 'Comentarios IA',        'icono' => 'chat-square-text',  'url' => '#',                    'permiso' => 'comentarios_ia'],
        ['clave' => 'alertas',        'texto' => 'Alertas',               'icono' => 'bell',              'url' => '#',                    'permiso' => 'alertas'],
        ['clave' => 'administracion', 'texto' => 'Administración',        'icono' => '',                  'url' => '',                     'permiso' => ''],
        ['clave' => 'usuarios',       'texto' => 'Usuarios y roles',      'icono' => 'people',            'url' => '/admin/usuarios.php',  'permiso' => 'usuarios'],
        ['clave' => 'catalogos',      'texto' => 'Catálogos y reglas',    'icono' => 'tags',              'url' => '#',                    'permiso' => 'catalogos'],
        ['clave' => 'auditoria',      'texto' => 'Auditoría',             'icono' => 'journal-text',      'url' => '#',                    'permiso' => 'auditoria'],
    ];
}

/**
 * Imprime el encabezado completo con barra superior y menú lateral.
 *
 * @param array $Par_Pagina_i titulo, subtitulo, activo, usuario.
 */
function vista_encabezado(array $Par_Pagina_i): void
{
    $Lar_Usuario = $Par_Pagina_i['usuario'];
    $Lv_Activo   = (string) ($Par_Pagina_i['activo'] ?? '');
    $Lv_Rol      = $Lar_Usuario['roles'][0]['nombre'] ?? 'Sin rol';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · <?= e($Par_Pagina_i['titulo']) ?></title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="/assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/styles.css" rel="stylesheet">
<script src="/assets/app.js" defer></script>
</head>
<body>

<div class="app-nav">
    <img class="logo" src="/assets/logos/anc-logo-claro.png" alt="Grupo ANC">
    <div class="app-title">Fallas Exitosas<small>Calidad de Servicio · Regional</small></div>

    <div class="region-pills ms-2">
        <span class="rpill active"><i class="bi bi-globe-americas me-1"></i><?= count($Lar_Usuario['paises']) ?> países</span>
        <?php foreach ($Lar_Usuario['paises'] as $Lv_Pais): ?>
            <span class="rpill"><?= e($Lv_Pais) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="ms-auto d-flex align-items-center gap-3">
        <div class="user-chip">
            <div class="avatar"><?= e(vista_iniciales($Lar_Usuario['nombre'])) ?></div>
            <div>
                <div class="u-name"><?= e($Lar_Usuario['nombre']) ?></div>
                <div class="u-role"><?= e($Lv_Rol) ?></div>
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/logout.php" title="Cerrar sesión">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="shell">
    <aside class="sidebar">
        <?php foreach (vista_menu() as $Lar_Item): ?>
            <?php if ($Lar_Item['permiso'] === ''): ?>
                <?php if (auth_puede_ver('usuarios') || auth_puede_ver('catalogos') || auth_puede_ver('auditoria')): ?>
                    <div class="nav-label"><?= e($Lar_Item['texto']) ?></div>
                <?php endif; ?>
            <?php elseif (auth_puede_ver($Lar_Item['permiso'])): ?>
                <a class="nav-item <?= $Lv_Activo === $Lar_Item['clave'] ? 'active' : '' ?>"
                   href="<?= e($Lar_Item['url']) ?>">
                    <i class="bi bi-<?= e($Lar_Item['icono']) ?>"></i><?= e($Lar_Item['texto']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="side-foot">
            <div class="phase-tag"><b>Fase 1</b> · CR · GT · NI · PE</div>
            <div class="phase-tag mt-1" style="color:#9aa5b1">El Salvador — fase 2</div>
        </div>
    </aside>

    <main class="main">
        <div class="page-head">
            <div>
                <h1><?= e($Par_Pagina_i['titulo']) ?></h1>
                <?php if (!empty($Par_Pagina_i['subtitulo'])): ?>
                    <p><?= e($Par_Pagina_i['subtitulo']) ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($Par_Pagina_i['acciones'])): ?>
                <div class="d-flex gap-2"><?= $Par_Pagina_i['acciones'] ?></div>
            <?php endif; ?>
        </div>
    <?php
}

/** Cierra la página. */
function vista_pie(): void
{
    ?>
        <div class="d-flex justify-content-between align-items-center mt-4">
            <div class="sample-note">Sesión con cierre por inactividad a los 30 minutos.</div>
            <div class="sample-note">Fallas Exitosas · Grupo ANC</div>
        </div>
    </main>
</div>
</body>
</html>
    <?php
}
