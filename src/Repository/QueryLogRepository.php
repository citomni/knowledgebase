<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\KnowledgeBase\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * Persist and maintain optional retrieval query log rows.
 *
 * Behavior:
 * - Owns all SQL for the optional `know_query_log` table.
 * - `write()` silently no-ops when the table does not exist.
 * - Table existence is checked once per process and then cached.
 * - JSON payloads are encoded deterministically before persistence.
 *
 * Notes:
 * - This repository is intentionally tolerant about table absence because the
 *   query log table is optional and created explicitly by setup tooling.
 * - The canonical write payload is fixed; extensibility belongs in metadata_json.
 *
 * Typical usage:
 *   $repo = new QueryLogRepository($this->app);
 *   $repo->write([
 *       'knowledge_base_id' => 1,
 *       'query_text' => 'må udlejer kræve depositum?',
 *       'strategy' => 'hybrid',
 *       'chunk_limit' => 5,
 *       'results_count' => 4,
 *       'top_chunk_ids' => [101, 205, 309],
 *       'reranked' => true,
 *       'duration_ms' => 87,
 *       'metadata_json' => ['synonyms_used' => true],
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 */
final class QueryLogRepository extends BaseRepository {

	/**
	 * Cached per-process table existence state.
	 */
	private ?bool $queryLogTableExists = null;


	/**
	 * Persist one query log row.
	 *
	 * Canonical accepted fields:
	 * - knowledge_base_id (required)
	 * - query_text (required)
	 * - strategy
	 * - chunk_limit
	 * - results_count
	 * - top_chunk_ids
	 * - reranked
	 * - duration_ms
	 * - metadata_json
	 * - metadata (alias of metadata_json)
	 *
	 * Behavior:
	 * - Returns null when the table does not exist.
	 * - Returns inserted row id when the row is written.
	 *
	 * @param array $data Canonical query log payload.
	 * @return ?int Inserted row id, or null when the table does not exist.
	 * @throws \InvalidArgumentException When input data is invalid.
	 */
	public function write(array $data): ?int {
		if (!$this->tableExists()) {
			return null;
		}

		$payload = $this->normalizeWriteData($data);

		return $this->app->db->insert('know_query_log', $payload);
	}


	/**
	 * Delete old query log rows.
	 *
	 * @param int $maxAgeDays Maximum row age in days.
	 * @return int Number of deleted rows.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function prune(int $maxAgeDays): int {
		$maxAgeDays = $this->normalizePositiveInt($maxAgeDays, 'maxAgeDays');

		if (!$this->tableExists()) {
			return 0;
		}

		return $this->app->db->execute(
			'DELETE FROM know_query_log
			WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
			[$maxAgeDays]
		);
	}


	/**
	 * Determine whether the optional query log table exists.
	 *
	 * Result is cached per process after the first lookup.
	 *
	 * @return bool True when the table exists.
	 */
	public function tableExists(): bool {
		if ($this->queryLogTableExists !== null) {
			return $this->queryLogTableExists;
		}

		$count = $this->app->db->fetchValue(
			'SELECT COUNT(*)
			FROM information_schema.tables
			WHERE table_schema = DATABASE()
				AND table_name = ?',
			['know_query_log']
		);

		$this->queryLogTableExists = ((int)$count) > 0;

		return $this->queryLogTableExists;
	}


	/**
	 * Create the optional query log table.
	 *
	 * Notes:
	 * - Safe to call multiple times because it uses IF NOT EXISTS.
	 * - Refreshes the cached table existence state.
	 *
	 * @return void
	 */
	public function createTable(): void {
		$this->app->db->queryRaw(
			'CREATE TABLE IF NOT EXISTS know_query_log (
				id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				knowledge_base_id INT UNSIGNED NOT NULL,
				query_text        TEXT NOT NULL,
				strategy          VARCHAR(50) NOT NULL DEFAULT \'hybrid\',
				chunk_limit       TINYINT UNSIGNED DEFAULT NULL,
				results_count     SMALLINT UNSIGNED DEFAULT NULL,
				top_chunk_ids     JSON DEFAULT NULL,
				reranked          TINYINT(1) NOT NULL DEFAULT 0,
				duration_ms       INT UNSIGNED DEFAULT NULL,
				metadata_json     JSON DEFAULT NULL,
				created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_kb_created (knowledge_base_id, created_at),
				CONSTRAINT fk_qlog_kb FOREIGN KEY (knowledge_base_id) REFERENCES know_bases (id) ON DELETE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
		);

		$this->queryLogTableExists = true;
	}









	// ----------------------------------------------------------------
	// Normalization helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize one write payload.
	 *
	 * @param array $data Raw query log payload.
	 * @return array Normalized DB payload.
	 * @throws \InvalidArgumentException When payload data is invalid.
	 */
	private function normalizeWriteData(array $data): array {
		$allowedKeys = [
			'knowledge_base_id',
			'query_text',
			'strategy',
			'chunk_limit',
			'results_count',
			'top_chunk_ids',
			'reranked',
			'duration_ms',
			'metadata_json',
			'metadata',
		];

		foreach ($data as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown query log field: ' . $key);
			}
		}

		if (!\array_key_exists('knowledge_base_id', $data)) {
			throw new \InvalidArgumentException('Missing required field: knowledge_base_id');
		}

		if (!\array_key_exists('query_text', $data)) {
			throw new \InvalidArgumentException('Missing required field: query_text');
		}

		if (\array_key_exists('metadata', $data) && \array_key_exists('metadata_json', $data)) {
			throw new \InvalidArgumentException('Use either metadata or metadata_json, not both.');
		}

		$payload = [
			'knowledge_base_id' => $this->normalizePositiveInt($data['knowledge_base_id'], 'knowledge_base_id'),
			'query_text' => $this->normalizeRequiredText($data['query_text'], 'query_text'),
			'strategy' => $this->normalizeStrategy($data['strategy'] ?? 'hybrid'),
			'chunk_limit' => $this->normalizeNullableTinyUnsignedInt($data['chunk_limit'] ?? null, 'chunk_limit'),
			'results_count' => $this->normalizeNullableSmallUnsignedInt($data['results_count'] ?? null, 'results_count'),
			'top_chunk_ids' => $this->normalizeNullableTopChunkIds($data['top_chunk_ids'] ?? null),
			'reranked' => $this->normalizeBoolInt($data['reranked'] ?? false),
			'duration_ms' => $this->normalizeNullableUnsignedInt($data['duration_ms'] ?? null, 'duration_ms'),
		];

		if (\array_key_exists('metadata', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata']);
		}

		if (\array_key_exists('metadata_json', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata_json']);
		}

		return $payload;
	}


	/**
	 * Normalize a positive integer.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return int Normalized integer.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizePositiveInt(mixed $value, string $field): int {
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('Field must be a positive integer: ' . $field);
		}

		$value = (int)$value;

		if ($value <= 0) {
			throw new \InvalidArgumentException('Field must be greater than zero: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a required non-empty text field.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return string Normalized text.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeRequiredText(mixed $value, string $field): string {
		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Field must be string: ' . $field);
		}

		$value = \trim($value);

		if ($value === '') {
			throw new \InvalidArgumentException('Field must not be empty: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize retrieval strategy.
	 *
	 * @param mixed $value Raw strategy.
	 * @return string Normalized strategy.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeStrategy(mixed $value): string {
		if (!\is_string($value)) {
			throw new \InvalidArgumentException('strategy must be string.');
		}

		$value = \trim($value);

		if ($value === '') {
			throw new \InvalidArgumentException('strategy must not be empty.');
		}

		if (\mb_strlen($value, 'UTF-8') > 50) {
			throw new \InvalidArgumentException('strategy exceeds max length.');
		}

		return $value;
	}


	/**
	 * Normalize nullable TINYINT UNSIGNED-like integer.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return ?int Normalized integer or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableTinyUnsignedInt(mixed $value, string $field): ?int {
		if ($value === null) {
			return null;
		}

		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('Field must be an unsigned integer or null: ' . $field);
		}

		$value = (int)$value;

		if ($value < 0 || $value > 255) {
			throw new \InvalidArgumentException('Field must be between 0 and 255: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize nullable SMALLINT UNSIGNED-like integer.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return ?int Normalized integer or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableSmallUnsignedInt(mixed $value, string $field): ?int {
		if ($value === null) {
			return null;
		}

		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('Field must be an unsigned integer or null: ' . $field);
		}

		$value = (int)$value;

		if ($value < 0 || $value > 65535) {
			throw new \InvalidArgumentException('Field must be between 0 and 65535: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize nullable INT UNSIGNED-like integer.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return ?int Normalized integer or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableUnsignedInt(mixed $value, string $field): ?int {
		if ($value === null) {
			return null;
		}

		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('Field must be an unsigned integer or null: ' . $field);
		}

		$value = (int)$value;

		if ($value < 0) {
			throw new \InvalidArgumentException('Field must not be negative: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize boolean-like input to 0/1.
	 *
	 * @param mixed $value Raw boolean-like value.
	 * @return int 0 or 1.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeBoolInt(mixed $value): int {
		if (\is_bool($value)) {
			return $value ? 1 : 0;
		}

		if (\is_int($value) && ($value === 0 || $value === 1)) {
			return $value;
		}

		if (\is_string($value)) {
			$value = \trim(\strtolower($value));

			if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
				return 1;
			}

			if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
				return 0;
			}
		}

		throw new \InvalidArgumentException('reranked must be boolean-like.');
	}


	/**
	 * Normalize nullable top chunk ids to JSON or null.
	 *
	 * @param mixed $value Raw chunk id list.
	 * @return ?string JSON string or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableTopChunkIds(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_array($value)) {
			throw new \InvalidArgumentException('top_chunk_ids must be an array or null.');
		}

		$normalized = [];

		foreach ($value as $chunkId) {
			$normalized[] = $this->normalizePositiveInt($chunkId, 'top_chunk_ids');
		}

		return \json_encode(\array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}


	/**
	 * Normalize metadata to JSON or null.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return ?string JSON string or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeMetadataJson(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (\is_array($value)) {
			return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}

		if (\is_string($value)) {
			$value = \trim($value);

			if ($value === '') {
				return null;
			}

			$decoded = \json_decode($value, true, 512, JSON_THROW_ON_ERROR);

			if (!\is_array($decoded)) {
				throw new \InvalidArgumentException('metadata_json must decode to an array.');
			}

			return \json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}

		throw new \InvalidArgumentException('metadata_json must be array, JSON string, or null.');
	}


}
