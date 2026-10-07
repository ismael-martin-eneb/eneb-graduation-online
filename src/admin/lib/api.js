// Cliente HTTP del panel: gestiona cookies de sesión, token CSRF y errores.

const API_BASE = import.meta.env.VITE_API_BASE || "";

let csrfToken = "";
let onUnauthorized = () => {};

/** Guarda el token CSRF que devuelve /api/auth.php. */
export const setCsrf = (t) => { csrfToken = t || ""; };
/** Callback que se ejecuta cuando la API responde 401 (sesión caducada). */
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

/** Error de API con el código HTTP y el mensaje legible del servidor. */
export class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

/**
 * Petición a la API.
 * @param {string} path  Ruta, p. ej. "/api/users.php?id=3"
 * @param {{method?:string, json?:object, form?:FormData, silent401?:boolean}} opts
 *   `json` se serializa; `form` se envía como multipart (subida de fotos).
 */
export async function api(path, { method = "GET", json, form, silent401 = false } = {}) {
  const headers = {};
  let body;
  if (json !== undefined) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(json);
  } else if (form) {
    body = form;
  }
  if (method !== "GET") headers["X-CSRF-Token"] = csrfToken;

  let res;
  try {
    res = await fetch(API_BASE + path, { method, headers, body, credentials: "include" });
  } catch {
    throw new ApiError("No se pudo conectar con el servidor", 0);
  }
  let data = null;
  try { data = await res.json(); } catch { /* respuesta sin JSON */ }

  if (!res.ok) {
    if (res.status === 401 && !silent401) onUnauthorized();
    throw new ApiError((data && data.error) || "Error " + res.status, res.status, data);
  }
  return data;
}
