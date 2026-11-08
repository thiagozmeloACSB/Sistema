-- Opa Studio — Patch v9: Reset de senha via e-mail
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(191) NOT NULL,
  `token`      VARCHAR(64)  NOT NULL,
  `expira_em`  DATETIME     NOT NULL,
  `usado`      TINYINT(1)   NOT NULL DEFAULT 0,
  `criado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
