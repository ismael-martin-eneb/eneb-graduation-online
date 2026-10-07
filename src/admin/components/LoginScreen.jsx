// Pantallas de acceso: login normal y primera configuración (primer superadmin).
import { useState } from "react";
import { useAuth } from "../auth/AuthContext.jsx";

export default function LoginScreen() {
  const { login, setup, needsSetup } = useAuth();
  const [form, setForm] = useState({ username: "", email: "", password: "", setup_key: "" });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      if (needsSetup) await setup(form);
      else await login(form.username, form.password);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="auth-screen">
      <div className="login-card">
        <div className="logo">ENEB</div>
        <div className="subtitle">
          {needsSetup ? "Primera configuración: crea el superadministrador" : "Panel de Administración — Graduaciones"}
        </div>
        <form onSubmit={submit}>
          {needsSetup && (
            <>
              <label>Clave de instalación</label>
              <input type="password" value={form.setup_key} onChange={set("setup_key")} required autoComplete="off" />
            </>
          )}
          <label>Usuario</label>
          <input value={form.username} onChange={set("username")} required autoComplete="username" autoFocus />
          {needsSetup && (
            <>
              <label>Email</label>
              <input type="email" value={form.email} onChange={set("email")} autoComplete="email" />
            </>
          )}
          <label>Contraseña {needsSetup && "(mín. 10 caracteres)"}</label>
          <input type="password" value={form.password} onChange={set("password")} required
                 autoComplete={needsSetup ? "new-password" : "current-password"} />
          <p className="form-error">{error}</p>
          <button className="btn-primary" disabled={busy}>
            {busy ? "Un momento…" : needsSetup ? "Crear y entrar" : "Entrar"}
          </button>
        </form>
      </div>
    </div>
  );
}
