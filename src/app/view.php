<?php
/**
 * Fallas Exitosas · Capa de presentación.
 *
 * Propósito : Estructura común de las páginas (barra superior, navegación por
 *             pestañas y pie), con el menú filtrado según los permisos del
 *             usuario.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-24 Versión inicial.
 *             2026-09-25 Rediseño visual: se retira Bootstrap 5 y la tipografía
 *                        de íconos; los estilos pasan a Tailwind compilado en
 *                        /assets/app.css y los íconos a SVG en línea. El menú
 *                        lateral se convierte en pestañas superiores. La lógica
 *                        de permisos y las firmas de las funciones no cambian.
 *             2026-09-28 «Catálogos y reglas» apunta a /admin/catalogos.php.
 *             2026-09-28 Administración pasa a ser una sola entrada del menú
 *                        principal, con menú lateral agrupado (usuarios,
 *                        catálogos, auditoría). El menú principal queda para el
 *                        trabajo diario.
 *             2026-09-29 Las secciones de Administración pasan a una barra
 *                        agrupada sobre el título, hecha solo con componentes
 *                        ya compilados (tarjeta, pastillas, rótulo de grupo).
 *             2026-09-29 Tema claro/oscuro: sigue al sistema operativo salvo
 *                        que la persona elija uno (se guarda en la sesión).
 *                        Selector en el encabezado y logo según el tema.
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

/**
 * Trazo SVG de cada ícono.
 *
 * Se dibujan en línea y no como tipografía: la CSP de Apache es
 * `default-src 'self'` y así no hay ningún recurso externo ni una fuente de
 * íconos que descargar. Todos comparten lienzo 24×24 y trazo de 1.75.
 */
function vista_icono_trazo(string $Pv_Nombre_i): string
{
    static $Lar_Trazos = [
        'panel'        => '<path d="M3 3h7v7H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 14h7v7H3z"/>',
        'casos'        => '<path d="M9 3h6v3H9zM8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path d="m9 13 2 2 4-4"/>',
        'comentarios'  => '<path d="M21 12a7 7 0 0 1-7 7H8l-5 3 1.5-4.5A7 7 0 0 1 10 5h4a7 7 0 0 1 7 7z"/><path d="M8.5 10h7M8.5 14h4"/>',
        'alertas'      => '<path d="M18 8a6 6 0 1 0-12 0c0 6-2 8-2 8h16s-2-2-2-8"/><path d="M10.5 20a2 2 0 0 0 3 0"/>',
        'usuarios'     => '<path d="M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="8" r="3.5"/><path d="M21 20v-1a4 4 0 0 0-3-3.87M16.5 5.13a3.5 3.5 0 0 1 0 6.74"/>',
        'catalogos'    => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h5l2 2.5h8A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/>',
        'auditoria'    => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H18a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6.5A1.5 1.5 0 0 1 5 19.5z"/><path d="M9 7.5h6M9 11h6M9 14.5h3"/>',
        'pais'         => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'rol'          => '<rect x="4.5" y="10.5" width="15" height="9.5" rx="2"/><path d="M8 10.5V7a4 4 0 1 1 8 0v3.5"/>',
        'roles'        => '<circle cx="12" cy="5" r="2.5"/><circle cx="5.5" cy="19" r="2.5"/><circle cx="18.5" cy="19" r="2.5"/><path d="M12 7.5v4M12 11.5H5.5v5M12 11.5h6.5v5"/>',
        'escudo'       => '<path d="M12 3l7 3v5.5c0 4.5-3 8-7 9.5-4-1.5-7-5-7-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
        'grafico'      => '<path d="M4 4v16h16"/><path d="m7.5 14 3.5-4 3 2.5L19 7"/>',
        'agregar'      => '<path d="M12 5v14M5 12h14"/>',
        'editar'       => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/>',
        'confirmar'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'cerrar'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'baja'         => '<path d="M15 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="8.5" cy="8" r="3.5"/><path d="M16 11h6"/>',
        'enlace'       => '<path d="M10 13a4 4 0 0 0 5.66 0l3-3A4 4 0 0 0 13 4.34l-1.5 1.5"/><path d="M14 11a4 4 0 0 0-5.66 0l-3 3A4 4 0 0 0 11 19.66l1.5-1.5"/>',
        'carga'        => '<path d="M12 16V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'volver'       => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'reintentar'   => '<path d="M20 11a8 8 0 1 0-.6 4"/><path d="M20 5v6h-6"/>',
        'codigo'       => '<path d="M5 9h14M5 15h14M10 4 8 20M16 4l-2 16"/>',
        'informacion'  => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'atencion'     => '<path d="M12 4.5 2.8 20h18.4z"/><path d="M12 10v4M12 17h.01"/>',
        'correcto'     => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'salir'        => '<path d="M9 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3"/><path d="M15 16.5 19.5 12 15 7.5M19.5 12H9"/>',
        'ajustes'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.5 13H3a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 3.5V3a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.4a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.4 1z"/>',
        'sin-datos'    => '<path d="M4 4v16h16"/><rect x="7.5" y="13" width="3" height="4"/><rect x="13.5" y="9" width="3" height="8"/>',
        'sol'          => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'luna'         => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'monitor'      => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'sin-usuarios' => '<path d="M16 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="8" r="3.5"/><path d="M17 8h5"/>',
    ];

    return $Lar_Trazos[$Pv_Nombre_i] ?? $Lar_Trazos['informacion'];
}

