-- Esquema de usuarios y permisos del panel admin.
-- api/auth.php lo crea automaticamente (ensureAuthSchema); este fichero es solo referencia
-- para quien prefiera aplicarlo a mano. Los roles por defecto se insertan desde PHP.
CREATE TABLE IF NOT EXISTS admin_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL DEFAULT '',
  permissions TEXT NOT NULL,            -- JSON: ["*"] o lista de permisos
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  FOREIGN KEY (role_id) REFERENCES admin_roles(id)
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(60) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at), INDEX (username, created_at)
) CHARACTER SET utf8mb4;
