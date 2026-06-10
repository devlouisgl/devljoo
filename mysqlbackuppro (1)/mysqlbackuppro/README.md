# MySQL Backup Pro for Moodle 4.1+ (PRO Version)

**Plugin:** local_mysqlbackuppro
**Version:** 2.2.0 PRO
**Author:** Louis Jhosimar Ocampo | GestLife Dev
**License:** GPL v3
**Requires:** Moodle 4.1+ (2022112800), PHP 7.4+

---

## Descripcion

MySQL Backup Pro para Moodle es una extension local que automatiza y simplifica el proceso de respaldo de bases de datos MySQL. Es un port completo del plugin WordPress original, adaptado nativamente para Moodle 4.1+.

**Caracteristicas PRO:**
- Backups automaticos programados via Moodle Task API
- Backups manuales con un solo clic
- Cliente S3 nativo (Signature V4) - **Sin SDK, sin dependencias externas**
- Compatibilidad: Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces
- Explorador S3 completo con navegacion por carpetas, busqueda, filtros y paginacion
- Cifrado AES-256-CBC para credenciales sensibles
- Compresion Gzip automatica
- Retencion configurable de backups
- Notificaciones por email
- Sistema de logs completo
- Funciona en cPanel y cualquier hosting con PHP + MySQL

---

## Estructura del Plugin

```
local/mysqlbackuppro/
├── ajax/
│   └── ajax_handler.php       # Endpoints AJAX unificados
├── amd/src/
│   └── admin.js               # JavaScript AMD (interfaz interactiva)
├── classes/
│   ├── task/
│   │   └── scheduled_backup.php  # Tarea programada Moodle
│   ├── backup.php             # Logica central de backups
│   ├── crypto.php             # Cifrado AES-256-CBC
│   ├── logger.php             # Sistema de logs
│   └── s3native.php           # Cliente S3 nativo (Signature V4)
├── db/
│   ├── access.php             # Capacidades
│   ├── install.xml            # Esquema de base de datos
│   ├── tasks.php              # Definicion de tareas programadas
│   └── upgrade.php            # Script de actualizacion
├── lang/en/
│   └── local_mysqlbackuppro.php  # Cadenas de idioma
├── templates/
│   ├── backups.mustache       # Template pagina de backups
│   ├── dashboard.mustache     # Template dashboard
│   ├── logs.mustache          # Template logs
│   ├── modal.mustache         # Template modal progreso
│   ├── s3_explorer.mustache   # Template S3 Explorer
│   └── settings_page.mustache # Template configuracion
├── download.php               # Descarga de archivos locales
├── index.php                  # Pagina principal (router)
├── lib.php                    # Funciones y utilidades
├── settings.php               # Registro en menu admin
├── styles.css                 # Estilos CSS
├── version.php                # Informacion de version
└── README.md                  # Este archivo
```

---

## Instalacion

### Metodo 1: Instalacion via ZIP (Recomendado)

1. **Crear el ZIP** con la estructura correcta:
   ```
   mysqlbackuppro.zip
   └── local/
       └── mysqlbackuppro/
           ├── ajax/
           ├── amd/
           ├── classes/
           ├── db/
           ├── lang/
           ├── templates/
           ├── download.php
           ├── index.php
           ├── lib.php
           ├── settings.php
           ├── styles.css
           ├── version.php
           └── README.md
   ```

2. **En Moodle:** Ir a `Administracion del sitio > Plugins > Instalar plugins`

3. **Subir el ZIP** y seguir las instrucciones en pantalla

4. **Verificar requisitos:** El plugin verificara Moodle 4.1+ y PHP 7.4+

### Metodo 2: Instalacion Manual (FTP/cPanel)

1. **Subir archivos** via FTP/File Manager a:
   ```
   [moodle_root]/local/mysqlbackuppro/
   ```

2. **Comprobar permisos:**
   ```bash
   chmod -R 755 local/mysqlbackuppro
   ```

3. **Ir a Moodle:** `Administracion del sitio > Notificaciones`
   - Moodle detectara el nuevo plugin y ejecutara el script de instalacion

### Metodo 3: Linea de Comando (CLI)

```bash
cd /ruta/a/moodle
# Copiar el directorio local/mysqlbackuppro
php admin/cli/upgrade.php
```

