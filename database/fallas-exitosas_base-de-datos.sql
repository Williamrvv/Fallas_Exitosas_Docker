/* ============================================================================
   Fallas Exitosas · Construcción de la base de datos
   ----------------------------------------------------------------------------
   Propósito : Crear la base, el esquema `fx` (usuarios, roles, permisos y
               ámbito geográfico multinivel) y sembrar los datos iniciales.
   Motor     : Microsoft SQL Server 2016 o superior (usa SYSTEM_VERSIONING).
   Autor     : William Valverde V.
   Fecha     : 2026-09-25
   Bitácora  : 2026-09-24 Versión inicial (01_schema.sql + 02_seed.sql).
               2026-09-25 Unificados en un solo archivo ejecutable.

   Cómo ejecutarlo
   ---------------
   Este archivo se corre contra el motor de SQL Server que ya existe; el
   proyecto NO instala ni administra un motor propio.

   a) SSMS o Azure Data Studio: abrir el archivo y ejecutar (F5). El script
      hace su propio CREATE DATABASE y USE, así que puede empezar en `master`.
   b) sqlcmd:
        sqlcmd -S <servidor> -U <usuario> -P <clave> -i fallas-exitosas_base-de-datos.sql
   c) Sin cliente SQL instalado, desde el contenedor del proyecto:
        docker compose exec web php /opt/fallas-exitosas/database/cargar.php

   Notas
   -----
   - Idempotente: se puede ejecutar varias veces sin duplicar ni perder datos.
   - Los lotes van separados por GO porque CREATE SCHEMA y CREATE VIEW deben
     ir solos en su lote. Si el cliente no interpreta GO, usar el cargador (c).
   - El nombre de la base es `anc_fallas_exitosas` y aparece en el CREATE
     DATABASE y en los dos USE. Si se cambia, debe cambiarse en los tres
     puntos y también en DB_NAME del archivo .env.
   - Nomenclatura ANC: tablas snake_case, PK/FK `<entidad>_id`, booleanos `is_`,
     marcas de tiempo `_at`, índices `idx_`, llaves `pk_` / `fk_`.

   Contenido
   ---------
   Parte 1 · Estructura : base, esquema, jerarquía Región > País > Zona >
                          Oficina, roles, permisos, usuarios con historial,
                          sesiones, auditoría y vistas de acceso.
   Parte 2 · Datos      : región ANC, 5 países, 5 roles, 8 permisos, matriz de
                          permisos y el usuario administrador inicial.
   ============================================================================ */

/* ============================================================================
   PARTE 1 · ESTRUCTURA
   ============================================================================ */

SET NOCOUNT ON;
GO

/* ---------------------------------------------------------------------------
   1. Base de datos
   --------------------------------------------------------------------------- */
IF DB_ID('anc_fallas_exitosas') IS NULL
BEGIN
    CREATE DATABASE [anc_fallas_exitosas] COLLATE Modern_Spanish_CI_AI;
END
GO

USE [anc_fallas_exitosas];
GO

/* ---------------------------------------------------------------------------
   2. Esquema propio (evita mezclarse con otros objetos de la instancia)
   --------------------------------------------------------------------------- */
IF SCHEMA_ID('fx') IS NULL
BEGIN
    EXEC('CREATE SCHEMA fx AUTHORIZATION dbo;');
END
GO

/* ---------------------------------------------------------------------------
   3. Ámbito geográfico — jerarquía Región > País > Zona > Oficina
   --------------------------------------------------------------------------- */
IF OBJECT_ID('fx.region', 'U') IS NULL
BEGIN
    CREATE TABLE fx.region
    (
        region_id   INT IDENTITY(1,1) NOT NULL,
        codigo      VARCHAR(20)  NOT NULL,
        nombre      NVARCHAR(80) NOT NULL,
        is_activo   BIT          NOT NULL CONSTRAINT df_region_is_activo DEFAULT (1),
        created_at  DATETIME2(0) NOT NULL CONSTRAINT df_region_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_region PRIMARY KEY CLUSTERED (region_id),
        CONSTRAINT uq_region_codigo UNIQUE (codigo)
    );
