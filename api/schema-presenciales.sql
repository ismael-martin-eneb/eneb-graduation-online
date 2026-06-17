-- SQL para crear tabla de alumnos presenciales
-- Ejecutar en la base de datos: eneb_graduacion_online

CREATE TABLE IF NOT EXISTS eneb_presenciales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  id_alumno VARCHAR(50),
  idioma VARCHAR(50),
  phone VARCHAR(30),
  intolerancias VARCHAR(500),
  linkedin VARCHAR(255),
  email VARCHAR(120),

  INDEX idx_nombre (nombre),
  INDEX idx_id_alumno (id_alumno),
  UNIQUE KEY uk_email (email)

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Ejemplo de inserción para pruebas:
-- INSERT INTO eneb_presenciales (nombre, id_alumno, idioma, phone, intolerancias, linkedin, email, created_at)
-- VALUES 
--   ('Juan García López', '20931', 'Español', '+34612345678', 'Sin gluten', 'https://linkedin.com/in/juangarcia', 'juan@example.com', NOW()),
--   ('María Rodríguez', '20932', 'Español', '+34698765432', 'Sin lactosa', 'https://linkedin.com/in/mrodriguez', 'maria@example.com', NOW());

-- Ver todos los registros:
-- SELECT * FROM eneb_presenciales ORDER BY nombre;

-- Contar registros:
-- SELECT COUNT(*) as total FROM eneb_presenciales;

-- Buscar por email:
-- SELECT * FROM eneb_presenciales WHERE email = 'juan@example.com';
