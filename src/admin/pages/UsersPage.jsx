// Gestión de usuarios y roles/permisos (requiere users.manage / roles.manage).
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "../auth/AuthContext.jsx";
import { api } from "../lib/api.js";
import { Alert, Field, Modal, Spinner } from "../components/ui.jsx";

export default function UsersPage() {
  const { can, user: me } = useAuth();
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [dialog, setDialog] = useState(null); // {type:'user'|'role', item?}

  const load = useCallback(async () => {
    try { setData(await api("/api/users.php")); setError(""); }
    catch (e) { setError(e.message); }
  }, []);
  useEffect(() => { load(); }, [load]);

  async function del(url, label) {
    if (!confirm("¿Eliminar " + label + "?")) return;
    try { await api(url, { method: "DELETE" }); load(); } catch (e) { setError(e.message); }
  }

  if (!data) return error ? <div className="page"><Alert type="error">{error}</Alert></div> : <Spinner />;

  return (
    <div className="page">
      <Alert type="error">{error}</Alert>
      {can("users.manage") && (
        <>
          <div className="toolbar">
            <h2 className="sub-title" style={{ margin: 0 }}>Usuarios</h2><span className="spacer" />
            <button className="btn-primary inline" onClick={() => setDialog({ type: "user" })}>Nuevo usuario</button>
          </div>
          <div className="table-scroll">
            <table className="data-table">
              <thead><tr><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th /></tr></thead>
              <tbody>
                {data.users.map((u) => (
                  <tr key={u.id}>
                    <td>{u.username}</td><td>{u.email}</td><td>{u.role}</td>
                    <td><span className={"pill " + (Number(u.active) ? "ok" : "off")}>{Number(u.active) ? "Activo" : "Inactivo"}</span></td>
                    <td>{u.last_login || "—"}</td>
                    <td className="row-actions">
                      <button className="btn-ghost" onClick={() => setDialog({ type: "user", item: u })}>Editar</button>
                      <button className="btn-danger" disabled={Number(u.id) === Number(me.id)} onClick={() => del("/api/users.php?id=" + u.id, u.username)}>Eliminar</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {can("roles.manage") && (
        <>
          <div className="toolbar" style={{ marginTop: 32 }}>
            <h2 className="sub-title" style={{ margin: 0 }}>Roles y permisos</h2><span className="spacer" />
            <button className="btn-primary inline" onClick={() => setDialog({ type: "role" })}>Nuevo rol</button>
          </div>
          <div className="table-scroll">
            <table className="data-table">
              <thead><tr><th>Rol</th><th>Descripción</th><th>Permisos</th><th /></tr></thead>
              <tbody>
                {data.roles.map((r) => (
                  <tr key={r.id}>
                    <td>{r.name} {r.is_system ? <span className="pill">sistema</span> : null}</td>
                    <td>{r.description}</td>
                    <td>{r.permissions.includes("*") ? "Todos" : r.permissions.length + " permisos"}</td>
                    <td className="row-actions">
                      {!r.is_system && <button className="btn-ghost" onClick={() => setDialog({ type: "role", item: r })}>Editar</button>}
                      {!r.is_system && <button className="btn-danger" onClick={() => del("/api/users.php?resource=roles&id=" + r.id, r.name)}>Eliminar</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {dialog?.type === "user" && <UserModal item={dialog.item} roles={data.roles} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />}
      {dialog?.type === "role" && <RoleModal item={dialog.item} permissions={data.permissions} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />}
    </div>
  );
}

/** Alta/edición de usuario. En edición, la contraseña vacía = no cambiarla. */
function UserModal({ item, roles, onClose, onSaved }) {
  const [f, setF] = useState({
    username: item?.username || "", email: item?.email || "", password: "",
    role_id: item?.role_id || roles[roles.length - 1]?.id, active: item ? Number(item.active) === 1 : true,
  });
  const [error, setError] = useState("");
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  async function save(e) {
    e.preventDefault();
    const body = { email: f.email, role_id: Number(f.role_id), active: f.active ? 1 : 0 };
    if (f.password) body.password = f.password;
    try {
      if (item) await api("/api/users.php?id=" + item.id, { method: "PUT", json: body });
      else await api("/api/users.php", { method: "POST", json: { ...body, username: f.username, password: f.password } });
      onSaved();
    } catch (err) { setError(err.message); }
  }

  return (
    <Modal title={item ? "Editar usuario" : "Nuevo usuario"} onClose={onClose}>
      <form onSubmit={save}>
        <Field label="Usuario"><input value={f.username} onChange={set("username")} disabled={!!item} required /></Field>
        <Field label="Email"><input type="email" value={f.email} onChange={set("email")} /></Field>
        <Field label="Rol">
          <select value={f.role_id} onChange={set("role_id")}>{roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select>
        </Field>
        <Field label="Contraseña" hint={item ? "Déjala vacía para no cambiarla" : "Mínimo 10 caracteres"}>
          <input type="password" value={f.password} onChange={set("password")} required={!item} autoComplete="new-password" />
        </Field>
        <label className="perm-grid"><span style={{ display: "flex", gap: 8 }}>
          <input type="checkbox" checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} /> Activo
        </span></label>
        <Alert type="error">{error}</Alert>
        <div className="actions">
          <button type="button" className="btn-ghost" onClick={onClose}>Cancelar</button>
          <button className="btn-primary inline">Guardar</button>
        </div>
      </form>
    </Modal>
  );
}

/** Alta/edición de rol con selección de permisos del catálogo del servidor. */
function RoleModal({ item, permissions, onClose, onSaved }) {
  const [name, setName] = useState(item?.name || "");
  const [description, setDescription] = useState(item?.description || "");
  const [perms, setPerms] = useState(new Set(item?.permissions || []));
  const [error, setError] = useState("");
  const toggle = (p) => setPerms((s) => { const n = new Set(s); n.has(p) ? n.delete(p) : n.add(p); return n; });

  async function save(e) {
    e.preventDefault();
    const json = { name, description, permissions: [...perms] };
    try {
      if (item) await api("/api/users.php?resource=roles&id=" + item.id, { method: "PUT", json });
      else await api("/api/users.php?resource=roles", { method: "POST", json });
      onSaved();
    } catch (err) { setError(err.message); }
  }

  return (
    <Modal title={item ? "Editar rol" : "Nuevo rol"} onClose={onClose} wide>
      <form onSubmit={save}>
        <Field label="Nombre"><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={60} /></Field>
        <Field label="Descripción"><input value={description} onChange={(e) => setDescription(e.target.value)} maxLength={255} /></Field>
        <Field label="Permisos">
          <div className="perm-grid">
            {Object.entries(permissions).map(([key, label]) => (
              <label key={key}><input type="checkbox" checked={perms.has(key)} onChange={() => toggle(key)} /> {label}</label>
            ))}
          </div>
        </Field>
        <Alert type="error">{error}</Alert>
        <div className="actions">
          <button type="button" className="btn-ghost" onClick={onClose}>Cancelar</button>
          <button className="btn-primary inline">Guardar</button>
        </div>
      </form>
    </Modal>
  );
}

