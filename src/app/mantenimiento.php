<?php
/**
 * Fallas Exitosas · Mantenimiento de catálogos y reglas (PB-20).
 *
 * Propósito : Definir las entidades configurables (categorías, tipos de
 *             experiencia, estados del caso, palabras y frases, destinatarios
 *             y parámetros por país) y la lógica común de alta, edición,
 *             deshabilitación y rehabilitación.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-28
 * Bitácora  : 2026-09-28 Versión inicial.
 *
 * Reglas    : - Nada se borra: se deshabilita (is_activo = 0).
 *             - No se crea un duplicado de algo deshabilitado: se vuelve a
 *               habilitar el existente y se informa cuántos registros tiene.
 *             - La unicidad no distingue mayúsculas ni tildes: las columnas de
 *               nombre usan Modern_Spanish_CI_AI y la búsqueda la hace SQL.
 *             - Todo cambio queda en fx.auditoria con valor anterior y nuevo.
 *
 * Seguridad : Tablas y columnas salen de las definiciones de este archivo,
 *             nunca de la petición. Los valores siempre van parametrizados.
 */

declare(strict_types=1);

/** Valor de país que significa "todos los países". */
const MANT_TODOS = '*';

/** Polaridad de una categoría principal (colores de la imagen de categorías). */
const MANT_POLARIDADES = [
    'negativa'     => 'Negativa',
    'positiva'     => 'Positiva',
    'sin_feedback' => 'Sin feedback',
];

/** Escala fija de cinco niveles (glosario del proyecto, PB-05). */
const MANT_SENTIMIENTOS = [
    'completamente_satisfecho'   => 'Completamente satisfecho',
    'algo_satisfecho'            => 'Algo satisfecho',
    'neutral'                    => 'Ni satisfecho ni insatisfecho',
    'algo_insatisfecho'          => 'Algo insatisfecho',
    'completamente_insatisfecho' => 'Completamente insatisfecho',
];

/** Motivos de alerta de PB-10. Deben coincidir con ck_destinatario_tipo_alerta. */
const MANT_TIPOS_ALERTA = [
    'todas'                => 'Todas las alertas',
    'sentimiento_negativo' => 'Sentimiento negativo',
    'han'                  => 'HAN',
    'falla_tardia'         => 'Falla tardía',
    'mecanica'             => 'Causa mecánica',
];

/**
 * Parámetros que el código sabe leer. Un parámetro que ningún proceso usa no
 * se ofrece: por eso la lista vive aquí y no en la base.
 */
const MANT_PARAMETROS = [
    'horas_falla_tardia' => [
        'nombre' => 'Horas para considerar tardía una falla',
        'tipo'   => 'entero', 'min' => 1, 'max' => 720,
        'ayuda'  => 'PB-09. Número entero de horas entre 1 y 720. Regla inicial: 24.',
    ],
    'umbral_confianza_ia' => [
        'nombre' => 'Confianza mínima de la IA',
        'tipo'   => 'decimal', 'min' => 0, 'max' => 1,
        'ayuda'  => 'PB-10. Entre 0 y 1, por ejemplo 0.70. Debajo de este valor la clasificación pasa a validación humana.',
    ],
    'alertas_habilitadas' => [
        'nombre' => 'Enviar alertas',
        'tipo'   => 'booleano',
        'ayuda'  => 'PB-11. Escribí Sí o No.',
    ],
    'whatsapp_habilitado' => [
        'nombre' => 'Enviar alertas por WhatsApp',
        'tipo'   => 'booleano',
        'ayuda'  => 'PB-11. Solo si el canal está aprobado. Escribí Sí o No.',
    ],
];

/* --------------------------------------------------------------------------
   Utilidades
   -------------------------------------------------------------------------- */

/** Recorta y colapsa espacios internos. */
function mant_texto(string $Pv_Valor_i): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $Pv_Valor_i));
}

