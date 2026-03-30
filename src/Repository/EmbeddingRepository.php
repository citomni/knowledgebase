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
use CitOmni\KnowledgeBase\Util\CosineSimilarity;

/**
 * Persist and read chunk embeddings.
 *
 * Behavior:
 * - Owns all SQL for the `know_embeddings` table.
 * - Packs float vectors to binary BLOB storage on write.
 * - Unpacks binary BLOB vectors on read.
 * - Executes V1 semantic retrieval by loading embeddings for one model and
 *   computing cosine similarity in PHP.
 *
 * Notes:
 * - The repository intentionally owns the storage format: application code sees float[].
 * - Vector search is scoped by model and may optionally be scoped by knowledge base ids.
 * - Search returns repository-shaped rows with `chunk_id`, `similarity_score`,
 *   `score`, and `retrieval_methods` added.
 *
 * Typical usage:
 *   $repo = new EmbeddingRepository($this->app);
 *   $repo->insertBatch([
 *       [
 *           'chunk_id' => 123,
 *           'model' => 'openai-text-embedding-3-small',
 *           'dimension' => 1536,
 *           'vector' => [0.0123, -0.991, ...],
 *       ],
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 */
final class EmbeddingRepository extends BaseRepository {

	/**
	 * Insert many embedding rows.
	 *
	 * Each row must contain:
	 * - chunk_id
	 * - model
	 * - dimension
	 * - vector (float[])
	 *
	 * Notes:
	 * - `dimension` must match the vector length.
	 * - The repository stores the vector as binary float32 via `pack('f*', ...)`.
	 *
	 * @param array $embeddings Embedding payload rows.
	 * @return int Number of inserted rows.
	 * @throws \InvalidArgumentException When input data is invalid.
	 */
	public function insertBatch(array $embeddings): int {
		if ($embeddings === []) {
			return 0;
		}

		$rows = [];

		foreach ($embeddings as $embedding) {
			if (!\is_array($embedding)) {
				throw new \InvalidArgumentException('Each embedding must be an array.');
			}

			$rows[] = $this->normalizeInsertEmbedding($embedding);
		}

		return $this->app->db->insertBatch('know_embeddings', $rows);
	}


	/**
	 * Find the most similar chunks for one query vector and model.
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
	 * - similarity_score
	 * - retrieval_methods
	 *
	 * @param array $queryVector Query embedding as float[].
	 * @param string $model Active embedding model.
	 * @param int $limit Max number of rows to return.
	 * @param float $minSimilarity Minimum cosine similarity threshold.
	 * @param ?array $knowledgeBaseIds Optional knowledge base filter.
	 * @return array Ranked chunk rows.
	 * @throws \InvalidArgumentException When input data is invalid.
	 */
	public function findByVector(array $queryVector, string $model, int $limit, float $minSimilarity, ?array $knowledgeBaseIds = null): array {
		$queryVector = $this->normalizeVector($queryVector, 'queryVector');
		$model = $this->normalizeModel($model);
		$limit = $this->normalizePositiveInt($limit, 'limit');
		$minSimilarity = $this->normalizeSimilarityThreshold($minSimilarity);
		$knowledgeBaseIds = $this->normalizeKnowledgeBaseIds($knowledgeBaseIds);
		$queryDimension = \count($queryVector);

		$sql = 'SELECT
				e.chunk_id,
				e.dimension,
				e.vector,
				c.unit_id,
				u.document_id,
				d.knowledge_base_id,
				d.title AS document_title,
				d.slug AS document_slug,
				u.identifier AS unit_identifier,
				u.unit_type,
				u.title AS unit_title,
				u.path AS unit_path,
				c.content
			FROM know_embeddings e
			INNER JOIN know_chunks c ON c.id = e.chunk_id
			INNER JOIN know_units u ON u.id = c.unit_id
			INNER JOIN know_documents d ON d.id = u.document_id
			WHERE e.model = ?
				AND e.dimension = ?';
		$params = [$model, $queryDimension];

		if ($knowledgeBaseIds !== null) {
			$placeholders = \implode(', ', \array_fill(0, \count($knowledgeBaseIds), '?'));
			$sql .= ' AND d.knowledge_base_id IN (' . $placeholders . ')';
			$params = \array_merge($params, $knowledgeBaseIds);
		}

		$rows = $this->app->db->fetchAll($sql, $params);

		if ($rows === []) {
			return [];
		}

		$results = [];

		foreach ($rows as $row) {
			$vector = $this->unpackVector((string)$row['vector'], (int)$row['dimension']);
			$similarity = CosineSimilarity::compute($queryVector, $vector);

			if ($similarity < $minSimilarity) {
				continue;
			}

			$results[] = [
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
				'score' => $similarity,
				'similarity_score' => $similarity,
				'retrieval_methods' => ['vector'],
			];
		}

		if ($results === []) {
			return [];
		}

		\usort($results, static function(array $a, array $b): int {
			if ($a['similarity_score'] === $b['similarity_score']) {
				return $a['chunk_id'] <=> $b['chunk_id'];
			}

			return $a['similarity_score'] < $b['similarity_score'] ? 1 : -1;
		});

		if (\count($results) > $limit) {
			$results = \array_slice($results, 0, $limit);
		}

		return $results;
	}