---

## Configuracion Post-Instalacion

### 1. Capacidades (Roles)

Por defecto, solo el rol **Manager** puede acceder. Para permitir a otros roles:

`Administracion del sitio > Usuarios > Permisos > Definir roles`
- Buscar `local/mysqlbackuppro:manage` y `local/mysqlbackuppro:view`
- Asignar al rol deseado

### 2. Acceder al Plugin

`Administracion del sitio > Servidor > MySQL Backup Pro`

O directamente:
```
[tu-moodle]/local/mysqlbackuppro/index.php?page=dashboard
```

### 3. Configurar S3 (Contabo/AWS/MinIO)

Ir a la pestana **Settings** y completar:

| Campo | Valor Ejemplo (Contabo) |
|-------|------------------------|
| Endpoint URL | `https://eu2.contabostorage.com` |
| Region | `default` |
| Bucket Name | `mi-bucket` |
| Access Key | `tu-access-key` |
| Secret Key | `tu-secret-key` |
| Path Style | **Activado** (requerido para Contabo/MinIO) |
| Carpeta Base | `mysql-backups` |

### 4. Configurar Backups Automaticos

- **Habilitar:** Activar el toggle "Automatic Backups"
- **Frecuencia:** Diario, Semanal, Mensual, etc.
- **Hora:** Hora del backup (ej: 02:00)
- **Retencion:** Numero maximo de backups a conservar
- **Email:** Direccion para notificaciones

### 5. Configurar Tarea Programada (Cron)

Moodle ejecuta las tareas programadas via cron. Verificar:

`Administracion del sitio > Servidor > Tareas programadas`

Buscar: **MySQL Backup Pro - Scheduled Backup**

El cron de Moodle debe estar configurado:
- Via CLI: `php admin/cli/cron.php` (recomendado)
- Via web: Configurar wget/curl en crontab

Para cPanel, usar el **Cron Jobs**:
```bash
/usr/bin/php /home/tuusuario/public_html/admin/cli/cron.php >/dev/null 2>&1
```

---

## Migracion desde WordPress

Si estas migrando desde el plugin WordPress original:

1. **Las credenciales S3** deben reconfigurarse (se guardan en el sistema de config de Moodle)
2. **Los backups locales** no se migran automaticamente
3. **Los backups en S3** permanecen intactos en tu bucket
4. La estructura de carpetas S3 es identica: `{base_path}/{dominio}/{YYYY}/{MM}/`

---

## Compatibilidad

| Servicio | Compatible | Notas |
|----------|-----------|-------|
| Contabo Object Storage | Si | Path Style ON, region=default |
| AWS S3 | Si | Path Style OFF para buckets virtuales |
| MinIO | Si | Path Style ON |
| Wasabi | Si | Path Style ON |
| DigitalOcean Spaces | Si | Path Style ON |
| cPanel + MySQL | Si | Sin dependencias externas |

---

## Resolucion de Problemas

### Error "S3 connection failed"
- Verificar endpoint, access key y secret key
- Para Contabo/MinIO: Path Style debe estar activado
- Verificar que el bucket existe en la region correcta

### Los backups automaticos no se ejecutan
- Verificar que el cron de Moodle esta configurado
- Revisar `Administracion > Tareas programadas` que la tarea no este deshabilitada
- Revisar logs en la pestana Logs del plugin

### Error de permisos al crear backup
- Verificar que el directorio `[moodledata]/mysql-backup-pro/` es escribible
- En cPanel: permisos 755 para el directorio

### Email no llega
- Configurar correctamente el SMTP en Moodle: `Administracion > Servidor > Email > Opciones de correo`
- Usar el boton "Send Test Email" para verificar

---

## Seguridad

- Las credenciales S3 se almacenan **cifradas** con AES-256-CBC
- El directorio de backups esta protegido con `.htaccess`
- Las descargas requieren sesskey valido y capacidad `local/mysqlbackuppro:manage`
- Las peticiones AJAX validan sesskey y capacidades
- Los logs no exponen credenciales

---

## Licencia

GPL v3 - Ver archivo LICENSE para detalles.

---

**Desarrollado por:** Louis Jhosimar Ocampo | GestLife Dev
**Basado en:** MySQL Backup Pro para WordPress v2.2.0
