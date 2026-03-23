-- ESTELSGP - schema base para MySQL 8+
-- Projeto: ESTEL SGP

SET NAMES utf8mb4;

CREATE SCHEMA IF NOT EXISTS `estelsgp`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `estelsgp`;

CREATE TABLE IF NOT EXISTS `user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `obs` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_nome` (`nome`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_user_obs_ativo` (`obs`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `requested_ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_tokens_hash` (`token_hash`),
  KEY `idx_password_reset_tokens_user` (`user_id`, `created_at`),
  KEY `idx_password_reset_tokens_exp_used` (`expires_at`, `used_at`),
  CONSTRAINT `fk_password_reset_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acao` VARCHAR(120) NOT NULL,
  `modulo` VARCHAR(40) NULL,
  `severidade` VARCHAR(12) NULL,
  `estado` VARCHAR(10) NULL,
  `detalhes` LONGTEXT NULL,
  `user_id` INT UNSIGNED NULL,
  `ator_nome` VARCHAR(120) NULL,
  `ator_email` VARCHAR(180) NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_app_logs_created_at` (`created_at`),
  KEY `idx_app_logs_acao` (`acao`),
  KEY `idx_app_logs_modulo` (`modulo`),
  KEY `idx_app_logs_severidade` (`severidade`),
  KEY `idx_app_logs_estado` (`estado`),
  KEY `idx_app_logs_user_id` (`user_id`),
  CONSTRAINT `fk_app_logs_user`
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cliente` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cliente_email` (`email`),
  KEY `idx_cliente_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formador` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_formador_email` (`email`),
  KEY `idx_formador_nome` (`nome`),
  KEY `idx_formador_ativo_nome` (`ativo`, `nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `turma` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(120) NOT NULL,
  `ativa` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_turma_nome` (`nome`),
  KEY `idx_turma_ativa_nome` (`ativa`, `nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `turma_aluno` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `turma_id` INT UNSIGNED NOT NULL,
  `aluno_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_turma_aluno` (`turma_id`, `aluno_id`),
  UNIQUE KEY `uq_turma_aluno_aluno` (`aluno_id`),
  CONSTRAINT `fk_turma_aluno_turma`
    FOREIGN KEY (`turma_id`) REFERENCES `turma` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_turma_aluno_cliente`
    FOREIGN KEY (`aluno_id`) REFERENCES `cliente` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `local` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `desc` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_local_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `material` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(150) NOT NULL,
  `marca` VARCHAR(100) NULL,
  `modelo` VARCHAR(100) NULL,
  `codigo_inventario` VARCHAR(120) NULL,
  `data_compra` DATE NULL,
  `isBroken` TINYINT(1) NOT NULL DEFAULT 0,
  `isAbate` TINYINT(1) NOT NULL DEFAULT 0,
  `disponibilidade` TINYINT(1) NOT NULL DEFAULT 1,
  `data_broken` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `idx_material_nome` (`nome`),
  KEY `idx_material_estado` (`isBroken`, `isAbate`, `disponibilidade`),
  UNIQUE KEY `uq_material_codigo_inventario` (`codigo_inventario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maquina` (
  `id` INT UNSIGNED NOT NULL,
  `mac` VARCHAR(32) NULL,
  `sn` VARCHAR(120) NULL,
  `local_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_maquina_local` (`local_id`),
  CONSTRAINT `fk_maquina_material`
    FOREIGN KEY (`id`) REFERENCES `material` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_maquina_local`
    FOREIGN KEY (`local_id`) REFERENCES `local` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `periferico` (
  `id` INT UNSIGNED NOT NULL,
  `local_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_periferico_local` (`local_id`),
  CONSTRAINT `fk_periferico_material`
    FOREIGN KEY (`id`) REFERENCES `material` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_periferico_local`
    FOREIGN KEY (`local_id`) REFERENCES `local` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extra` (
  `id` INT UNSIGNED NOT NULL,
  `desc` TEXT NULL,
  `local_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_extra_local` (`local_id`),
  CONSTRAINT `fk_extra_material`
    FOREIGN KEY (`id`) REFERENCES `material` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_extra_local`
    FOREIGN KEY (`local_id`) REFERENCES `local` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emprestimo` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `data_inicio` DATE NOT NULL,
  `prazo_entrega` DATE NOT NULL,
  `data_fim` DATE NULL,
  `obs_inicio` TEXT NULL,
  `obs_fim` TEXT NULL,
  `email_longa_enviado_em` DATETIME NULL,
  `ultimo_email_atraso_em` DATETIME NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `cliente_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_emprestimo_user` (`user_id`),
  KEY `idx_emprestimo_cliente_ativo` (`cliente_id`, `data_fim`),
  KEY `idx_emprestimo_prazo_ativo` (`prazo_entrega`, `data_fim`),
  CONSTRAINT `fk_emprestimo_user`
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_emprestimo_cliente`
    FOREIGN KEY (`cliente_id`) REFERENCES `cliente` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emprestimo_material` (
  `emprestimo_id` INT UNSIGNED NOT NULL,
  `material_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`emprestimo_id`, `material_id`),
  KEY `idx_emprestimo_material_material` (`material_id`),
  CONSTRAINT `fk_emprestimo_material_emprestimo`
    FOREIGN KEY (`emprestimo_id`) REFERENCES `emprestimo` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_emprestimo_material_material`
    FOREIGN KEY (`material_id`) REFERENCES `material` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW `vw_auditoria_itens` AS
SELECT
  m.id AS material_id,
  CONCAT('MAT-', LPAD(m.id, 4, '0')) AS codigo,
  m.nome AS item_nome,
  CASE
    WHEN ma.id IS NOT NULL THEN 'Máquina'
    WHEN p.id IS NOT NULL THEN 'Periférico'
    WHEN e.id IS NOT NULL THEN 'Extra'
    ELSE 'Material'
  END AS categoria,
  COALESCE(ma.local_id, p.local_id, e.local_id) AS local_id,
  COALESCE(lm.nome, lp.nome, le.nome) AS sala,
  m.isBroken,
  m.isAbate,
  m.disponibilidade,
  CASE
    WHEN m.isAbate = 1 THEN 'abate'
    WHEN m.isBroken = 1 THEN 'avariado'
    ELSE 'em_uso'
  END AS estado_key,
  CASE
    WHEN m.isAbate = 1 THEN 'Abate'
    WHEN m.isBroken = 1 THEN 'Avariado'
    ELSE 'Em uso'
  END AS estado_label,
  CASE
    WHEN m.disponibilidade = 1 THEN 'Disponível'
    ELSE 'Indisponível'
  END AS disponibilidade_label
FROM material m
LEFT JOIN maquina ma ON ma.id = m.id
LEFT JOIN local lm ON lm.id = ma.local_id
LEFT JOIN periferico p ON p.id = m.id
LEFT JOIN local lp ON lp.id = p.local_id
LEFT JOIN extra e ON e.id = m.id
LEFT JOIN local le ON le.id = e.local_id;

CREATE OR REPLACE VIEW `vw_auditoria_emprestimos` AS
SELECT
  e.id AS emprestimo_id,
  e.data_inicio,
  e.prazo_entrega,
  e.data_fim,
  CASE WHEN e.data_fim IS NULL THEN 1 ELSE 0 END AS ativo,
  c.nome AS cliente_nome,
  c.email AS cliente_email,
  m.id AS material_id,
  CONCAT('MAT-', LPAD(m.id, 4, '0')) AS item_codigo,
  m.nome AS item_nome,
  COALESCE(lm.nome, lp.nome, le.nome, 'Sem local') AS sala
FROM emprestimo e
INNER JOIN cliente c ON c.id = e.cliente_id
INNER JOIN emprestimo_material em ON em.emprestimo_id = e.id
INNER JOIN material m ON m.id = em.material_id
LEFT JOIN maquina ma ON ma.id = m.id
LEFT JOIN local lm ON lm.id = ma.local_id
LEFT JOIN periferico p ON p.id = m.id
LEFT JOIN local lp ON lp.id = p.local_id
LEFT JOIN extra ex ON ex.id = m.id
LEFT JOIN local le ON le.id = ex.local_id;


