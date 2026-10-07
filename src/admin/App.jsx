// Raíz del panel admin: control de sesión, cabecera, buscador y navegación por pestañas.
import { useState } from "react";
import { AuthProvider, useAuth } from "./auth/AuthContext.jsx";
import LoginScreen from "./components/LoginScreen.jsx";
import ChangePasswordModal from "./components/ChangePasswordModal.jsx";
import { Spinner, useDebounced } from "./components/ui.jsx";
import OnlineTab from "./pages/OnlineTab.jsx";
import PresencialTab from "./pages/PresencialTab.jsx";
import UsersPage from "./pages/UsersPage.jsx";

/**
 * Definición de pestañas. Para añadir una sección: crear la página y añadirla
 * aquí con el permiso que la habilita (la pestaña se oculta si no se tiene).
 */
const TABS = [
  { id: "online", label: "Online", perm: "graduates.view", search: true, Page: OnlineTab },
  { id: "presencial", label: "Presencial", perm: "presenciales.view", search: true, Page: PresencialTab },
  { id: "users", label: "Usuarios y permisos", perm: ["users.manage", "roles.manage"], search: false, Page: UsersPage },
];

function Panel() {
  const { user, can, logout } = useAuth();
  const [tab, setTab] = useState(null);
  const [query, setQuery] = useState("");
  const [count, setCount] = useState(null);
  const [pwOpen, setPwOpen] = useState(false);
  const search = useDebounced(query, 350);

  const allowed = TABS.filter((t) => [].concat(t.perm).some(can));
  const current = allowed.find((t) => t.id === tab) || allowed[0];

  return (
    <>
      <header className="admin-header">
        <div className="header-brand">ENEB <span>Graduaciones</span></div>
        {current?.search && (
          <div className="search-wrap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input type="search" placeholder="Buscar por nombre…" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
        )}
        {current?.search && count !== null && <span className="header-count">{count} resultados</span>}
        <div className="header-user" style={{ marginLeft: "auto" }}>
          <span>{user.username}</span><span className="role-badge">{user.role}</span>
          <button className="btn-ghost" onClick={() => setPwOpen(true)}>Contraseña</button>
          <button className="btn-logout" onClick={logout}>Cerrar sesión</button>
        </div>
      </header>

      <nav className="nav-tabs">
        {allowed.map((t) => (
          <button key={t.id} className={t.id === current.id ? "active" : ""} onClick={() => { setTab(t.id); setCount(null); }}>{t.label}</button>
        ))}
      </nav>

      {current ? <current.Page key={current.id} search={search} onCount={setCount} />
        : <div className="center-msg">Tu rol no tiene permisos asignados. Contacta con un administrador.</div>}
      {pwOpen && <ChangePasswordModal onClose={() => setPwOpen(false)} />}
    </>
  );
}

/** Decide entre carga, login o panel según el estado de sesión. */
function Gate() {
  const { loading, user } = useAuth();
  if (loading) return <Spinner />;
  return user ? <Panel /> : <LoginScreen />;
}

export default function AdminApp() {
  return <AuthProvider><Gate /></AuthProvider>;
}
