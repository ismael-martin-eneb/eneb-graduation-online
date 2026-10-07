// Modal genérico de importación CSV (graduados online y presenciales).
import { useState } from "react";
import { api } from "../lib/api.js";
import { Alert, Modal } from "./ui.jsx";

/**
 * @param {string} title        Título del modal
 * @param {string} columns      Texto con las columnas esperadas
 * @param {(text:string)=>object} parse  Convierte el CSV en el cuerpo JSON (lanza Error si es inválido)
 * @param {string} endpoint     URL a la que se hace POST
 * @param {()=>void} onDone     Se llama tras importar para recargar datos
 */
export default function CsvImportModal({ title, columns, parse, endpoint, onDone, onClose }) {
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setResult(null);
    try {
      const body = parse(await file.text());
      const res = await api(endpoint, { method: "POST", json: body });
      const errs = res.errors || [];
      setResult({
        type: errs.length ? "info" : "success",
        text: "Procesados: " + (res.inserted ?? 0) + " nuevos" +
          (res.updated !== undefined ? ", " + res.updated + " actualizados" : "") +
          " de " + (res.total ?? "?") + ".",
        errors: errs,
      });
      onDone();
    } catch (err) {
      setResult({ type: "error", text: err.message, errors: [] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal title={title} onClose={onClose} wide>
      <p className="csv-description">Selecciona un CSV (separador , o ;) con las columnas:<br /><strong>{columns}</strong></p>
      <form onSubmit={submit}>
        <input type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files[0])} required />
        {result && (
          <div style={{ marginTop: 14 }}>
            <Alert type={result.type}>{result.text}</Alert>
            {result.errors.length > 0 && (
              <ul style={{ fontSize: 12, color: "var(--muted)", maxHeight: 160, overflow: "auto", paddingLeft: 18 }}>
                {result.errors.map((er, i) => <li key={i}>{typeof er === "string" ? er : JSON.stringify(er)}</li>)}
              </ul>
            )}
          </div>
        )}
        <div className="actions">
          <button type="button" className="btn-ghost" onClick={onClose}>Cerrar</button>
          <button className="btn-primary inline" disabled={!file || busy}>{busy ? "Procesando…" : "Subir y procesar"}</button>
        </div>
      </form>
    </Modal>
  );
}