END
GO

IF OBJECT_ID('fx.pais', 'U') IS NULL
BEGIN
    CREATE TABLE fx.pais
    (
        pais_id     INT IDENTITY(1,1) NOT NULL,
        region_id   INT          NOT NULL,
        codigo      VARCHAR(3)   NOT NULL,   -- ISO-3 usado por TSD: CRI, GTM, NIC, PER, SLV
        nombre      NVARCHAR(80) NOT NULL,
        fase        TINYINT      NOT NULL CONSTRAINT df_pais_fase DEFAULT (1),
        is_activo   BIT          NOT NULL CONSTRAINT df_pais_is_activo DEFAULT (1),
        created_at  DATETIME2(0) NOT NULL CONSTRAINT df_pais_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_pais PRIMARY KEY CLUSTERED (pais_id),
        CONSTRAINT uq_pais_codigo UNIQUE (codigo),
        CONSTRAINT fk_pais_region FOREIGN KEY (region_id) REFERENCES fx.region (region_id)
    );
END
GO

IF OBJECT_ID('fx.zona', 'U') IS NULL
BEGIN
    CREATE TABLE fx.zona
    (
        zona_id     INT IDENTITY(1,1) NOT NULL,
        pais_id     INT          NOT NULL,
        codigo      VARCHAR(20)  NOT NULL,
        nombre      NVARCHAR(80) NOT NULL,
        is_activo   BIT          NOT NULL CONSTRAINT df_zona_is_activo DEFAULT (1),
        created_at  DATETIME2(0) NOT NULL CONSTRAINT df_zona_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_zona PRIMARY KEY CLUSTERED (zona_id),
        CONSTRAINT uq_zona_pais_codigo UNIQUE (pais_id, codigo),
        CONSTRAINT fk_zona_pais FOREIGN KEY (pais_id) REFERENCES fx.pais (pais_id)
    );
END
GO

IF OBJECT_ID('fx.oficina', 'U') IS NULL
BEGIN
    CREATE TABLE fx.oficina
    (
        oficina_id    INT IDENTITY(1,1) NOT NULL,
        zona_id       INT          NOT NULL,
        codigo        VARCHAR(20)  NOT NULL,   -- código de oficina tal como viene de TSD
        nombre        NVARCHAR(120) NOT NULL,
        is_activo     BIT          NOT NULL CONSTRAINT df_oficina_is_activo DEFAULT (1),
        created_at    DATETIME2(0) NOT NULL CONSTRAINT df_oficina_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_oficina PRIMARY KEY CLUSTERED (oficina_id),
        CONSTRAINT uq_oficina_codigo UNIQUE (codigo),
        CONSTRAINT fk_oficina_zona FOREIGN KEY (zona_id) REFERENCES fx.zona (zona_id)
    );
END
GO

/* ---------------------------------------------------------------------------
   4. Catálogo de roles y permisos
   --------------------------------------------------------------------------- */
IF OBJECT_ID('fx.rol', 'U') IS NULL
BEGIN
    CREATE TABLE fx.rol
    (
        rol_id      INT IDENTITY(1,1) NOT NULL,
        codigo      VARCHAR(40)   NOT NULL,
        nombre      NVARCHAR(80)  NOT NULL,
        descripcion NVARCHAR(300) NULL,
        orden       TINYINT       NOT NULL CONSTRAINT df_rol_orden DEFAULT (99),
        is_activo   BIT           NOT NULL CONSTRAINT df_rol_is_activo DEFAULT (1),
        created_at  DATETIME2(0)  NOT NULL CONSTRAINT df_rol_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_rol PRIMARY KEY CLUSTERED (rol_id),
        CONSTRAINT uq_rol_codigo UNIQUE (codigo)
    );
