-- ============================================================================
-- citomni/knowledgebase — DDL
-- MySQL 8.0+, InnoDB, utf8mb4_unicode_ci
--
-- Seven core tables:
--   know_bases           – top-level knowledge domains
--   know_documents       – versioned source documents within a domain
--   know_units           – stable hierarchical structure within a document
--   know_chunks          – retrieval-optimized atoms derived from units
--   know_embeddings      – vector representations of chunks (one per model/chunk)
--   know_synonym_groups  – synonym group containers scoped per knowledge base
--   know_synonym_terms   – one normalized synonym term per row
--
-- Optional (created by know:setup-query-log command):
--   know_query_log   – retrieval analytics
--
-- Design principles:
--   Units are stable. They survive chunking and embedding changes.
--   Chunks are disposable. Delete and regenerate freely.
--   Embeddings are isolated. Swap models without touching chunks.
--   Synonyms are admin-maintained. They improve lexical retrieval quality.
--   Synonym storage is relational: one group row plus one term row per surface form.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- Knowledge bases
-- ----------------------------------------------------------------------------
-- One per knowledge domain: "lejeloven", "almenlejeloven", "huslejenævn-faq".
-- Separates domains without schema-level isolation. Retrieval queries scope
-- to one or more knowledge bases.

CREATE TABLE know_bases (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
	slug          VARCHAR(100) NOT NULL,
	title         VARCHAR(255) NOT NULL,
	description   TEXT DEFAULT NULL,
	language      VARCHAR(10) NOT NULL DEFAULT 'da',
	status        ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
	metadata_json JSON DEFAULT NULL,
	created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Documents
-- ----------------------------------------------------------------------------
-- One row per versioned source: a law consolidation, a manual edition, a dated
-- FAQ snapshot. A revised law is a new document (or a status change on the old
-- one), never an in-place mutation of units/chunks.
--
-- content_hash: SHA-256 hex of the raw source text at ingest time. Enables
-- idempotent re-import: matching hash = skip, mismatch = re-ingest. NULL is
-- valid for manually created documents or legacy imports without source text.
-- Stored as ascii_bin to avoid collation ambiguity on fixed hex values.

CREATE TABLE know_documents (
	id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
	knowledge_base_id INT UNSIGNED NOT NULL,
	slug              VARCHAR(150) NOT NULL,
	title             VARCHAR(500) NOT NULL,
	source_type       VARCHAR(50) NOT NULL,
	source_ref        VARCHAR(255) DEFAULT NULL,
	effective_date    DATE DEFAULT NULL,
	version_label     VARCHAR(100) DEFAULT NULL,
	content_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
	language          VARCHAR(10) NOT NULL DEFAULT 'da',
	metadata_json     JSON DEFAULT NULL,
	status            ENUM('draft', 'active', 'superseded', 'archived') NOT NULL DEFAULT 'draft',
	created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_kb_slug (knowledge_base_id, slug),
	KEY idx_kb_status (knowledge_base_id, status),
	CONSTRAINT fk_doc_kb FOREIGN KEY (knowledge_base_id) REFERENCES know_bases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Units
-- ----------------------------------------------------------------------------
-- The human-readable, domain-meaningful structure within a document.
-- For a law: Afsnit, Kapitel, Paragraf, Stykke.
-- For a FAQ: Category, Question, Answer.
-- For a manual: Chapter, Section, Subsection.
--
-- Units are stable. They survive chunking strategy changes and embedding model
-- upgrades. They are what source citations reference.
--
-- Hierarchy: self-referencing parent_id + materialized path for subtree queries.
-- Path format: zero-padded dot-separated sort_order chain, e.g. "001.003.002".
-- Three-digit padding supports up to 999 siblings per level. Deterministic
-- lexical ordering — no recursive CTE needed for subtree or sibling queries.
--
-- Sibling ordering: uq_sibling_order prevents duplicate positions among
-- siblings with a non-NULL parent. MySQL treats NULL as distinct in UNIQUE
-- constraints, so top-level units (parent_id IS NULL) are not covered by the
-- constraint — Repository enforces ordering for root-level units.
--
-- The UNIQUE index also serves as the primary query index for the dominant
-- access pattern: fetching children of a parent sorted by position.

CREATE TABLE know_units (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
	document_id   INT UNSIGNED NOT NULL,
	parent_id     INT UNSIGNED DEFAULT NULL,
	unit_type     VARCHAR(50) NOT NULL,
	identifier    VARCHAR(100) DEFAULT NULL,
	title         VARCHAR(500) DEFAULT NULL,
	body          TEXT DEFAULT NULL,
	sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
	depth         TINYINT UNSIGNED NOT NULL DEFAULT 0,
	path          VARCHAR(500) DEFAULT NULL,
	metadata_json JSON DEFAULT NULL,
	created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_sibling_order (document_id, parent_id, sort_order),
	KEY idx_document (document_id),
	KEY idx_parent (parent_id),
	KEY idx_doc_type (document_id, unit_type),
	KEY idx_path (document_id, path),
	CONSTRAINT fk_unit_doc FOREIGN KEY (document_id) REFERENCES know_documents (id) ON DELETE CASCADE,
	CONSTRAINT fk_unit_parent FOREIGN KEY (parent_id) REFERENCES know_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Chunks
-- ----------------------------------------------------------------------------
-- Retrieval-optimized atoms derived from units. One unit may produce one or
-- many chunks depending on chunking strategy.
--
-- Chunks are disposable: delete and regenerate when changing chunking strategy
-- or token budgets. Units remain untouched.
--
-- context_before / context_after: overlap text for sliding-window chunking.
-- Included in embedding input but presented separately so the retriever can
-- distinguish core content from overlap context.

CREATE TABLE know_chunks (
	id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
	unit_id        INT UNSIGNED NOT NULL,
	chunk_index    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	content        TEXT NOT NULL,
	context_before TEXT DEFAULT NULL,
	context_after  TEXT DEFAULT NULL,
	token_count    SMALLINT UNSIGNED DEFAULT NULL,
	char_count     SMALLINT UNSIGNED DEFAULT NULL,
	metadata_json  JSON DEFAULT NULL,
	created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_unit_chunk (unit_id, chunk_index),
	KEY idx_unit (unit_id),
	CONSTRAINT fk_chunk_unit FOREIGN KEY (unit_id) REFERENCES know_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE know_chunks ADD FULLTEXT INDEX ft_content (content);


-- ----------------------------------------------------------------------------
-- Embeddings
-- ----------------------------------------------------------------------------
-- Vector representations of chunks. Separated from chunks so that:
--   - queries not needing vectors skip BLOB scanning entirely
--   - multiple models can coexist per chunk (A/B, migration, benchmarking)
--   - re-embedding is INSERT + DELETE on this table; chunks/units untouched
--
-- Storage: binary BLOB of float32 values via pack('f*'). This is an internal
-- storage detail — Repository owns pack/unpack. Application code sees float[].
--
-- One row per chunk per model. The retriever selects which model to use via
-- cfg profile and JOINs only matching rows.
--
-- Chunks without an embedding row for the active model are silently excluded
-- from the semantic retrieval leg. They still participate in lexical search.
-- Use EmbeddingRepository::countMissing() to check completeness.
--
-- idx_model covers the full-scan retrieval path (WHERE model = ?) used when
-- computing cosine similarity in PHP across all embeddings for a model.
-- uq_chunk_model covers per-chunk lookups (WHERE chunk_id = ? AND model = ?).

CREATE TABLE know_embeddings (
	id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
	chunk_id   INT UNSIGNED NOT NULL,
	model      VARCHAR(100) NOT NULL,
	dimension  SMALLINT UNSIGNED NOT NULL,
	vector     BLOB NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_chunk_model (chunk_id, model),
	KEY idx_model (model),
	CONSTRAINT fk_emb_chunk FOREIGN KEY (chunk_id) REFERENCES know_chunks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Synonyms
-- ----------------------------------------------------------------------------
-- Domain-specific synonym groups for lexical query expansion. Admin-maintained.
-- Scoped per knowledge base — legal synonyms differ from insurance synonyms.
--
-- Synonyms are stored relationally:
--   - know_synonym_groups: one administrative group row per knowledge base
--   - know_synonym_terms: one normalized term row per synonym surface form
--
-- Lookup is bidirectional: querying any stored term expands to all terms in the
-- same synonym group.
--
-- Normalization rules for canonical_term and term:
--   - trimmed
--   - lowercased using UTF-8 aware lowercasing
--   - internal whitespace collapsed to a single space
--   - empty values rejected after normalization
--
-- Overlap rule:
--   A normalized term may belong to only one synonym group per knowledge base.
--   This is enforced by a UNIQUE constraint on (knowledge_base_id, term).
--
-- The group's canonical_term is an administrative anchor and must also exist as
-- a term row in know_synonym_terms for the same group.
-- This invariant is enforced by the Repository layer transactionally.
--
-- Example:
--   know_synonym_groups:
--     id=10, knowledge_base_id=1, canonical_term="depositum"
--
--   know_synonym_terms:
--     (group_id=10, knowledge_base_id=1, term="depositum")
--     (group_id=10, knowledge_base_id=1, term="indskud")
--     (group_id=10, knowledge_base_id=1, term="deposit")
--
-- Used by Retriever to augment lexical queries before FULLTEXT search.
-- When synonyms are active, FULLTEXT switches from natural language mode to
-- boolean mode to support structured term expansion.

CREATE TABLE know_synonym_groups (
	id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
	knowledge_base_id INT UNSIGNED NOT NULL,
	canonical_term    VARCHAR(200) NOT NULL,
	created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_syn_group_kb_canonical (knowledge_base_id, canonical_term),
	KEY idx_syn_group_kb (knowledge_base_id),
	CONSTRAINT fk_syn_group_kb FOREIGN KEY (knowledge_base_id) REFERENCES know_bases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE know_synonym_terms (
	id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
	group_id          INT UNSIGNED NOT NULL,
	knowledge_base_id INT UNSIGNED NOT NULL,
	term              VARCHAR(200) NOT NULL,
	sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_syn_term_group_term (group_id, term),
	UNIQUE KEY uq_syn_term_kb_term (knowledge_base_id, term),
	KEY idx_syn_term_group_sort (group_id, sort_order),
	KEY idx_syn_term_kb_term (knowledge_base_id, term),
	CONSTRAINT fk_syn_term_group FOREIGN KEY (group_id) REFERENCES know_synonym_groups (id) ON DELETE CASCADE,
	CONSTRAINT fk_syn_term_kb FOREIGN KEY (knowledge_base_id) REFERENCES know_bases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- Optional: Query log
-- ============================================================================
-- Not part of the base DDL. Created explicitly by the know:setup-query-log
-- CLI command. Repository no-ops when logging is enabled in cfg but the table
-- does not exist (checks once per process, cached).
--
-- Retrieval analytics: which questions are asked, which chunks are returned,
-- how long retrieval takes. Queryable analytics infrastructure — not debug
-- logging (which goes through CitOmni's JSONL log service).
--
-- Pruned by know:prune-query-log.
-- ============================================================================

-- CREATE TABLE know_query_log (
-- 	id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
-- 	knowledge_base_id INT UNSIGNED NOT NULL,
-- 	query_text        TEXT NOT NULL,
-- 	strategy          VARCHAR(50) NOT NULL DEFAULT 'hybrid',
-- 	chunk_limit       TINYINT UNSIGNED DEFAULT NULL,
-- 	results_count     SMALLINT UNSIGNED DEFAULT NULL,
-- 	top_chunk_ids     JSON DEFAULT NULL,
-- 	reranked          TINYINT(1) NOT NULL DEFAULT 0,
-- 	duration_ms       INT UNSIGNED DEFAULT NULL,
-- 	metadata_json     JSON DEFAULT NULL,
-- 	created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
-- 	PRIMARY KEY (id),
-- 	KEY idx_kb_created (knowledge_base_id, created_at),
-- 	CONSTRAINT fk_qlog_kb FOREIGN KEY (knowledge_base_id) REFERENCES know_bases (id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