/** Código estable a partir de un nombre: minúsculas, sin tildes, con guion bajo. */
function mant_slug(string $Pv_Nombre_i): string
{
    $Lv_Texto = strtr(mb_strtolower(mant_texto($Pv_Nombre_i)), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    $Lv_Texto = trim((string) preg_replace('/[^a-z0-9]+/', '_', $Lv_Texto), '_');

    return substr($Lv_Texto !== '' ? $Lv_Texto : 'registro', 0, 36);
}

/** Texto visible de un país guardado. */
function mant_pais_texto(string $Pv_Pais_i): string
{
    return $Pv_Pais_i === MANT_TODOS ? 'Todos' : $Pv_Pais_i;
}

/** Indica si el usuario ve todos los países habilitados (puede usar '*'). */
function mant_ve_todos_los_paises(array $Par_Usuario_i): bool
{
    return array_diff(array_keys(AUTH_PAISES), $Par_Usuario_i['paises']) === [];
}

/** Opciones de país que el usuario puede asignar. */
function mant_opciones_pais(array $Par_Usuario_i): array
{
    $Lar_Opciones = [];

    if (mant_ve_todos_los_paises($Par_Usuario_i)) {
        $Lar_Opciones[MANT_TODOS] = 'Todos los países';
    }

    foreach (AUTH_PAISES as $Lv_Codigo => $Lv_Nombre) {
        if (in_array($Lv_Codigo, $Par_Usuario_i['paises'], true)) {
            $Lar_Opciones[$Lv_Codigo] = $Lv_Nombre . ' · ' . $Lv_Codigo;
        }
    }

    return $Lar_Opciones;
}

/** Valor legible de un parámetro guardado. */
function mant_parametro_texto(string $Pv_Codigo_i, string $Pv_Valor_i): string
{
    if ((MANT_PARAMETROS[$Pv_Codigo_i]['tipo'] ?? '') === 'booleano') {
        return $Pv_Valor_i === '1' ? 'Sí' : 'No';
    }

    return $Pv_Valor_i;
}

/**
 * Valida y normaliza el valor de un parámetro según su definición.
 * Devuelve el texto a guardar.
 */
function mant_parametro_normalizar(string $Pv_Codigo_i, string $Pv_Valor_i): string
{
    $Lar_Def   = MANT_PARAMETROS[$Pv_Codigo_i] ?? null;
    $Lv_Valor  = mb_strtolower(mant_texto($Pv_Valor_i));

    if ($Lar_Def === null) {
        throw new InvalidArgumentException('Elegí un parámetro de la lista.');
    }

    switch ($Lar_Def['tipo']) {
        case 'entero':
            if (preg_match('/\A[0-9]+\z/', $Lv_Valor) !== 1
                || (int) $Lv_Valor < $Lar_Def['min'] || (int) $Lv_Valor > $Lar_Def['max']) {
                throw new InvalidArgumentException(sprintf(
                    '«%s» debe ser un número entero entre %d y %d.',
                    $Lar_Def['nombre'], $Lar_Def['min'], $Lar_Def['max']
                ));
            }

            return (string) (int) $Lv_Valor;

        case 'decimal':
            $Lv_Valor = str_replace(',', '.', $Lv_Valor);

            if (!is_numeric($Lv_Valor)
                || (float) $Lv_Valor < $Lar_Def['min'] || (float) $Lv_Valor > $Lar_Def['max']) {
                throw new InvalidArgumentException(sprintf(
                    '«%s» debe ser un número entre %s y %s, por ejemplo 0.70.',
                    $Lar_Def['nombre'], $Lar_Def['min'], $Lar_Def['max']
                ));
            }

            return number_format((float) $Lv_Valor, 2, '.', '');

        case 'booleano':
            if (in_array($Lv_Valor, ['si', 'sí', '1', 'true'], true)) {
                return '1';
            }

            if (in_array($Lv_Valor, ['no', '0', 'false'], true)) {
                return '0';
            }

            throw new InvalidArgumentException(sprintf('«%s» se responde con Sí o No.', $Lar_Def['nombre']));
    }

    throw new InvalidArgumentException('Tipo de parámetro no reconocido.');
}

/* --------------------------------------------------------------------------
   Definición de entidades
   -------------------------------------------------------------------------- */

/**
 * Entidades mantenibles.
 *
 * Claves de cada entidad:
 *   titulo, singular, descripcion  Textos de pantalla.
 *   tabla, pk                      Tabla y llave primaria (solo desde aquí).
 *   fijos                          Columnas con valor fijo (p. ej. tipo del catálogo).
 *   clave                          Columnas que identifican un registro único.
 *   rotulo                         Campo que nombra al registro en mensajes.
 *   rotulo_sql                     Expresión SQL para nombrarlo cuando la clave tiene varias columnas.
 *   nombrar(valores)               Nombre legible para mensajes, si el rótulo es un código.
 *   campo_pais                     Campo de país, si la entidad se filtra por ámbito.
 *   campos                         Formulario: etiqueta, tipo, requerido, max, opciones…
 *   extra                          Columnas que calcula validar() y también se guardan.
 *   select, orden, buscar          Consulta del listado.
 *   columnas                       Celdas del listado (html ya escapado).
 *   validar(valores, actual)       Reglas propias; devuelve los valores a guardar.
 *   uso(id)                        Registros asociados: ['total' => int, 'detalle' => string].
 *   antes_activar / antes_desactivar(fila)  Lanzan InvalidArgumentException si no procede.
 *   aviso_baja(fila)               Texto extra para confirmar la deshabilitación.
 *   bloqueo_baja(fila)             Motivo por el que no se ofrece deshabilitar (o null).
 */
function mant_entidades(array $Par_Usuario_i): array
{
    $Lar_Paises = mant_opciones_pais($Par_Usuario_i);

    $Lf_Estado = static fn (array $Par_Fila_i): string => (int) $Par_Fila_i['is_activo'] === 1
        ? '<span class="fx-estado fx-estado-activo">Activo</span>'
        : '<span class="fx-estado fx-estado-inactivo">Deshabilitado</span>';

    $Lf_Catalogo = static function (string $Pv_Tipo_i, string $Pv_Titulo_i, string $Pv_Singular_i, string $Pv_Descripcion_i) use ($Lf_Estado): array {
        $Lb_EsTipo = $Pv_Tipo_i === 'tipo_experiencia';

        return [
            'titulo'      => $Pv_Titulo_i,
            'singular'    => $Pv_Singular_i,
            'descripcion' => $Pv_Descripcion_i,
            'tabla'       => 'fx.catalogo',
            'pk'          => 'catalogo_id',
            'fijos'       => ['tipo' => $Pv_Tipo_i],
            'clave'       => ['nombre'],
            'rotulo'      => 'nombre',
            'campos'      => [
                'nombre'      => ['etiqueta' => 'Nombre', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
                'orden'       => ['etiqueta' => 'Orden en listas', 'tipo' => 'numero', 'requerido' => true, 'min' => 1, 'max' => 999, 'defecto' => '100'],
                'descripcion' => [
                    'etiqueta' => 'Descripción', 'tipo' => 'texto_largo', 'max' => 300,
                    'ayuda'    => $Lb_EsTipo
                        ? 'Qué significa. La IA la usa como criterio para clasificar.'
                        : 'Cuándo se usa este estado.',
                ],
            ],
            'extra'  => ['codigo'],
            'select' => 'SELECT t.catalogo_id AS id, t.codigo, t.nombre, t.descripcion, t.orden, t.is_activo,
                                t.created_at, t.created_by, t.updated_at, t.updated_by,
                                (SELECT COUNT(*) FROM fx.palabra AS w WHERE w.tipo_experiencia_id = t.catalogo_id) AS palabras
                         FROM fx.catalogo AS t',
            'orden'  => 't.orden, t.nombre',
            'buscar' => ['t.nombre', 't.descripcion'],
            'columnas' => array_values(array_filter([
                ['titulo' => 'Orden', 'html' => static fn (array $Par_F_i): string => '<span class="tnum">' . (int) $Par_F_i['orden'] . '</span>'],
                ['titulo' => 'Nombre', 'html' => static fn (array $Par_F_i): string =>
                    '<div class="fx-nombre">' . e($Par_F_i['nombre']) . '</div>'
                    . '<div class="fx-secundario">Código ' . e($Par_F_i['codigo']) . '</div>'],
                ['titulo' => 'Descripción', 'html' => static fn (array $Par_F_i): string =>
                    $Par_F_i['descripcion'] !== null && $Par_F_i['descripcion'] !== ''
                        ? e($Par_F_i['descripcion'])
                        : '<span class="fx-secundario">Sin descripción</span>'],
                $Lb_EsTipo
                    ? ['titulo' => 'Palabras', 'html' => static fn (array $Par_F_i): string => '<span class="tnum">' . (int) $Par_F_i['palabras'] . '</span>']
                    : null,
                ['titulo' => 'Estado', 'html' => $Lf_Estado],
            ])),
            'validar' => static function (array $Par_Valores_i, ?array $Par_Actual_i) use ($Pv_Tipo_i): array {
                if ($Par_Actual_i !== null) {
                    return $Par_Valores_i;   // El código no cambia al renombrar.
                }

                // Código único dentro del tipo: nombre en minúsculas y, si choca, un sufijo.
                $Lv_Base   = mant_slug($Par_Valores_i['nombre']);
                $Lv_Codigo = $Lv_Base;

                for ($Li_Intento = 2; db_fila(
                    'SELECT 1 AS existe FROM fx.catalogo WHERE tipo = :tipo AND codigo = :codigo',
                    [':tipo' => $Pv_Tipo_i, ':codigo' => $Lv_Codigo]
                ) !== null; $Li_Intento++) {
                    $Lv_Codigo = $Lv_Base . '_' . $Li_Intento;
                }

                return $Par_Valores_i + ['codigo' => $Lv_Codigo];
            },
            'uso' => static function (int $Pi_Id_i): array {
                $Li_Palabras = (int) (db_fila(
                    'SELECT COUNT(*) AS total FROM fx.palabra WHERE tipo_experiencia_id = :id',
                    [':id' => $Pi_Id_i]
                )['total'] ?? 0);

                return ['total' => $Li_Palabras, 'detalle' => $Li_Palabras . ' palabras o frases'];
            },
            'antes_desactivar' => static function (array $Par_Fila_i) use ($Pv_Tipo_i, $Pv_Singular_i): void {
                $Li_Otros = (int) (db_fila(
                    'SELECT COUNT(*) AS total FROM fx.catalogo WHERE tipo = :tipo AND is_activo = 1 AND catalogo_id <> :id',
                    [':tipo' => $Pv_Tipo_i, ':id' => (int) $Par_Fila_i['id']]
                )['total'] ?? 0);

                if ($Li_Otros === 0) {
                    throw new InvalidArgumentException('Es el último ' . $Pv_Singular_i . ' activo: habilitá otro antes de deshabilitar este.');
                }
            },
        ];
    };

    return [
        /* ---------------------------------------------------------------- */
        'categorias' => [
            'titulo'      => 'Categorías',
            'singular'    => 'categoría',
            'descripcion' => 'Causas de la experiencia en dos niveles: categoría principal y subcategoría.',
            'tabla'       => 'fx.categoria',
            'pk'          => 'categoria_id',
            'fijos'       => [],
            'clave'       => ['padre_id', 'polaridad', 'nombre'],
            'rotulo'      => 'nombre',
            'campos'      => [
                'padre_id' => [
                    'etiqueta' => 'Pertenece a', 'tipo' => 'seleccion', 'entero' => true,
                    'vacio'    => '— Es una categoría principal —',
                    'opciones' => static function (?array $Par_Actual_i): array {
                        $Lar_Grupos = [];

                        foreach (db_filas(
                            'SELECT categoria_id, polaridad, nombre, is_activo FROM fx.categoria
                             WHERE padre_id IS NULL AND (is_activo = 1 OR categoria_id = :actual)
                               AND categoria_id <> :propio
                             ORDER BY nombre',
                            [':actual' => (int) ($Par_Actual_i['padre_id'] ?? 0), ':propio' => (int) ($Par_Actual_i['id'] ?? 0)]
                        ) as $Lar_Fila) {
                            $Lv_Grupo = MANT_POLARIDADES[$Lar_Fila['polaridad']] ?? 'Otras';
                            $Lar_Grupos[$Lv_Grupo][(int) $Lar_Fila['categoria_id']] = $Lar_Fila['nombre']
                                . ((int) $Lar_Fila['is_activo'] === 1 ? '' : ' (deshabilitada)');
                        }

                        return $Lar_Grupos;
                    },
                    'ayuda' => 'Vacío crea una categoría principal. Solo hay dos niveles.',
                ],
                'nombre' => ['etiqueta' => 'Nombre', 'tipo' => 'texto', 'requerido' => true, 'max' => 120],
                'polaridad' => [
                    'etiqueta' => 'Polaridad', 'tipo' => 'seleccion', 'opciones' => MANT_POLARIDADES,
                    'vacio'    => '— La hereda de su categoría —',
                    'ayuda'    => 'Obligatoria en una categoría principal. Una subcategoría hereda la de su categoría.',
                ],
                'is_mecanica' => [
                    'etiqueta' => 'Causa mecánica', 'tipo' => 'casilla',
                    'ayuda'    => 'Solo en subcategorías. Las alertas con esta causa incluyen a Mantenimiento (PB-11).',
                ],
                'descripcion' => [
                    'etiqueta' => 'Descripción', 'tipo' => 'texto_largo', 'max' => 300,
                    'ayuda'    => 'Qué entra en esta causa. La IA la usa como criterio para clasificar.',
                ],
            ],
            'extra'  => [],
            'select' => 'SELECT t.categoria_id AS id, t.padre_id, t.polaridad, t.nombre, t.descripcion,
                                t.is_mecanica, t.is_activo, t.created_at, t.created_by, t.updated_at, t.updated_by,
                                COALESCE(p.polaridad, t.polaridad) AS polaridad_efectiva,
                                p.nombre AS padre_nombre, p.is_activo AS padre_activo,
                                (SELECT COUNT(*) FROM fx.categoria AS h WHERE h.padre_id = t.categoria_id) AS hijos,
                                (SELECT COUNT(*) FROM fx.palabra AS w WHERE w.categoria_id = t.categoria_id) AS palabras
                         FROM fx.categoria AS t
                             LEFT JOIN fx.categoria AS p ON p.categoria_id = t.padre_id',
            'orden'  => "CASE COALESCE(p.polaridad, t.polaridad) WHEN 'negativa' THEN 1 WHEN 'positiva' THEN 2 ELSE 3 END,
                         COALESCE(p.nombre, t.nombre), CASE WHEN t.padre_id IS NULL THEN 0 ELSE 1 END, t.nombre",
            'buscar' => ['t.nombre', 'p.nombre', 't.descripcion'],
            'columnas' => [
                ['titulo' => 'Categoría', 'html' => static fn (array $Par_F_i): string => $Par_F_i['padre_id'] === null
                    ? '<div class="fx-nombre">' . e($Par_F_i['nombre']) . '</div>'
                      . '<div class="fx-secundario">' . (int) $Par_F_i['hijos'] . ' subcategorías</div>'
                    : '<span class="fx-secundario">' . e($Par_F_i['padre_nombre']) . '</span>'],
                ['titulo' => 'Subcategoría', 'html' => static fn (array $Par_F_i): string => $Par_F_i['padre_id'] === null
                    ? '<span class="fx-secundario">Categoría principal</span>'
                    : '<div class="fx-nombre">' . e($Par_F_i['nombre']) . '</div>'
                      . ($Par_F_i['descripcion'] !== null && $Par_F_i['descripcion'] !== ''
                          ? '<div class="fx-secundario">' . e($Par_F_i['descripcion']) . '</div>' : '')],
                ['titulo' => 'Polaridad', 'html' => static fn (array $Par_F_i): string =>
                    '<span class="fx-polaridad fx-polaridad-' . e((string) $Par_F_i['polaridad_efectiva']) . '">'
                    . e(MANT_POLARIDADES[$Par_F_i['polaridad_efectiva']] ?? '—') . '</span>'],
                ['titulo' => 'Mecánica', 'html' => static fn (array $Par_F_i): string =>
                    (int) $Par_F_i['is_mecanica'] === 1 ? '<span class="fx-ambito">Mantenimiento</span>' : ''],
                ['titulo' => 'Palabras', 'html' => static fn (array $Par_F_i): string => '<span class="tnum">' . (int) $Par_F_i['palabras'] . '</span>'],
                ['titulo' => 'Estado', 'html' => static function (array $Par_F_i) use ($Lf_Estado): string {
                    if ((int) $Par_F_i['is_activo'] === 1 && $Par_F_i['padre_id'] !== null && (int) $Par_F_i['padre_activo'] !== 1) {
                        return '<span class="fx-estado fx-estado-inactivo">Sin uso: categoría deshabilitada</span>';
                    }

                    return $Lf_Estado($Par_F_i);
                }],
            ],
            'validar' => static function (array $Par_Valores_i, ?array $Par_Actual_i): array {
                if ($Par_Valores_i['padre_id'] === null) {
                    if ($Par_Valores_i['polaridad'] === null) {
                        throw new InvalidArgumentException('Una categoría principal necesita polaridad: negativa, positiva o sin feedback.');
                    }

                    $Par_Valores_i['is_mecanica'] = 0;   // Solo aplica a subcategorías.

                    return $Par_Valores_i;
                }

                if ($Par_Actual_i !== null && (int) $Par_Valores_i['padre_id'] === (int) $Par_Actual_i['id']) {
                    throw new InvalidArgumentException('Una categoría no puede pertenecer a sí misma.');
                }

                if ($Par_Actual_i !== null && (int) $Par_Actual_i['hijos'] > 0) {
                    throw new InvalidArgumentException('Tiene subcategorías: no puede pasar a ser subcategoría. Solo hay dos niveles.');
                }

                $Lar_Padre = db_fila(
                    'SELECT categoria_id, is_activo FROM fx.categoria WHERE categoria_id = :id AND padre_id IS NULL',
                    [':id' => $Par_Valores_i['padre_id']]
                );

                if ($Lar_Padre === null) {
                    throw new InvalidArgumentException('Elegí una categoría principal de la lista.');
                }

                if ((int) $Lar_Padre['is_activo'] !== 1
                    && (int) ($Par_Actual_i['padre_id'] ?? 0) !== (int) $Par_Valores_i['padre_id']) {
                    throw new InvalidArgumentException('Esa categoría está deshabilitada. Habilitala antes de agregarle subcategorías.');
                }

                $Par_Valores_i['polaridad'] = null;   // La hereda de su categoría.

                return $Par_Valores_i;
            },
            'uso' => static function (int $Pi_Id_i): array {
                $Lar_Fila = db_fila(
                    'SELECT (SELECT COUNT(*) FROM fx.categoria WHERE padre_id = :id1) AS hijos,
                            (SELECT COUNT(*) FROM fx.palabra
                             WHERE categoria_id = :id2
                                OR categoria_id IN (SELECT categoria_id FROM fx.categoria WHERE padre_id = :id3)) AS palabras',
                    [':id1' => $Pi_Id_i, ':id2' => $Pi_Id_i, ':id3' => $Pi_Id_i]
                ) ?? ['hijos' => 0, 'palabras' => 0];

                $Lar_Detalle = [];

                if ((int) $Lar_Fila['hijos'] > 0) {
                    $Lar_Detalle[] = (int) $Lar_Fila['hijos'] . ' subcategorías';
                }

                $Lar_Detalle[] = (int) $Lar_Fila['palabras'] . ' palabras o frases';

                return [
                    'total'   => (int) $Lar_Fila['hijos'] + (int) $Lar_Fila['palabras'],
                    'detalle' => implode(' y ', $Lar_Detalle),
                ];
            },
            'antes_activar' => static function (array $Par_Fila_i): void {
                if ($Par_Fila_i['padre_id'] !== null && (int) $Par_Fila_i['padre_activo'] !== 1) {
                    throw new InvalidArgumentException(
                        'Su categoría «' . $Par_Fila_i['padre_nombre'] . '» está deshabilitada. Habilitala primero.'
                    );
                }
            },
            'aviso_baja' => static fn (array $Par_Fila_i): string => $Par_Fila_i['padre_id'] === null && (int) $Par_Fila_i['hijos'] > 0
                ? ' Sus ' . (int) $Par_Fila_i['hijos'] . ' subcategorías también dejarán de usarse.'
                : '',
        ],

        /* ---------------------------------------------------------------- */
        'tipos' => $Lf_Catalogo(
            'tipo_experiencia',
            'Tipos de experiencia',
            'tipo de experiencia',
            'Naturaleza de la experiencia, independiente del sentimiento (PB-08).'
        ),

        'estados' => $Lf_Catalogo(
            'estado_caso',
            'Estados del caso',
            'estado del caso',
            'Etapas del seguimiento de un caso (PB-13).'
        ),

        /* ---------------------------------------------------------------- */
        'palabras' => [
            'titulo'      => 'Palabras y frases',
            'singular'    => 'palabra o frase',
            'descripcion' => 'Clasificación por reglas antes de la IA: si el comentario la contiene, se aplica lo indicado (PB-06).',
            'tabla'       => 'fx.palabra',
            'pk'          => 'palabra_id',
            'fijos'       => [],
            'clave'       => ['texto', 'pais'],
            'rotulo'      => 'texto',
            'rotulo_sql'  => "CONCAT(texto, ' · ', pais)",
            'campo_pais'  => 'pais',
            'campos'      => [
                'texto' => [
                    'etiqueta' => 'Palabra o frase', 'tipo' => 'texto', 'requerido' => true, 'max' => 200,
                    'ayuda'    => 'No distingue mayúsculas ni tildes.',
                ],
                'pais' => ['etiqueta' => 'País', 'tipo' => 'seleccion', 'requerido' => true, 'opciones' => $Lar_Paises],
                'categoria_id' => [
                    'etiqueta' => 'Subcategoría', 'tipo' => 'seleccion', 'entero' => true, 'vacio' => '— Sin subcategoría —',
                    'opciones' => static function (?array $Par_Actual_i): array {
                        $Lar_Grupos = [];

                        foreach (db_filas(
                            'SELECT s.categoria_id, s.nombre, p.nombre AS padre, p.polaridad,
                                    CASE WHEN s.is_activo = 1 AND p.is_activo = 1 THEN 1 ELSE 0 END AS en_uso
                             FROM fx.categoria AS s
                                 INNER JOIN fx.categoria AS p ON p.categoria_id = s.padre_id
                             WHERE (s.is_activo = 1 AND p.is_activo = 1) OR s.categoria_id = :actual
                             ORDER BY p.nombre, s.nombre',
                            [':actual' => (int) ($Par_Actual_i['categoria_id'] ?? 0)]
                        ) as $Lar_Fila) {
                            $Lv_Grupo = $Lar_Fila['padre'] . ' · ' . (MANT_POLARIDADES[$Lar_Fila['polaridad']] ?? '');
                            $Lar_Grupos[$Lv_Grupo][(int) $Lar_Fila['categoria_id']] = $Lar_Fila['nombre']
                                . ((int) $Lar_Fila['en_uso'] === 1 ? '' : ' (deshabilitada)');
                        }

                        return $Lar_Grupos;
                    },
                ],
                'sentimiento' => [
                    'etiqueta' => 'Sentimiento', 'tipo' => 'seleccion', 'opciones' => MANT_SENTIMIENTOS,
                    'vacio'    => '— Sin sentimiento —',
                ],
                'tipo_experiencia_id' => [
                    'etiqueta' => 'Tipo de experiencia', 'tipo' => 'seleccion', 'entero' => true, 'vacio' => '— Sin tipo —',
                    'opciones' => static function (?array $Par_Actual_i): array {
                        $Lar_Opciones = [];

                        foreach (db_filas(
                            "SELECT catalogo_id, nombre, is_activo FROM fx.catalogo
                             WHERE tipo = 'tipo_experiencia' AND (is_activo = 1 OR catalogo_id = :actual)
                             ORDER BY orden, nombre",
                            [':actual' => (int) ($Par_Actual_i['tipo_experiencia_id'] ?? 0)]
                        ) as $Lar_Fila) {
                            $Lar_Opciones[(int) $Lar_Fila['catalogo_id']] = $Lar_Fila['nombre']
                                . ((int) $Lar_Fila['is_activo'] === 1 ? '' : ' (deshabilitado)');
                        }

                        return $Lar_Opciones;
                    },
                    'ayuda' => 'Indicá al menos una: subcategoría, sentimiento o tipo.',
                ],
            ],
            'extra'  => [],
            'select' => 'SELECT t.palabra_id AS id, t.texto, t.pais, t.categoria_id, t.sentimiento, t.tipo_experiencia_id,
                                t.is_activo, t.created_at, t.created_by, t.updated_at, t.updated_by,
                                s.nombre AS subcategoria, c.nombre AS categoria,
                                CASE WHEN s.categoria_id IS NULL OR (s.is_activo = 1 AND c.is_activo = 1) THEN 1 ELSE 0 END AS sub_en_uso,
                                k.nombre AS tipo_nombre, COALESCE(k.is_activo, 1) AS tipo_en_uso
                         FROM fx.palabra AS t
                             LEFT JOIN fx.categoria AS s ON s.categoria_id = t.categoria_id
                             LEFT JOIN fx.categoria AS c ON c.categoria_id = s.padre_id
                             LEFT JOIN fx.catalogo  AS k ON k.catalogo_id = t.tipo_experiencia_id',
            'orden'  => 't.texto, t.pais',
            'buscar' => ['t.texto', 's.nombre', 'c.nombre'],
            'columnas' => [
                ['titulo' => 'Palabra o frase', 'html' => static fn (array $Par_F_i): string => '<div class="fx-nombre">' . e($Par_F_i['texto']) . '</div>'],
                ['titulo' => 'País', 'html' => static fn (array $Par_F_i): string =>
                    '<span class="fx-ambito' . ($Par_F_i['pais'] === MANT_TODOS ? ' fx-ambito-todo' : '') . '">'
                    . e(mant_pais_texto((string) $Par_F_i['pais'])) . '</span>'],
                ['titulo' => 'Clasifica como', 'html' => static function (array $Par_F_i): string {
                    $Lar_Partes = [];

                    if ($Par_F_i['subcategoria'] !== null) {
                        $Lar_Partes[] = '<div>' . e($Par_F_i['categoria'] . ' › ' . $Par_F_i['subcategoria'])
                            . ((int) $Par_F_i['sub_en_uso'] === 1 ? '' : ' <span class="fx-secundario">(deshabilitada: no se aplica)</span>')
                            . '</div>';
                    }

                    if ($Par_F_i['sentimiento'] !== null) {
                        $Lar_Partes[] = '<div class="fx-secundario">Sentimiento: '
                            . e(MANT_SENTIMIENTOS[$Par_F_i['sentimiento']] ?? $Par_F_i['sentimiento']) . '</div>';
                    }

                    if ($Par_F_i['tipo_nombre'] !== null) {
                        $Lar_Partes[] = '<div class="fx-secundario">Tipo: ' . e($Par_F_i['tipo_nombre'])
                            . ((int) $Par_F_i['tipo_en_uso'] === 1 ? '' : ' (deshabilitado: no se aplica)') . '</div>';
                    }

                    return implode('', $Lar_Partes);
                }],
                ['titulo' => 'Estado', 'html' => $Lf_Estado],
            ],
            'validar' => static function (array $Par_Valores_i, ?array $Par_Actual_i): array {
                if ($Par_Valores_i['categoria_id'] === null
                    && $Par_Valores_i['sentimiento'] === null
                    && $Par_Valores_i['tipo_experiencia_id'] === null) {
                    throw new InvalidArgumentException('Indicá qué aplica la frase: una subcategoría, un sentimiento o un tipo de experiencia.');
                }

                return $Par_Valores_i;
            },
            // Cuando exista la clasificación por reglas, aquí se cuentan los comentarios clasificados con esta frase.
            'uso' => static fn (int $Pi_Id_i): array => ['total' => 0, 'detalle' => ''],
        ],

        /* ---------------------------------------------------------------- */
        'destinatarios' => [
            'titulo'      => 'Responsables y destinatarios',
            'singular'    => 'destinatario',
            'descripcion' => 'Quién recibe cada alerta por país (PB-11). Zona y oficina se agregan con la API de zonas.',
            'tabla'       => 'fx.destinatario',
            'pk'          => 'destinatario_id',
            'fijos'       => [],
            'clave'       => ['pais', 'correo', 'tipo_alerta'],
            'rotulo'      => 'correo',
            'rotulo_sql'  => "CONCAT(correo, ' · ', pais, ' · ', tipo_alerta)",
            'campo_pais'  => 'pais',
            'campos'      => [
                'nombre' => ['etiqueta' => 'Nombre completo', 'tipo' => 'texto', 'requerido' => true, 'max' => 160],
                'correo' => ['etiqueta' => 'Correo corporativo', 'tipo' => 'correo', 'requerido' => true, 'max' => 160],
                'pais'   => ['etiqueta' => 'País', 'tipo' => 'seleccion', 'requerido' => true, 'opciones' => $Lar_Paises],
                'tipo_alerta' => [
                    'etiqueta' => 'Recibe', 'tipo' => 'seleccion', 'requerido' => true, 'opciones' => MANT_TIPOS_ALERTA,
                    'ayuda'    => 'Mantenimiento suele ir solo con «Causa mecánica».',
                ],
                'es_responsable' => [
                    'etiqueta' => 'Responsable del caso', 'tipo' => 'casilla',
                    'ayuda'    => 'Da seguimiento hasta el cierre. Sin marcar, solo recibe copia.',
                ],
            ],
            'extra'  => [],
            'select' => 'SELECT t.destinatario_id AS id, t.nombre, t.correo, t.pais, t.tipo_alerta, t.es_responsable,
                                t.is_activo, t.created_at, t.created_by, t.updated_at, t.updated_by
                         FROM fx.destinatario AS t',
            'orden'  => 't.pais, t.nombre, t.tipo_alerta',
            'buscar' => ['t.nombre', 't.correo'],
            'columnas' => [
                ['titulo' => 'Persona', 'html' => static fn (array $Par_F_i): string =>
                    '<div class="fx-nombre">' . e($Par_F_i['nombre']) . '</div>'
                    . '<div class="fx-secundario">' . e($Par_F_i['correo']) . '</div>'],
                ['titulo' => 'País', 'html' => static fn (array $Par_F_i): string =>
                    '<span class="fx-ambito' . ($Par_F_i['pais'] === MANT_TODOS ? ' fx-ambito-todo' : '') . '">'
                    . e(mant_pais_texto((string) $Par_F_i['pais'])) . '</span>'],
                ['titulo' => 'Recibe', 'html' => static fn (array $Par_F_i): string => e(MANT_TIPOS_ALERTA[$Par_F_i['tipo_alerta']] ?? $Par_F_i['tipo_alerta'])],
                ['titulo' => 'Rol', 'html' => static fn (array $Par_F_i): string => (int) $Par_F_i['es_responsable'] === 1
                    ? '<span class="fx-ambito fx-ambito-todo">Responsable</span>'
                    : '<span class="fx-secundario">Copia</span>'],
                ['titulo' => 'Estado', 'html' => $Lf_Estado],
            ],
            'validar' => static function (array $Par_Valores_i, ?array $Par_Actual_i): array {
                $Par_Valores_i['correo'] = mb_strtolower($Par_Valores_i['correo']);

                if (!filter_var($Par_Valores_i['correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('El correo no tiene un formato válido.');
                }

                return $Par_Valores_i;
            },
            // Cuando exista el registro de envíos (PB-11), aquí se cuentan las alertas enviadas.
            'uso' => static fn (int $Pi_Id_i): array => ['total' => 0, 'detalle' => ''],
        ],

        /* ---------------------------------------------------------------- */
        'parametros' => [
            'titulo'      => 'Parámetros por país',
            'singular'    => 'parámetro',
            'descripcion' => 'El valor de «Todos» es el regional; un país con valor propio activo lo reemplaza.',
            'tabla'       => 'fx.parametro',
            'pk'          => 'parametro_id',
            'fijos'       => [],
            'clave'       => ['codigo', 'pais'],
            'rotulo'      => 'codigo',
            'rotulo_sql'  => "CONCAT(codigo, ' · ', pais)",
            'nombrar'     => static fn (array $Par_V_i): string =>
                (MANT_PARAMETROS[$Par_V_i['codigo']]['nombre'] ?? (string) $Par_V_i['codigo']) . ' · ' . mant_pais_texto((string) $Par_V_i['pais']),
            'campo_pais'  => 'pais',
            'campos'      => [
                'codigo' => [
                    'etiqueta' => 'Parámetro', 'tipo' => 'seleccion', 'requerido' => true, 'solo_alta' => true,
                    'opciones' => array_map(static fn (array $Par_D_i): string => $Par_D_i['nombre'], MANT_PARAMETROS),
                ],
                'pais'  => [
                    'etiqueta' => 'País', 'tipo' => 'seleccion', 'requerido' => true, 'solo_alta' => true,
                    'opciones' => $Lar_Paises,
                ],
                'valor' => [
                    'etiqueta' => 'Valor', 'tipo' => 'texto', 'requerido' => true, 'max' => 200,
                    'ayuda'    => 'Horas: entero de 1 a 720 · Confianza: entre 0 y 1 · Enviar: Sí o No.',
                ],
            ],
            'extra'  => [],
            'select' => 'SELECT t.parametro_id AS id, t.codigo, t.pais, t.valor, t.is_activo,
                                t.created_at, t.created_by, t.updated_at, t.updated_by
                         FROM fx.parametro AS t',
            'orden'  => "t.codigo, CASE WHEN t.pais = '*' THEN 0 ELSE 1 END, t.pais",
            'buscar' => ['t.codigo', 't.valor'],
            'columnas' => [
                ['titulo' => 'Parámetro', 'html' => static fn (array $Par_F_i): string =>
                    '<div class="fx-nombre">' . e(MANT_PARAMETROS[$Par_F_i['codigo']]['nombre'] ?? $Par_F_i['codigo']) . '</div>'
                    . '<div class="fx-secundario">' . e(isset(MANT_PARAMETROS[$Par_F_i['codigo']])
                        ? MANT_PARAMETROS[$Par_F_i['codigo']]['ayuda']
                        : 'Ningún proceso lee este parámetro.') . '</div>'],
                ['titulo' => 'País', 'html' => static fn (array $Par_F_i): string => $Par_F_i['pais'] === MANT_TODOS
                    ? '<span class="fx-ambito fx-ambito-todo">Todos · regional</span>'
                    : '<span class="fx-ambito">' . e($Par_F_i['pais']) . '</span>'],
                ['titulo' => 'Valor', 'html' => static fn (array $Par_F_i): string =>
                    '<span class="fx-nombre tnum">' . e(mant_parametro_texto((string) $Par_F_i['codigo'], (string) $Par_F_i['valor'])) . '</span>'],
                ['titulo' => 'Estado', 'html' => $Lf_Estado],
            ],
            'validar' => static function (array $Par_Valores_i, ?array $Par_Actual_i): array {
                $Par_Valores_i['valor'] = mant_parametro_normalizar((string) $Par_Valores_i['codigo'], (string) $Par_Valores_i['valor']);

                return $Par_Valores_i;
            },
            'uso' => static fn (int $Pi_Id_i): array => ['total' => 0, 'detalle' => ''],
            'bloqueo_baja' => static fn (array $Par_Fila_i): ?string => $Par_Fila_i['pais'] === MANT_TODOS
                ? 'Valor regional: siempre activo'
                : null,
            'antes_desactivar' => static function (array $Par_Fila_i): void {
                if ($Par_Fila_i['pais'] === MANT_TODOS) {
                    throw new InvalidArgumentException(
                        'El valor regional no se deshabilita: es el que usan los países sin valor propio. Cambiá su valor si hace falta.'
                    );
                }
            },
            'aviso_baja' => static fn (array $Par_Fila_i): string => ' El país vuelve a usar el valor regional.',
        ],
    ];
}

/* --------------------------------------------------------------------------
   Motor común
   -------------------------------------------------------------------------- */

/** Opciones planas (sin grupos) de un campo de selección. */
function mant_opciones_planas(array $Par_Opciones_i): array
{
    $Lar_Planas = [];

    foreach ($Par_Opciones_i as $Lm_Clave => $Lm_Valor) {
        if (is_array($Lm_Valor)) {
            foreach ($Lm_Valor as $Lm_Sub => $Lv_Texto) {
                $Lar_Planas[(string) $Lm_Sub] = (string) $Lv_Texto;
            }
        } else {
            $Lar_Planas[(string) $Lm_Clave] = (string) $Lm_Valor;
        }
    }

    return $Lar_Planas;
}

/** Resuelve las opciones de un campo (arreglo fijo o función). */
function mant_opciones(array $Par_Campo_i, ?array $Par_Actual_i): array
{
    $Lm_Opciones = $Par_Campo_i['opciones'] ?? [];

    return is_callable($Lm_Opciones) ? $Lm_Opciones($Par_Actual_i) : $Lm_Opciones;
}

/**
 * Lee y valida el formulario según la definición de campos.
 * En edición, los campos solo_alta conservan el valor guardado.
 */
function mant_leer_formulario(array $Par_Spec_i, array $Par_Post_i, ?array $Par_Actual_i): array
{
    $Lar_Valores = [];

    foreach ($Par_Spec_i['campos'] as $Lv_Campo => $Lar_Campo) {
        if ($Par_Actual_i !== null && !empty($Lar_Campo['solo_alta'])) {
            $Lar_Valores[$Lv_Campo] = $Par_Actual_i[$Lv_Campo];
            continue;
        }

        $Lm_Crudo = $Par_Post_i[$Lv_Campo] ?? null;
        $Lv_Tipo  = $Lar_Campo['tipo'];

        if ($Lv_Tipo === 'casilla') {
            $Lar_Valores[$Lv_Campo] = $Lm_Crudo === '1' ? 1 : 0;
            continue;
        }

        $Lv_Valor = is_string($Lm_Crudo) ? mant_texto($Lm_Crudo) : '';

        if ($Lv_Valor === '') {
            if (!empty($Lar_Campo['requerido'])) {
                throw new InvalidArgumentException('«' . $Lar_Campo['etiqueta'] . '» es obligatorio.');
            }

            $Lar_Valores[$Lv_Campo] = null;
            continue;
        }

        if (isset($Lar_Campo['max']) && mb_strlen($Lv_Valor) > $Lar_Campo['max']) {
            throw new InvalidArgumentException(sprintf(
                '«%s» admite hasta %d caracteres.', $Lar_Campo['etiqueta'], $Lar_Campo['max']
            ));
        }

        if ($Lv_Tipo === 'seleccion') {
            // Solo se aceptan valores que la lista ofrece: nunca entrada libre.
            if (!array_key_exists($Lv_Valor, mant_opciones_planas(mant_opciones($Lar_Campo, $Par_Actual_i)))) {
                throw new InvalidArgumentException('Elegí una opción de la lista en «' . $Lar_Campo['etiqueta'] . '».');
            }

            $Lar_Valores[$Lv_Campo] = !empty($Lar_Campo['entero']) ? (int) $Lv_Valor : $Lv_Valor;
            continue;
        }

        if ($Lv_Tipo === 'numero') {
            if (preg_match('/\A[0-9]+\z/', $Lv_Valor) !== 1
                || (int) $Lv_Valor < $Lar_Campo['min'] || (int) $Lv_Valor > $Lar_Campo['max']) {
                throw new InvalidArgumentException(sprintf(
                    '«%s» debe ser un número entre %d y %d.', $Lar_Campo['etiqueta'], $Lar_Campo['min'], $Lar_Campo['max']
                ));
            }

            $Lar_Valores[$Lv_Campo] = (int) $Lv_Valor;
            continue;
        }

        $Lar_Valores[$Lv_Campo] = $Lv_Valor;
    }

    return $Par_Spec_i['validar']($Lar_Valores, $Par_Actual_i);
}

/**
 * Condición SQL de las columnas fijas y del ámbito de país del usuario.
 * Devuelve [sql, parámetros]; los nombres de columna salen de la definición.
 */
function mant_condicion_base(array $Par_Spec_i, array $Par_Usuario_i): array
{
    $Lar_Sql = [];
    $Lar_Par = [];
    $Li_N    = 0;

    foreach ($Par_Spec_i['fijos'] as $Lv_Columna => $Lv_Valor) {
        $Lar_Sql[] = 't.' . $Lv_Columna . ' = :f' . $Li_N;
        $Lar_Par[':f' . $Li_N++] = $Lv_Valor;
    }

    if (isset($Par_Spec_i['campo_pais'])) {
        // Solo filas de sus países; las de "Todos" las ve cualquiera con acceso.
        $Lar_Lista = [':f' . $Li_N => MANT_TODOS];
        $Li_N++;

        foreach ($Par_Usuario_i['paises'] as $Lv_Pais) {
            $Lar_Lista[':f' . $Li_N++] = $Lv_Pais;
        }

        $Lar_Sql[] = 't.' . $Par_Spec_i['campo_pais'] . ' IN (' . implode(', ', array_keys($Lar_Lista)) . ')';
        $Lar_Par  += $Lar_Lista;
    }

    return [$Lar_Sql, $Lar_Par];
}

/**
 * Listado con filtro de estado y búsqueda.
 *
 * @param string $Pv_Estado_i activos | deshabilitados | todos
 */
function mant_listar(array $Par_Spec_i, array $Par_Usuario_i, string $Pv_Estado_i, string $Pv_Buscar_i): array
{
    [$Lar_Sql, $Lar_Par] = mant_condicion_base($Par_Spec_i, $Par_Usuario_i);

    if ($Pv_Estado_i === 'activos') {
        $Lar_Sql[] = 't.is_activo = 1';
    } elseif ($Pv_Estado_i === 'deshabilitados') {
        $Lar_Sql[] = 't.is_activo = 0';
    }

    if ($Pv_Buscar_i !== '') {
        // Se escapan los comodines de LIKE para buscar el texto tal cual.
        $Lv_Patron = '%' . strtr($Pv_Buscar_i, ['[' => '[[]', '%' => '[%]', '_' => '[_]']) . '%';
        $Lar_Or    = [];

        foreach ($Par_Spec_i['buscar'] as $Li_I => $Lv_Columna) {
            $Lar_Or[] = $Lv_Columna . ' LIKE :b' . $Li_I;
            $Lar_Par[':b' . $Li_I] = $Lv_Patron;
        }

        $Lar_Sql[] = '(' . implode(' OR ', $Lar_Or) . ')';
    }

    return db_filas(
        $Par_Spec_i['select']
        . ($Lar_Sql !== [] ? ' WHERE ' . implode(' AND ', $Lar_Sql) : '')
        . ' ORDER BY ' . $Par_Spec_i['orden'],
        $Lar_Par
    );
}

/** Totales por estado para los filtros. */
function mant_conteos(array $Par_Spec_i, array $Par_Usuario_i): array
{
    [$Lar_Sql, $Lar_Par] = mant_condicion_base($Par_Spec_i, $Par_Usuario_i);

    $Lar_Fila = db_fila(
        'SELECT COUNT(*) AS todos, COALESCE(SUM(CAST(t.is_activo AS INT)), 0) AS activos
         FROM ' . $Par_Spec_i['tabla'] . ' AS t'
        . ($Lar_Sql !== [] ? ' WHERE ' . implode(' AND ', $Lar_Sql) : ''),
        $Lar_Par
    ) ?? ['todos' => 0, 'activos' => 0];

    return [
        'activos'        => (int) $Lar_Fila['activos'],
        'deshabilitados' => (int) $Lar_Fila['todos'] - (int) $Lar_Fila['activos'],
        'todos'          => (int) $Lar_Fila['todos'],
    ];
}

/** Un registro dentro del ámbito del usuario, o null. */
function mant_fila(array $Par_Spec_i, array $Par_Usuario_i, int $Pi_Id_i): ?array
{
    [$Lar_Sql, $Lar_Par] = mant_condicion_base($Par_Spec_i, $Par_Usuario_i);
    $Lar_Sql[]            = 't.' . $Par_Spec_i['pk'] . ' = :id';
    $Lar_Par[':id']       = $Pi_Id_i;

    return db_fila($Par_Spec_i['select'] . ' WHERE ' . implode(' AND ', $Lar_Sql), $Lar_Par);
}

/**
 * Busca otro registro con la misma clave única (activo o deshabilitado).
 * La comparación la hace SQL Server con la intercalación de la columna.
 */
function mant_buscar_clave(array $Par_Spec_i, array $Par_Valores_i, int $Pi_Excluir_i): ?array
{
    $Lar_Sql = [];
    $Lar_Par = [];
    $Li_N    = 0;
    $Lar_Clave = $Par_Spec_i['fijos'] + array_intersect_key($Par_Valores_i, array_flip($Par_Spec_i['clave']));

    foreach ($Lar_Clave as $Lv_Columna => $Lm_Valor) {
        if ($Lm_Valor === null) {
            $Lar_Sql[] = $Lv_Columna . ' IS NULL';
        } else {
            $Lar_Sql[] = $Lv_Columna . ' = :k' . $Li_N;
            $Lar_Par[':k' . $Li_N++] = $Lm_Valor;
        }
    }

    $Lar_Sql[]           = $Par_Spec_i['pk'] . ' <> :excluir';
    $Lar_Par[':excluir'] = $Pi_Excluir_i;

    return db_fila(
        'SELECT ' . $Par_Spec_i['pk'] . ' AS id, ' . ($Par_Spec_i['rotulo_sql'] ?? $Par_Spec_i['rotulo']) . ' AS rotulo, is_activo
         FROM ' . $Par_Spec_i['tabla'] . ' WHERE ' . implode(' AND ', $Lar_Sql),
        $Lar_Par
    );
}

/** Resumen legible de los valores para la auditoría. */
function mant_resumen(array $Par_Spec_i, array $Par_Valores_i, ?array $Par_Actual_i): string
{
    $Lar_Partes = [];

    foreach ($Par_Spec_i['campos'] as $Lv_Campo => $Lar_Campo) {
        $Lm_Valor = $Par_Valores_i[$Lv_Campo] ?? null;

        if ($Lar_Campo['tipo'] === 'casilla') {
            $Lv_Texto = (int) $Lm_Valor === 1 ? 'sí' : 'no';
        } elseif ($Lm_Valor === null || $Lm_Valor === '') {
            $Lv_Texto = '—';
        } elseif ($Lar_Campo['tipo'] === 'seleccion') {
            $Lv_Texto = mant_opciones_planas(mant_opciones($Lar_Campo, $Par_Actual_i))[(string) $Lm_Valor] ?? (string) $Lm_Valor;
        } else {
            $Lv_Texto = (string) $Lm_Valor;
        }

        $Lar_Partes[] = $Lar_Campo['etiqueta'] . ': ' . $Lv_Texto;
    }

    return implode(' · ', $Lar_Partes);
}

/** Nombre legible de un registro para mensajes y auditoría. */
function mant_nombre(array $Par_Spec_i, array $Par_Valores_i): string
{
    return isset($Par_Spec_i['nombrar'])
        ? $Par_Spec_i['nombrar']($Par_Valores_i)
        : (string) $Par_Valores_i[$Par_Spec_i['rotulo']];
}

/** Entidad de auditoría: nombre de la tabla sin esquema. */
function mant_entidad_auditoria(array $Par_Spec_i): string
{
    return substr($Par_Spec_i['tabla'], 3);
}

/** Indica si la excepción es una violación de unicidad de SQL Server. */
function mant_es_duplicado(Throwable $Po_Error_i): bool
{
    $Lar_Info = $Po_Error_i instanceof PDOException && is_array($Po_Error_i->errorInfo ?? null)
        ? $Po_Error_i->errorInfo
        : [];

    return in_array((int) ($Lar_Info[1] ?? 0), [2601, 2627], true);
}

/**
 * Alta o edición.
 *
 * Alta de algo que ya existe deshabilitado: no se crea otro, se habilita el
 * existente sin tocar sus datos y se informa cuántos registros tiene.
 *
 * @return array{mensaje:string, tipo:string, id:int}
 */
function mant_guardar(array $Par_Spec_i, array $Par_Usuario_i, int $Pi_Id_i, array $Par_Post_i): array
{
    $Lar_Actual = null;

    if ($Pi_Id_i > 0) {
        $Lar_Actual = mant_fila($Par_Spec_i, $Par_Usuario_i, $Pi_Id_i);

        if ($Lar_Actual === null) {
            throw new InvalidArgumentException('El registro no existe o está fuera de tus países.');
        }
    }

    $Lar_Valores = mant_leer_formulario($Par_Spec_i, $Par_Post_i, $Lar_Actual);
    $Lar_Otro    = mant_buscar_clave($Par_Spec_i, $Lar_Valores, $Pi_Id_i);
    $Lv_Actor    = (string) $Par_Usuario_i['correo'];

    if ($Lar_Otro !== null && (int) $Lar_Otro['is_activo'] === 1) {
        throw new InvalidArgumentException(sprintf('«%s» ya existe entre los activos.', $Lar_Otro['rotulo']));
    }

    if ($Lar_Otro !== null && $Lar_Actual !== null) {
        throw new InvalidArgumentException(sprintf(
            '«%s» ya existe entre los deshabilitados. Habilitá ese registro desde la lista en lugar de repetir el nombre aquí.',
            $Lar_Otro['rotulo']
        ));
    }

    if ($Lar_Otro !== null) {
        // Rehabilitación en lugar de duplicado.
        $Lar_Fila = mant_fila($Par_Spec_i, $Par_Usuario_i, (int) $Lar_Otro['id'])
            ?? throw new InvalidArgumentException('«' . $Lar_Otro['rotulo'] . '» ya existe entre los deshabilitados, fuera de tus países.');

        if (isset($Par_Spec_i['antes_activar'])) {
            $Par_Spec_i['antes_activar']($Lar_Fila);
        }

        mant_cambiar_estado_fila($Par_Spec_i, $Lar_Fila, 1, $Lv_Actor, $Par_Usuario_i, 'Rehabilitado al intentar crearlo de nuevo.');

        $Lar_Uso = $Par_Spec_i['uso']((int) $Lar_Otro['id']);

        return [
            'tipo'    => 'info',
            'id'      => (int) $Lar_Otro['id'],
            'mensaje' => sprintf(
                '«%s» ya existía entre los deshabilitados: se volvió a habilitar en lugar de crear un duplicado. %s Conserva sus datos anteriores; editá el registro si necesitás cambiarlos.',
                $Lar_Otro['rotulo'],
                $Lar_Uso['total'] > 0
                    ? sprintf('Tiene %d registros asociados (%s).', $Lar_Uso['total'], $Lar_Uso['detalle'])
                    : 'Todavía no tiene registros asociados.'
            ),
        ];
    }

    // Solo se escriben columnas declaradas en la definición.
    $Lar_Columnas = array_merge(array_keys($Par_Spec_i['campos']), $Par_Spec_i['extra']);
    $Lar_Datos    = array_intersect_key($Lar_Valores, array_flip($Lar_Columnas));

    if ($Lar_Actual === null) {
        $Lar_Datos = $Par_Spec_i['fijos'] + $Lar_Datos;
        $Lar_Cols  = array_keys($Lar_Datos);
        $Lar_Par   = [':created_by' => $Lv_Actor];

        foreach ($Lar_Cols as $Li_I => $Lv_Columna) {
            $Lar_Par[':v' . $Li_I] = $Lar_Datos[$Lv_Columna];
        }

        db_ejecutar(
            'INSERT INTO ' . $Par_Spec_i['tabla'] . ' (' . implode(', ', $Lar_Cols) . ', created_by)
             VALUES (' . implode(', ', array_map(static fn (int $Li_I): string => ':v' . $Li_I, array_keys($Lar_Cols))) . ', :created_by)',
            $Lar_Par
        );

        $Li_Id = (int) db_conexion()->lastInsertId();

        audit_registrar('alta', [
            'usuario_id'  => (int) $Par_Usuario_i['usuario_id'],
            'entidad'     => mant_entidad_auditoria($Par_Spec_i),
            'entidad_id'  => (string) $Li_Id,
            'valor_nuevo' => mant_resumen($Par_Spec_i, $Lar_Valores, null),
            'detalle'     => 'Alta en ' . mb_strtolower($Par_Spec_i['titulo']) . ': ' . mant_nombre($Par_Spec_i, $Lar_Valores),
        ]);

        return ['tipo' => 'ok', 'id' => $Li_Id, 'mensaje' => 'Se creó «' . mant_nombre($Par_Spec_i, $Lar_Valores) . '».'];
    }

    $Lar_Set = [];
    $Lar_Par = [':updated_by' => $Lv_Actor, ':id' => $Pi_Id_i];

    foreach (array_keys($Lar_Datos) as $Li_I => $Lv_Columna) {
        if (!empty($Par_Spec_i['campos'][$Lv_Columna]['solo_alta'])) {
            continue;
        }

        $Lar_Set[] = $Lv_Columna . ' = :v' . $Li_I;
        $Lar_Par[':v' . $Li_I] = $Lar_Datos[$Lv_Columna];
    }

    db_ejecutar(
        'UPDATE ' . $Par_Spec_i['tabla'] . '
         SET ' . implode(', ', $Lar_Set) . ', updated_at = SYSUTCDATETIME(), updated_by = :updated_by
         WHERE ' . $Par_Spec_i['pk'] . ' = :id',
        $Lar_Par
    );

    audit_registrar('cambio', [
        'usuario_id'     => (int) $Par_Usuario_i['usuario_id'],
        'entidad'        => mant_entidad_auditoria($Par_Spec_i),
        'entidad_id'     => (string) $Pi_Id_i,
        'valor_anterior' => mant_resumen($Par_Spec_i, $Lar_Actual, $Lar_Actual),
        'valor_nuevo'    => mant_resumen($Par_Spec_i, $Lar_Valores, $Lar_Actual),
        'detalle'        => 'Cambio en ' . mb_strtolower($Par_Spec_i['titulo']) . ': ' . mant_nombre($Par_Spec_i, $Lar_Valores),
    ]);

    return ['tipo' => 'ok', 'id' => $Pi_Id_i, 'mensaje' => 'Se guardaron los cambios de «' . mant_nombre($Par_Spec_i, $Lar_Valores) . '».'];
}

/** Aplica el cambio de estado y lo audita. */
function mant_cambiar_estado_fila(array $Par_Spec_i, array $Par_Fila_i, int $Pi_Nuevo_i, string $Pv_Actor_i, array $Par_Usuario_i, string $Pv_Detalle_i): void
{
    db_ejecutar(
        'UPDATE ' . $Par_Spec_i['tabla'] . '
         SET is_activo = :estado, updated_at = SYSUTCDATETIME(), updated_by = :actor
         WHERE ' . $Par_Spec_i['pk'] . ' = :id',
        [':estado' => $Pi_Nuevo_i, ':actor' => $Pv_Actor_i, ':id' => (int) $Par_Fila_i['id']]
    );

    audit_registrar($Pi_Nuevo_i === 1 ? 'cambio' : 'baja', [
        'usuario_id'     => (int) $Par_Usuario_i['usuario_id'],
        'entidad'        => mant_entidad_auditoria($Par_Spec_i),
        'entidad_id'     => (string) $Par_Fila_i['id'],
        'valor_anterior' => 'is_activo=' . (int) $Par_Fila_i['is_activo'],
        'valor_nuevo'    => 'is_activo=' . $Pi_Nuevo_i,
        'detalle'        => trim(($Pi_Nuevo_i === 1 ? 'Habilitación' : 'Deshabilitación') . ' en '
            . mb_strtolower($Par_Spec_i['titulo']) . ': «' . mant_nombre($Par_Spec_i, $Par_Fila_i) . '». ' . $Pv_Detalle_i),
    ]);
}

/**
 * Habilita o deshabilita un registro.
 *
 * @return array{mensaje:string, tipo:string}
 */
function mant_cambiar_estado(array $Par_Spec_i, array $Par_Usuario_i, int $Pi_Id_i): array
{
    $Lar_Fila = mant_fila($Par_Spec_i, $Par_Usuario_i, $Pi_Id_i);

    if ($Lar_Fila === null) {
        throw new InvalidArgumentException('El registro no existe o está fuera de tus países.');
    }

    $Li_Nuevo = (int) $Lar_Fila['is_activo'] === 1 ? 0 : 1;
    $Lv_Hook  = $Li_Nuevo === 1 ? 'antes_activar' : 'antes_desactivar';

    if (isset($Par_Spec_i[$Lv_Hook])) {
        $Par_Spec_i[$Lv_Hook]($Lar_Fila);
    }

    mant_cambiar_estado_fila($Par_Spec_i, $Lar_Fila, $Li_Nuevo, (string) $Par_Usuario_i['correo'], $Par_Usuario_i, '');

    return [
        'tipo'    => 'ok',
        'mensaje' => $Li_Nuevo === 1
            ? 'Se habilitó «' . mant_nombre($Par_Spec_i, $Lar_Fila) . '».'
            : 'Se deshabilitó «' . mant_nombre($Par_Spec_i, $Lar_Fila) . '». Deja de usarse; su historia se conserva.',
    ];
}
