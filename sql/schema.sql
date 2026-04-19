
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_logs` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shift_assignment_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL,
  `break_minutes` int NOT NULL DEFAULT '0',
  `status` enum('working','completed','absent','late') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'working',
  `clock_in_method` enum('manual','auto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `clock_out_method` enum('manual','auto','timeout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_tenant_store_date` (`tenant_id`,`store_id`,`clock_in`),
  KEY `idx_att_user` (`tenant_id`,`user_id`,`clock_in`),
  KEY `idx_att_status` (`tenant_id`,`store_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('owner','manager','staff','device') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作種別: menu_update, staff_create, order_cancel 等',
  `entity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '対象種別: menu_item, user, order, settings 等',
  `entity_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '対象レコードのID',
  `old_value` json DEFAULT NULL COMMENT '変更前の値',
  `new_value` json DEFAULT NULL COMMENT '変更後の値',
  `reason` text COLLATE utf8mb4_unicode_ci COMMENT '操作理由（キャンセル理由等）',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_store` (`tenant_id`,`store_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=840 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_alerts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'スタッフ呼び出し',
  `type` enum('staff_call','product_ready','kitchen_call') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff_call',
  `order_item_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '関連品目ID（product_ready時）',
  `item_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '品目名（表示用）',
  `status` enum('pending','acknowledged') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_store_status` (`store_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_price` int NOT NULL DEFAULT '0',
  `action` enum('add','remove') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_store_date` (`store_id`,`created_at`),
  KEY `idx_ce_item` (`item_id`,`action`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_log` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作者',
  `type` enum('open','close','cash_sale','cash_in','cash_out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` int NOT NULL,
  `note` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cl_store_date` (`store_id`,`created_at`),
  CONSTRAINT `cash_log_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cat_tenant` (`tenant_id`),
  KEY `idx_cat_sort` (`tenant_id`,`sort_order`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_phases` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phase_number` int NOT NULL COMMENT 'フェーズ番号（1, 2, 3...）',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'フェーズ名（前菜, メイン, デザート等）',
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `items` json NOT NULL COMMENT '品目リスト [{id, name, qty}]',
  `auto_fire_min` int DEFAULT NULL COMMENT '前フェーズ完了後N分で自動発火（NULL=手動）',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_cp_course_phase` (`course_id`,`phase_number`),
  CONSTRAINT `course_phases_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `course_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_templates` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'コース名（松コース 等）',
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `price` int NOT NULL DEFAULT '0' COMMENT 'コース料金（税込）',
  `description` text COLLATE utf8mb4_unicode_ci,
  `phase_count` int NOT NULL DEFAULT '1' COMMENT 'フェーズ数（前菜→メイン→デザート等）',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ct_store` (`store_id`),
  CONSTRAINT `course_templates_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_recommendations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `menu_item_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` enum('template','local') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'template',
  `badge_type` enum('recommend','popular','new','limited','today_only') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recommend',
  `comment` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_date` date NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_date` (`store_id`,`display_date`),
  KEY `idx_menu_item` (`menu_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_registration_tokens` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of plain token',
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `visible_tools` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kds',
  `is_used` tinyint(1) NOT NULL DEFAULT '0',
  `used_by_user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'manager/owner user_id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_tenant_store` (`tenant_id`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingredients` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原材料名',
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '個' COMMENT '単位（g, ml, 個, 枚, etc.）',
  `stock_quantity` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '現在庫数（マイナス許容）',
  `cost_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '原価（単位あたり）',
  `low_stock_threshold` decimal(10,2) DEFAULT NULL COMMENT '在庫少アラート閾値',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ing_tenant` (`tenant_id`),
  CONSTRAINT `ingredients_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kds_routing_rules` (
  `station_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`station_id`,`category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `kds_routing_rules_ibfk_1` FOREIGN KEY (`station_id`) REFERENCES `kds_stations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `kds_routing_rules_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kds_stations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ks_store` (`store_id`),
  CONSTRAINT `kds_stations_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `local_item_options` (
  `local_item_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`local_item_id`,`group_id`),
  KEY `idx_lio_group` (`group_id`),
  CONSTRAINT `local_item_options_ibfk_1` FOREIGN KEY (`local_item_id`) REFERENCES `store_local_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `local_item_options_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `option_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu_template_options` (
  `template_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0' COMMENT '必須オプションか',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`template_id`,`group_id`),
  KEY `idx_mto_group` (`group_id`),
  CONSTRAINT `menu_template_options_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `menu_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `menu_template_options_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `option_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu_templates` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_price` int NOT NULL COMMENT '本部基準価格（税込円）',
  `description` text COLLATE utf8mb4_unicode_ci,
  `description_en` text COLLATE utf8mb4_unicode_ci,
  `image_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `calories` int DEFAULT NULL,
  `allergens` json DEFAULT NULL,
  `is_sold_out` tinyint(1) NOT NULL DEFAULT '0' COMMENT '本部レベルの品切れ',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '本部が全店舗で無効化可能',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mt_tenant` (`tenant_id`),
  KEY `idx_mt_category` (`category_id`),
  KEY `idx_mt_sort` (`sort_order`),
  CONSTRAINT `menu_templates_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `menu_templates_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu_translations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` enum('menu_item','local_item','category','option_group','option_choice') COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'zh-Hans, zh-Hant, ko',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `translated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entity_lang` (`entity_type`,`entity_id`,`lang`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_mt_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `option_choices` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_diff` int NOT NULL DEFAULT '0' COMMENT '価格差分（円）。マイナスも可',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'デフォルト選択',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oc_group` (`group_id`),
  CONSTRAINT `option_choices_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `option_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `option_groups` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selection_type` enum('single','multi') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single' COMMENT 'single=ラジオボタン（1つ選択）, multi=チェックボックス（複数選択）',
  `min_select` int NOT NULL DEFAULT '0' COMMENT '最低選択数（0=任意）',
  `max_select` int NOT NULL DEFAULT '1' COMMENT '最大選択数',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_og_tenant` (`tenant_id`),
  CONSTRAINT `option_groups_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `menu_item_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'menu_itemsのID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '品目名（注文時点のスナップショット）',
  `price` int NOT NULL DEFAULT '0' COMMENT '単価（注文時点）',
  `qty` int NOT NULL DEFAULT '1',
  `options` json DEFAULT NULL COMMENT 'オプション情報JSON',
  `allergen_selections` json DEFAULT NULL,
  `status` enum('pending','preparing','ready','served','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `prepared_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_store_status` (`store_id`,`status`),
  KEY `idx_order_items_payment_id` (`payment_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_rate_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rate_store_table` (`store_id`,`table_id`,`session_id`,`ordered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `items` json NOT NULL,
  `total_amount` int NOT NULL,
  `status` enum('pending','pending_payment','preparing','ready','served','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `order_type` enum('dine_in','takeout','handy') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dine_in' COMMENT '注文種別: dine_in=顧客セルフ, takeout=テイクアウト, handy=スタッフ入力',
  `staff_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ハンディ注文を入力したスタッフ',
  `customer_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'テイクアウト: 顧客名',
  `customer_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'テイクアウト: 電話番号',
  `pickup_at` datetime DEFAULT NULL COMMENT 'テイクアウト: 受取予定時刻',
  `course_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'コーステンプレートID（コース注文時）',
  `current_phase` int DEFAULT NULL COMMENT 'コース: 現在の提供フェーズ番号',
  `payment_method` enum('cash','card','qr') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_amount` int DEFAULT NULL,
  `change_amount` int DEFAULT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '注文時のテーブルセッション',
  `sub_session_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smaregi_transaction_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸ä»®è²©å£²ã®å–å¼•ID',
  `memo` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `prepared_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_idempotency` (`idempotency_key`),
  KEY `idx_order_store` (`store_id`),
  KEY `idx_order_table` (`table_id`),
  KEY `idx_order_status` (`status`),
  KEY `idx_order_store_created` (`store_id`,`created_at`),
  KEY `idx_order_updated` (`updated_at`),
  KEY `idx_order_type` (`order_type`),
  KEY `idx_order_pickup` (`pickup_at`),
  KEY `idx_order_staff` (`staff_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `merged_table_ids` json DEFAULT NULL,
  `merged_session_ids` json DEFAULT NULL,
  `session_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_ids` json NOT NULL COMMENT '対象注文IDリスト',
  `paid_items` json DEFAULT NULL COMMENT '個別会計時の明細 [{name,qty,price,taxRate}]',
  `subtotal_10` int NOT NULL DEFAULT '0' COMMENT '10%税率 税抜小計',
  `tax_10` int NOT NULL DEFAULT '0' COMMENT '10%税額',
  `subtotal_8` int NOT NULL DEFAULT '0' COMMENT '8%税率 税抜小計',
  `tax_8` int NOT NULL DEFAULT '0' COMMENT '8%税額',
  `total_amount` int NOT NULL COMMENT '合計（税込）',
  `payment_method` enum('cash','card','qr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `gateway_name` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'square|stripe|null(ç¾é‡‘)',
  `external_payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'å¤–éƒ¨æ±ºæ¸ˆIDï¼ˆSquare payment_id / Stripe PaymentIntent idï¼‰',
  `gateway_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'å¤–éƒ¨æ±ºæ¸ˆã‚¹ãƒ†ãƒ¼ã‚¿ã‚¹ï¼ˆCOMPLETED/succeededç­‰ï¼‰',
  `refund_status` enum('none','partial','full') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `refund_amount` int NOT NULL DEFAULT '0',
  `refund_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_reason` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `refunded_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_amount` int DEFAULT NULL COMMENT '預かり金（現金時）',
  `change_amount` int DEFAULT NULL COMMENT 'お釣り',
  `is_partial` tinyint(1) NOT NULL DEFAULT '0' COMMENT '個別会計フラグ',
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作スタッフ',
  `note` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_store_date` (`store_id`,`paid_at`),
  KEY `idx_pay_table` (`table_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_features` (
  `plan` enum('standard','pro','enterprise') COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`plan`,`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_menu_items` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'time_limit_plansのID',
  `category_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'categoriesのID（カテゴリ分け用）',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '品目名',
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '品目名（英語）',
  `description` text COLLATE utf8mb4_unicode_ci,
  `image_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pmi_plan` (`plan_id`),
  KEY `idx_pmi_category` (`category_id`),
  CONSTRAINT `plan_menu_items_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `time_limit_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `posla_admin_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHP session_id() å€¤',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `login_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_posla_session` (`session_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_admin_active` (`admin_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `posla_admins` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `posla_settings` (
  `setting_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `receipts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'payments テーブルの ID',
  `receipt_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '領収書番号（連番: R-YYYYMMDD-NNNN）',
  `receipt_type` enum('receipt','invoice') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'receipt' COMMENT '領収書 or 適格簡易請求書',
  `addressee` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '宛名（会社名・個人名）',
  `subtotal_10` int NOT NULL DEFAULT '0',
  `tax_10` int NOT NULL DEFAULT '0',
  `subtotal_8` int NOT NULL DEFAULT '0',
  `tax_8` int NOT NULL DEFAULT '0',
  `total_amount` int NOT NULL DEFAULT '0',
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '生成済みPDFパス',
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_number` (`store_id`,`receipt_number`),
  KEY `idx_store_date` (`store_id`,`issued_at`),
  KEY `idx_payment` (`payment_id`),
  CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipes` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `menu_template_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '対象メニューテンプレートID',
  `ingredient_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原材料ID',
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00' COMMENT '1品あたり消費量',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipe_menu_ing` (`menu_template_id`,`ingredient_id`),
  KEY `idx_recipe_menu` (`menu_template_id`),
  KEY `idx_recipe_ingredient` (`ingredient_id`),
  CONSTRAINT `recipes_ibfk_1` FOREIGN KEY (`menu_template_id`) REFERENCES `menu_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `recipes_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_courses` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `description_en` text COLLATE utf8mb4_unicode_ci,
  `price` int NOT NULL DEFAULT '0',
  `duration_min` int NOT NULL DEFAULT '90',
  `min_party_size` int NOT NULL DEFAULT '1',
  `max_party_size` int NOT NULL DEFAULT '10',
  `image_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_active` (`store_id`,`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_customers` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visit_count` int NOT NULL DEFAULT '0',
  `no_show_count` int NOT NULL DEFAULT '0',
  `cancel_count` int NOT NULL DEFAULT '0',
  `total_spend` int NOT NULL DEFAULT '0',
  `last_visit_at` datetime DEFAULT NULL,
  `preferences` text COLLATE utf8mb4_unicode_ci,
  `allergies` text COLLATE utf8mb4_unicode_ci,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_vip` tinyint(1) NOT NULL DEFAULT '0',
  `is_blacklisted` tinyint(1) NOT NULL DEFAULT '0',
  `blacklist_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_memo` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_store_phone` (`store_id`,`customer_phone`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_store_vip` (`store_id`,`is_vip`),
  KEY `idx_store_blacklist` (`store_id`,`is_blacklisted`),
  KEY `idx_email` (`customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_holds` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reserved_at` datetime NOT NULL,
  `party_size` int NOT NULL,
  `duration_min` int NOT NULL DEFAULT '90',
  `expires_at` datetime NOT NULL,
  `client_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_expires` (`store_id`,`expires_at`),
  KEY `idx_store_reserved` (`store_id`,`reserved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_notifications_log` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reservation_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_type` enum('confirm','reminder_24h','reminder_2h','cancel','no_show','deposit_required','deposit_captured','deposit_refunded') COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` enum('email','sms','line') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `recipient` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_excerpt` text COLLATE utf8mb4_unicode_ci,
  `status` enum('queued','sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservation` (`reservation_id`),
  KEY `idx_store_type` (`store_id`,`notification_type`,`sent_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_settings` (
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `online_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `lead_time_hours` int NOT NULL DEFAULT '2',
  `max_advance_days` int NOT NULL DEFAULT '60',
  `default_duration_min` int NOT NULL DEFAULT '90',
  `slot_interval_min` int NOT NULL DEFAULT '30',
  `max_party_size` int NOT NULL DEFAULT '10',
  `min_party_size` int NOT NULL DEFAULT '1',
  `open_time` time DEFAULT '11:00:00',
  `close_time` time DEFAULT '22:00:00',
  `last_order_offset_min` int NOT NULL DEFAULT '60',
  `weekly_closed_days` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `require_phone` tinyint(1) NOT NULL DEFAULT '1',
  `require_email` tinyint(1) NOT NULL DEFAULT '0',
  `buffer_before_min` int NOT NULL DEFAULT '0',
  `buffer_after_min` int NOT NULL DEFAULT '10',
  `notes_to_customer` text COLLATE utf8mb4_unicode_ci,
  `cancel_deadline_hours` int NOT NULL DEFAULT '3',
  `deposit_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `deposit_per_person` int NOT NULL DEFAULT '0',
  `deposit_min_party_size` int NOT NULL DEFAULT '4',
  `reminder_24h_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `reminder_2h_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `ai_chat_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `notification_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `party_size` int NOT NULL DEFAULT '1',
  `reserved_at` datetime NOT NULL,
  `duration_min` int NOT NULL DEFAULT '90',
  `status` enum('pending','confirmed','seated','no_show','cancelled','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `source` enum('web','phone','walk_in','google','external','ai_chat') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `assigned_table_ids` json DEFAULT NULL,
  `course_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `course_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `memo` text COLLATE utf8mb4_unicode_ci,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'ja',
  `table_session_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deposit_required` tinyint(1) NOT NULL DEFAULT '0',
  `deposit_amount` int NOT NULL DEFAULT '0',
  `deposit_status` enum('not_required','pending','authorized','captured','released','refunded','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `deposit_payment_intent_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deposit_session_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_policy_hours` int DEFAULT NULL,
  `cancel_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reminder_24h_sent_at` datetime DEFAULT NULL,
  `reminder_2h_sent_at` datetime DEFAULT NULL,
  `edit_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `confirmed_at` datetime DEFAULT NULL,
  `seated_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_store_reserved_at` (`store_id`,`reserved_at`),
  KEY `idx_tenant_reserved_at` (`tenant_id`,`reserved_at`),
  KEY `idx_status` (`store_id`,`status`,`reserved_at`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_phone` (`customer_phone`),
  KEY `idx_edit_token` (`edit_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `satisfaction_ratings` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_item_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `menu_item_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint NOT NULL COMMENT '1-5 (1=最低 5=最高)',
  `session_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_created` (`store_id`,`created_at`),
  KEY `idx_order` (`order_id`),
  KEY `idx_menu_item` (`menu_item_id`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_assignments` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `break_minutes` int NOT NULL DEFAULT '0',
  `role_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'kitchen / hall / NULL',
  `status` enum('draft','published','confirmed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `help_request_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ãƒ˜ãƒ«ãƒ—è¦è«‹çµŒç”±ã§ä½œæˆã•ã‚ŒãŸå ´åˆã®è¦è«‹ID',
  PRIMARY KEY (`id`),
  KEY `idx_sa_tenant_store_date` (`tenant_id`,`store_id`,`shift_date`),
  KEY `idx_sa_user_date` (`tenant_id`,`user_id`,`shift_date`),
  KEY `idx_sa_status` (`tenant_id`,`store_id`,`status`),
  KEY `idx_sa_help` (`help_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_availabilities` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_date` date NOT NULL,
  `availability` enum('available','preferred','unavailable') COLLATE utf8mb4_unicode_ci NOT NULL,
  `preferred_start` time DEFAULT NULL,
  `preferred_end` time DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_avail_unique` (`tenant_id`,`store_id`,`user_id`,`target_date`),
  KEY `idx_avail_store_date` (`tenant_id`,`store_id`,`target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_help_assignments` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `help_request_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'æ´¾é£ã•ã‚Œã‚‹ã‚¹ã‚¿ãƒƒãƒ•',
  `shift_assignment_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'è‡ªå‹•ä½œæˆã•ã‚ŒãŸshift_assignmentsã®ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sha_request` (`help_request_id`),
  KEY `idx_sha_user` (`user_id`),
  CONSTRAINT `shift_help_assignments_ibfk_1` FOREIGN KEY (`help_request_id`) REFERENCES `shift_help_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_help_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_help_requests` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'è¦è«‹å…ƒï¼ˆäººæ‰‹ä¸è¶³ã®åº—èˆ—ï¼‰',
  `to_store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'è¦è«‹å…ˆï¼ˆã‚¹ã‚¿ãƒƒãƒ•ã‚’æ´¾é£ã™ã‚‹åº—èˆ—ï¼‰',
  `requested_date` date NOT NULL COMMENT 'ãƒ˜ãƒ«ãƒ—å¸Œæœ›æ—¥',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `requested_staff_count` int NOT NULL DEFAULT '1',
  `role_hint` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'kitchen / hall / NULL',
  `status` enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requesting_user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ä¾é ¼è€…ï¼ˆfrom_storeã®ãƒžãƒãƒ¼ã‚¸ãƒ£ãƒ¼ï¼‰',
  `responding_user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'æ‰¿èª/å´ä¸‹è€…ï¼ˆto_storeã®ãƒžãƒãƒ¼ã‚¸ãƒ£ãƒ¼ï¼‰',
  `note` text COLLATE utf8mb4_unicode_ci COMMENT 'ä¾é ¼ãƒ¡ãƒ¢',
  `response_note` text COLLATE utf8mb4_unicode_ci COMMENT 'å›žç­”ãƒ¡ãƒ¢',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shr_tenant` (`tenant_id`),
  KEY `idx_shr_from` (`tenant_id`,`from_store_id`,`status`),
  KEY `idx_shr_to` (`tenant_id`,`to_store_id`,`status`),
  KEY `idx_shr_date` (`tenant_id`,`requested_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_settings` (
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_deadline_day` tinyint NOT NULL DEFAULT '5' COMMENT 'æ¯ŽæœˆNæ—¥ã¾ã§ã«å¸Œæœ›æå‡º',
  `default_break_minutes` int NOT NULL DEFAULT '60',
  `overtime_threshold_minutes` int NOT NULL DEFAULT '480' COMMENT '8h=480åˆ†',
  `early_clock_in_minutes` int NOT NULL DEFAULT '15' COMMENT 'æ—©å‡ºæ‰“åˆ»è¨±å®¹ï¼ˆåˆ†ï¼‰',
  `auto_clock_out_hours` int NOT NULL DEFAULT '12' COMMENT 'è‡ªå‹•é€€å‹¤ï¼ˆæ‰“åˆ»å¿˜ã‚Œå¯¾ç­–ï¼‰',
  `store_lat` decimal(10,7) DEFAULT NULL COMMENT 'åº—èˆ—ç·¯åº¦',
  `store_lng` decimal(10,7) DEFAULT NULL COMMENT 'åº—èˆ—çµŒåº¦',
  `gps_radius_meters` int NOT NULL DEFAULT '200' COMMENT 'GPSè¨±å®¹åŠå¾„ï¼ˆmï¼‰',
  `gps_required` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'GPSå‡ºé€€å‹¤ã‚’å¿…é ˆã«ã™ã‚‹',
  `staff_visible_tools` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CSV: handy,kds,register (NULL=å…¨è¡¨ç¤º)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `default_hourly_rate` int DEFAULT NULL COMMENT 'åº—èˆ—ãƒ‡ãƒ•ã‚©ãƒ«ãƒˆæ™‚çµ¦ï¼ˆå††ï¼‰ã€‚NULL=æœªè¨­å®š',
  PRIMARY KEY (`store_id`),
  KEY `idx_ss_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_templates` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `day_of_week` tinyint NOT NULL COMMENT '0=æ—¥ 1=æœˆ ... 6=åœŸ',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `required_staff` int NOT NULL DEFAULT '1',
  `role_hint` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'kitchen / hall / NULL',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_st_tenant_store` (`tenant_id`,`store_id`),
  KEY `idx_st_day` (`tenant_id`,`store_id`,`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `smaregi_product_mapping` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `menu_template_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'POSLAãƒ¡ãƒ‹ãƒ¥ãƒ¼ãƒ†ãƒ³ãƒ—ãƒ¬ãƒ¼ãƒˆID',
  `smaregi_product_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸å•†å“ID',
  `smaregi_store_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸åº—èˆ—ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_smaregi_prod_menu` (`menu_template_id`),
  UNIQUE KEY `idx_smaregi_prod_ext` (`tenant_id`,`smaregi_product_id`,`smaregi_store_id`),
  KEY `idx_smaregi_prod_tenant` (`tenant_id`),
  CONSTRAINT `smaregi_product_mapping_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `smaregi_product_mapping_ibfk_2` FOREIGN KEY (`menu_template_id`) REFERENCES `menu_templates` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `smaregi_store_mapping` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'POSLAåº—èˆ—ID',
  `smaregi_store_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸åº—èˆ—ID',
  `sync_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'æ³¨æ–‡é€ä¿¡æœ‰åŠ¹ãƒ•ãƒ©ã‚°',
  `last_menu_sync` datetime DEFAULT NULL COMMENT 'æœ€çµ‚ãƒ¡ãƒ‹ãƒ¥ãƒ¼åŒæœŸæ—¥æ™‚',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_smaregi_map_store` (`store_id`),
  UNIQUE KEY `idx_smaregi_map_ext` (`tenant_id`,`smaregi_store_id`),
  KEY `idx_smaregi_map_tenant` (`tenant_id`),
  CONSTRAINT `smaregi_store_mapping_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `smaregi_store_mapping_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_local_items` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` int NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `description_en` text COLLATE utf8mb4_unicode_ci,
  `image_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `calories` int DEFAULT NULL,
  `allergens` json DEFAULT NULL,
  `is_sold_out` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sli_store` (`store_id`),
  KEY `idx_sli_category` (`category_id`),
  CONSTRAINT `store_local_items_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `store_local_items_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_menu_overrides` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` int DEFAULT NULL COMMENT 'NULL=本部価格を継承',
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'この店舗では非表示',
  `is_sold_out` tinyint(1) NOT NULL DEFAULT '0' COMMENT '店舗レベルの品切れ',
  `image_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '店舗独自の画像（NULLならテンプレート画像を継承）',
  `sort_order` int DEFAULT NULL COMMENT 'NULL=テンプレートの順序を継承',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_smo_store_template` (`store_id`,`template_id`),
  KEY `idx_smo_template` (`template_id`),
  CONSTRAINT `store_menu_overrides_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `store_menu_overrides_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `menu_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_option_overrides` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `choice_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_diff` int DEFAULT NULL COMMENT 'NULL=本部価格を継承',
  `is_available` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0=この店舗では非表示',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_soo_store_choice` (`store_id`,`choice_id`),
  KEY `choice_id` (`choice_id`),
  CONSTRAINT `store_option_overrides_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `store_option_overrides_ibfk_2` FOREIGN KEY (`choice_id`) REFERENCES `option_choices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_settings` (
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_items_per_order` int NOT NULL DEFAULT '10',
  `max_amount_per_order` int NOT NULL DEFAULT '30000',
  `max_quantity_per_item` int NOT NULL DEFAULT '5',
  `max_toppings_per_item` int NOT NULL DEFAULT '5',
  `rate_limit_orders` int NOT NULL DEFAULT '3',
  `rate_limit_window_min` int NOT NULL DEFAULT '5',
  `day_cutoff_time` time NOT NULL DEFAULT '05:00:00',
  `default_open_amount` int NOT NULL DEFAULT '30000',
  `overshort_threshold` int NOT NULL DEFAULT '1000',
  `payment_methods_enabled` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash,card,qr',
  `receipt_store_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_address` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '10.00',
  `receipt_footer` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_place_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Google Place IDï¼ˆãƒ¬ãƒ“ãƒ¥ãƒ¼èª˜å°Žç”¨ï¼‰',
  `brand_color` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_logo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_display_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '適格請求書発行事業者登録番号（T+13桁）',
  `business_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '事業者正式名称',
  `ai_api_key` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Gemini APIキー',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `google_places_api_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `welcome_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `welcome_message_en` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_order_time` time DEFAULT NULL COMMENT '定時ラストオーダー時刻（例: 21:30:00）。NULLなら無効',
  `last_order_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '手動ラストオーダーフラグ（1=発動中）',
  `last_order_activated_at` datetime DEFAULT NULL COMMENT '手動ラストオーダー発動時刻',
  `takeout_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `takeout_min_prep_minutes` int NOT NULL DEFAULT '30',
  `takeout_available_from` time DEFAULT '10:00:00',
  `takeout_available_to` time DEFAULT '20:00:00',
  `takeout_slot_capacity` int NOT NULL DEFAULT '5',
  `takeout_online_payment` tinyint(1) NOT NULL DEFAULT '0',
  `self_checkout_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'ã‚»ãƒ«ãƒ•ãƒ¬ã‚¸æœ‰åŠ¹ãƒ•ãƒ©ã‚°ï¼ˆL-8ï¼‰',
  PRIMARY KEY (`store_id`),
  CONSTRAINT `store_settings_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stores` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'テナント内URL識別子 (例: shibuya)',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Asia/Tokyo',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_store_tenant_slug` (`tenant_id`,`slug`),
  KEY `idx_store_tenant` (`tenant_id`),
  CONSTRAINT `stores_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_events` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stripeã‚¤ãƒ™ãƒ³ãƒˆã‚¿ã‚¤ãƒ—',
  `stripe_event_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Stripe Event ID (evt_xxx)',
  `data` text COLLATE utf8mb4_unicode_ci COMMENT 'ã‚¤ãƒ™ãƒ³ãƒˆãƒ‡ãƒ¼ã‚¿JSON',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stripe_event_id` (`stripe_event_id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_stripe_event_id` (`stripe_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `table_sessions` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reservation_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('seated','eating','bill_requested','paid','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'seated' COMMENT 'seated=着席, eating=食事中, bill_requested=会計待ち, paid=会計済み, closed=退席済み',
  `guest_count` int DEFAULT NULL COMMENT '来客数',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `memo` text COLLATE utf8mb4_unicode_ci COMMENT 'セッションメモ（アレルギー・VIP・特記事項等）',
  `session_pin` char(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'time_limit_plansのID',
  `course_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'course_templatesのID',
  `current_phase_number` int DEFAULT NULL COMMENT '現在の提供フェーズ番号',
  `phase_fired_at` datetime DEFAULT NULL COMMENT '現フェーズの注文投入時刻',
  `time_limit_min` int DEFAULT NULL COMMENT '制限時間（分）',
  `last_order_min` int DEFAULT NULL COMMENT 'ラストオーダー（終了N分前）',
  `expires_at` datetime DEFAULT NULL COMMENT '制限時間の終了時刻',
  `last_order_at` datetime DEFAULT NULL COMMENT 'ラストオーダー時刻',
  PRIMARY KEY (`id`),
  KEY `idx_ts_store` (`store_id`),
  KEY `idx_ts_table` (`table_id`),
  KEY `idx_ts_active` (`store_id`,`status`),
  KEY `idx_ts_course` (`store_id`,`course_id`),
  KEY `idx_reservation` (`reservation_id`),
  CONSTRAINT `table_sessions_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `table_sessions_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `table_sub_sessions` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_session_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sub_token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ã‚²ã‚¹ãƒˆè¡¨ç¤ºå (Aæ§˜, Bæ§˜ ç­‰)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sub_session_token` (`sub_token`),
  KEY `idx_sub_session_parent` (`table_session_id`),
  KEY `idx_sub_session_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tables` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'UUID（グローバル一意）',
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '表示コード: T01, T02 等（店舗内UNIQUE）',
  `capacity` int NOT NULL DEFAULT '4' COMMENT '座席数',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `session_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'テーブル使用サイクル識別トークン',
  `session_token_expires_at` datetime DEFAULT NULL COMMENT 'セッショントークンの有効期限',
  `next_session_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_session_token_expires_at` datetime DEFAULT NULL,
  `next_session_opened_by_user_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_session_opened_at` datetime DEFAULT NULL,
  `pos_x` int NOT NULL DEFAULT '0' COMMENT 'フロアマップ上のX座標（px）',
  `pos_y` int NOT NULL DEFAULT '0' COMMENT 'フロアマップ上のY座標（px）',
  `width` int NOT NULL DEFAULT '80' COMMENT 'フロアマップ上の幅（px）',
  `height` int NOT NULL DEFAULT '80' COMMENT 'フロアマップ上の高さ（px）',
  `shape` enum('rect','circle','ellipse') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rect' COMMENT 'テーブル形状',
  `floor` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1F' COMMENT 'フロア名（1F, 2F, テラス等）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_table_store_code` (`store_id`,`table_code`),
  KEY `idx_table_floor` (`store_id`,`floor`),
  KEY `idx_next_token_expires` (`next_session_token_expires_at`),
  CONSTRAINT `tables_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'URL識別子 (例: matsunoya)',
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '企業表示名',
  `name_en` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan` enum('standard','pro','enterprise') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `stripe_secret_key` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Stripe Secret Key',
  `payment_gateway` enum('none','stripe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'ä½¿ç”¨ã™ã‚‹æ±ºæ¸ˆã‚²ãƒ¼ãƒˆã‚¦ã‚§ã‚¤ï¼ˆP1-2 ã§ square å‰Šé™¤ï¼‰',
  `stripe_customer_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Stripe Customer ID (cus_xxx)',
  `stripe_subscription_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Stripe Subscription ID (sub_xxx)',
  `subscription_status` enum('none','active','past_due','canceled','trialing') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT 'ã‚µãƒ–ã‚¹ã‚¯çŠ¶æ…‹',
  `current_period_end` datetime DEFAULT NULL COMMENT 'ç¾åœ¨ã®èª²é‡‘æœŸé–“çµ‚äº†æ—¥',
  `stripe_connect_account_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Stripe Connect Express Account ID (acct_...)',
  `connect_onboarding_complete` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Connectã‚ªãƒ³ãƒœãƒ¼ãƒ‡ã‚£ãƒ³ã‚°å®Œäº†ãƒ•ãƒ©ã‚°',
  `smaregi_contract_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸å¥‘ç´„ID',
  `smaregi_access_token` text COLLATE utf8mb4_unicode_ci COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸ ã‚¢ã‚¯ã‚»ã‚¹ãƒˆãƒ¼ã‚¯ãƒ³',
  `smaregi_refresh_token` text COLLATE utf8mb4_unicode_ci COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸ ãƒªãƒ•ãƒ¬ãƒƒã‚·ãƒ¥ãƒˆãƒ¼ã‚¯ãƒ³',
  `smaregi_token_expires_at` datetime DEFAULT NULL COMMENT 'ã‚¢ã‚¯ã‚»ã‚¹ãƒˆãƒ¼ã‚¯ãƒ³æœ‰åŠ¹æœŸé™',
  `smaregi_connected_at` datetime DEFAULT NULL COMMENT 'ã‚¹ãƒžãƒ¬ã‚¸é€£æºæ—¥æ™‚',
  `hq_menu_broadcast` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Î±-1 æœ¬éƒ¨ä¸€æ‹¬ãƒ¡ãƒ‹ãƒ¥ãƒ¼é…ä¿¡ã‚¢ãƒ‰ã‚ªãƒ³å¥‘ç´„ãƒ•ãƒ©ã‚° (0=æœªå¥‘ç´„, 1=å¥‘ç´„)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_limit_plans` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'プラン名（90分食べ放題 等）',
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `duration_min` int NOT NULL COMMENT '制限時間（分）',
  `last_order_min` int NOT NULL DEFAULT '15' COMMENT 'ラストオーダー（終了N分前）',
  `price` int NOT NULL DEFAULT '0' COMMENT 'プラン料金（税込）',
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tlp_store` (`store_id`),
  CONSTRAINT `time_limit_plans_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ui_translations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lang` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lang_key` (`lang`,`msg_key`)
) ENGINE=InnoDB AUTO_INCREMENT=984 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PHPセッションID',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `device_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '簡易デバイス名: Chrome/Windows等',
  `login_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session` (`session_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_active` (`user_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=248 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_stores` (
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `visible_tools` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CSV: handy,kds,register (NULL=store default)',
  `hourly_rate` int DEFAULT NULL COMMENT '時給（円、L-3 シフト管理用）',
  PRIMARY KEY (`user_id`,`store_id`),
  KEY `idx_us_store` (`store_id`),
  CONSTRAINT `user_stores_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `user_stores_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'password_hash() output',
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `role` enum('owner','manager','staff','device') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_username` (`username`),
  UNIQUE KEY `idx_user_email` (`email`),
  KEY `idx_user_tenant` (`tenant_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

