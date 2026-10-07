// Pestaña "Online": rejilla de graduados con subida/edición/borrado de fotos.
import { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "../auth/AuthContext.jsx";
import { api } from "../lib/api.js";
import { COUNTRIES, countryFlag, countryLabel } from "../lib/countries.js";
import { ONLINE_COLUMNS, parseOnlineCsv } from "../lib/csv.js";
import CsvImportModal from "../components/CsvImportModal.jsx";
import { Alert, Field, Modal, Spinner } from "../components/ui.jsx";

/** Construye el FormData de una acción sobre un lead. */
function leadForm(leadId, fields) {
  const fd = new FormData();
  fd.append("lead_id", leadId);
  Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
  return fd;
}

export default function OnlineTab({ search, onCount }) {
  const { can } = useAuth();
  const [leads, setLeads] = useState(null);
  const [error, setError] = useState("");
  const [csvOpen, setCsvOpen] = useState(false);
  // Un solo modal activo a la vez: {type:'upload'|'edit', lead, field, file}
  const [dialog, setDialog] = useState(null);

  const load = useCallback(async () => {
    try {
      const data = await api("/api/admin-photos.php?q=" + encodeURIComponent(search));
      setLeads(data.leads);
      onCount(data.leads.length);
      setError("");
    } catch (e) {
      setError(e.message);
      setLeads([]);
    }
  }, [search, onCount]);

  useEffect(() => { load(); }, [load]);

  /** Actualiza un lead en memoria sin recargar toda la lista. */
  const patchLead = (id, patch) =>
    setLeads((ls) => ls.map((l) => (l.id === id ? { ...l, ...patch } : l)));

  async function deletePhoto(lead, field) {
    if (!confirm("¿Eliminar la foto de " + lead.nombre + "?")) return;
    try {
      await api("/api/admin-photos.php", { method: "POST", form: leadForm(lead.id, { action: "delete", target_field: field }) });
      patchLead(lead.id, { [field]: null });
    } catch (e) { alert(e.message); }
  }

  async function deleteLead(lead) {
    if (!confirm("¿Eliminar al graduado " + lead.nombre + " y todos sus datos?")) return;
    try {
      await api("/api/admin-photos.php", { method: "POST", form: leadForm(lead.id, { action: "delete_lead" }) });
      setLeads((ls) => { const n = ls.filter((l) => l.id !== lead.id); onCount(n.length); return n; });
    } catch (e) { alert(e.message); }
  }

  return (
    <div className="page">
      <div className="toolbar">
        {can("csv.online.import") && (
          <button className="btn-primary inline" onClick={() => setCsvOpen(true)}>Cargar graduados desde CSV</button>
        )}
      </div>
      <Alert type="error">{error}</Alert>
      {leads === null ? <Spinner text="Cargando…" /> : leads.length === 0 ? (
        <div className="center-msg">No hay graduados que coincidan.</div>
      ) : (
        <div className="leads-grid">
          {leads.map((lead) => (
            <LeadCard key={lead.id} lead={lead}
              onUpload={(field, file) => setDialog({ type: "upload", lead, field, file })}
              onEdit={() => setDialog({ type: "edit", lead })}
              onDeletePhoto={deletePhoto} onDeleteLead={deleteLead} />
          ))}
        </div>
      )}

      {dialog?.type === "upload" && (
        <UploadModal {...dialog} onClose={() => setDialog(null)}
          onUploaded={(url) => { patchLead(dialog.lead.id, { [dialog.field]: url }); setDialog(null); }} />
      )}
      {dialog?.type === "edit" && (
        <EditModal lead={dialog.lead} onClose={() => setDialog(null)}
          onSaved={(patch) => { patchLead(dialog.lead.id, patch); setDialog(null); }} />
      )}
      {csvOpen && (
        <CsvImportModal title="Cargar graduados online desde CSV" columns={ONLINE_COLUMNS.join(", ")}
          parse={parseOnlineCsv} endpoint="/api/graduados-online-csv.php" onDone={load} onClose={() => setCsvOpen(false)} />
      )}
    </div>
  );
}

/** Tarjeta de un graduado: foto, acciones (según permisos) y arrastrar-y-soltar. */
function LeadCard({ lead, onUpload, onEdit, onDeletePhoto, onDeleteLead }) {
  const { can } = useAuth();
  const [drag, setDrag] = useState(false);
  const canUpload = can("photos.upload");

  return (
    <div className={"lead-card" + (drag ? " drag-over" : "")}
      onDragOver={(e) => { if (canUpload) { e.preventDefault(); setDrag(true); } }}
      onDragLeave={() => setDrag(false)}
      onDrop={(e) => {
        e.preventDefault(); setDrag(false);
        const f = e.dataTransfer.files[0];
        if (canUpload && f) onUpload("foto", f);
      }}>
      <div className="card-photo">
        {lead.foto ? <img src={lead.foto} alt={lead.nombre} loading="lazy" /> : <div className="no-photo"><span>Sin foto</span></div>}
        <div className="photo-overlay">
          {canUpload && <button className="btn-upload-photo" onClick={() => onUpload("foto", null)}>↑ Subir</button>}
          {canUpload && <button style={{ background: "var(--warn)", color: "#fff" }} onClick={() => onUpload("foto_graduate", null)}>↑ Graduado</button>}
          {can("graduates.edit") && <button className="btn-edit-lead" onClick={onEdit}>✎ Editar</button>}
          {can("photos.delete") && lead.foto && <button className="btn-delete-photo" onClick={() => onDeletePhoto(lead, "foto")}>✕</button>}
          {can("photos.delete") && lead.foto_graduate && <button className="btn-delete-photo" onClick={() => onDeletePhoto(lead, "foto_graduate")}>✕ G</button>}
          {can("graduates.delete") && <button style={{ background: "rgba(255,0,0,.3)", color: "#fff" }} onClick={() => onDeleteLead(lead)}>🗑</button>}
        </div>
      </div>
      <div className="card-info">
        <div className="card-name">{lead.nombre}</div>
        {lead.programas && <div className="card-program">{lead.programas}</div>}
        <div className="card-meta">
          <span className="meta-id">{lead.id_alumno ? "# " + lead.id_alumno : "Sin ID"}</span>
          {lead.pais && <span className="meta-id">{countryFlag(lead.pais)} {lead.pais}</span>}
        </div>
        {lead.frase && <div className="meta-frase">“{lead.frase}”</div>}
      </div>
      <div className={"card-status " + (lead.foto ? "ok" : "idle")}>{lead.foto ? "✓ Con foto" : "Sin foto asignada"}</div>
    </div>
  );
}

/** Modal de subida: selector/drag&drop, opción IA (solo foto principal) y barra de progreso. */
function UploadModal({ lead, field, file: initialFile, onClose, onUploaded }) {
  const [file, setFile] = useState(initialFile);
  const [useAi, setUseAi] = useState(false);
  const [busy, setBusy] = useState(false);
  const [pct, setPct] = useState(0);
  const [error, setError] = useState("");
  const input = useRef(null);

  async function send() {
    setBusy(true); setError("");
    // La IA puede tardar ~25 s: se anima la barra hasta el 90 % y se completa al responder
    const t0 = Date.now();
    const timer = setInterval(() => setPct(Math.min(90, ((Date.now() - t0) / 25000) * 90)), 200);
    try {
      const res = await api("/api/admin-photos.php", {
        method: "POST",
        form: leadForm(lead.id, { file, skip_ai: useAi && field === "foto" ? "0" : "1", target_field: field }),
      });
      setPct(100);
      onUploaded(res.foto_url);
    } catch (e) {
      setError(e.message); setBusy(false);
    } finally { clearInterval(timer); }
  }

  return (
    <Modal title={field === "foto" ? "Asignar foto" : "Asignar foto de graduado"} onClose={busy ? () => {} : onClose}>
      <p className="grad-name">{lead.nombre}</p>
      <div className="drop-zone" onClick={() => input.current.click()}
        onDragOver={(e) => e.preventDefault()}
        onDrop={(e) => { e.preventDefault(); if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); }}>
        <p className="dz-label"><strong>Arrastra una imagen aquí</strong><br />o haz clic para seleccionarla</p>
        <p style={{ marginTop: 8, fontSize: 12, color: "var(--ok)", minHeight: 16 }}>{file?.name}</p>
        <input ref={input} type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files[0])} />
      </div>
      {field === "foto" && (
        <label className="option-row">
          <input type="checkbox" checked={useAi} onChange={(e) => setUseAi(e.target.checked)} />
          <div className="opt-desc">
            <strong>Procesado con IA (EXPERIMENTAL)</strong>
            <span>Elimina el fondo y genera la foto con toga académica (tarda unos segundos)</span>
          </div>
        </label>
      )}
      {busy && <div className="card-progress"><div className="card-progress-bar" style={{ width: pct + "%" }} /></div>}
      <Alert type="error">{error}</Alert>
      <div className="actions">
        <button className="btn-ghost" onClick={onClose} disabled={busy}>Cancelar</button>
        <button className="btn-primary inline" onClick={send} disabled={!file || busy}>{busy ? "Subiendo…" : "Subir foto"}</button>
      </div>
    </Modal>
  );
}

