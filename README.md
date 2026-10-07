# ENEB · Graduación Online

Web pública de graduados (`index.html`) + panel de administración (`admin.html`).
Frontend **React + Vite**; backend **PHP + MySQL** (`api/`).

## Estructura
```
index.html / admin.html   Entradas Vite (multi-página)
src/main-public.jsx       Arranque de la web pública
src/public/               Web pública (App, i18n, componentes, estilos)
src/admin/                Panel admin
  auth/                   Sesión y permisos (AuthContext, can())
  lib/                    api.js (fetch+CSRF), csv.js, pdf.js, countries.js
  components/, pages/     UI reutilizable y pestañas (Online, Presencial, Usuarios)
public/                   Estáticos (assets/, images/)
api/                      Endpoints PHP
  auth-lib.php            Sesión, CSRF, catálogo de permisos y roles por defecto
  auth.php / users.php    Login, setup, usuarios y roles
  migrations/auth.sql     Esquema de referencia (se crea solo)
```

## Desarrollo
```
npm install
cp api/config.example.php api/config.php   # rellenar credenciales
npm run api     # PHP en :8000
npm run dev     # Vite en :5173 (proxy /api -> :8000)
```

## Producción
`npm run build` y subir el contenido de `dist/` junto a la carpeta `api/` en el mismo dominio
(la sesión usa cookie same-origin). Si la API vive en otro origen, definir `ADMIN_ALLOWED_ORIGINS`
en `config.php` y `VITE_API_BASE` al compilar.

## Usuarios y permisos
- Primer acceso: `admin.html` muestra "Primera configuración". Se introduce la clave de instalación
  (`ADMIN_PASSWORD` de `config.php`) y se crea el superadmin. Después esa clave ya no se usa.
- Roles por defecto: Superadmin (`*`), Editor, Presenciales, Solo lectura. Se pueden crear roles propios
  desde "Usuarios y permisos".
- Sesión por cookie HttpOnly + token CSRF, límite de 5 intentos fallidos / 15 min, contraseñas ≥ 10 caracteres.
- **Añadir un permiso:** declararlo en `ADMIN_PERMISSIONS` (`api/auth-lib.php`), exigirlo con
  `requirePermission()` en el endpoint y ocultarlo en la UI con `can()`.
- **Añadir una sección al panel:** crear la página en `src/admin/pages/` y registrarla en `TABS` de `src/admin/App.jsx`.

Más información: `GUIA-USO.md`, `GUIA-GRADUADOS-ONLINE-CSV.md`.
