# Fallas Exitosas · Plataforma regional de Calidad de Servicio

Front-end web del proyecto Fallas Exitosas: administración, usuarios, roles,
ámbito geográfico y, más adelante, los dashboards de calidad de servicio.

**Autor:** William Valverde V. · Grupo ANC
**Fecha:** 2026-09-24

---

## Qué levanta este proyecto

Un **único contenedor** (PHP 8.3 + Apache, servido por HTTPS).
SQL Server y n8n **viven fuera** y se alcanzan por red; este proyecto no los
administra ni los levanta.

| Componente | Dónde vive | Puerto |
|---|---|---|
| Front-end (este proyecto) | Contenedor Docker | `44320` (HTTPS) · `8320` (HTTP, solo redirige) |
| SQL Server | Motor ya instalado en el entorno de William | `1433` |
| n8n | Ya instalado aparte | — |

---

## Requisitos previos

1. **Docker Desktop** en ejecución.
2. **Motor de SQL Server propio, ya instalado**, y acceso de red hasta él
   desde este equipo. El proyecto no instala ningún motor.
3. **La base de datos creada** con `database/fallas-exitosas_base-de-datos.sql`
   (ver siguiente sección).
4. **El App Registration compartido de Entra ID** debe conservar activo el
   redirect Web `https://localhost:44320/callback.php`.

---

## 1. Crear la base de datos

Todo el código está en **un solo archivo**:

```
database/fallas-exitosas_base-de-datos.sql
```

Crea la base `anc_fallas_exitosas`, el esquema `fx` con sus 12 tablas y las
vistas de acceso, y siembra región, países, roles, permisos y el administrador
inicial. Es **idempotente**: se puede ejecutar varias veces sin duplicar.

### Opción A · con un cliente SQL (recomendada)

En **SSMS** o **Azure Data Studio**: abrir el archivo y ejecutar (F5). Puede
empezar conectado a `master`, porque el script hace su propio `CREATE DATABASE`
y `USE`.

Con **sqlcmd**:

```bash
sqlcmd -S <servidor> -U <usuario> -P <clave> -i database/fallas-exitosas_base-de-datos.sql
```

### Opción B · desde el contenedor, sin cliente SQL instalado

El contenedor ya trae los controladores y monta `database/` en solo lectura:

```bash
docker compose exec web php /opt/fallas-exitosas/database/cargar.php
```

Separa los lotes por `GO` igual que SSMS —necesario porque `CREATE SCHEMA` y
`CREATE VIEW` deben ir solos en su lote— y al final informa cuántas tablas,
roles, permisos, países y usuarios quedaron. Para revisar sin aplicar cambios:

```bash
docker compose exec web php /opt/fallas-exitosas/database/cargar.php --solo-verificar
```

### Contra qué servidor trabaja

Toma `DB_HOST` del `.env`. El motor es el que ya tiene William instalado:

| Dónde corre el motor | `DB_HOST` |
|---|---|
| Otro equipo de la red | su IP, p. ej. `192.168.0.3` |
| Esta misma laptop | `host.docker.internal` |
| Instancia nombrada (Express) | `192.168.0.3\SQLEXPRESS` |

Con **instancia nombrada** el puerto es dinámico: `DB_PORT` se ignora, lo
resuelve el servicio **SQL Server Browser** (UDP 1434), que debe estar activo.
En cualquier caso SQL Server necesita **TCP/IP habilitado** y la regla de
firewall correspondiente.

El esquema usa **tablas temporales del sistema** (SQL Server 2016+): al
desactivar o modificar un usuario, su rol o su ámbito, el histórico se conserva
automáticamente, sin borrado físico y sin triggers.

Se siembra **un solo usuario**: `william.valverde@grupoanc.com` como
Administrador con ámbito regional. El resto del padrón entra por alta masiva.

---

## 2. Configurar Microsoft Entra ID

Por decisión de William (DEC-41) se reutilizan el tenant, client ID y secret del
proyecto de Finanzas. No se crea una contraseña local y la aplicación nunca
recibe la contraseña corporativa: el usuario pulsa **Iniciar sesión con
Microsoft** y escribe correo/contraseña únicamente en la pantalla de Microsoft.

En Entra ID → *App registrations* → aplicación compartida → *Authentication*,
confirmar este redirect adicional:

| Campo | Valor |
|---|---|
| Redirect URI | **Web** → `https://localhost:44320/callback.php` |

Los scopes usados son `openid profile email`. Las variables
`O365_TENANT_ID`, `O365_CLIENT_ID` y `O365_CLIENT_SECRET` se cargan desde
`.env`; nunca deben registrarse en documentación ni logs.