END
GO

IF OBJECT_ID('fx.permiso', 'U') IS NULL
BEGIN
    CREATE TABLE fx.permiso
    (
        permiso_id  INT IDENTITY(1,1) NOT NULL,
        codigo      VARCHAR(60)  NOT NULL,   -- p. ej. dashboard, comentarios_ia, usuarios
        modulo      NVARCHAR(80) NOT NULL,
        orden       TINYINT      NOT NULL CONSTRAINT df_permiso_orden DEFAULT (99),
        created_at  DATETIME2(0) NOT NULL CONSTRAINT df_permiso_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_permiso PRIMARY KEY CLUSTERED (permiso_id),
        CONSTRAINT uq_permiso_codigo UNIQUE (codigo)
    );
END
GO

IF OBJECT_ID('fx.rol_permiso', 'U') IS NULL
BEGIN
    CREATE TABLE fx.rol_permiso
    (
        rol_id        INT         NOT NULL,
        permiso_id    INT         NOT NULL,
        nivel_acceso  VARCHAR(10) NOT NULL,   -- completo | ver
        CONSTRAINT pk_rol_permiso PRIMARY KEY CLUSTERED (rol_id, permiso_id),
        CONSTRAINT fk_rol_permiso_rol     FOREIGN KEY (rol_id)     REFERENCES fx.rol (rol_id),
        CONSTRAINT fk_rol_permiso_permiso FOREIGN KEY (permiso_id) REFERENCES fx.permiso (permiso_id),
        CONSTRAINT ck_rol_permiso_nivel   CHECK (nivel_acceso IN ('completo', 'ver'))
    );
END
GO

/* ---------------------------------------------------------------------------
   5. Usuarios — con historial automático (SYSTEM_VERSIONING)

   Regla de seguridad: Entra ID autentica, SQL Server autoriza.
   `entra_oid` es la identidad inmutable de Entra y se enlaza en el primer
   ingreso. El correo se guarda para búsqueda y alta masiva, pero NO es la
   llave de seguridad (un correo puede cambiar o reasignarse).
   --------------------------------------------------------------------------- */
IF OBJECT_ID('fx.usuario', 'U') IS NULL
BEGIN
    CREATE TABLE fx.usuario
    (
        usuario_id        INT IDENTITY(1,1) NOT NULL,
        entra_oid         UNIQUEIDENTIFIER NULL,        -- se completa en el primer ingreso
        correo            NVARCHAR(160)    NOT NULL,
        nombre            NVARCHAR(160)    NOT NULL,
        puesto            NVARCHAR(120)    NULL,
        is_activo         BIT              NOT NULL CONSTRAINT df_usuario_is_activo DEFAULT (1),
        ultimo_ingreso_at DATETIME2(0)     NULL,
        created_at        DATETIME2(0)     NOT NULL CONSTRAINT df_usuario_created_at DEFAULT (SYSUTCDATETIME()),
        created_by        NVARCHAR(160)    NULL,
        updated_at        DATETIME2(0)     NULL,
        updated_by        NVARCHAR(160)    NULL,
        valido_desde      DATETIME2(0) GENERATED ALWAYS AS ROW START NOT NULL,
        valido_hasta      DATETIME2(0) GENERATED ALWAYS AS ROW END   NOT NULL,
        PERIOD FOR SYSTEM_TIME (valido_desde, valido_hasta),
        CONSTRAINT pk_usuario PRIMARY KEY CLUSTERED (usuario_id),
        CONSTRAINT uq_usuario_correo UNIQUE (correo)
    )
    WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = fx.usuario_historial));

    CREATE UNIQUE INDEX idx_usuario_entra_oid
        ON fx.usuario (entra_oid) WHERE entra_oid IS NOT NULL;
END
GO