/**
 * Devuelve un ícono SVG en línea.
 *
 * Es decorativo salvo que se le pase un rótulo: sin rótulo queda oculto para
 * el lector de pantalla, porque el texto contiguo ya dice lo mismo.
 */
function icono(string $Pv_Nombre_i, ?string $Pv_Rotulo_i = null): string
{
    $Lv_Accesible = $Pv_Rotulo_i === null
        ? ' aria-hidden="true" focusable="false"'
        : ' role="img" aria-label="' . e($Pv_Rotulo_i) . '"';

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
        . ' stroke-linecap="round" stroke-linejoin="round"' . $Lv_Accesible . '>'
        . vista_icono_trazo($Pv_Nombre_i)
        . '</svg>';
}

/** Temas disponibles. «sistema» sigue al sistema operativo. */
const VISTA_TEMAS = [
    'sistema' => ['texto' => 'Automático', 'icono' => 'monitor'],
    'claro'   => ['texto' => 'Claro',      'icono' => 'sol'],
    'oscuro'  => ['texto' => 'Oscuro',     'icono' => 'luna'],
];

/** Tema elegido en esta sesión; sin elección, el del sistema operativo. */
function vista_tema(): string
{
    $Lv_Tema = (string) ($_SESSION['tema'] ?? 'sistema');

    return isset(VISTA_TEMAS[$Lv_Tema]) ? $Lv_Tema : 'sistema';
}

/**
 * Logo institucional según el tema: «claro» es para fondo claro y «oscuro»
 * para fondo oscuro. En automático decide el navegador.
 */
function vista_logo(): string
{
    $Lv_Claro  = '/assets/logos/anc-logo-claro.png';
    $Lv_Oscuro = '/assets/logos/anc-logo-oscuro.png';

    return match (vista_tema()) {
        'claro'  => '<img src="' . $Lv_Claro . '" alt="Grupo ANC">',
        'oscuro' => '<img src="' . $Lv_Oscuro . '" alt="Grupo ANC">',
        default  => '<picture><source srcset="' . $Lv_Oscuro . '" media="(prefers-color-scheme: dark)">'
            . '<img src="' . $Lv_Claro . '" alt="Grupo ANC"></picture>',
    };
}

/** Menú principal: el trabajo diario. Cada entrada declara el permiso que la habilita. */
function vista_menu(): array
{
    return [
        ['clave' => 'dashboard',   'texto' => 'Panel ejecutivo',     'icono' => 'panel',       'url' => '/dashboard.php', 'permiso' => 'dashboard'],
        ['clave' => 'casos',       'texto' => 'Casos y seguimiento', 'icono' => 'casos',       'url' => '#',              'permiso' => 'casos'],
        ['clave' => 'comentarios', 'texto' => 'Comentarios IA',      'icono' => 'comentarios', 'url' => '#',              'permiso' => 'comentarios_ia'],
        ['clave' => 'alertas',     'texto' => 'Alertas',             'icono' => 'alertas',     'url' => '#',              'permiso' => 'alertas'],
    ];
}

