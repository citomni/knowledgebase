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
 * Persist and read retrieval chunks.
 *
 * Behavior:
 * - Owns all SQL for the `know_chunks` table.
 * - Supports deterministic batch insert per unit.
 * - Executes FULLTEXT lexical search in natural or boolean mode.
 * - Supports optional knowledge base scoping through units -> documents -> bases.
 *
 * Notes:
 * - FULLTEXT search is performed only against `content`.
 * - `context_before` and `context_after` are stored for embedding/context purposes,
 *   but they do not participate in lexical ranking.
 * - `findByLexical()` returns repository-shaped rows with `chunk_id`,
 *   `relevance_score`, `score`, and `retrieval_methods` added.
 *
 * Typical usage:
 *   $repo = new ChunkRepository($this->app);
 *   $repo->insertBatch($unitId, [
 *       [
 *           'content' => '...',
 *           'context_before' => null,
 *           'context_after' => null,
 *           'token_count' => 123,
 *           'char_count' => 456,
 *       ],
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 */
final class ChunkRepository extends BaseRepository {

	/**
	 * Insert many chunks for one unit.
	 *
	 * Each chunk row is inserted with an auto-assigned sequential `chunk_index`
	 * based on the position in the provided array.
	 *
	 * Accepted per-chunk fields:
	 * - content (required)
	 * - context_before
	 * - context_after
	 * - token_count
	 * - char_count
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 *
	 * @param int $unitId Unit id.
	 * @param array $chunks Chunk payloads.
	 * @return int Number of inserted rows.
	 * @throws \InvalidArgumentException When input data is invalid.
	 */
	public function insertBatch(int $unitId, array $chunks): int {
		$unitId = $this->normalizePositiveInt($unitId, 'unitId');

		if ($chunks === []) {
			return 0;
		}

		$rows = [];

		foreach ($chunks as $index => $chunk) {
			if (!\is_array($chunk)) {
				throw new \InvalidArgumentException('Each chunk must be an array.');
			}

			$row = $this->normalizeInsertChunk($chunk);
			$row['unit_id'] = $unitId;
			$row['chunk_index'] = $index;
			$rows[] = $row;
		}

		return $this->app->db->insertBatch('know_chunks', $rows);
	}


	/**
	 * Find all chunks for one unit.
	 *
	 * @param int $unitId Unit id.
	 * @return array Hydrated chunk rows ordered by chunk_index.
	 */
	public function findByUnit(int $unitId): array {
		$rows = $this->app->db->fetchAll(
			'SELECT id, unit_id, chunk_index, content, context_before, context_after,
				token_count, char_count, metadata_json, created_at
			FROM know_chunks
			WHERE unit_id = ?
			ORDER BY chunk_index ASC, id ASC',
			[$unitId]
		);

		return $this->hydrateRows($rows);
	}


	/**
	 * Find one chunk by id.
	 *
	 * @param int $id Chunk id.
	 * @return ?array Hydrated chunk row or null when not found.
	 */
	public function findById(int $id): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, unit_id, chunk_index, content, context_before, context_after,
				token_count, char_count, metadata_json, created_at
			FROM know_chunks
			WHERE id = ?
			LIMIT 1',
			[$id]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * Execute lexical FULLTEXT search against chunk content.
	 *
	 * Return shape includes:
	 * - chunk_id
	 * - unit_id
	 * - document_id
	 * - knowledge_base_id
	 * - document_title
	 * - document_slug
	 * - unit_identifier
	 * - unit_type
	 * - unit_title
	 * - unit_path
	 * - content
	 * - score
	 * - relevance_score
	 * - retrieval_methods
	 *
	 * @param string $query Search query.
	 * @param int $limit Max number of rows.
	 * @param ?array $knowledgeBaseIds Optional knowledge base filter.
	 * @param string $mode Search mode: 'natural' or 'boolean'.
	 * @return array Ranked chunk rows.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function findByLexical(string $query, int $limit, ?array $knowledgeBaseIds = null, string $mode = 'natural'): array {
		$query = $this->normalizeSearchQuery($query);
		$limit = $this->normalizePositiveInt($limit, 'limit');
		$mode = $this->normalizeSearchMode($mode);
		$knowledgeBaseIds = $this->normalizeKnowledgeBaseIds($knowledgeBaseIds);

		$againstClause = $mode === 'boolean' ? 'AGAINST (? IN BOOLEAN MODE)' : 'AGAINST (? IN NATURAL LANGUAGE MODE)';

		$sql = 'SELECT
				c.id AS chunk_id,
				c.unit_id,
				u.document_id,
				d.knowledge_base_id,
				d.title AS document_title,
				d.slug AS document_slug,
				u.identifier AS unit_identifier,
				u.unit_type,
				u.title AS unit_title,
				u.path AS unit_path,
				c.content,
				MATCH(c.content) ' . $againstClause . ' AS relevance_score
			FROM know_chunks c
			INNER JOIN know_units u ON u.id = c.unit_id
			INNER JOIN know_documents d ON d.id = u.document_id
			WHERE MATCH(c.content) ' . $againstClause;

		$params = [$query, $query];

		if ($knowledgeBaseIds !== null) {
			$placeholders = \implode(', ', \array_fill(0, \count($knowledgeBaseIds), '?'));
			$sql .= ' AND d.knowledge_base_id IN (' . $placeholders . ')';
			$params = \array_merge($params, $knowledgeBaseIds);
		}

		$sql .= ' ORDER BY relevance_score DESC, c.id ASC LIMIT ?';
		$params[] = $limit;

		$rows = $this->app->db->fetchAll($sql, $params);

		if ($rows === []) {
			return [];
		}

		foreach ($rows as $index => $row) {
			$rows[$index] = $this->hydrateLexicalResultRow($row);
		}

		return $rows;
	}


	/**
	 * Delete all chunks for one unit.
	 *
	 * @param int $unitId Unit id.
	 * @return int Affected rows.
	 */
	public function deleteByUnit(int $unitId): int {
		return $this->app->db->delete('know_chunks', 'unit_id = ?', [$unitId]);
	}


	/**
	 * Delete all chunks for one document via unit join.
	 *
	 * @param int $documentId Document id.
	 * @return int Affected rows.
	 */
	public function deleteByDocument(int $documentId): int {
		return $this->app->db->execute(
			'DELETE c
			FROM know_chunks c
			INNER JOIN know_units u ON u.id = c.unit_id
			WHERE u.document_id = ?',
			[$documentId]
		);
	}


	/**
	 * Count chunks for one document.
	 *
	 * @param int $documentId Document id.
	 * @return int Chunk count.
	 */
	public function countByDocument(int $documentId): int {
		$count = $this->app->db->fetchValue(
			'SELECT COUNT(*)
			FROM know_chunks c
			INNER JOIN know_units u ON u.id = c.unit_id
			WHERE u.document_id = ?',
			[$documentId]
		);

		return (int)$count;
	}








	// ----------------------------------------------------------------
	// Normalization and hydration
	// ----------------------------------------------------------------

	/**
	 * Normalize one insert chunk payload.
	 *
	 * @param array $chunk Raw chunk payload.
	 * @return array Normalized DB row payload.
	 * @throws \InvalidArgumentException When the payload is invalid.
	 */
	private function normalizeInsertChunk(array $chunk): array {
		$allowedKeys = [
			'content',
			'context_before',
			'context_after',
			'token_count',
			'char_count',
			'metadata_json',
			'metadata',
		];

		foreach ($chunk as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown chunk field: ' . $key);
			}
		}

		if (!\array_key_exists('content', $chunk)) {
			throw new \InvalidArgumentException('Missing required chunk field: content');
		}

		if (\array_key_exists('metadata', $chunk) && \array_key_exists('metadata_json', $chunk)) {
			throw new \InvalidArgumentException('Use either metadata or metadata_json, not both.');
		}

		$row = [
			'content' => $this->normalizeRequiredText($chunk['content'], 'content'),
			'context_before' => $this->normalizeNullableText($chunk['context_before'] ?? null),
			'context_after' => $this->normalizeNullableText($chunk['context_after'] ?? null),
			'token_count' => $this->normalizeNullableSmallUnsignedInt($chunk['token_count'] ?? null, 'token_count'),
			'char_count' => $this->normalizeNullableSmallUnsignedInt($chunk['char_count'] ?? null, 'char_count'),
		];

		if (\array_key_exists('metadata', $chunk)) {
			$row['metadata_json'] = $this->normalizeMetadataJson($chunk['metadata']);
		}

		if (\array_key_exists('metadata_json', $chunk)) {
			$row['metadata_json'] = $this->normalizeMetadataJson($chunk['metadata_json']);
		}

		return $row;
	}


	/**
	 * Hydrate one raw chunk row.
	 *
	 * @param ?array $row Raw row.
	 * @return ?array Hydrated row or null.
	 */
	private function hydrateRow(?array $row): ?array {
		if ($row === null) {
			return null;
		}

		$row['id'] = (int)$row['id'];
		$row['unit_id'] = (int)$row['unit_id'];
		$row['chunk_index'] = (int)$row['chunk_index'];
		$row['content'] = (string)$row['content'];
		$row['context_before'] = $row['context_before'] !== null ? (string)$row['context_before'] : null;
		$row['context_after'] = $row['context_after'] !== null ? (string)$row['context_after'] : null;
		$row['token_count'] = $row['token_count'] !== null ? (int)$row['token_count'] : null;
		$row['char_count'] = $row['char_count'] !== null ? (int)$row['char_count'] : null;
		$row['metadata_json'] = $this->decodeMetadataJson($row['metadata_json'] ?? null);
		$row['created_at'] = (string)$row['created_at'];

		return $row;
	}


	/**
	 * Hydrate many raw chunk rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array Hydrated rows.
	 */
	private function hydrateRows(array $rows): array {
		if ($rows === []) {
			return [];
		}

		foreach ($rows as $index => $row) {
			$rows[$index] = $this->hydrateRow($row);
		}

		return $rows;
	}


	/**
	 * Hydrate one lexical result row.
	 *
	 * @param array $row Raw lexical result row.
	 * @return array Hydrated lexical result row.
	 */
	private function hydrateLexicalResultRow(array $row): array {
		$score = (float)$row['relevance_score'];

		return [
			'chunk_id' => (int)$row['chunk_id'],
			'unit_id' => (int)$row['unit_id'],
			'document_id' => (int)$row['document_id'],
			'knowledge_base_id' => (int)$row['knowledge_base_id'],
			'document_title' => (string)$row['document_title'],
			'document_slug' => (string)$row['document_slug'],
			'unit_identifier' => $row['unit_identifier'] !== null ? (string)$row['unit_identifier'] : null,
			'unit_type' => (string)$row['unit_type'],
			'unit_title' => $row['unit_title'] !== null ? (string)$row['unit_title'] : null,
			'unit_path' => $row['unit_path'] !== null ? (string)$row['unit_path'] : null,
			'content' => (string)$row['content'],
			'score' => $score,
			'relevance_score' => $score,
			'retrieval_methods' => ['lexical'],
		];
	}







	// ----------------------------------------------------------------
	// Scalar normalization helpers
	// ----------------------------------------------------------------

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

		if ($value === '') {
			throw new \InvalidArgumentException('Field must not be empty: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize nullable text.
	 *
	 * @param mixed $value Raw value.
	 * @return ?string Normalized text or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Text field must be string or null.');
		}

		return $value === '' ? null : $value;
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
	 * Normalize metadata to a JSON string or null.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return ?string JSON string or null.
	 * @throws \InvalidArgumentException When the metadata type or JSON is invalid.
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


	/**
	 * Decode metadata JSON to array or null.
	 *
	 * @param mixed $value Raw DB value.
	 * @return ?array Decoded metadata array or null.
	 * @throws \InvalidArgumentException When stored JSON is invalid.
	 */
	private function decodeMetadataJson(mixed $value): ?array {
		if ($value === null || $value === '') {
			return null;
		}

		if (\is_array($value)) {
			return $value;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Stored metadata_json has invalid type.');
		}

		$decoded = \json_decode($value, true, 512, JSON_THROW_ON_ERROR);

		if ($decoded === null) {
			return null;
		}

		if (!\is_array($decoded)) {
			throw new \InvalidArgumentException('Stored metadata_json must decode to an array.');
		}

		return $decoded;
	}


	/**
	 * Normalize lexical query text.
	 *
	 * @param string $query Raw query string.
	 * @return string Normalized query.
	 * @throws \InvalidArgumentException When the query is empty.
	 */
	private function normalizeSearchQuery(string $query): string {
		$query = \trim($query);

		if ($query === '') {
			throw new \InvalidArgumentException('Search query must not be empty.');
		}

		return $query;
	}


	/**
	 * Normalize lexical search mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string Normalized mode.
	 * @throws \InvalidArgumentException When the mode is invalid.
	 */
	private function normalizeSearchMode(string $mode): string {
		$mode = \trim($mode);

		if ($mode !== 'natural' && $mode !== 'boolean') {
			throw new \InvalidArgumentException('Search mode must be "natural" or "boolean".');
		}

		return $mode;
	}


	/**
	 * Normalize optional knowledge base id filter list.
	 *
	 * @param ?array $knowledgeBaseIds Raw ids.
	 * @return ?array Normalized ids or null.
	 * @throws \InvalidArgumentException When the list is invalid.
	 */
	private function normalizeKnowledgeBaseIds(?array $knowledgeBaseIds): ?array {
		if ($knowledgeBaseIds === null) {
			return null;
		}

		if ($knowledgeBaseIds === []) {
			throw new \InvalidArgumentException('knowledgeBaseIds must not be an empty array.');
		}

		$normalized = [];

		foreach ($knowledgeBaseIds as $index => $id) {
			$normalized[$index] = $this->normalizePositiveInt($id, 'knowledgeBaseIds');
		}

		return \array_values(\array_unique($normalized));
	}


}
