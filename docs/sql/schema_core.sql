-- =============================================================================
-- Access Logger — schema core (open source)
-- Fonte de verdade para instalação. Sem gates (access_log_feature_events).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- user_fingerprints
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_fingerprints` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fingerprint_hash` varchar(64) NOT NULL COMMENT 'Hash SHA-256 dos sinais estáveis do dispositivo',
  `screen_resolution` varchar(20) DEFAULT NULL COMMENT 'ex: 1920x1080',
  `user_agent` text COMMENT 'User-Agent completo',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IPv4 ou IPv6',
  `operating_system` varchar(100) DEFAULT NULL,
  `browser_name` varchar(100) DEFAULT NULL,
  `browser_version` varchar(50) DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
  `language` varchar(10) DEFAULT NULL COMMENT 'ex: pt-BR',
  `timezone` varchar(50) DEFAULT NULL COMMENT 'IANA ex: America/Sao_Paulo',
  `color_depth` int DEFAULT NULL,
  `pixel_ratio` decimal(3,2) DEFAULT NULL,
  `touch_support` tinyint(1) DEFAULT 0,
  `webgl_vendor` varchar(255) DEFAULT NULL,
  `webgl_renderer` varchar(255) DEFAULT NULL,
  `canvas_fingerprint` varchar(64) DEFAULT NULL,
  `audio_fingerprint` varchar(64) DEFAULT NULL,
  `plugins_list` text COMMENT 'JSON',
  `fonts_list` text COMMENT 'JSON',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fingerprint_hash` (`fingerprint_hash`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_device_type` (`device_type`),
  KEY `idx_created` (`created`),
  KEY `idx_fingerprint_created` (`fingerprint_hash`,`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Identidade anônima estável do dispositivo/navegador';

-- -----------------------------------------------------------------------------
-- access_logs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `access_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_fingerprint_id` int NOT NULL,
  `user_id` int DEFAULT NULL COMMENT 'Opcional: preenchido após login no host app',
  `session_id` varchar(128) DEFAULT NULL COMMENT 'UUID da sessão de navegação',
  `url` text NOT NULL,
  `referer` text DEFAULT NULL,
  `is_authenticated` tinyint(1) DEFAULT 0,
  `page_load_time` int DEFAULT NULL COMMENT 'ms',
  `scroll_depth` int DEFAULT 0 COMMENT 'pixels',
  `time_on_page` int DEFAULT NULL COMMENT 'segundos',
  `utm_source` varchar(255) DEFAULT NULL,
  `utm_medium` varchar(255) DEFAULT NULL,
  `utm_campaign` varchar(255) DEFAULT NULL,
  `utm_term` varchar(255) DEFAULT NULL,
  `utm_content` varchar(255) DEFAULT NULL,
  `navigation_order` int DEFAULT 1,
  `previous_access_log_id` int DEFAULT NULL,
  `exit_type` enum('navigation','close','refresh','back','forward') DEFAULT NULL,
  `viewport_width` int DEFAULT NULL,
  `viewport_height` int DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_fingerprint_id` (`user_fingerprint_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_is_authenticated` (`is_authenticated`),
  KEY `idx_created` (`created`),
  KEY `idx_navigation_order` (`navigation_order`),
  KEY `idx_previous_access_log_id` (`previous_access_log_id`),
  KEY `idx_url_prefix` (`url`(255)),
  KEY `idx_session_navigation` (`session_id`,`navigation_order`),
  KEY `idx_fingerprint_created` (`user_fingerprint_id`,`created`),
  KEY `idx_auth_created` (`is_authenticated`,`created`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_access_logs_fingerprint`
    FOREIGN KEY (`user_fingerprint_id`) REFERENCES `user_fingerprints` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_access_logs_previous`
    FOREIGN KEY (`previous_access_log_id`) REFERENCES `access_logs` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Um registro por pageview';

-- -----------------------------------------------------------------------------
-- access_log_events
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `access_log_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `access_log_id` int NOT NULL,
  `event_name` varchar(96) NOT NULL,
  `element_type` varchar(32) DEFAULT NULL,
  `element_label` varchar(128) DEFAULT NULL,
  `target_href` varchar(255) DEFAULT NULL,
  `numeric_value` decimal(12,4) DEFAULT NULL,
  `scroll_percent` tinyint unsigned DEFAULT NULL COMMENT '0-100',
  `time_offset_ms` int unsigned DEFAULT NULL COMMENT 'ms desde o início do pageview',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ale_access_log` (`access_log_id`),
  KEY `idx_ale_event_created` (`event_name`,`created`),
  CONSTRAINT `fk_ale_access_log`
    FOREIGN KEY (`access_log_id`) REFERENCES `access_logs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Micro-eventos associados a um pageview';

SET FOREIGN_KEY_CHECKS = 1;
