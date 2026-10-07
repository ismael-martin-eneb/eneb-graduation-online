// Piezas de interfaz reutilizables del panel.
import { useEffect } from "react";

/** Modal centrado. Se cierra con Escape o clic en el fondo. */
export function Modal({ title, onClose, wide = false, children }) {
  useEffect(() => {
    const onKey = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={"modal" + (wide ? " wide" : "")} role="dialog" aria-modal="true">
        <h3>{title}</h3>
        {children}
      </div>
    </div>
  );
}

/** Mensaje de estado. type: error | success | info */
export const Alert = ({ type = "info", children }) =>
  children ? <div className={"alert " + type}>{children}</div> : null;

/** Campo de formulario con etiqueta. */
export const Field = ({ label, hint, children }) => (
  <div className="field">
    <label>{label}</label>
    {children}
    {hint && <p className="field-hint">{hint}</p>}
  </div>
);

export const Spinner = ({ text }) => (
  <div className="center-msg"><div className="spinner" />{text && <span>{text}</span>}</div>
);

/** Hook: devuelve el valor tras `delay` ms sin cambios (para búsquedas). */
import { useState } from "react";
export function useDebounced(value, delay = 350) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), delay);
    return () => clearTimeout(id);
  }, [value, delay]);
  return v;
}
