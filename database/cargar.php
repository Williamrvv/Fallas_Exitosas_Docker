<?php
/**
 * Fallas Exitosas · Cargador de la base de datos.
 *
 * Propósito : Aplicar los scripts SQL sin depender de SSMS ni de sqlcmd. Se
 *             ejecuta dentro del contenedor, que ya trae los controladores.
 * Autor     : William Valverde V.
 * Fecha     : 2026-09-25
 * Bitácora  : 2026-09-25 Versión inicial.
 *
 * Uso       : docker compose exec web php /opt/fallas-exitosas/database/cargar.php
 *             Agregar --solo-verificar para revisar sin aplicar cambios.
 *
 * Notas     : Se conecta a `master` porque la base puede no existir todavía;
 *             los scripts hacen el USE hacia la base del proyecto. Los lotes se
 *             separan por GO, igual que en SSMS, porque sentencias como
 *             CREATE SCHEMA o CREATE VIEW deben ir solas en su lote.
 */

declare(strict_types=1);

const RUTA_SCRIPTS = __DIR__;

/** Escribe una línea en la salida estándar. */
function decir(string $Pv_Texto_i): void
{
    fwrite(STDOUT, $Pv_Texto_i . PHP_EOL);
}

/** Termina el proceso con un mensaje de error. */
function abortar(string $Pv_Texto_i): never
{
    fwrite(STDERR, 'ERROR: ' . $Pv_Texto_i . PHP_EOL);
    exit(1);
}

/** Lee una variable de entorno obligatoria. */
function entorno(string $Pv_Nombre_i, ?string $Pv_PorDefecto_i = null): string
{
    $Lv_Valor = getenv($Pv_Nombre_i);

    if ($Lv_Valor === false || $Lv_Valor === '') {
        if ($Pv_PorDefecto_i === null) {
            abortar("falta la variable de entorno {$Pv_Nombre_i}");
        }

        return $Pv_PorDefecto_i;
    }

    return $Lv_Valor;
}

/**
 * Divide un script en lotes usando GO como separador, igual que SSMS.
 * GO no es una sentencia de SQL Server: es una marca del cliente.
 *
 * @return string[]
 */
function separar_lotes(string $Pv_Script_i): array
{
    $Lar_Crudos = preg_split('/^\s*GO\s*;?\s*$/mi', $Pv_Script_i) ?: [];
    $Lar_Lotes  = [];

    foreach ($Lar_Crudos as $Lv_Lote) {
        $Lv_Lote = trim($Lv_Lote);

        if ($Lv_Lote !== '') {
            $Lar_Lotes[] = $Lv_Lote;
        }
    }

    return $Lar_Lotes;
}

// --- Parámetros ------------------------------------------------------------
$Gb_SoloVerificar = in_array('--solo-verificar', $argv, true);

$Gv_Host   = entorno('DB_HOST');
$Gi_Puerto = (int) entorno('DB_PORT', '1433');
$Gv_Base   = entorno('DB_NAME');
$Gv_Usuario   = entorno('DB_USER');
$Gv_Contrasena = entorno('DB_PASSWORD');
$Gb_Cifrar = strtolower(entorno('DB_ENCRYPT', 'no')) === 'yes';
$Gb_Confiar = strtolower(entorno('DB_TRUST_CERT', 'yes')) === 'yes';

// Instancia nombrada (por ejemplo EQUIPO\SQLEXPRESS): el puerto es dinámico y
// lo resuelve el SQL Browser, así que el DSN va sin puerto.
$Gb_InstanciaNombrada = str_contains($Gv_Host, '\\');
$Gv_Servidor = $Gb_InstanciaNombrada ? $Gv_Host : sprintf('%s,%d', $Gv_Host, $Gi_Puerto);

decir('Fallas Exitosas · carga de base de datos');
decir(str_repeat('-', 46));
decir("Servidor : {$Gv_Servidor}");
decir("Base     : {$Gv_Base}");
decir('Modo     : ' . ($Gb_SoloVerificar ? 'solo verificar' : 'aplicar'));
decir('');

// --- Alcance del servidor --------------------------------------------------
// Con instancia nombrada no se sondea el puerto: no se conoce de antemano.
if (!$Gb_InstanciaNombrada) {
    $Lo_Socket = @fsockopen($Gv_Host, $Gi_Puerto, $Li_ErrNo, $Lv_ErrStr, 5);

    if ($Lo_Socket === false) {
        abortar(
            "no hay respuesta en {$Gv_Host}:{$Gi_Puerto} ({$Lv_ErrStr}). " .
            'Verificá que el servidor esté encendido, que SQL Server tenga ' .
            'TCP/IP habilitado en ese puerto y que haya VPN si corresponde.'
        );
    }

    fclose($Lo_Socket);
    decir('Puerto alcanzable.');
}

