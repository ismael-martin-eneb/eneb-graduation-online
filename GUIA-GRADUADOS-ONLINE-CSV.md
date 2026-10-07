# CSV Graduados Online - Guía de Uso

## Descripción

La nueva funcionalidad permite cargar graduados online mediante un archivo CSV en la pestaña "Online" del panel de administración. El sistema automatiza la verificación, actualización e inserción de registros en `zoho_leads`.

## Formato del CSV

El archivo CSV debe contener exactamente estas columnas (en cualquier orden):

| Columna | Descripción | Requerido | Ejemplo |
|---------|-------------|-----------|---------|
| `nombre` | Nombre completo del graduado | ✅ | Juan Pérez García |
| `id_alumno` | ID numérico del alumno en Moodle | ✅ | 1001 |
| `campus` | URL del campus Moodle o identificador | ✅ | https://moodle.campus1.com |
| `frase` | Frase representativa (máx. 255 caracteres) | ❌ | Una frase inspiradora |
| `timecreated` | Timestamp Unix o fecha en formato legible | ❌ | 1706745600 |

### Separadores Soportados

El sistema **detecta automáticamente** si el CSV usa **coma (`,`)** o **punto y coma (`;`)** como separador.

## Ejemplo de CSV (con punto y coma)

```csv
nombre;id_alumno;frase;timecreated;campus
Vincent-Ray Rivera Viray;19994;Distinguished with academic excellence.;11-Jun-2026 17:59:48;https://campus.eneb.com
Neptalí Julián Montoya Lanza;144292;¡Hasta el infinito...y mas allá!;18-Jun-2026 17:48:32;https://campusvirtual.eneb.es
Jesus Alvarez Castillo;173749;¡Las matemáticas es el lenguaje divino!;10-Jun-2026 02:19:13;https://campusvirtual.eneb.es
```

También se soporta **coma como separador**:

```csv
nombre,id_alumno,frase,timecreated,campus
Juan Pérez García,1001,"Una frase inspiradora",1706745600,https://campus1.eneb.com
María García López,1002,"Otra frase motivadora",1706832000,https://campus2.eneb.es
```

## Proceso de Carga

1. Acceder al panel de administración (`/admin.html`)
2. Ir a la pestaña "Online"
3. Hacer clic en "Cargar graduados desde CSV"
4. Seleccionar el archivo CSV
5. Hacer clic en "Subir y procesar"

## Lógica de Procesamiento

Para **cada fila** del CSV:

1. **Validación**: Se validan los campos requeridos (nombre, id_alumno, campus)
2. **Verificación**: Se busca si existe en `zoho_leads` la combinación `id_alumno + campus`
3. **Actualización** (si existe):
   - Se actualiza: nombre, frase, timecreated
4. **Inserción** (si no existe):
   - Se inserta el registro en `zoho_leads`
   - Se llama automáticamente a `getMoodleEmbajador_cli()` para obtener datos del alumno desde Moodle
   - Se guardan todos los datos en `raw_payload` para auditoria

## Respuesta del Sistema

Tras procesar el CSV, el sistema muestra:

- **Insertados**: Número de registros nuevos creados
- **Actualizados**: Número de registros existentes actualizados
- **Total**: Cantidad total de registros procesados
- **Errores**: Lista detallada de cualquier error por fila

### Estados de Respuesta

| Estado | Significado |
|--------|------------|
| ✅ Success | Todos los registros se procesaron correctamente |
| ⚠️ Info | Procesamiento completado pero con algunos errores |
| ❌ Error | Error crítico que impidió procesar el CSV |

## Validaciones

### Errores Comunes

| Error | Causa | Solución |
|-------|-------|----------|
| "El nombre es obligatorio" | Campo nombre vacío | Llenar campo nombre para la fila |
| "El id_alumno es obligatorio" | Campo id_alumno vacío | Agregar ID de alumno numérico |
| "El campus es obligatorio" | Campo campus vacío | Especificar URL del campus |
| "JSON inválido" | El servidor no pudo parsear los datos | Verificar formato del CSV |

### Validaciones de Campos

- **nombre**: Se normaliza (capitalización, espacios simples)
- **id_alumno**: Debe ser numérico positivo
- **campus**: Se guarda como texto exacto recibido
- **frase**: Máximo 255 caracteres (se trunca si es mayor)
- **timecreated**: Se acepta timestamp Unix o fecha legible (se convierte a Unix timestamp)

## Archivo de Ejemplo

Ver `ejemplo-graduados-online.csv` en la raíz del proyecto.

## Integración con Moodle

Cuando se **inserta** un registro nuevo, el sistema automáticamente:

1. Obtiene los datos del alumno desde la API de Moodle
2. Valida que el alumno existe en el campus especificado
3. Registra el estado en los logs del servidor

**Nota**: La actualización de registros existentes **NO** consulta Moodle. Solo ocurre en inserciones nuevas.

## Notas Técnicas

- La autenticación se realiza mediante cabecera `X-Admin-Password`
- Los datos se envían en formato JSON
- La API está en `/api/graduados-online-csv.php`
- El límite de registros por carga no está restringido (depende del tamaño del CSV y memoria del servidor)

## Soporte y Debugging

- Ver logs en: `/var/log/php-errors.log` o similar según configuración del servidor
- Activar la consola del navegador (F12) para ver errores de cliente
- Revisar la respuesta JSON del servidor en Network → graduados-online-csv.php
