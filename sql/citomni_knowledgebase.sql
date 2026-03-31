/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.4.9-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: citomni
-- ------------------------------------------------------
-- Server version	11.4.9-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `know_bases`
--

DROP TABLE IF EXISTS `know_bases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_bases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'da',
  `status` enum('active','inactive','archived') NOT NULL DEFAULT 'active',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_bases`
--

LOCK TABLES `know_bases` WRITE;
/*!40000 ALTER TABLE `know_bases` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_bases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_chunks`
--

DROP TABLE IF EXISTS `know_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_chunks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `unit_id` int(10) unsigned NOT NULL,
  `chunk_index` smallint(5) unsigned NOT NULL DEFAULT 0,
  `content` text NOT NULL,
  `context_before` text DEFAULT NULL,
  `context_after` text DEFAULT NULL,
  `token_count` smallint(5) unsigned DEFAULT NULL,
  `char_count` smallint(5) unsigned DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_chunk` (`unit_id`,`chunk_index`),
  KEY `idx_unit` (`unit_id`),
  FULLTEXT KEY `ft_content` (`content`),
  CONSTRAINT `fk_chunk_unit` FOREIGN KEY (`unit_id`) REFERENCES `know_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_chunks`
--

LOCK TABLES `know_chunks` WRITE;
/*!40000 ALTER TABLE `know_chunks` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_chunks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_documents`
--

DROP TABLE IF EXISTS `know_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_base_id` int(10) unsigned NOT NULL,
  `slug` varchar(150) NOT NULL,
  `title` varchar(500) NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_ref` varchar(255) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `version_label` varchar(100) DEFAULT NULL,
  `content_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'da',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `status` enum('draft','active','superseded','archived') NOT NULL DEFAULT 'draft',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kb_slug` (`knowledge_base_id`,`slug`),
  KEY `idx_kb_status` (`knowledge_base_id`,`status`),
  CONSTRAINT `fk_doc_kb` FOREIGN KEY (`knowledge_base_id`) REFERENCES `know_bases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_documents`
--

LOCK TABLES `know_documents` WRITE;
/*!40000 ALTER TABLE `know_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_embeddings`
--

DROP TABLE IF EXISTS `know_embeddings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_embeddings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `chunk_id` int(10) unsigned NOT NULL,
  `model` varchar(100) NOT NULL,
  `dimension` smallint(5) unsigned NOT NULL,
  `vector` blob NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chunk_model` (`chunk_id`,`model`),
  KEY `idx_model` (`model`),
  CONSTRAINT `fk_emb_chunk` FOREIGN KEY (`chunk_id`) REFERENCES `know_chunks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_embeddings`
--

LOCK TABLES `know_embeddings` WRITE;
/*!40000 ALTER TABLE `know_embeddings` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_embeddings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_query_log`
--

DROP TABLE IF EXISTS `know_query_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_query_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_base_id` int(10) unsigned NOT NULL,
  `query_text` text NOT NULL,
  `strategy` varchar(50) NOT NULL DEFAULT 'hybrid',
  `chunk_limit` tinyint(3) unsigned DEFAULT NULL,
  `results_count` smallint(5) unsigned DEFAULT NULL,
  `top_chunk_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`top_chunk_ids`)),
  `reranked` tinyint(1) NOT NULL DEFAULT 0,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kb_created` (`knowledge_base_id`,`created_at`),
  CONSTRAINT `fk_qlog_kb` FOREIGN KEY (`knowledge_base_id`) REFERENCES `know_bases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_query_log`
--

LOCK TABLES `know_query_log` WRITE;
/*!40000 ALTER TABLE `know_query_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_query_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_synonym_groups`
--

DROP TABLE IF EXISTS `know_synonym_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_synonym_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `knowledge_base_id` int(10) unsigned NOT NULL,
  `canonical_term` varchar(200) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_syn_group_kb_canonical` (`knowledge_base_id`,`canonical_term`),
  KEY `idx_syn_group_kb` (`knowledge_base_id`),
  CONSTRAINT `fk_syn_group_kb` FOREIGN KEY (`knowledge_base_id`) REFERENCES `know_bases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_synonym_groups`
--

LOCK TABLES `know_synonym_groups` WRITE;
/*!40000 ALTER TABLE `know_synonym_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_synonym_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_synonym_terms`
--

DROP TABLE IF EXISTS `know_synonym_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_synonym_terms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL,
  `knowledge_base_id` int(10) unsigned NOT NULL,
  `term` varchar(200) NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_syn_term_group_term` (`group_id`,`term`),
  UNIQUE KEY `uq_syn_term_kb_term` (`knowledge_base_id`,`term`),
  KEY `idx_syn_term_group_sort` (`group_id`,`sort_order`),
  KEY `idx_syn_term_kb_term` (`knowledge_base_id`,`term`),
  CONSTRAINT `fk_syn_term_group` FOREIGN KEY (`group_id`) REFERENCES `know_synonym_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_syn_term_kb` FOREIGN KEY (`knowledge_base_id`) REFERENCES `know_bases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_synonym_terms`
--

LOCK TABLES `know_synonym_terms` WRITE;
/*!40000 ALTER TABLE `know_synonym_terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_synonym_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `know_units`
--

DROP TABLE IF EXISTS `know_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `know_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `parent_id_for_order` int(10) unsigned GENERATED ALWAYS AS (coalesce(`parent_id`,0)) STORED,
  `unit_type` varchar(50) NOT NULL,
  `identifier` varchar(100) DEFAULT NULL,
  `title` varchar(500) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `depth` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `path` varchar(500) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sibling_order` (`document_id`,`parent_id_for_order`,`sort_order`),
  KEY `idx_document` (`document_id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_doc_type` (`document_id`,`unit_type`),
  KEY `idx_path` (`document_id`,`path`),
  CONSTRAINT `fk_unit_doc` FOREIGN KEY (`document_id`) REFERENCES `know_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_unit_parent` FOREIGN KEY (`parent_id`) REFERENCES `know_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `know_units`
--

LOCK TABLES `know_units` WRITE;
/*!40000 ALTER TABLE `know_units` DISABLE KEYS */;
/*!40000 ALTER TABLE `know_units` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-03-31  0:45:31
