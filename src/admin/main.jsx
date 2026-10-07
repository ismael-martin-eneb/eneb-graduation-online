// Punto de entrada del panel de administración (admin.html).
import React from "react";
import { createRoot } from "react-dom/client";
import AdminApp from "./App.jsx";
import "./admin.css";

createRoot(document.getElementById("root")).render(<React.StrictMode><AdminApp /></React.StrictMode>);