IF OBJECT_ID('fx.usuario_rol', 'U') IS NULL
BEGIN
    CREATE TABLE fx.usuario_rol
    (
        usuario_rol_id INT IDENTITY(1,1) NOT NULL,
        usuario_id     INT NOT NULL,
        rol_id         INT NOT NULL,
        created_at     DATETIME2(0)  NOT NULL CONSTRAINT df_usuario_rol_created_at DEFAULT (SYSUTCDATETIME()),
        created_by     NVARCHAR(160) NULL,
        valido_desde   DATETIME2(0) GENERATED ALWAYS AS ROW START NOT NULL,
        valido_hasta   DATETIME2(0) GENERATED ALWAYS AS ROW END   NOT NULL,
        PERIOD FOR SYSTEM_TIME (valido_desde, valido_hasta),
        CONSTRAINT pk_usuario_rol PRIMARY KEY CLUSTERED (usuario_rol_id),
        CONSTRAINT uq_usuario_rol UNIQUE (usuario_id, rol_id),
        CONSTRAINT fk_usuario_rol_usuario FOREIGN KEY (usuario_id) REFERENCES fx.usuario (usuario_id),
        CONSTRAINT fk_usuario_rol_rol     FOREIGN KEY (rol_id)     REFERENCES fx.rol (rol_id)
    )
    WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = fx.usuario_rol_historial));
END
GO

/* Ámbito asignado. Un usuario puede tener varios ámbitos y de distinto nivel.
   Solo la columna correspondiente al `nivel_ambito` va con valor.            */
IF OBJECT_ID('fx.usuario_ambito', 'U') IS NULL
BEGIN
    CREATE TABLE fx.usuario_ambito
    (
        usuario_ambito_id INT IDENTITY(1,1) NOT NULL,
        usuario_id        INT         NOT NULL,
        nivel_ambito      VARCHAR(10) NOT NULL,   -- region | pais | zona | oficina
        region_id         INT NULL,
        pais_id           INT NULL,
        zona_id           INT NULL,
        oficina_id        INT NULL,
        created_at        DATETIME2(0)  NOT NULL CONSTRAINT df_usuario_ambito_created_at DEFAULT (SYSUTCDATETIME()),
        created_by        NVARCHAR(160) NULL,
        valido_desde      DATETIME2(0) GENERATED ALWAYS AS ROW START NOT NULL,
        valido_hasta      DATETIME2(0) GENERATED ALWAYS AS ROW END   NOT NULL,
        PERIOD FOR SYSTEM_TIME (valido_desde, valido_hasta),
        CONSTRAINT pk_usuario_ambito PRIMARY KEY CLUSTERED (usuario_ambito_id),
        CONSTRAINT fk_usuario_ambito_usuario FOREIGN KEY (usuario_id) REFERENCES fx.usuario (usuario_id),
        CONSTRAINT fk_usuario_ambito_region  FOREIGN KEY (region_id)  REFERENCES fx.region (region_id),
        CONSTRAINT fk_usuario_ambito_pais    FOREIGN KEY (pais_id)    REFERENCES fx.pais (pais_id),
        CONSTRAINT fk_usuario_ambito_zona    FOREIGN KEY (zona_id)    REFERENCES fx.zona (zona_id),
        CONSTRAINT fk_usuario_ambito_oficina FOREIGN KEY (oficina_id) REFERENCES fx.oficina (oficina_id),
        CONSTRAINT ck_usuario_ambito_nivel CHECK (nivel_ambito IN ('region', 'pais', 'zona', 'oficina')),
        /* Exactamente una referencia con valor, coherente con el nivel declarado. */
        CONSTRAINT ck_usuario_ambito_coherente CHECK
        (
            (nivel_ambito = 'region'  AND region_id IS NOT NULL AND pais_id IS NULL     AND zona_id IS NULL     AND oficina_id IS NULL)
         OR (nivel_ambito = 'pais'    AND pais_id   IS NOT NULL AND region_id IS NULL   AND zona_id IS NULL     AND oficina_id IS NULL)
         OR (nivel_ambito = 'zona'    AND zona_id   IS NOT NULL AND region_id IS NULL   AND pais_id IS NULL     AND oficina_id IS NULL)
         OR (nivel_ambito = 'oficina' AND oficina_id IS NOT NULL AND region_id IS NULL  AND pais_id IS NULL     AND zona_id IS NULL)
        )
    )
    WITH (SYSTEM_VERSIONING = ON (HISTORY_TABLE = fx.usuario_ambito_historial));

    CREATE INDEX idx_usuario_ambito_usuario ON fx.usuario_ambito (usuario_id);