/**
 * Secciones de Administración, agrupadas por lo que la persona quiere hacer.
 * La clave coincide con el `activo` de cada página. Sin url = todavía no existe.
 */
function vista_menu_admin(): array
{
    return [
        'Acceso y trazabilidad' => [
            ['clave' => 'usuarios',      'texto' => 'Usuarios y roles',             'url' => '/admin/usuarios.php',                 'permiso' => 'usuarios'],
            ['clave' => 'auditoria',     'texto' => 'Auditoría',                    'url' => '',                                    'permiso' => 'auditoria'],
        ],
        'Clasificación' => [
            ['clave' => 'categorias',    'texto' => 'Categorías',                   'url' => '/admin/catalogos.php?m=categorias',    'permiso' => 'catalogos'],
            ['clave' => 'tipos',         'texto' => 'Tipos de experiencia',         'url' => '/admin/catalogos.php?m=tipos',         'permiso' => 'catalogos'],
            ['clave' => 'palabras',      'texto' => 'Palabras y frases',            'url' => '/admin/catalogos.php?m=palabras',      'permiso' => 'catalogos'],
        ],
        'Alertas y seguimiento' => [
            ['clave' => 'destinatarios', 'texto' => 'Responsables y destinatarios', 'url' => '/admin/catalogos.php?m=destinatarios', 'permiso' => 'catalogos'],
            ['clave' => 'estados',       'texto' => 'Estados del caso',             'url' => '/admin/catalogos.php?m=estados',       'permiso' => 'catalogos'],
            ['clave' => 'parametros',    'texto' => 'Parámetros por país',          'url' => '/admin/catalogos.php?m=parametros',    'permiso' => 'catalogos'],
        ],
    ];
}

/** Grupos de Administración que el usuario puede ver; los grupos vacíos no aparecen. */
function vista_admin_visible(): array
{
    $Lar_Visibles = [];

    foreach (vista_menu_admin() as $Lv_Grupo => $Lar_Items) {
        $Lar_Items = array_values(array_filter(
            $Lar_Items,
            static fn (array $Par_Item_i): bool => auth_puede_ver($Par_Item_i['permiso'])
        ));

        if ($Lar_Items !== []) {
            $Lar_Visibles[$Lv_Grupo] = $Lar_Items;
        }
    }

    return $Lar_Visibles;
}

/**
 * Dirección de la entrada «Administración»: la primera sección disponible.
 * Null si el usuario no ve ninguna sección que ya exista.
 */
function vista_admin_url(): ?string
{
    foreach (vista_admin_visible() as $Lar_Items) {
        foreach ($Lar_Items as $Lar_Item) {
            if ($Lar_Item['url'] !== '') {
                return $Lar_Item['url'];
            }
        }
    }

    return null;
}

/**
 * Imprime el encabezado completo con barra superior y pestañas.
 *
 * @param array $Par_Pagina_i titulo, subtitulo, activo, usuario, acciones.
 */