	/**
	 * Delete all embeddings for one model.
	 *
	 * @param string $model Model id.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When the model is invalid.
	 */
	public function deleteByModel(string $model): int {
		return $this->app->db->delete('know_embeddings', 'model = ?', [$this->normalizeModel($model)]);
	}


	/**
	 * Delete embeddings for a set of chunk ids.
	 *
	 * @param array $chunkIds Chunk ids.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When the id list is invalid.
	 */
	public function deleteByChunkIds(array $chunkIds): int {
		$chunkIds = $this->normalizeIdList($chunkIds, 'chunkIds');

		if ($chunkIds === []) {
			return 0;
		}

		$placeholders = \implode(', ', \array_fill(0, \count($chunkIds), '?'));

		return $this->app->db->execute(
			'DELETE FROM know_embeddings WHERE chunk_id IN (' . $placeholders . ')',
			$chunkIds
		);
	}


	/**
	 * Count embeddings for one model.
	 *
	 * @param string $model Model id.
	 * @return int Embedding count.
	 * @throws \InvalidArgumentException When the model is invalid.
	 */
	public function countByModel(string $model): int {
		$count = $this->app->db->fetchValue(
			'SELECT COUNT(*)
			FROM know_embeddings
			WHERE model = ?',
			[$this->normalizeModel($model)]
		);

		return (int)$count;
	}


	/**
	 * Count chunks in one knowledge base that are missing an embedding for one model.
	 *
	 * Notes:
	 * - Chunks without a matching embedding row for the active model are counted as missing.
	 * - This is the observability/completeness query described in the design.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param string $model Model id.
	 * @return int Missing embedding count.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function countMissing(int $knowledgeBaseId, string $model): int {
		$knowledgeBaseId = $this->normalizePositiveInt($knowledgeBaseId, 'knowledgeBaseId');
		$model = $this->normalizeModel($model);

		$count = $this->app->db->fetchValue(
			'SELECT COUNT(*)
			FROM know_chunks c
			INNER JOIN know_units u ON u.id = c.unit_id
			INNER JOIN know_documents d ON d.id = u.document_id
			LEFT JOIN know_embeddings e ON e.chunk_id = c.id AND e.model = ?
			WHERE d.knowledge_base_id = ?
				AND e.id IS NULL',
			[$model, $knowledgeBaseId]
		);

		return (int)$count;
	}







	// ----------------------------------------------------------------
	// Normalization helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize one insert embedding payload.
	 *
	 * @param array $embedding Raw embedding payload.
	 * @return array Normalized DB row payload.
	 * @throws \InvalidArgumentException When the payload is invalid.
	 */
	private function normalizeInsertEmbedding(array $embedding): array {
		$allowedKeys = ['chunk_id', 'model', 'dimension', 'vector'];

		foreach ($embedding as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown embedding field: ' . $key);
			}
		}

		foreach ($allowedKeys as $requiredKey) {
			if (!\array_key_exists($requiredKey, $embedding)) {
				throw new \InvalidArgumentException('Missing required embedding field: ' . $requiredKey);
			}
		}

		$chunkId = $this->normalizePositiveInt($embedding['chunk_id'], 'chunk_id');
		$model = $this->normalizeModel((string)$embedding['model']);
		$dimension = $this->normalizeDimension($embedding['dimension']);
		$vector = $this->normalizeVector($embedding['vector'], 'vector');

		if (\count($vector) !== $dimension) {
			throw new \InvalidArgumentException('Embedding dimension does not match vector length.');
		}

