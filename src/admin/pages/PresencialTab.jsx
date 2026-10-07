// Pestaña "Presencial": tabla de alumnos, importación CSV, borrado y PDF de acreditaciones.
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "../auth/AuthContext.jsx";
import { api } from "../lib/api.js";
import { PRESENCIAL_COLUMNS, parsePresencialCsv } from "../lib/csv.js";
import { generateStudentsPdf } from "../lib/pdf.js";
import CsvImportModal from "../components/CsvImportModal.jsx";
import { Alert, Spinner } from "../components/ui.jsx";

export default function PresencialTab({ search, onCount }) {
  const { can } = useAuth();
  const [rows, setRows] = useState(null);
  const [selected, setSelected] = useState(new Set());
  const [error, setError] = useState("");
  const [csvOpen, setCsvOpen] = useState(false);
  const [pdfBusy, setPdfBusy] = useState(false);

  const load = useCallback(async () => {
    try {
      const data = await api("/api/presenciales-csv.php?q=" + encodeURIComponent(search));
      setRows(data.data);
      onCount(data.data.length);
      setSelected(new Set());
      setError("");
    } catch (e) { setError(e.message); setRows([]); }
  }, [search, onCount]);

  useEffect(() => { load(); }, [load]);

  const toggle = (id) => setSelected((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });
  const allSelected = rows?.length > 0 && selected.size === rows.length;
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)));

  async function remove(row) {
    if (!confirm("¿Eliminar a " + row.nombre_diploma + "?")) return;
    try {
      await api("/api/presenciales-csv.php?id=" + row.id, { method: "DELETE" });
      load();
    } catch (e) { setError(e.message); }
  }

  async function makePdf() {
    setPdfBusy(true);
    try { await generateStudentsPdf(rows.filter((r) => selected.has(r.id))); }
    catch (e) { setError("No se pudo generar el PDF: " + e.message); }
    finally { setPdfBusy(false); }
  }

  return (
    <div className="page">
      <div className="toolbar">
        {can("presenciales.import") && (
          <button className="btn-primary inline" onClick={() => setCsvOpen(true)}>Cargar alumnos desde CSV</button>
        )}
        {can("presenciales.pdf") && (
          <button className="btn-ghost" disabled={!selected.size || pdfBusy} onClick={makePdf}>
            {pdfBusy ? "Generando…" : "Generar PDF seleccionados (" + selected.size + ")"}
          </button>
        )}
      </div>
      <Alert type="error">{error}</Alert>
      {rows === null ? <Spinner text="Cargando…" /> : (
        <div className="table-scroll">
          <table className="presenciales-table">
            <thead>
              <tr>
                <th><input type="checkbox" checked={allSelected} onChange={toggleAll} aria-label="Seleccionar todos" /></th>
                <th>Nombre diploma</th><th>ID Moodle</th><th>Escuela</th><th>Idioma</th><th>País</th>
                <th>Último programa</th><th>Graduación</th><th>Email</th><th>VIP</th><th>LinkedIn</th><th />
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && <tr><td colSpan={12} className="empty-state">No hay alumnos presenciales.</td></tr>}
              {rows.map((r) => (
                <tr key={r.id}>
                  <td><input type="checkbox" checked={selected.has(r.id)} onChange={() => toggle(r.id)} /></td>
                  <td>{r.nombre_diploma}</td><td>{r.id_moodle}</td><td>{r.escuela}</td><td>{r.idioma}</td>
                  <td>{r.pais}</td><td>{r.ultimo_programa}</td><td>{r.graduacion}</td><td>{r.email}</td>
                  <td>{Number(r.vip) === 1 ? "★" : ""}</td>
                  <td>{r.linkedin ? <a href={r.linkedin} target="_blank" rel="noopener noreferrer">ver</a> : ""}</td>
                  <td>{can("presenciales.delete") && <button className="btn-danger" onClick={() => remove(r)}>Eliminar</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {csvOpen && (
        <CsvImportModal title="Cargar alumnos presenciales desde CSV" columns={PRESENCIAL_COLUMNS.join(", ")}
          parse={parsePresencialCsv} endpoint="/api/presenciales-csv.php" onDone={load} onClose={() => setCsvOpen(false)} />
      )}
    </div>
  );
}