END
GO

/* ---------------------------------------------------------------------------
   Credencial local (correo y contraseña) — alternativa a Entra ID.

   Tabla aparte y SIN historial: los hashes anteriores no quedan guardados en
   fx.usuario_historial y cada intento fallido no genera una versión nueva.
   Sin fila en esta tabla, el usuario solo puede ingresar con Entra ID.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('fx.usuario_clave', 'U') IS NULL
BEGIN
    CREATE TABLE fx.usuario_clave
    (
        usuario_id        INT           NOT NULL,
        clave_hash        VARCHAR(255)  NOT NULL,   -- password_hash() de PHP
        intentos_fallidos TINYINT       NOT NULL CONSTRAINT df_usuario_clave_intentos DEFAULT (0),
        bloqueado_hasta   DATETIME2(0)  NULL,
        actualizada_at    DATETIME2(0)  NOT NULL CONSTRAINT df_usuario_clave_actualizada_at DEFAULT (SYSUTCDATETIME()),
        actualizada_by    NVARCHAR(160) NULL,
        CONSTRAINT pk_usuario_clave PRIMARY KEY CLUSTERED (usuario_id),
        CONSTRAINT fk_usuario_clave_usuario FOREIGN KEY (usuario_id) REFERENCES fx.usuario (usuario_id)
    );
END
GO

/* ---------------------------------------------------------------------------
   6. Sesiones activas y bitácora de auditoría
   --------------------------------------------------------------------------- */
IF OBJECT_ID('fx.sesion', 'U') IS NULL
BEGIN
    CREATE TABLE fx.sesion
    (
        sesion_id            BIGINT IDENTITY(1,1) NOT NULL,
        usuario_id           INT           NOT NULL,
        token_hash           CHAR(64)      NOT NULL,   -- SHA-256 del identificador de sesión
        direccion_ip         VARCHAR(45)   NULL,
        agente_usuario       NVARCHAR(400) NULL,
        iniciada_at          DATETIME2(0)  NOT NULL CONSTRAINT df_sesion_iniciada_at DEFAULT (SYSUTCDATETIME()),
        ultima_actividad_at  DATETIME2(0)  NOT NULL CONSTRAINT df_sesion_actividad_at DEFAULT (SYSUTCDATETIME()),
        cerrada_at           DATETIME2(0)  NULL,
        motivo_cierre        VARCHAR(30)   NULL,       -- salida | inactividad | maximo | revocada
        CONSTRAINT pk_sesion PRIMARY KEY CLUSTERED (sesion_id),
        CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES fx.usuario (usuario_id)
    );

    CREATE INDEX idx_sesion_token ON fx.sesion (token_hash) WHERE cerrada_at IS NULL;
END
GO

