// Contexto de autenticación: estado de sesión, login/logout y comprobación de permisos.
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, setCsrf, setUnauthorizedHandler } from "../lib/api.js";

const AuthContext = createContext(null);

/** Hook de acceso al contexto: { user, can, login, logout, setup, ... } */
export const useAuth = () => useContext(AuthContext);

/**
 * Comprueba un permiso contra la lista del rol. '*' concede todo.
 * Solo afecta a la UI: el servidor vuelve a validar cada petición.
 */
export const hasPermission = (user, permission) =>
  !!user && (user.permissions.includes("*") || user.permissions.includes(permission));

export function AuthProvider({ children }) {
  const [state, setState] = useState({ loading: true, user: null, needsSetup: false });

  /** Aplica la respuesta de /api/auth.php (usuario + CSRF + needsSetup). */
  const apply = useCallback((data) => {
    setCsrf(data.csrf);
    setState({ loading: false, user: data.user || null, needsSetup: !!data.needsSetup });
  }, []);

  const refresh = useCallback(async () => {
    try {
      apply(await api("/api/auth.php", { silent401: true }));
    } catch {
      setState({ loading: false, user: null, needsSetup: false });
    }
  }, [apply]);

  useEffect(() => {
    refresh();
    // Sesión caducada en cualquier petición → volver al login
    setUnauthorizedHandler(() => setState((s) => ({ ...s, user: null })));
  }, [refresh]);

  const value = useMemo(() => ({
    ...state,
    can: (p) => hasPermission(state.user, p),
    login: async (username, password) =>
      apply(await api("/api/auth.php?action=login", { method: "POST", json: { username, password }, silent401: true })),
    setup: async (payload) =>
      apply(await api("/api/auth.php?action=setup", { method: "POST", json: payload, silent401: true })),
    logout: async () => {
      try { await api("/api/auth.php?action=logout", { method: "POST" }); } catch { /* da igual */ }
      await refresh();
    },
    changePassword: (current_password, new_password) =>
      api("/api/auth.php?action=change_password", { method: "POST", json: { current_password, new_password } }),
  }), [state, apply, refresh]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