function vista_encabezado(array $Par_Pagina_i): void
{
    $Lar_Usuario = $Par_Pagina_i['usuario'];
    $Lv_Activo   = (string) ($Par_Pagina_i['activo'] ?? '');
    $Lv_Rol      = $Lar_Usuario['roles'][0]['nombre'] ?? 'Sin rol';
    $Lar_Menu    = vista_menu();
    $Lar_Admin   = vista_admin_visible();
    $Lv_AdminUrl = vista_admin_url();
    $Lar_Activo  = null;
    $Lv_GrupoAdmin = null;

    foreach ($Lar_Menu as $Lar_Item) {
        if ($Lar_Item['clave'] === $Lv_Activo) {
            $Lar_Activo = $Lar_Item;
            break;
        }
    }

    foreach ($Lar_Admin as $Lv_Grupo => $Lar_Items) {
        foreach ($Lar_Items as $Lar_Item) {
            if ($Lar_Item['clave'] === $Lv_Activo) {
                $Lv_GrupoAdmin = $Lv_Grupo;
                $Lar_Activo    = ['icono' => 'ajustes', 'texto' => 'Administración · ' . $Lar_Item['texto']];
            }
        }
    }

    $Lb_EnAdmin = $Lv_GrupoAdmin !== null;
    $Lv_Tema    = vista_tema();
    ?>
<!DOCTYPE html>
<html lang="es"<?= $Lv_Tema !== 'sistema' ? ' data-tema="' . e($Lv_Tema) . '"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fallas Exitosas · <?= e($Par_Pagina_i['titulo']) ?></title>
<link rel="icon" href="/assets/logos/favicon-192.png">
<link href="/assets/app.css" rel="stylesheet">
<script src="/assets/app.js" defer></script>
</head>
<body>

<a class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-lg focus:bg-anc-azul focus:px-4 focus:py-2 focus:text-white"
   href="#contenido">Saltar al contenido</a>

<header class="fx-encabezado">
    <div class="fx-encabezado-interno">
        <div class="fx-marca">
            <?= vista_logo() ?>
            <div class="fx-marca-texto">
                <div class="fx-marca-t1">Fallas Exitosas</div>
                <div class="fx-marca-t2">Calidad de Servicio · Regional</div>
            </div>
        </div>

        <div class="fx-encabezado-acciones">
            <div class="hidden lg:flex flex-wrap items-center gap-1.5"
                 role="list" aria-label="Países en tu ámbito">
                <?php foreach ($Lar_Usuario['paises'] as $Lv_Pais): ?>
                    <span class="fx-pastilla fx-pastilla-activa" role="listitem"><?= e($Lv_Pais) ?></span>
                <?php endforeach; ?>
                <?php if ($Lar_Usuario['paises'] === []): ?>
                    <span class="fx-pastilla" role="listitem">Sin país asignado</span>
                <?php endif; ?>
            </div>

            <div class="fx-usuario">
                <div class="fx-avatar"><?= e(vista_iniciales($Lar_Usuario['nombre'])) ?></div>
                <div class="fx-usuario-info">
                    <div class="fx-usuario-nombre"><?= e($Lar_Usuario['nombre']) ?></div>
                    <div class="fx-usuario-rol"><?= e($Lv_Rol) ?></div>
                </div>
            </div>

            <details class="fx-tema">
                <summary class="fx-btn fx-btn-neutro fx-btn-sm fx-btn-icono"
                         title="Tema: <?= e(VISTA_TEMAS[$Lv_Tema]['texto']) ?>">
                    <?= icono(VISTA_TEMAS[$Lv_Tema]['icono']) ?>
                    <span class="sr-only">Tema: <?= e(VISTA_TEMAS[$Lv_Tema]['texto']) ?>. Cambiar tema</span>
                </summary>
                <form class="fx-tema-panel" method="post" action="/tema.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="volver" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard.php')) ?>">
                    <?php foreach (VISTA_TEMAS as $Lv_Clave => $Lar_Opcion): ?>
                        <button class="fx-tema-opcion" type="submit" name="tema" value="<?= e($Lv_Clave) ?>"
                                aria-pressed="<?= $Lv_Clave === $Lv_Tema ? 'true' : 'false' ?>">
                            <?= icono($Lar_Opcion['icono']) ?><?= e($Lar_Opcion['texto']) ?>
                        </button>
                    <?php endforeach; ?>
                </form>
            </details>

            <a class="fx-btn fx-btn-neutro fx-btn-sm" href="/logout.php" title="Cerrar sesión">
                <?= icono('salir') ?><span class="fx-salir-texto">Cerrar sesión</span>
            </a>
        </div>
    </div>
</header>

<nav class="fx-pestanas" aria-label="Secciones">
    <div class="fx-pestanas-interno">
        <?php foreach ($Lar_Menu as $Lar_Item): ?>
            <?php if (auth_puede_ver($Lar_Item['permiso'])): ?>
                <a class="fx-pestana" href="<?= e($Lar_Item['url']) ?>"
                   <?= $Lv_Activo === $Lar_Item['clave'] ? 'aria-current="page"' : '' ?>>
                    <?= icono($Lar_Item['icono']) ?><?= e($Lar_Item['texto']) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($Lv_AdminUrl !== null): ?>
            <a class="fx-pestana fx-pestana-admin" href="<?= e($Lv_AdminUrl) ?>"
               <?= $Lb_EnAdmin ? 'aria-current="page"' : '' ?>>
                <?= icono('ajustes') ?>Administración
            </a>
        <?php endif; ?>
    </div>
</nav>

<div class="fx-menu-movil">
    <details>
        <summary>
            <span class="fx-menu-movil-activo">
                <?= icono((string) ($Lar_Activo['icono'] ?? 'panel')) ?>
                <?= e((string) ($Lar_Activo['texto'] ?? $Par_Pagina_i['titulo'])) ?>
            </span>
            <span class="fx-menu-movil-rotulo">Menú</span>
            <span class="fx-menu-movil-flecha" aria-hidden="true"></span>
        </summary>
        <nav aria-label="Secciones en móvil">
            <?php foreach ($Lar_Menu as $Lar_Item): ?>
                <?php if (auth_puede_ver($Lar_Item['permiso'])): ?>
                    <a href="<?= e($Lar_Item['url']) ?>"
                       <?= $Lv_Activo === $Lar_Item['clave'] ? 'aria-current="page"' : '' ?>>
                        <?= icono($Lar_Item['icono']) ?><span><?= e($Lar_Item['texto']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($Lv_AdminUrl !== null): ?>
                <a href="<?= e($Lv_AdminUrl) ?>" <?= $Lb_EnAdmin ? 'aria-current="page"' : '' ?>>
                    <?= icono('ajustes') ?><span>Administración</span>
                </a>
            <?php endif; ?>
        </nav>
    </details>
</div>

<main class="fx-lienzo" id="contenido">
    <?php if ($Lb_EnAdmin): ?>
        <nav class="fx-tarjeta fx-tarjeta-cuerpo mb-4" aria-label="Secciones de administración">
            <div class="flex flex-wrap gap-4">
                <?php foreach ($Lar_Admin as $Lv_Grupo => $Lar_Items): ?>
                    <div>
                        <div class="fx-subtitulo-grupo mb-2"><?= e($Lv_Grupo) ?></div>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($Lar_Items as $Lar_Item): ?>
                                <?php if ($Lar_Item['url'] === ''): ?>
                                    <span class="fx-pastilla" aria-disabled="true"><?= e($Lar_Item['texto']) ?> · próximamente</span>
                                <?php else: ?>
                                    <a class="fx-pastilla<?= $Lv_Activo === $Lar_Item['clave'] ? ' fx-pastilla-activa' : '' ?>"
                                       href="<?= e($Lar_Item['url']) ?>"
                                       <?= $Lv_Activo === $Lar_Item['clave'] ? 'aria-current="page"' : '' ?>><?= e($Lar_Item['texto']) ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </nav>
    <?php endif; ?>
    <div class="fx-titulo-pagina">
        <div>
            <?php if ($Lb_EnAdmin): ?>
                <div class="fx-subtitulo-grupo">Administración · <?= e($Lv_GrupoAdmin) ?></div>
            <?php endif; ?>
            <h1><?= e($Par_Pagina_i['titulo']) ?></h1>
            <?php if (!empty($Par_Pagina_i['subtitulo'])): ?>
                <p><?= e($Par_Pagina_i['subtitulo']) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($Par_Pagina_i['acciones'])): ?>
            <div class="flex flex-wrap gap-2"><?= $Par_Pagina_i['acciones'] ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/** Cierra la página. */
function vista_pie(): void
{
    ?>
    <footer class="mt-8 flex flex-wrap items-center justify-between gap-2 border-t border-linea pt-4">
        <p class="fx-nota m-0">Sesión con cierre por inactividad a los 30 minutos.</p>
        <p class="fx-nota m-0">
            Fase 1 · CR · GT · NI · PE — El Salvador en fase 2 · Grupo ANC
        </p>
    </footer>
</main>
</body>
</html>
    <?php
}