IF OBJECT_ID('fx.auditoria', 'U') IS NULL
BEGIN
    CREATE TABLE fx.auditoria
    (
        auditoria_id   BIGINT IDENTITY(1,1) NOT NULL,
        usuario_id     INT            NULL,           -- NULL en eventos previos a identificar al usuario
        correo_actor   NVARCHAR(160)  NULL,
        accion         VARCHAR(40)    NOT NULL,       -- ingreso | salida | alta | cambio | baja | acceso_denegado
        entidad        VARCHAR(60)    NULL,
        entidad_id     VARCHAR(60)    NULL,
        valor_anterior NVARCHAR(MAX)  NULL,
        valor_nuevo    NVARCHAR(MAX)  NULL,
        detalle        NVARCHAR(400)  NULL,
        direccion_ip   VARCHAR(45)    NULL,
        created_at     DATETIME2(0)   NOT NULL CONSTRAINT df_auditoria_created_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT pk_auditoria PRIMARY KEY CLUSTERED (auditoria_id),
        CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES fx.usuario (usuario_id)
    );

    CREATE INDEX idx_auditoria_created_at ON fx.auditoria (created_at DESC);
END
GO

/* ---------------------------------------------------------------------------
   7. Vistas de resolución de acceso
   --------------------------------------------------------------------------- */
GO
CREATE OR ALTER VIEW fx.v_usuario_permiso
AS
    /* Permiso efectivo por usuario. Si dos roles otorgan el mismo permiso,
       gana el nivel más amplio (completo sobre ver).                        */
    SELECT
        u.usuario_id       AS usuario_id,
        p.codigo           AS permiso_codigo,
        p.modulo           AS modulo,
        MIN(rp.nivel_acceso) AS nivel_acceso   -- 'completo' < 'ver' alfabéticamente
    FROM fx.usuario AS u
        INNER JOIN fx.usuario_rol AS ur ON ur.usuario_id = u.usuario_id
        INNER JOIN fx.rol         AS r  ON r.rol_id      = ur.rol_id AND r.is_activo = 1
        INNER JOIN fx.rol_permiso AS rp ON rp.rol_id     = r.rol_id
        INNER JOIN fx.permiso     AS p  ON p.permiso_id  = rp.permiso_id
    WHERE u.is_activo = 1
    GROUP BY u.usuario_id, p.codigo, p.modulo;
GO

CREATE OR ALTER VIEW fx.v_usuario_pais
AS
    /* Países efectivos por usuario, expandiendo los ámbitos superiores.
       Un ámbito de región concede todos los países activos de esa región.   */
    SELECT DISTINCT
        ua.usuario_id AS usuario_id,
        pa.pais_id    AS pais_id,
        pa.codigo     AS pais_codigo
    FROM fx.usuario_ambito AS ua
        INNER JOIN fx.pais AS pa
            ON  (ua.nivel_ambito = 'region'  AND pa.region_id = ua.region_id)
            OR  (ua.nivel_ambito = 'pais'    AND pa.pais_id   = ua.pais_id)
            OR  (ua.nivel_ambito = 'zona'    AND pa.pais_id   = (SELECT z.pais_id FROM fx.zona AS z WHERE z.zona_id = ua.zona_id))
            OR  (ua.nivel_ambito = 'oficina' AND pa.pais_id   = (SELECT z2.pais_id
                                                                 FROM fx.oficina AS o
                                                                     INNER JOIN fx.zona AS z2 ON z2.zona_id = o.zona_id
                                                                 WHERE o.oficina_id = ua.oficina_id))
    WHERE pa.is_activo = 1;
GO

PRINT 'Fallas Exitosas: esquema creado o ya existente.';
GO

/* ============================================================================
   PARTE 2 · DATOS INICIALES
   ============================================================================ */

SET NOCOUNT ON;
GO

USE [anc_fallas_exitosas];
GO