		return [
			'chunk_id' => $chunkId,
			'model' => $model,
			'dimension' => $dimension,
			'vector' => $this->packVector($vector),
		];
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
	 * Normalize a SMALLINT UNSIGNED-like dimension value.
	 *
	 * @param mixed $value Raw value.
	 * @return int Normalized dimension.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeDimension(mixed $value): int {
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('dimension must be an unsigned integer.');
		}

		$value = (int)$value;

		if ($value <= 0 || $value > 65535) {
			throw new \InvalidArgumentException('dimension must be between 1 and 65535.');
		}

		return $value;
	}


	/**
	 * Normalize a model id.
	 *
	 * @param string $model Raw model id.
	 * @return string Normalized model id.
	 * @throws \InvalidArgumentException When the model is invalid.
	 */
	private function normalizeModel(string $model): string {
		$model = \trim($model);

		if ($model === '') {
			throw new \InvalidArgumentException('Model must not be empty.');
		}

		if (\mb_strlen($model, 'UTF-8') > 100) {
			throw new \InvalidArgumentException('Model exceeds max length.');
		}

		return $model;
	}


	/**
	 * Normalize a float vector.
	 *
	 * @param mixed $vector Raw vector value.
	 * @param string $field Field name for exception text.
	 * @return array Normalized float[].
	 * @throws \InvalidArgumentException When the vector is invalid.
	 */
	private function normalizeVector(mixed $vector, string $field): array {
		if (!\is_array($vector) || $vector === []) {
			throw new \InvalidArgumentException('Field must be a non-empty float array: ' . $field);
		}

		$normalized = [];

		foreach ($vector as $index => $value) {
			if (!\is_int($value) && !\is_float($value)) {
				throw new \InvalidArgumentException('Vector element must be int or float at index: ' . $index);
			}

			$floatValue = (float)$value;

			if (\is_nan($floatValue) || \is_infinite($floatValue)) {
				throw new \InvalidArgumentException('Vector element must be finite at index: ' . $index);
			}

			$normalized[] = $floatValue;
		}

		return $normalized;
	}


	/**
	 * Normalize similarity threshold.
	 *
	 * @param mixed $value Raw threshold value.
	 * @return float Normalized threshold.
	 * @throws \InvalidArgumentException When the threshold is invalid.
	 */
	private function normalizeSimilarityThreshold(mixed $value): float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new \InvalidArgumentException('minSimilarity must be numeric.');
		}

		$value = (float)$value;

		if (\is_nan($value) || \is_infinite($value)) {
			throw new \InvalidArgumentException('minSimilarity must be finite.');
		}

		if ($value < -1.0 || $value > 1.0) {
			throw new \InvalidArgumentException('minSimilarity must be between -1.0 and 1.0.');
		}

		return $value;
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

		return $this->normalizeIdList($knowledgeBaseIds, 'knowledgeBaseIds');
	}


	/**
	 * Normalize an id list to unique positive ints.
	 *
	 * @param array $ids Raw ids.
	 * @param string $field Field name for exception text.
	 * @return array Normalized ids.
	 * @throws \InvalidArgumentException When the list is invalid.
	 */
	private function normalizeIdList(array $ids, string $field): array {
		if ($ids === []) {
			return [];
		}

		$normalized = [];

		foreach ($ids as $index => $id) {
			$normalized[$index] = $this->normalizePositiveInt($id, $field);
		}

		return \array_values(\array_unique($normalized));
	}







	// ----------------------------------------------------------------
	// Vector packing helpers
	// ----------------------------------------------------------------

	/**
	 * Pack a float vector to binary float32 storage.
	 *
	 * @param array $vector Float vector.
	 * @return string Binary BLOB payload.
	 */
	private function packVector(array $vector): string {
		return \pack('f*', ...$vector);
	}


	/**
	 * Unpack a binary float32 vector.
	 *
	 * @param string $blob Binary vector payload.
	 * @param int $dimension Expected dimension.
	 * @return array Float vector.
	 * @throws \RuntimeException When the stored vector is malformed.
	 */
	private function unpackVector(string $blob, int $dimension): array {
		if ($blob === '') {
			throw new \RuntimeException('Stored vector blob is empty.');
		}

		$expectedBytes = $dimension * 4;

		if (\strlen($blob) !== $expectedBytes) {
			throw new \RuntimeException('Stored vector blob length does not match dimension.');
		}

		$unpacked = \unpack('f*', $blob);

		if (!\is_array($unpacked) || \count($unpacked) !== $dimension) {
			throw new \RuntimeException('Stored vector blob could not be unpacked correctly.');
		}

		return \array_values($unpacked);
	}


}