// --- Conexión a master -----------------------------------------------------
$Gv_Dsn = sprintf(
    'sqlsrv:Server=%s;Database=master;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=10',
    $Gv_Servidor,
    $Gb_Cifrar ? 'yes' : 'no',
    $Gb_Confiar ? 'yes' : 'no'
);

try {
    $Go_Conexion = new PDO($Gv_Dsn, $Gv_Usuario, $Gv_Contrasena, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $Lo_Error) {
    abortar('no se pudo autenticar contra el servidor: ' . $Lo_Error->getMessage());
}

decir('Conexión establecida.');

$Gar_Version = $Go_Conexion->query('SELECT @@VERSION AS v')->fetch(PDO::FETCH_ASSOC);
decir('Motor    : ' . strtok((string) ($Gar_Version['v'] ?? ''), "\n"));
decir('');

// --- Estado previo ---------------------------------------------------------
$Lo_Consulta = $Go_Conexion->prepare('SELECT DB_ID(:base) AS id');
$Lo_Consulta->execute([':base' => $Gv_Base]);
$Gb_BaseExiste = $Lo_Consulta->fetch(PDO::FETCH_ASSOC)['id'] !== null;

decir('Base existente: ' . ($Gb_BaseExiste ? 'sí' : 'no'));

if ($Gb_SoloVerificar) {
    decir('');
    decir('Verificación completa. No se aplicó ningún cambio.');
    exit(0);
}

// --- Aplicación de los scripts --------------------------------------------
$Gar_Scripts = ['fallas-exitosas_base-de-datos.sql'];

foreach ($Gar_Scripts as $Gv_Script) {
    $Gv_Ruta = RUTA_SCRIPTS . '/' . $Gv_Script;

    if (!is_readable($Gv_Ruta)) {
        abortar("no se puede leer {$Gv_Ruta}");
    }

    $Gar_Lotes = separar_lotes((string) file_get_contents($Gv_Ruta));
    decir('');
    decir("→ {$Gv_Script} (" . count($Gar_Lotes) . ' lotes)');

    foreach ($Gar_Lotes as $Gi_Indice => $Gv_Lote) {
        try {
            $Go_Conexion->exec($Gv_Lote);
        } catch (PDOException $Lo_Error) {
            // Mostrar el inicio del lote ayuda a ubicar el problema rápido.
            $Lv_Extracto = trim(substr(preg_replace('/\s+/', ' ', $Gv_Lote) ?? '', 0, 120));

            abortar(sprintf(
                "%s, lote %d falló.%s  Inicio del lote: %s%s  Detalle: %s",
                $Gv_Script,
                $Gi_Indice + 1,
                PHP_EOL,
                $Lv_Extracto,
                PHP_EOL,
                $Lo_Error->getMessage()
            ));
        }
    }

    decir("   aplicado correctamente.");
}

// --- Comprobación final ----------------------------------------------------
decir('');
decir('Comprobando resultado…');

$Go_Conexion->exec('USE [' . str_replace(']', ']]', $Gv_Base) . ']');

$Gar_Resumen = $Go_Conexion->query(
    "SELECT
         (SELECT COUNT(*) FROM sys.tables AS t
              INNER JOIN sys.schemas AS s ON s.schema_id = t.schema_id
          WHERE s.name = 'fx')                 AS tablas,
         (SELECT COUNT(*) FROM fx.rol)         AS roles,
         (SELECT COUNT(*) FROM fx.permiso)     AS permisos,
         (SELECT COUNT(*) FROM fx.pais)        AS paises,
         (SELECT COUNT(*) FROM fx.usuario)     AS usuarios"
)->fetch(PDO::FETCH_ASSOC);

decir(sprintf(
    '  tablas fx: %d | roles: %d | permisos: %d | países: %d | usuarios: %d',
    (int) $Gar_Resumen['tablas'],
    (int) $Gar_Resumen['roles'],
    (int) $Gar_Resumen['permisos'],
    (int) $Gar_Resumen['paises'],
    (int) $Gar_Resumen['usuarios']
));

$Gar_Admin = $Go_Conexion->query(
    "SELECT TOP 1
         u.correo                AS correo,
         r.nombre                AS rol,
         (SELECT COUNT(*) FROM fx.v_usuario_pais AS vp
          WHERE vp.usuario_id = u.usuario_id) AS paises
     FROM fx.usuario AS u
         INNER JOIN fx.usuario_rol AS ur ON ur.usuario_id = u.usuario_id
         INNER JOIN fx.rol AS r ON r.rol_id = ur.rol_id
     ORDER BY u.usuario_id"
)->fetch(PDO::FETCH_ASSOC);

if ($Gar_Admin !== false) {
    decir(sprintf(
        '  administrador: %s · %s · %d países visibles',
        (string) $Gar_Admin['correo'],
        (string) $Gar_Admin['rol'],
        (int) $Gar_Admin['paises']
    ));
}

decir('');
decir('Base de datos lista.');
exit(0);