/* ---------------------------------------------------------------------------
   1. Región y países
   --------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM fx.region WHERE codigo = 'ANC-REG')
BEGIN
    INSERT INTO fx.region (codigo, nombre) VALUES ('ANC-REG', N'ANC Regional');
END
GO

DECLARE @region_id INT = (SELECT region_id FROM fx.region WHERE codigo = 'ANC-REG');

MERGE fx.pais AS destino
USING
(
    VALUES
        ('CRI', N'Costa Rica',  1),
        ('GTM', N'Guatemala',   1),
        ('NIC', N'Nicaragua',   1),
        ('PER', N'Perú',        1),
        ('SLV', N'El Salvador', 2)   -- fase 2: se crea inactivo
) AS origen (codigo, nombre, fase)
    ON destino.codigo = origen.codigo
WHEN NOT MATCHED BY TARGET THEN
    INSERT (region_id, codigo, nombre, fase, is_activo)
    VALUES (@region_id, origen.codigo, origen.nombre, origen.fase,
            CASE WHEN origen.fase = 1 THEN 1 ELSE 0 END);
GO

/* ---------------------------------------------------------------------------
   2. Roles
   --------------------------------------------------------------------------- */
MERGE fx.rol AS destino
USING
(
    VALUES
        ('administrador',       N'Administrador',           N'Acceso completo, incluida la administración de usuarios y catálogos.', 1),
        ('gerente_regional',    N'Gerente Regional',        N'Visibilidad de todos los países habilitados; no administra usuarios.', 2),
        ('operaciones',         N'Operaciones',             N'Gestión de casos, alertas y seguimiento dentro de su ámbito.',         3),
        ('experiencia_cliente', N'Experiencia del Cliente', N'Análisis de comentarios, causas y tendencias dentro de su ámbito.',    4),
        ('consulta',            N'Consulta',                N'Solo lectura de panel y tendencias dentro de su ámbito.',              5)
) AS origen (codigo, nombre, descripcion, orden)
    ON destino.codigo = origen.codigo
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, nombre, descripcion, orden)
    VALUES (origen.codigo, origen.nombre, origen.descripcion, origen.orden);
GO

/* ---------------------------------------------------------------------------
   3. Permisos por módulo
   --------------------------------------------------------------------------- */
MERGE fx.permiso AS destino
USING
(
    VALUES
        ('dashboard',      N'Panel y dashboards',   1),
        ('comentarios_ia', N'Comentarios IA',       2),
        ('casos',          N'Casos y seguimiento',  3),
        ('alertas',        N'Alertas',              4),
        ('exportar',       N'Exportar a Excel',     5),
        ('catalogos',      N'Catálogos y reglas',   6),
        ('usuarios',       N'Usuarios y roles',     7),
        ('auditoria',      N'Auditoría',            8)
) AS origen (codigo, modulo, orden)
    ON destino.codigo = origen.codigo
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, modulo, orden)
    VALUES (origen.codigo, origen.modulo, origen.orden);
GO

/* ---------------------------------------------------------------------------
   4. Matriz de roles y permisos

   Refleja la matriz aprobada el 2026-09-24. Los permisos marcados como 'ver'
   para Operaciones y Experiencia del Cliente sobre `catalogos`, y para Gerente
   Regional sobre `casos`, quedan pendientes de confirmación con Operaciones.
   --------------------------------------------------------------------------- */
MERGE fx.rol_permiso AS destino
USING
(
    SELECT r.rol_id, p.permiso_id, m.nivel_acceso
    FROM
    (
        VALUES
            -- Administrador: acceso completo a todo
            ('administrador','dashboard','completo'), ('administrador','comentarios_ia','completo'),
            ('administrador','casos','completo'),     ('administrador','alertas','completo'),
            ('administrador','exportar','completo'),  ('administrador','catalogos','completo'),
            ('administrador','usuarios','completo'),  ('administrador','auditoria','completo'),

            -- Gerente Regional: ve todo su ámbito, no administra
            ('gerente_regional','dashboard','completo'), ('gerente_regional','comentarios_ia','ver'),
            ('gerente_regional','casos','ver'),          ('gerente_regional','alertas','completo'),
            ('gerente_regional','exportar','completo'),  ('gerente_regional','auditoria','ver'),

            -- Operaciones
            ('operaciones','dashboard','completo'), ('operaciones','comentarios_ia','completo'),
            ('operaciones','casos','completo'),     ('operaciones','alertas','completo'),
            ('operaciones','exportar','completo'),  ('operaciones','catalogos','ver'),

            -- Experiencia del Cliente
            ('experiencia_cliente','dashboard','completo'), ('experiencia_cliente','comentarios_ia','completo'),
            ('experiencia_cliente','casos','completo'),     ('experiencia_cliente','alertas','completo'),
            ('experiencia_cliente','exportar','completo'),  ('experiencia_cliente','catalogos','ver'),

            -- Consulta: solo lectura
            ('consulta','dashboard','ver'), ('consulta','comentarios_ia','ver')
    ) AS m (rol_codigo, permiso_codigo, nivel_acceso)
        INNER JOIN fx.rol     AS r ON r.codigo = m.rol_codigo
        INNER JOIN fx.permiso AS p ON p.codigo = m.permiso_codigo
) AS origen (rol_id, permiso_id, nivel_acceso)
    ON  destino.rol_id     = origen.rol_id
    AND destino.permiso_id = origen.permiso_id
