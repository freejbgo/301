-- MySQL dump 10.13  Distrib 5.6.50, for Linux (x86_64)
--
-- Host: localhost    Database: redi_admin
--
-- 绿场/bootstrap 用：一次导入即可（Dcat 菜单 + 业务表 + 默认 admin + 节点存储过程 redi_node_bump_skip）。
-- 过程体与 database/migrations/2026_03_28_100009_redi_node_skip_procedure.php 保持一致；发版改过程时两处同步。
-- 无历史数据环境：勿再执行已删除的「升级/清理」迁移（如原 100010）。
-- 主控发版后若有新迁移（100010+），再在服务器执行 php artisan migrate --force。
-- ------------------------------------------------------
-- Server version	5.6.50-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_extension_histories`
--

DROP TABLE IF EXISTS `admin_extension_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_extension_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` tinyint(4) NOT NULL DEFAULT '1',
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `detail` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_extension_histories_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_extension_histories`
--

LOCK TABLES `admin_extension_histories` WRITE;
/*!40000 ALTER TABLE `admin_extension_histories` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_extension_histories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_extensions`
--

DROP TABLE IF EXISTS `admin_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_extensions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_enabled` tinyint(4) NOT NULL DEFAULT '0',
  `options` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_extensions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_extensions`
--

LOCK TABLES `admin_extensions` WRITE;
/*!40000 ALTER TABLE `admin_extensions` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_extensions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_menu`
--

DROP TABLE IF EXISTS `admin_menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_menu` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) NOT NULL DEFAULT '0',
  `order` int(11) NOT NULL DEFAULT '0',
  `title` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uri` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extension` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `show` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_menu`
--

LOCK TABLES `admin_menu` WRITE;
/*!40000 ALTER TABLE `admin_menu` DISABLE KEYS */;
INSERT INTO `admin_menu` VALUES (1,0,1,'Index','feather icon-bar-chart-2','/domain','',0,'2023-02-22 00:32:10','2023-04-11 05:42:08'),(2,0,5,'Admin','feather icon-settings',NULL,'',1,'2023-02-22 00:32:10','2023-04-11 02:33:32'),(3,2,6,'Users',NULL,'auth/users','',1,'2023-02-22 00:32:10','2023-04-11 02:27:59'),(4,2,7,'Roles',NULL,'auth/roles','',1,'2023-02-22 00:32:10','2023-04-11 02:28:08'),(5,2,8,'Permission',NULL,'auth/permissions','',1,'2023-02-22 00:32:10','2023-04-11 02:28:16'),(6,2,9,'Menu',NULL,'auth/menu','',1,'2023-02-22 00:32:10','2023-04-11 02:28:23'),(7,2,10,'Extensions',NULL,'auth/extensions','',1,'2023-02-22 00:32:10','2023-04-11 02:28:30'),(8,0,2,'站点配置','fa-desktop',NULL,'',1,'2023-02-26 08:21:11','2023-02-26 08:23:03'),(9,8,3,'分组管理',NULL,'group','',1,'2023-02-26 08:22:50','2023-04-06 00:25:45'),(10,8,4,'域名管理',NULL,'domain','',1,'2023-02-27 02:06:08','2023-04-06 00:25:53'),(11,8,5,'系统外观','fa-paint-brush','platform-settings','',1,'2026-03-28 00:00:00','2026-03-28 00:00:00'),(12,8,6,'DNS与节点池','fa-globe','platform-dns','',1,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(13,8,7,'节点管理','fa-server','nodes','',1,'2026-03-28 12:00:00','2026-03-28 12:00:00');
/*!40000 ALTER TABLE `admin_menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_permission_menu`
--

DROP TABLE IF EXISTS `admin_permission_menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_permission_menu` (
  `permission_id` bigint(20) NOT NULL,
  `menu_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `admin_permission_menu_permission_id_menu_id_unique` (`permission_id`,`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_permission_menu`
--

LOCK TABLES `admin_permission_menu` WRITE;
/*!40000 ALTER TABLE `admin_permission_menu` DISABLE KEYS */;
INSERT INTO `admin_permission_menu` VALUES (2,3,'2023-04-11 02:27:59','2023-04-11 02:27:59'),(3,4,'2023-04-11 02:28:08','2023-04-11 02:28:08'),(4,5,'2023-04-11 02:28:16','2023-04-11 02:28:16'),(5,6,'2023-04-11 02:28:23','2023-04-11 02:28:23'),(6,7,'2023-04-11 02:28:30','2023-04-11 02:28:30'),(7,8,'2023-04-06 00:27:11','2023-04-06 00:27:11'),(7,9,'2023-04-11 02:27:42','2023-04-11 02:27:42'),(7,10,'2023-04-11 02:27:28','2023-04-11 02:27:28'),(8,9,'2023-04-06 00:27:33','2023-04-06 00:27:33'),(9,10,'2023-04-06 00:28:15','2023-04-06 00:28:15'),(10,11,'2026-03-28 00:00:00','2026-03-28 00:00:00'),(11,12,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(12,13,'2026-03-28 12:00:00','2026-03-28 12:00:00');
/*!40000 ALTER TABLE `admin_permission_menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_permissions`
--

DROP TABLE IF EXISTS `admin_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `http_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `http_path` text COLLATE utf8mb4_unicode_ci,
  `order` int(11) NOT NULL DEFAULT '0',
  `parent_id` bigint(20) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_permissions_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_permissions`
--

LOCK TABLES `admin_permissions` WRITE;
/*!40000 ALTER TABLE `admin_permissions` DISABLE KEYS */;
INSERT INTO `admin_permissions` VALUES (1,'Auth management','auth-management','','',1,0,'2023-02-22 00:32:10',NULL),(2,'Users','users','','/auth/users*',2,1,'2023-02-22 00:32:10',NULL),(3,'Roles','roles','','/auth/roles*',3,1,'2023-02-22 00:32:10',NULL),(4,'Permissions','permissions','','/auth/permissions*',4,1,'2023-02-22 00:32:10',NULL),(5,'Menu','menu','','/auth/menu*',5,1,'2023-02-22 00:32:10',NULL),(6,'Extension','extension','','/auth/extensions*',6,1,'2023-02-22 00:32:10',NULL),(7,'UrlSkip','UrlSkip','','',7,0,'2023-04-06 00:27:11','2023-04-06 00:27:11'),(8,'group','group','','/group*',8,7,'2023-04-06 00:27:33','2023-04-11 02:31:12'),(9,'domain','domain','','/domain*',9,7,'2023-04-06 00:28:15','2023-04-11 02:31:20'),(10,'Platform branding','platform-branding','','/platform-settings*',10,7,'2026-03-28 00:00:00','2026-03-28 00:00:00'),(11,'Platform DNS','platform-dns','','/platform-dns*',11,7,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(12,'Nodes','nodes','','/nodes*',12,7,'2026-03-28 12:00:00','2026-03-28 12:00:00');
/*!40000 ALTER TABLE `admin_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_role_menu`
--

DROP TABLE IF EXISTS `admin_role_menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_role_menu` (
  `role_id` bigint(20) NOT NULL,
  `menu_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `admin_role_menu_role_id_menu_id_unique` (`role_id`,`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_role_menu`
--

LOCK TABLES `admin_role_menu` WRITE;
/*!40000 ALTER TABLE `admin_role_menu` DISABLE KEYS */;
INSERT INTO `admin_role_menu` VALUES (1,2,'2023-04-11 02:33:32','2023-04-11 02:33:32'),(1,3,'2023-04-11 02:27:59','2023-04-11 02:27:59'),(1,4,'2023-04-11 02:28:08','2023-04-11 02:28:08'),(1,5,'2023-04-11 02:28:16','2023-04-11 02:28:16'),(1,6,'2023-04-11 02:28:23','2023-04-11 02:28:23'),(1,7,'2023-04-11 02:28:30','2023-04-11 02:28:30'),(1,8,'2023-04-06 00:21:34','2023-04-06 00:21:34'),(1,9,'2023-04-06 00:21:14','2023-04-06 00:21:14'),(1,10,'2023-04-06 00:21:21','2023-04-06 00:21:21'),(1,11,'2026-03-28 00:00:00','2026-03-28 00:00:00'),(1,12,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(1,13,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(2,8,'2023-04-11 02:33:41','2023-04-11 02:33:41'),(2,9,'2023-03-29 04:25:29','2023-03-29 04:25:29'),(2,10,'2023-03-29 04:25:29','2023-03-29 04:25:29'),(3,8,'2023-04-11 02:33:41','2023-04-11 02:33:41'),(3,9,'2023-04-06 00:22:53','2023-04-06 00:22:53'),(3,10,'2023-04-06 00:22:53','2023-04-06 00:22:53');
/*!40000 ALTER TABLE `admin_role_menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_role_permissions`
--

DROP TABLE IF EXISTS `admin_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_role_permissions` (
  `role_id` bigint(20) NOT NULL,
  `permission_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `admin_role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_role_permissions`
--

LOCK TABLES `admin_role_permissions` WRITE;
/*!40000 ALTER TABLE `admin_role_permissions` DISABLE KEYS */;
INSERT INTO `admin_role_permissions` VALUES (1,10,'2026-03-28 00:00:00','2026-03-28 00:00:00'),(1,11,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(1,12,'2026-03-28 12:00:00','2026-03-28 12:00:00'),(2,8,'2023-04-11 03:57:48','2023-04-11 03:57:48'),(2,9,'2023-04-11 03:57:48','2023-04-11 03:57:48'),(3,8,'2023-04-06 00:28:29','2023-04-06 00:28:29'),(3,9,'2023-04-06 00:28:29','2023-04-06 00:28:29');
/*!40000 ALTER TABLE `admin_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_role_users`
--

DROP TABLE IF EXISTS `admin_role_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_role_users` (
  `role_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `admin_role_users_role_id_user_id_unique` (`role_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_role_users`
--

LOCK TABLES `admin_role_users` WRITE;
/*!40000 ALTER TABLE `admin_role_users` DISABLE KEYS */;
INSERT INTO `admin_role_users` VALUES (1,1,'2023-02-22 00:32:10','2023-02-22 00:32:10');
/*!40000 ALTER TABLE `admin_role_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_roles`
--

DROP TABLE IF EXISTS `admin_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_roles_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_roles`
--

LOCK TABLES `admin_roles` WRITE;
/*!40000 ALTER TABLE `admin_roles` DISABLE KEYS */;
INSERT INTO `admin_roles` VALUES (1,'Administrator','administrator','2023-02-22 00:32:10','2023-02-22 00:32:10'),(2,'Users','users','2023-03-29 04:25:29','2023-03-29 04:25:29'),(3,'url_admin','url_admin','2023-04-06 00:22:53','2023-04-06 00:22:53');
/*!40000 ALTER TABLE `admin_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_settings`
--

DROP TABLE IF EXISTS `admin_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_settings` (
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_settings`
--

LOCK TABLES `admin_settings` WRITE;
/*!40000 ALTER TABLE `admin_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_users_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_users`
--

LOCK TABLES `admin_users` WRITE;
/*!40000 ALTER TABLE `admin_users` DISABLE KEYS */;
INSERT INTO `admin_users` VALUES (1,'admin','$2b$10$H1daH3IQEcnNNKkGJxQQoeVvxw0RXPjBAXHMPqwttW8/BAIUYKEYS','管理员',NULL,NULL,'2023-02-22 00:32:10',NOW());
/*!40000 ALTER TABLE `admin_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',1),(2,'2014_10_12_100000_create_password_resets_table',1),(3,'2016_01_04_173148_create_admin_tables',1),(4,'2019_08_19_000000_create_failed_jobs_table',1),(5,'2019_12_14_000001_create_personal_access_tokens_table',1),(6,'2020_09_07_090635_create_admin_settings_table',1),(7,'2020_09_22_015815_create_admin_extensions_table',1),(8,'2020_11_01_083237_update_admin_menu_table',1),(9,'2026_03_28_000001_add_platform_settings_menu',2),(10,'2026_03_28_099998_create_redi_admin_core_tables',2),(11,'2026_03_28_100000_create_edge_pool_and_cert_tables',2),(12,'2026_03_28_100001_add_edge_pool_admin_menu',2),(13,'2026_03_28_100002_add_acme_challenges_and_jobs',2),(14,'2026_03_28_100003_add_node_challenge_sync',2),(15,'2026_03_28_100004_add_node_extra_ips',2),(16,'2026_10_13_000001_ensure_failed_jobs_table',3),(17,'2026_03_28_100005_global_unique_original_url',3),(18,'2026_03_28_100006_redi_domain_certs_domain_fk',4),(19,'2026_03_28_100007_node_domain_view_and_url_hash',5),(20,'2026_03_28_100008_redi_cert_deployments_foreign_keys',5),(21,'2026_03_28_100009_redi_node_skip_procedure',6);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users` (Laravel 默认迁移；本系统后台使用 admin_users)
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`(191),`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `redi_platform_dns`
--

DROP TABLE IF EXISTS `redi_platform_dns`;
CREATE TABLE `redi_platform_dns` (
  `id` tinyint(3) unsigned NOT NULL,
  `edge_zone` varchar(255) NOT NULL DEFAULT '',
  `pool_host` varchar(63) NOT NULL DEFAULT 'pool',
  `dns_mode` varchar(16) NOT NULL DEFAULT 'manual',
  `dns_driver` varchar(32) NOT NULL DEFAULT 'manual',
  `api_credentials` text,
  `dns_zone_id` varchar(128) DEFAULT NULL,
  `acme_contact_email` varchar(255) DEFAULT NULL,
  `acme_staging` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `redi_platform_dns` VALUES (1,'','pool','manual','manual',NULL,NULL,NULL,0,NOW(),NOW());

DROP TABLE IF EXISTS `redi_acme_challenges`;
CREATE TABLE `redi_acme_challenges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_name` varchar(255) NOT NULL,
  `token` varchar(128) NOT NULL,
  `response_body` text NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_acme_challenges_domain_name_unique` (`domain_name`),
  KEY `redi_acme_challenges_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_acme_challenge_node_sync`;
CREATE TABLE `redi_acme_challenge_node_sync` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `node_id` int(10) unsigned NOT NULL,
  `domain_name` varchar(255) NOT NULL,
  `synced_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_acme_challenge_node_sync_node_id_domain_name_unique` (`node_id`,`domain_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_nodes`;
CREATE TABLE `redi_nodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `public_ip` varchar(45) NOT NULL,
  `extra_ips` text,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `api_token_hash` varchar(255) DEFAULT NULL,
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `agent_version` varchar(32) DEFAULT NULL,
  `note` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_nodes_public_ip_unique` (`public_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_tenant_edge`;
CREATE TABLE `redi_tenant_edge` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `token` varchar(32) NOT NULL,
  `edge_fqdn` varchar(255) NOT NULL,
  `dns_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_tenant_edge_admin_id_unique` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_domain_certs`;
CREATE TABLE `redi_domain_certs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int(10) unsigned NOT NULL,
  `admin_id` int(10) unsigned NOT NULL,
  `domain_name` varchar(255) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'none',
  `method` varchar(16) NOT NULL DEFAULT 'http-01',
  `expires_at` timestamp NULL DEFAULT NULL,
  `certificate` text,
  `private_key` text,
  `last_error` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_domain_certs_domain_id_unique` (`domain_id`),
  KEY `redi_domain_certs_admin_id_status_index` (`admin_id`,`status`),
  CONSTRAINT `redi_domain_certs_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `redi_admin_domain` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_cert_deployments`;
CREATE TABLE `redi_cert_deployments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cert_id` int(10) unsigned NOT NULL,
  `node_id` int(10) unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `last_error` text,
  `deployed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redi_cert_deployments_cert_id_node_id_unique` (`cert_id`,`node_id`),
  KEY `redi_cert_deployments_cert_id_foreign` (`cert_id`),
  KEY `redi_cert_deployments_node_id_foreign` (`node_id`),
  CONSTRAINT `redi_cert_deployments_cert_id_foreign` FOREIGN KEY (`cert_id`) REFERENCES `redi_domain_certs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `redi_cert_deployments_node_id_foreign` FOREIGN KEY (`node_id`) REFERENCES `redi_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `redi_dns_sync_logs`;
CREATE TABLE `redi_dns_sync_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(64) NOT NULL,
  `status` varchar(16) NOT NULL,
  `message` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `redi_admin_domain`
--

DROP TABLE IF EXISTS `redi_admin_domain`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `redi_admin_domain` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) NOT NULL DEFAULT '0' COMMENT '分组id',
  `original_url` varchar(255) NOT NULL DEFAULT '' COMMENT '原始域名',
  `original_url_hash` char(64) DEFAULT NULL COMMENT 'sha256(normalized original_url)',
  `skip_url` varchar(255) NOT NULL DEFAULT '' COMMENT '跳转链接',
  `status` tinyint(4) unsigned NOT NULL DEFAULT '1' COMMENT '状态：1启用 2停用',
  `force_https` tinyint(1) NOT NULL DEFAULT '0',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '添加人id',
  `admin_name` varchar(50) NOT NULL DEFAULT '' COMMENT '添加人名字',
  `skip_num` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '跳转数量',
  `created_at` varchar(50) NOT NULL COMMENT '创建时间',
  `updated_at` varchar(50) NOT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_original_url` (`original_url`(191)),
  KEY `idx_admin` (`admin_id`),
  UNIQUE KEY `uniq_original_url_global` (`original_url`(191)),
  UNIQUE KEY `uniq_original_url_hash` (`original_url_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `redi_admin_domain`
--

LOCK TABLES `redi_admin_domain` WRITE;
/*!40000 ALTER TABLE `redi_admin_domain` DISABLE KEYS */;
/*!40000 ALTER TABLE `redi_admin_domain` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `redi_admin_group`
--

DROP TABLE IF EXISTS `redi_admin_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `redi_admin_group` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：1启用 2停用',
  `created_at` varchar(50) NOT NULL,
  `updated_at` varchar(50) NOT NULL,
  `admin_id` int(10) NOT NULL DEFAULT '0',
  `admin_name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `redi_admin_group`
--

LOCK TABLES `redi_admin_group` WRITE;
/*!40000 ALTER TABLE `redi_admin_group` DISABLE KEYS */;
/*!40000 ALTER TABLE `redi_admin_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'redi_admin'
--

--
-- Dumping routines for database 'redi_admin'
--
-- 绿场仅保留存储过程跳转；废弃 migration 100007 的 lookup 视图（节点已用 redi_node_bump_skip）
DROP VIEW IF EXISTS `redi_node_domain_lookup`;
DROP PROCEDURE IF EXISTS `redi_node_bump_skip`;
DELIMITER ;;
CREATE PROCEDURE `redi_node_bump_skip`(IN p_host VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
    READS SQL DATA
    MODIFIES SQL DATA
    SQL SECURITY DEFINER
    COMMENT 'Edge node: return skip_url for one host and increment skip_num'
BEGIN
    DECLARE v_skip VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;

    START TRANSACTION;

    SELECT `skip_url` INTO v_skip
    FROM `redi_admin_domain`
    WHERE `original_url` = p_host AND `status` = 1
    LIMIT 1
    FOR UPDATE;

    IF v_skip IS NOT NULL THEN
        UPDATE `redi_admin_domain`
        SET `skip_num` = `skip_num` + 1
        WHERE `original_url` = p_host AND `status` = 1
        LIMIT 1;
    END IF;

    COMMIT;

    SELECT v_skip AS `skip_url`;
END ;;
DELIMITER ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2024-11-27  0:08:34
