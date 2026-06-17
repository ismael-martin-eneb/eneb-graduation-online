# ENEB · Graduación Online — notas para llevarlo a producción

> **Última actualización:** 2026-06-16  
> **Cambios recientes:** Agregada funcionalidad de carga CSV para alumnos presenciales en pestaña "Presencial" del panel admin.

## Stack recomendado
- **Frontend:** Next.js 14 (App Router) desplegado en el subdominio (p. ej. `graduacion.eneb.com`). SSR/ISR para que cada ficha de alumno tenga URL propia indexable y compartible.
- **Base de datos:** Postgres gestionado (Supabase o Neon). Tablas: `programs`, `graduates`, `badges`, `graduate_badges`.
- **Autenticación del panel admin:** NextAuth con SSO corporativo de ENEB.
- **Almacenamiento de fotos:** Supabase Storage o S3 + CDN.

## Integración con Moodle
El flujo sugerido, ya que los datos vienen de Moodle:
1. **Webhook / cron nocturno** lee los cursos marcados como "Graduación Online" vía la API REST de Moodle (`core_enrol_get_enrolled_users`, `core_course_get_contents`).
2. Se sincroniza a Postgres: nombre, país, programa, nota, honores.
3. El alumno, tras comprar el producto "Graduación Online", queda marcado (`has_graduation_product = true`) y aparece publicado.
4. El panel admin permite: subir foto, editar mensaje personal, asignar badges manualmente (Class President, Cum Laude).

## Rutas Next.js
- `/` — pantalla pública (este prototipo).
- `/alumno/[id]` — ficha individual con metadatos OpenGraph para compartir bien en redes/WhatsApp.
- `/api/graduates` — endpoint JSON con filtros (`?q=`, `?program=`).
- `/admin` — CRUD protegido para la escuela.

## GDPR / consentimiento
- Al comprar el producto, checkbox explícito: "Acepto aparecer con mi nombre y foto en la página pública de graduados".
- En el panel admin: botón "ocultar de la página pública" sin borrar el registro.
- La URL `/alumno/[id]` debe responder 404 si el alumno no ha consentido.

## Mockup actual — qué está implementado
- Pantalla pública fiel al Figma (rojo, Poppins, Cooper Hewitt → Inter como fallback).
- Buscador por nombre y país (live-filter).
- 45 alumnos mock distribuidos en 6 másters de negocio.
- Modal de ficha con nombre, país, programa, honores, nota media, badges (Class President, Graduate Cum Laude) y mensaje personal.
- Share link: `#alumno=ID` permite abrir la ficha directa al compartir.
- Paralaje en el fondo al hacer scroll.
- Responsive desktop + tablet + móvil.
- Avatares SVG genéricos (silueta) sustituibles por fotos reales.

## Qué no hay aún (siguiente iteración)
- Cambio de idioma (ES/EN) para alumnos de LATAM/Portugal.
- Animación de entrada escalonada para el vídeo promocional.
- Filtro por programa en la UI (hoy se filtra por búsqueda de texto).
- Panel admin.