WHEN MATCHED AND destino.nivel_acceso <> origen.nivel_acceso THEN
    UPDATE SET nivel_acceso = origen.nivel_acceso
WHEN NOT MATCHED BY TARGET THEN
    INSERT (rol_id, permiso_id, nivel_acceso)
    VALUES (origen.rol_id, origen.permiso_id, origen.nivel_acceso);
GO

/* ---------------------------------------------------------------------------
   5. Administrador inicial

   Único usuario sembrado. Entra ID lo autentica; esta fila lo autoriza.
   --------------------------------------------------------------------------- */
DECLARE @correo_admin NVARCHAR(160) = N'william.valverde@grupoanc.com';

IF NOT EXISTS (SELECT 1 FROM fx.usuario WHERE correo = @correo_admin)
BEGIN
    INSERT INTO fx.usuario (entra_oid, correo, nombre, puesto, is_activo, created_by)
    VALUES (NULL, @correo_admin, N'William Valverde V.', N'Líder técnico', 1, N'seed');
END
GO

DECLARE @usuario_id INT = (SELECT usuario_id FROM fx.usuario WHERE correo = N'william.valverde@grupoanc.com');
DECLARE @rol_id     INT = (SELECT rol_id     FROM fx.rol     WHERE codigo = 'administrador');
DECLARE @region_id  INT = (SELECT region_id  FROM fx.region  WHERE codigo = 'ANC-REG');

IF NOT EXISTS (SELECT 1 FROM fx.usuario_rol WHERE usuario_id = @usuario_id AND rol_id = @rol_id)
BEGIN
    INSERT INTO fx.usuario_rol (usuario_id, rol_id, created_by)
    VALUES (@usuario_id, @rol_id, N'seed');
END

/* Ámbito regional: concede todos los países activos de la región. */
IF NOT EXISTS (SELECT 1 FROM fx.usuario_ambito WHERE usuario_id = @usuario_id AND nivel_ambito = 'region')
BEGIN
    INSERT INTO fx.usuario_ambito (usuario_id, nivel_ambito, region_id, created_by)
    VALUES (@usuario_id, 'region', @region_id, N'seed');
END
GO

/* ---------------------------------------------------------------------------
   6. Verificación
   --------------------------------------------------------------------------- */
SELECT
    u.correo          AS correo,
    u.nombre          AS nombre,
    r.nombre          AS rol,
    COUNT(vp.pais_id) AS paises_visibles
FROM fx.usuario AS u
    INNER JOIN fx.usuario_rol  AS ur ON ur.usuario_id = u.usuario_id
    INNER JOIN fx.rol          AS r  ON r.rol_id      = ur.rol_id
    LEFT  JOIN fx.v_usuario_pais AS vp ON vp.usuario_id = u.usuario_id
GROUP BY u.correo, u.nombre, r.nombre;

PRINT 'Fallas Exitosas: datos iniciales aplicados.';
GO
