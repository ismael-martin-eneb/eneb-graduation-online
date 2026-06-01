# ENEB · Graduación Online — Guía de Uso

> **Versión:** 2026 · **Entorno:** `dev-graduados.eneb.es`

---

## Índice

1. [Visión general](#1-visión-general)
2. [Página pública de graduados](#2-página-pública-de-graduados)
3. [Panel de administración](#3-panel-de-administración)
   - [Acceso y login](#31-acceso-y-login)
   - [Buscar un alumno](#32-buscar-un-alumno)
   - [Subir o cambiar la foto de un alumno](#33-subir-o-cambiar-la-foto-de-un-alumno)
   - [Eliminar la foto de un alumno](#34-eliminar-la-foto-de-un-alumno)
   - [Editar información del alumno](#35-editar-información-del-alumno)
4. [Flujo automático de datos (webhooks)](#4-flujo-automático-de-datos-webhooks)
   - [Registro de alumno desde Zoho Forms](#41-registro-de-alumno-desde-zoho-forms)
   - [Recepción automática de fotos desde Zoho WorkDrive](#42-recepción-automática-de-fotos-desde-zoho-workdrive)
5. [Procesado de fotos con IA](#5-procesado-de-fotos-con-ia)
6. [Compartir ficha de un alumno](#6-compartir-ficha-de-un-alumno)
7. [Preguntas frecuentes y resolución de problemas](#7-preguntas-frecuentes-y-resolución-de-problemas)
8. [Referencia de la API (técnica)](#8-referencia-de-la-api-técnica)
9. [Configuración del servidor (para técnicos)](#9-configuración-del-servidor-para-técnicos)

---

## 1. Visión general

La aplicación **ENEB Graduación Online** es una plataforma web que muestra públicamente los graduados de ENEB organizados por programa de máster. Consta de dos partes:

| Parte | URL | Acceso |
|---|---|---|
| Página pública | `/` (`index.html`) | Cualquier usuario |
| Panel de administración | `/admin.html` | Solo personal de ENEB (contraseña) |

**Flujo de datos resumido:**

```
Alumno completa formulario Zoho Forms
        ↓
Webhook → Base de datos (nombre, frase, ID de alumno)
        ↓
Zoho WorkDrive (foto) → webhook-foto → AWS S3
        ↓
Panel admin (revisión y ajustes manuales)
        ↓
Página pública (muestra a los graduados)
```

---

## 2. Página pública de graduados

Accesible en la raíz del sitio. No requiere contraseña.

### Funcionalidades

| Función | Cómo usarla |
|---|---|
| **Ver todos los graduados** | Se cargan automáticamente al entrar a la página |
| **Buscar** | Escribe en la caja de búsqueda (filtra por nombre o país en tiempo real) |
| **Ver ficha completa** | Haz clic en la tarjeta de cualquier graduado |
| **Cambiar idioma** | Usa el selector de idioma (ES / EN) en la parte superior |
| **Compartir ficha** | Abre la ficha → botón "Compartir perfil" (copia la URL directa) |

### Información que muestra cada ficha

- Nombre completo
- País de origen (con bandera)
- Programa de máster
- Nota media y distinción (Aprobado, Notable, Sobresaliente, Cum Laude…)
- Badges obtenidos (ej.: *Graduate Cum Laude*)
- Frase o mensaje personal del alumno
- Foto (o avatar genérico si aún no tiene foto asignada)

### URL directa a un alumno

Cualquier ficha tiene una URL única de la forma:

```
https://dev-graduados.eneb.es/#alumno=ID_DEL_ALUMNO
```

Compartir ese enlace abre directamente la ficha del alumno, útil para difusión en redes sociales o WhatsApp.

---

## 3. Panel de administración

### 3.1 Acceso y login

1. Accede a `https://dev-graduados.eneb.es/admin.html`
2. Introduce la **contraseña de administrador** (facilitada por el equipo técnico)
3. Haz clic en **Entrar**

> Si la contraseña es incorrecta, aparecerá el mensaje *"Contraseña incorrecta"*.  
> La sesión se mantiene activa mientras no cierres o recargues la página. Para cerrar sesión usa el botón **Salir** de la cabecera.

---

### 3.2 Buscar un alumno

- Usa la **barra de búsqueda** en la cabecera del panel.
- Filtra por nombre (búsqueda en tiempo real mientras escribes).
- El contador en la cabecera muestra cuántos resultados coinciden.

---

### 3.3 Subir o cambiar la foto de un alumno

Hay **dos métodos** para subir una foto:

#### Método A — Arrastrar y soltar (drag & drop)

1. Localiza la tarjeta del alumno en el panel.
2. Arrastra el archivo de imagen directamente **sobre la tarjeta**.
3. La tarjeta se iluminará en rojo al detectar el archivo. Suéltalo.
4. Aparecerá el diálogo de opciones de subida (ver más abajo).

#### Método B — Botón de subida

1. Pasa el cursor por encima de la tarjeta del alumno.
2. Aparecerá el overlay con los botones de acción; haz clic en **Subir foto**.
3. Selecciona el archivo en el diálogo del sistema operativo.
4. Aparecerá el diálogo de opciones de subida.

#### Diálogo de opciones de subida

Antes de confirmar la subida puedes elegir:

| Opción | Descripción |
|---|---|
| **Procesar con IA (eliminar fondo)** ✓ | Activo por defecto. El sistema envía la imagen a Google Vertex AI (modelo Imagen), que recorta al alumno y elimina el fondo, dejando un resultado más limpio y uniforme. |
| Sin IA | Si desactivas la casilla, se sube la imagen tal cual, sin modificar. |

- Formatos aceptados: **JPG / PNG**
- Tamaño máximo recomendado: **5 MB**
- La imagen se almacena en **AWS S3** y se referencia en la base de datos automáticamente.

La barra de progreso en la parte inferior de la tarjeta indica el estado de la subida:

| Estado | Color | Significado |
|---|---|---|
| Gris | Idle | Sin actividad |
| Ámbar | Subiendo… | Procesando |
| Verde | ✓ Foto guardada | Subida correcta |
| Rojo | Error | Ha fallado; inténtalo de nuevo |

---

### 3.4 Eliminar la foto de un alumno

1. Pasa el cursor por encima de la tarjeta.
2. Haz clic en el botón **Eliminar foto** (texto en rojo claro).
3. La foto quedará eliminada y el alumno volverá a mostrar el avatar genérico en la página pública.

> Esta acción **no borra** el fichero de S3 ni el registro del alumno; solo desvincula la foto de su perfil.

---

### 3.5 Editar información del alumno

1. Pasa el cursor por encima de la tarjeta.
2. Haz clic en el botón **Editar**.
3. Se abrirá un formulario con los campos editables:

| Campo | Descripción |
|---|---|
| **ID de alumno** | Identificador numérico de Moodle (obligatorio) |
| **Frase** | Mensaje personal del alumno (máx. 100 caracteres) |
| **País** | Código ISO de 2 letras, ej.: `ES`, `MX`, `CO` (opcional) |

4. Haz clic en **Guardar** para confirmar los cambios.

---

## 4. Flujo automático de datos (webhooks)

Estos procesos son **automáticos** y normalmente no requieren intervención manual. Se documentan aquí para que el equipo entienda de dónde vienen los datos.

### 4.1 Registro de alumno desde Zoho Forms

Cuando un alumno completa el **formulario de graduación en Zoho Forms**, este envía automáticamente los datos al servidor mediante un webhook:

- **URL del webhook:** `https://dev-graduados.eneb.es/api/webhook-zoho.php?token=TOKEN_SECRETO`
- **Método:** `POST`
- **Campos que se guardan:** nombre completo, ID de alumno (Moodle), frase representativa, referencia de foto, fecha

**Lógica de deduplicación:**  
Si el alumno ya existe en la base de datos (mismo `id_alumno`) y el nombre coincide en ≥ 80 %, el registro se **actualiza** en lugar de duplicarse.

**Campos esperados del formulario Zoho:**

| Campo en Zoho | Descripción |
|---|---|
| `nombre_completo` | Nombre y apellidos |
| `id_estudiante` | ID numérico de Moodle |
| `frase_representativa` | Cita o mensaje personal |
| `foto_referencia` | URL/referencia del fichero en Zoho WorkDrive |
| `hora_agregado` | Timestamp de envío |

---

### 4.2 Recepción automática de fotos desde Zoho WorkDrive

Cuando se sube una foto a Zoho WorkDrive con el nombre correcto, otro webhook la transfiere a AWS S3:

- **URL del webhook:** `https://dev-graduados.eneb.es/api/webhook-foto.php?token=TOKEN_SECRETO`
- **Método:** `POST`

**Convención de nombre de archivo:**  
El sistema extrae el ID de alumno del nombre del fichero. El formato esperado es:

```
ENEB_EXPERIENCE_Rev._Nombre_Apellido_IDMOODLE.jpg
```

Ejemplo: `ENEB_EXPERIENCE_Rev._Simon_Robert_Wake_20931.jpg` → ID de alumno: **20931**

> Si el fichero no sigue esta convención, la foto **no se vinculará** al alumno automáticamente y habrá que asignarla manualmente desde el panel de administración.

---

## 5. Procesado de fotos con IA

El sistema usa **Google Vertex AI** (modelo `imagen-3.0-capability-001`) para mejorar las fotos antes de publicarlas.

**Qué hace:**
- Detecta al alumno en la imagen
- Elimina o sustituye el fondo por uno neutro (blanco/gris)
- Devuelve la imagen recortada y lista para publicar

**Cuándo se activa:**
- Al subir manualmente desde el panel admin (si la casilla "Procesar con IA" está marcada)
- Opcionalmente en el flujo automático de WebHook de fotos

**Cuándo desactivarlo:**
- Si la foto ya viene con fondo limpio o la IA produce resultados incorrectos, desmarca la casilla "Procesar con IA" antes de confirmar la subida.

---

## 6. Compartir ficha de un alumno

### Desde la página pública

1. Haz clic en la tarjeta del alumno para abrir su ficha.
2. Pulsa el botón **Compartir perfil** en la parte inferior del modal.
   - En móvil: usa el diálogo nativo de compartir del sistema operativo.
   - En escritorio: copia la URL al portapapeles automáticamente (aparece "*Enlace copiado*").
3. Comparte la URL generada. Tiene el formato:
   ```
   https://dev-graduados.eneb.es/#alumno=ID
   ```

---

## 7. Preguntas frecuentes y resolución de problemas

### La foto subida no aparece en la página pública

- Comprueba que la subida terminó con el indicador **verde** en el panel admin.
- Refresca la página pública (Ctrl+F5 para forzar caché).
- Si sigue sin aparecer, revisa los logs del servidor (`/var/log/...` o el panel de hosting).

### El alumno no aparece en el panel admin

- Verifica que el alumno ha completado el formulario de Zoho Forms.
- Comprueba que el webhook de Zoho está configurado con la URL y el token correctos.
- Revisa los logs del webhook: el fichero de log del servidor registrará cualquier error de validación.

### La IA devuelve una imagen incorrecta o con artefactos

- Sube la foto original sin procesado de IA (desmarca la casilla).
- Asegúrate de que la foto tenga buena iluminación y el alumno sea claramente visible.

### El panel de admin no deja entrar (contraseña incorrecta)

- La contraseña distingue mayúsculas y minúsculas.
- Si se ha olvidado, el equipo técnico debe consultarla en `api/config.php` (constante `ADMIN_PASSWORD`) y actualizarla si es necesario.

### La foto automática desde WorkDrive no se vincula al alumno

- El nombre del archivo debe terminar con el **ID de Moodle del alumno** antes de la extensión.
- Formato correcto: `ENEB_EXPERIENCE_Rev._Nombre_Apellido_IDMOODLE.jpg`

### Error "Base de datos no disponible" en la página pública

- Problema de conectividad con el servidor RDS de AWS.
- Contactar al equipo técnico con el mensaje de error exacto y la hora en que ocurrió.

---

## 8. Referencia de la API (técnica)

### `GET /api/graduates.php`

Devuelve todos los programas y graduados visibles.

**Respuesta:**
```json
{
  "programs": [
    { "id": "1", "name": "MBA - Máster en Dirección de Empresas", "shortName": "MBA", "year": 2026, "campus": "online" }
  ],
  "graduates": [
    {
      "id": "alumno-123",
      "name": "Nombre Apellido",
      "programId": "1",
      "country": "ES",
      "grade": 8.7,
      "honor": "notable_alto",
      "badges": [{ "id": "cum-laude", "label": "Graduate Cum Laude", "icon": "diploma" }],
      "message": "Frase del alumno",
      "photo": "https://s3.amazonaws.com/...",
      "gender": "M",
      "year": 2026
    }
  ]
}
```

---

### `GET /api/admin-photos.php` *(requiere autenticación)*

Lista todos los alumnos con su foto actual.

**Cabecera requerida:**
```
X-Admin-Password: <contraseña en base64>
```

**Parámetros opcionales:**
- `?q=nombre` — Filtra por nombre

---

### `POST /api/admin-photos.php` *(requiere autenticación)*

Sube, actualiza o elimina datos de un alumno.

| Acción | Campo `action` | Campos adicionales |
|---|---|---|
| Subir foto | *(omitir o `upload`)* | `lead_id`, `file` (multipart), `skip_ai` (opcional) |
| Eliminar foto | `delete` | `lead_id` |
| Editar info | `update_info` | `lead_id`, `id_alumno`, `frase`, `pais` |

---

### `POST /api/webhook-zoho.php?token=TOKEN`

Receptor del formulario Zoho Forms. Crea o actualiza el registro del alumno.

---

### `POST /api/webhook-foto.php?token=TOKEN`

Receptor de fotos desde Zoho WorkDrive. Descarga la imagen y la sube a S3.

---

## 9. Configuración del servidor (para técnicos)

### Archivo `api/config.php`

Contiene todas las credenciales y parámetros del sistema. **Nunca debe subirse al repositorio.**  
Variables clave:

| Constante | Descripción |
|---|---|
| `ADMIN_PASSWORD` | Contraseña del panel de admin |
| `WEBHOOK_SECRET_TOKEN` | Token para autenticar los webhooks de Zoho |
| `DB_DSN` / `DB_USER` / `DB_PASSWORD` | Conexión a MySQL/MariaDB (RDS AWS) |
| `DB_TABLE_ZOHO_LEADS` | Tabla con los datos de alumnos (`zoho_leads`) |
| `DB_TABLE_PROGRAMAS` | Tabla de programas (`eneb_programs`) |
| `DB_TABLE_GRADUADOS` | Tabla de graduados (`eneb_graduates`) |
| `MOODLE_BASE_URL_*` / `MOODLE_WS_TOKEN_*` | Credenciales de las 3 instancias Moodle (ES, COM, PT) |
| `ZOHO_WORKDRIVE_*` | Credenciales OAuth2 de Zoho WorkDrive |
| `AWS_S3_BUCKET` / `AWS_S3_*` | Bucket y credenciales de AWS S3 |
| `GOOGLE_SERVICE_ACCOUNT_KEY_FILE` | Ruta al JSON de la Service Account de GCP |
| `GOOGLE_CLOUD_PROJECT_ID` / `GOOGLE_AI_MODEL` | Configuración de Vertex AI (Imagen) |

### Base de datos — tablas principales

| Tabla | Contenido |
|---|---|
| `zoho_leads` | Datos del alumno: nombre, id_alumno, foto (URL S3), frase, país, payload raw |
| `eneb_programs` | Programas de máster disponibles |
| `eneb_graduates` | Relación alumno ↔ programa con nota y distinción |

### Almacenamiento de fotos

Las fotos se guardan en el bucket S3 configurado bajo el prefijo `fotos/`:

```
s3://BUCKET_NAME/fotos/NOMBRE_ARCHIVO.jpg
```

La URL pública resultante se almacena en la columna `foto` de `zoho_leads`.

### Renovación del token de Zoho WorkDrive

El access token de Zoho caduca cada **60 minutos** y se renueva automáticamente usando el refresh token almacenado en `api/config.php`. Si el webhook de fotos falla con error de autenticación, comprueba que `ZOHO_WORKDRIVE_REFRESH_TOKEN` sigue siendo válido.

---

*Documento generado para el equipo de ENEB · Junio 2026*
