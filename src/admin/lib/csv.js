// Utilidades para leer CSV en el navegador (sin dependencias).

/**
 * Parsea texto CSV a filas de arrays. Detecta el separador (, ; o tab) por la
 * primera línea, respeta comillas dobles ("" = comilla escapada) y saltos de
 * línea dentro de campos entre comillas.
 */
export function parseCsvRows(text) {
  const src = text.replace(/^\uFEFF/, "");
  const firstLine = src.split(/\r?\n/, 1)[0] || "";
  const delimiter = firstLine.includes(";") ? ";" : firstLine.includes("\t") ? "\t" : ",";

  const rows = [];
  let row = [], field = "", inQuotes = false;
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (inQuotes) {
      if (ch === '"' && src[i + 1] === '"') { field += '"'; i++; }
      else if (ch === '"') inQuotes = false;
      else field += ch;
    } else if (ch === '"') inQuotes = true;
    else if (ch === delimiter) { row.push(field); field = ""; }
    else if (ch === "\n" || ch === "\r") {
      if (ch === "\r" && src[i + 1] === "\n") i++;
      row.push(field); field = "";
      rows.push(row); row = [];
    } else field += ch;
  }
  if (field !== "" || row.length) { row.push(field); rows.push(row); }
  return rows.filter((r) => r.some((c) => c.trim() !== ""));
}

/** Convierte filas a objetos usando la primera fila como cabecera (en minúsculas). */
export function parseCsvObjects(text) {
  const rows = parseCsvRows(text);
  if (rows.length < 2) return { headers: [], records: [] };
  const headers = rows[0].map((h) => h.trim().toLowerCase());
  const records = rows.slice(1).map((r) =>
    Object.fromEntries(headers.map((h, i) => [h, (r[i] ?? "").trim()]))
  );
  return { headers, records };
}

/** Lee un File como texto UTF-8. */
export const readFileText = (file) => file.text();

/** Columnas del CSV de graduados online. */
export const ONLINE_COLUMNS = ["nombre", "id_alumno", "frase", "timecreated", "campus"];

/** Columnas del CSV de presenciales (orden usado si no hay cabecera reconocible). */
export const PRESENCIAL_COLUMNS = [
  "id_moodle", "nombre_diploma", "escuela", "idioma", "pais", "ultimo_programa",
  "n_programas_fin", "graduacion", "email", "telefono", "linkedin",
  "interes_profesional", "intolerancias", "vip",
];

/**
 * Prepara el CSV online. Devuelve {graduates} o lanza Error si faltan columnas.
 */
export function parseOnlineCsv(text) {
  const { headers, records } = parseCsvObjects(text);
  const missing = ["nombre", "id_alumno", "campus"].filter((c) => !headers.includes(c));
  if (missing.length) throw new Error("Faltan columnas obligatorias: " + missing.join(", "));
  if (!records.length) throw new Error("El CSV no contiene filas");
  return { graduates: records };
}

/**
 * Prepara el CSV de presenciales. Si la cabecera no incluye `nombre_diploma`
 * ni `id_moodle`, se asume el orden posicional de PRESENCIAL_COLUMNS.
 */
export function parsePresencialCsv(text) {
  const rows = parseCsvRows(text);
  if (rows.length < 2) throw new Error("El CSV no contiene filas");
  const headers = rows[0].map((h) => h.trim().toLowerCase());
  const hasHeader = headers.includes("nombre_diploma") || headers.includes("id_moodle");
  const cols = hasHeader ? headers : PRESENCIAL_COLUMNS;
  const data = hasHeader ? rows.slice(1) : rows;
  const students = data.map((r) =>
    Object.fromEntries(cols.map((c, i) => [c, (r[i] ?? "").trim()]))
  );
  return { students };
}
