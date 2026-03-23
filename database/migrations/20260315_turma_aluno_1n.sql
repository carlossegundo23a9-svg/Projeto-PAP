-- Migration: enforce 1 turma per aluno using turma_aluno
-- Date: 2026-03-15
-- Strategy:
-- 1) Backup duplicate links
-- 2) Keep latest association per aluno (highest id)
-- 3) Enforce UNIQUE(aluno_id)

USE `estelsgp`;

CREATE TABLE IF NOT EXISTS `turma_aluno_backup_20260315_1n` LIKE `turma_aluno`;

INSERT IGNORE INTO `turma_aluno_backup_20260315_1n`
SELECT ta.*
FROM `turma_aluno` ta
JOIN (
    SELECT `aluno_id`
    FROM `turma_aluno`
    GROUP BY `aluno_id`
    HAVING COUNT(*) > 1
) dup ON dup.`aluno_id` = ta.`aluno_id`;

DELETE ta1
FROM `turma_aluno` ta1
JOIN `turma_aluno` ta2
  ON ta1.`aluno_id` = ta2.`aluno_id`
 AND ta1.`id` < ta2.`id`;

ALTER TABLE `turma_aluno`
  ADD UNIQUE KEY `uq_turma_aluno_aluno` (`aluno_id`);
