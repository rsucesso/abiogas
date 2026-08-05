-- Coletor de Leads - schema MySQL
-- Importe este arquivo no phpMyAdmin (cPanel) dentro do banco criado para o projeto.

CREATE TABLE IF NOT EXISTS capturadores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  own_qr_code VARCHAR(500) NOT NULL,
  name VARCHAR(255) NOT NULL,
  company VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL DEFAULT '',
  session_token CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_own_qr (own_qr_code(191)),
  UNIQUE KEY uniq_session_token (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS capturas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  capturador_id INT UNSIGNED NOT NULL,
  qr_code_capturado VARCHAR(500) NOT NULL,
  is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
  event_day DATE NOT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tags VARCHAR(500) NULL DEFAULT NULL,
  notes TEXT NULL DEFAULT NULL,
  CONSTRAINT fk_capturas_capturador FOREIGN KEY (capturador_id) REFERENCES capturadores(id),
  INDEX idx_capturador_qr (capturador_id, qr_code_capturado(191)),
  INDEX idx_event_day (event_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Base de inscritos do Sympla, atualizada periodicamente via upload no painel de gestor.
-- "codigo" e o valor codificado no QR do crache que tambem aparece no export do Sympla.
CREATE TABLE IF NOT EXISTS sympla_inscritos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(191) NOT NULL,
  nome VARCHAR(255) NOT NULL DEFAULT '',
  sobrenome VARCHAR(255) NOT NULL DEFAULT '',
  empresa VARCHAR(255) NOT NULL DEFAULT '',
  cargo VARCHAR(255) NOT NULL DEFAULT '',
  telefone VARCHAR(50) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
