// Punto de entrada de la vista pública (index.html)
import { createRoot } from "react-dom/client";
import App from "./public/App.jsx";
import "./public/styles.css";

createRoot(document.getElementById("root")).render(<App />);