> El `O365_TENANT_ID` ya viene configurado: es el mismo tenant de la
> organización.

Para entrar también desde otro equipo de la red, agregar en Entra un segundo
Redirect URI con la IP de esta máquina (hoy `https://192.168.0.12:44320/callback.php`)
y cambiar `APP_PUBLIC_ORIGIN` en el `.env`. Si la IP cambia, hay que actualizar
`APP_PUBLIC_ORIGIN`, `O365_REDIRECT_URI`, `TLS_CERTIFICATE_SAN` y el Redirect URI.

---

## 3. Levantar el contenedor

```bash
docker compose up -d
```

Luego abrir **https://localhost:44320**

En el primer arranque, el contenedor genera una **CA local y un certificado
TLS** dentro de un volumen de Docker. Para completar el retorno de Entra sin
advertencias, la CA debe estar en las raíces de confianza del usuario de
Windows. Primero se exporta:

```bash
docker compose cp web:/var/lib/fallas-exitosas/tls/local-ca.crt ./local-ca.crt
```

e importarla en *Entidades de certificación raíz de confianza*.

> Instalar una CA modifica la confianza del equipo. Verificá que el certificado
> tenga el sujeto `Grupo ANC / Fallas Exitosas Local CA` antes de importarlo y
> retiralo al dejar de usar este ambiente.

### Comandos útiles

```bash
docker compose logs -f web      # ver el log
docker compose restart web      # aplicar cambios del .env
docker compose down             # detener
docker compose up -d --build    # reconstruir tras cambiar el Dockerfile
curl -k https://localhost:44320/health.php  # liveness del contenedor
curl -k https://localhost:44320/ready.php   # SQL + esquema fx (ready/503)
```

---

## Modelo de acceso

**Entra ID autentica · SQL Server autoriza.**

Tener cuenta corporativa válida no alcanza: la persona debe estar dada de alta
y activa en `fx.usuario`. El enlace con Entra se hace por `oid` (identidad
inmutable), no por correo, de modo que un cambio de correo no rompe ni
transfiere accesos.

### Roles

| Rol | Alcance |
|---|---|
| Administrador | Todo, incluida la administración de usuarios |
| Gerente Regional | Visibilidad de todos los países habilitados |
| Operaciones | Casos, alertas y seguimiento en su ámbito |
| Experiencia del Cliente | Comentarios, causas y tendencias en su ámbito |
| Consulta | Solo lectura de panel y tendencias |

### Ámbito geográfico

Jerarquía **Región › País › Zona › Oficina**. Un usuario puede tener varios
ámbitos. Un ámbito de región concede todos los países activos de esa región
(así ven los cuatro países quienes lo necesitan, sin listarlos uno a uno).

### Sesión

30 minutos de inactividad y 8 horas de tope absoluto. La salida cierra la
sesión local y también la de Entra.

---

## Estructura

```
fallas-exitosas/
├─ compose.yaml            Orquestación del contenedor
├─ Dockerfile              PHP 8.3 + Apache + controladores SQL Server
├─ .env                    Configuración real (NO versionar)
├─ .env.example            Plantilla de configuración
├─ database/               Scripts de base de datos
├─ docker/                 Apache, PHP, TLS y sonda de salud
└─ src/
   ├─ app/                 Configuración, datos, OIDC, sesión, permisos
   └─ public/              Raíz web (lo único accesible desde el navegador)
```

Solo `src/public/` queda expuesto: el código de `src/app/` no es alcanzable
desde el navegador.

---

## Pendientes declarados

- **Usuario de base de datos con mínimo privilegio.** Hoy `DB_USER` reutiliza
  la cuenta administrativa del servidor. Conviene crear una cuenta propia con
  permisos solo sobre el esquema `fx`.
- **Disponibilidad de SQL Server.** El login no puede finalizar mientras el
  motor de `DB_HOST` no responda y no se haya aplicado
  `database/fallas-exitosas_base-de-datos.sql`. `health.php` solo mide el
  proceso web; `ready.php` devuelve 503 hasta que SQL y el esquema estén listos.
- **Zonas y oficinas.** Las tablas existen y están vacías: se van a sincronizar
  desde TSD para no mantener un maestro duplicado a mano.
- **Matriz de permisos.** Queda por confirmar con Operaciones si Operaciones y
  Experiencia del Cliente editan catálogos y cambian el estado de los casos.
- **Dashboards.** El panel muestra el alcance real del usuario; los indicadores
  se incorporan cuando el flujo de análisis alimente la base.
