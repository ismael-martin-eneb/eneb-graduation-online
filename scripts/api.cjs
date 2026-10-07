// Arranca el servidor PHP de desarrollo (puerto 8000) localizando php.exe:
// 1) variable PHP_BIN, 2) PATH, 3) rutas habituales de XAMPP/Laragon en Windows.
const { spawn, spawnSync } = require("node:child_process");
const fs = require("node:fs");

const candidates = [
  process.env.PHP_BIN,
  "php",
  "C:\\xampp\\php\\php.exe",
  "C:\\laragon\\bin\\php\\php.exe",
  "C:\\php\\php.exe",
].filter(Boolean);

const php = candidates.find((c) => {
  if (c !== "php" && !fs.existsSync(c)) return false;
  return spawnSync(c, ["-v"], { stdio: "ignore" }).status === 0;
});

if (!php) {
  console.error("No se encontró PHP. Instálalo (XAMPP) o define PHP_BIN con la ruta a php.exe.");
  process.exit(1);
}

console.log(`[api] usando ${php} en http://127.0.0.1:8000`);
const child = spawn(php, ["-S", "127.0.0.1:8000", "-t", "."], { stdio: "inherit" });
child.on("exit", (code) => process.exit(code ?? 0));
