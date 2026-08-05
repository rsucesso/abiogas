-- Migracao v2: tags/observacoes, email do coletor, base do Sympla.
-- Rode isto no phpMyAdmin do banco biogas_leads (aba SQL) apenas UMA VEZ, no site ao vivo.
-- Todas as mudancas sao aditivas (colunas novas com default / tabela nova) - nao apaga nada existente.

ALTER TABLE capturadores
  ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER company;

ALTER TABLE capturas
  ADD COLUMN tags VARCHAR(500) NULL DEFAULT NULL AFTER event_day,
  ADD COLUMN notes TEXT NULL DEFAULT NULL AFTER tags;

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