/** Modal de edición de datos del graduado (nombre, ID Moodle, frase, país). */
function EditModal({ lead, onClose, onSaved }) {
  const [f, setF] = useState({
    nombre: lead.nombre || "", id_alumno: lead.id_alumno || "", frase: lead.frase || "",
    pais: lead.pais ? countryLabel([lead.pais, (COUNTRIES.find((c) => c[0] === lead.pais) || [0, ""])[1]]).replace(/ - $/, "") : "",
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  async function save() {
    setBusy(true); setError("");
    // El campo admite "ES - España" (datalist) o solo "ES": nos quedamos con el código
    const pais = f.pais.trim().split(/\s|-/)[0].toUpperCase();
    try {
      await api("/api/admin-photos.php", {
        method: "POST",
        form: leadForm(lead.id, { action: "update_info", nombre: f.nombre, id_alumno: f.id_alumno, frase: f.frase, pais }),
      });
      onSaved({ nombre: f.nombre.trim(), id_alumno: f.id_alumno, frase: f.frase.trim(), pais: pais || null });
    } catch (e) { setError(e.message); setBusy(false); }
  }

  return (
    <Modal title="Editar información" onClose={onClose}>
      <Field label="Nombre de alumno"><input value={f.nombre} onChange={set("nombre")} /></Field>
      <Field label="ID de alumno (Moodle)"><input type="number" min="1" value={f.id_alumno} onChange={set("id_alumno")} /></Field>
      <Field label="Frase representativa" hint={f.frase.length + "/100 caracteres"}>
        <textarea maxLength={100} value={f.frase} onChange={set("frase")} />
      </Field>
      <Field label="País" hint="Escribe el nombre o el código ISO (ej: ES)">
        <input list="pais-datalist" value={f.pais} onChange={set("pais")} autoComplete="off" />
        <datalist id="pais-datalist">{COUNTRIES.map((c) => <option key={c[0]} value={countryLabel(c)} />)}</datalist>
      </Field>
      <Alert type="error">{error}</Alert>
      <div className="actions">
        <button className="btn-ghost" onClick={onClose}>Cancelar</button>
        <button className="btn-primary inline" onClick={save} disabled={busy}>Guardar</button>
      </div>
    </Modal>
  );
}
