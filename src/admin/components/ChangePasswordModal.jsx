// Diálogo para que el usuario cambie su propia contraseña.
import { useState } from "react";
import { useAuth } from "../auth/AuthContext.jsx";
import { Alert, Field, Modal } from "./ui.jsx";

export default function ChangePasswordModal({ onClose }) {
  const { changePassword } = useAuth();
  const [cur, setCur] = useState("");
  const [next, setNext] = useState("");
  const [msg, setMsg] = useState(null);

  async function submit(e) {
    e.preventDefault();
    try {
      await changePassword(cur, next);
      setMsg({ type: "success", text: "Contraseña actualizada" });
      setCur(""); setNext("");
    } catch (err) {
      setMsg({ type: "error", text: err.message });
    }
  }

  return (
    <Modal title="Cambiar contraseña" onClose={onClose}>
      <form onSubmit={submit}>
        <Field label="Contraseña actual">
          <input type="password" value={cur} onChange={(e) => setCur(e.target.value)} required autoComplete="current-password" />
        </Field>
        <Field label="Nueva contraseña" hint="Mínimo 10 caracteres">
          <input type="password" value={next} onChange={(e) => setNext(e.target.value)} required autoComplete="new-password" />
        </Field>
        {msg && <Alert type={msg.type}>{msg.text}</Alert>}
        <div className="actions">
          <button type="button" className="btn-ghost" onClick={onClose}>Cerrar</button>
          <button className="btn-primary inline">Guardar</button>
        </div>
      </form>
    </Modal>
  );
}
