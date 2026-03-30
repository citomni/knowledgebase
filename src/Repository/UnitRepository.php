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
 * Persist and read hierarchical unit rows within documents.
 *
 * Behavior:
 * - Owns all SQL for the `know_units` table.
 * - Validates parent/document consistency on insert and move.
 * - Generates and maintains zero-padded materialized paths.
 * - Enforces root-level sibling ordering fail-fast because MySQL UNIQUE does not
 *   cover `(document_id, NULL, sort_order)` as intended.
 *
 * Notes:
 * - Updating `parent_id` and/or `sort_order` is treated as a structural move.
 * - Structural updates are transactional and propagate path/depth changes to descendants.
 * - This repository does not allow changing `document_id` through update().
 *
 * Typical usage:
 *   $repo = new UnitRepository($this->app);
 *   $id = $repo->insert([
 *       'document_id' => 10,
 *       'parent_id' => null,
 *       'unit_type' => 'paragraph',
 *       'identifier' => '§ 34',
 *       'title' => null,
 *       'body' => '...',
 *       'sort_order' => 1,
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 * @throws \RuntimeException When hierarchy rules are violated.
 */
final class UnitRepository extends BaseRepository {

	/**
	 * Insert one unit row.
	 *
	 * Accepted fields:
	 * - document_id (required)
	 * - parent_id
	 * - unit_type (required)
	 * - identifier
	 * - title
	 * - body
	 * - sort_order (required)
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 *
	 * @param array $data Row data.
	 * @return int Inserted unit id.
	 * @throws \InvalidArgumentException When required or invalid data is provided.
	 * @throws \RuntimeException When hierarchy rules are violated.
	 */
	public function insert(array $data): int {
		$payload = $this->normalizeInsertData($data);

		return $this->app->db->transaction(function() use ($payload): int {
			$documentId = $payload['document_id'];
			$parentId = $payload['parent_id'];
			$sortOrder = $payload['sort_order'];

			if ($parentId === null) {
				$this->assertRootSortOrderAvailable($documentId, $sortOrder);
				$payload['depth'] = 0;
				$payload['path'] = $this->buildRootPath($sortOrder);
			} else {
				$parent = $this->getParentRowForDocument($parentId, $documentId);
				$payload['depth'] = ((int)$parent['depth']) + 1;
				$payload['path'] = $this->buildPath($parentId, $sortOrder);
			}

			return $this->app->db->insert('know_units', $payload);
		});
	}


	/**
	 * Find one unit by id.
	 *
	 * @param int $id Unit id.
	 * @return ?array Hydrated unit row or null when not found.
	 */
	public function findById(int $id): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, document_id, parent_id, unit_type, identifier, title, body, sort_order,
				depth, path, metadata_json, created_at, updated_at
			FROM know_units
			WHERE id = ?
			LIMIT 1',
			[$id]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * Find direct children for one parent.
	 *
	 * @param int $parentId Parent unit id.
	 * @return array Hydrated child rows ordered deterministically.
	 */
	public function findChildren(int $parentId): array {
		$rows = $this->app->db->fetchAll(
			'SELECT id, document_id, parent_id, unit_type, identifier, title, body, sort_order,
				depth, path, metadata_json, created_at, updated_at
			FROM know_units
			WHERE parent_id = ?
			ORDER BY sort_order ASC, id ASC',
			[$parentId]
		);

		return $this->hydrateRows($rows);
	}


	/**
	 * Find top-level units for one document.
	 *
	 * @param int $documentId Document id.
	 * @return array Hydrated root rows ordered deterministically.
	 */
	public function findRootUnits(int $documentId): array {
		$rows = $this->app->db->fetchAll(
			'SELECT id, document_id, parent_id, unit_type, identifier, title, body, sort_order,
				depth, path, metadata_json, created_at, updated_at
			FROM know_units
			WHERE document_id = ? AND parent_id IS NULL
			ORDER BY sort_order ASC, id ASC',
			[$documentId]
		);

		return $this->hydrateRows($rows);
	}


	/**
	 * Find one subtree by path prefix.
	 *
	 * Includes the exact node matching the prefix plus all descendants.
	 *
	 * @param int $documentId Document id.
	 * @param string $pathPrefix Materialized path prefix.
	 * @return array Hydrated subtree rows ordered by path.
	 * @throws \InvalidArgumentException When path prefix is invalid.
	 */
	public function findByPath(int $documentId, string $pathPrefix): array {
		$pathPrefix = $this->normalizePathPrefix($pathPrefix);

		$rows = $this->app->db->fetchAll(
			'SELECT id, document_id, parent_id, unit_type, identifier, title, body, sort_order,
				depth, path, metadata_json, created_at, updated_at
			FROM know_units
			WHERE document_id = ?
				AND (path = ? OR path LIKE CONCAT(?, \'.%\'))
			ORDER BY path ASC, id ASC',
			[$documentId, $pathPrefix, $pathPrefix]
		);

		return $this->hydrateRows($rows);
	}


	/**
	 * Find all units for one document.
	 *
	 * @param int $documentId Document id.
	 * @return array Hydrated rows ordered by path.
	 */
	public function findByDocument(int $documentId): array {
		$rows = $this->app->db->fetchAll(
			'SELECT id, document_id, parent_id, unit_type, identifier, title, body, sort_order,
				depth, path, metadata_json, created_at, updated_at
			FROM know_units
			WHERE document_id = ?
			ORDER BY path ASC, id ASC',
			[$documentId]
		);

		return $this->hydrateRows($rows);
	}


	/**
	 * Update one unit row.
	 *
	 * Accepted fields:
	 * - parent_id
	 * - unit_type
	 * - identifier
	 * - title
	 * - body
	 * - sort_order
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 *
	 * Notes:
	 * - `document_id` is intentionally not mutable here.
	 * - When `parent_id` and/or `sort_order` changes, the move is applied transactionally
	 *   and descendant `path` / `depth` values are updated.
	 *
	 * @param int $id Unit id.
	 * @param array $data Fields to update.
	 * @return int Affected rows for the main unit update.
	 * @throws \InvalidArgumentException When no valid fields or invalid data is provided.
	 * @throws \RuntimeException When hierarchy rules are violated.
	 */
	public function update(int $id, array $data): int {
		$payload = $this->normalizeUpdateData($data);

		return $this->app->db->transaction(function() use ($id, $payload): int {
			$current = $this->getUnitRowOrFail($id);
			$documentId = (int)$current['document_id'];

			$newParentId = \array_key_exists('parent_id', $payload) ? $payload['parent_id'] : ($current['parent_id'] !== null ? (int)$current['parent_id'] : null);
			$newSortOrder = \array_key_exists('sort_order', $payload) ? $payload['sort_order'] : (int)$current['sort_order'];

			$oldPath = (string)$current['path'];
			$oldDepth = (int)$current['depth'];
			$needsStructuralUpdate = ($newParentId !== ($current['parent_id'] !== null ? (int)$current['parent_id'] : null)) || ($newSortOrder !== (int)$current['sort_order']);

			if ($needsStructuralUpdate) {
				if ($newParentId === null) {
					$this->assertRootSortOrderAvailable($documentId, $newSortOrder, $id);
					$newDepth = 0;
					$newPath = $this->buildRootPath($newSortOrder);
				} else {
					if ($newParentId === $id) {
						throw new \RuntimeException('A unit cannot be its own parent.');
					}

					$parent = $this->getParentRowForDocument($newParentId, $documentId);

					if ($this->isPathWithinSubtree((string)$parent['path'], $oldPath)) {
						throw new \RuntimeException('A unit cannot be moved under its own descendant.');
					}

					$newDepth = ((int)$parent['depth']) + 1;
					$newPath = $this->buildPath($newParentId, $newSortOrder);
				}

				$payload['depth'] = $newDepth;
				$payload['path'] = $newPath;
			}

			$affected = $this->app->db->update('know_units', $payload, 'id = ?', [$id]);

			if ($needsStructuralUpdate) {
				$this->updateDescendantPaths(
					$documentId,
					$oldPath,
					(string)$payload['path'],
					(int)$payload['depth'] - $oldDepth
				);
			}

			return $affected;
		});
	}


	/**
	 * Delete one unit row.
	 *
	 * Notes:
	 * - Child units cascade through the self-referencing foreign key.
	 * - Chunks and embeddings cascade through child relations.
	 *
	 * @param int $id Unit id.
	 * @return int Affected rows.
	 */
	public function delete(int $id): int {
		return $this->app->db->delete('know_units', 'id = ?', [$id]);
	}


	/**
	 * Delete all units for one document.
	 *
	 * @param int $documentId Document id.
	 * @return int Affected rows.
	 */
	public function deleteByDocument(int $documentId): int {
		return $this->app->db->delete('know_units', 'document_id = ?', [$documentId]);
	}


	/**
	 * Build a materialized child path from parent id and sort order.
	 *
	 * Example:
	 * - parent path: 001.003
	 * - sort order: 2
	 * - result: 001.003.002
	 *
	 * @param int $parentId Parent unit id.
	 * @param int $sortOrder Child sort order.
	 * @return string Materialized path.
	 * @throws \RuntimeException When the parent does not exist.
	 * @throws \InvalidArgumentException When sort order is invalid.
	 */
	public function buildPath(int $parentId, int $sortOrder): string {
		$parent = $this->getUnitRowOrFail($parentId);

		return (string)$parent['path'] . '.' . $this->formatPathSegment($sortOrder);
	}






	// ----------------------------------------------------------------
	// Normalization and hydration
	// ----------------------------------------------------------------

	/**
	 * Normalize insert payload.
	 *
	 * @param array $data Raw input data.
	 * @return array Normalized DB payload.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	private function normalizeInsertData(array $data): array {
		$allowedKeys = [
			'document_id',
			'parent_id',
			'unit_type',
			'identifier',
			'title',
			'body',
			'sort_order',
			'metadata_json',
			'metadata',
		];

		foreach ($data as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown unit field: ' . $key);
			}
		}

		if (!\array_key_exists('document_id', $data)) {
			throw new \InvalidArgumentException('Missing required field: document_id');
		}

		if (!\array_key_exists('unit_type', $data)) {
			throw new \InvalidArgumentException('Missing required field: unit_type');
		}

		if (!\array_key_exists('sort_order', $data)) {
			throw new \InvalidArgumentException('Missing required field: sort_order');
		}

		if (\array_key_exists('metadata', $data) && \array_key_exists('metadata_json', $data)) {
			throw new \InvalidArgumentException('Use either metadata or metadata_json, not both.');
		}

		$payload = [
			'document_id' => $this->normalizePositiveInt($data['document_id'], 'document_id'),
			'parent_id' => $this->normalizeNullablePositiveInt($data['parent_id'] ?? null, 'parent_id'),
			'unit_type' => $this->normalizeRequiredString($data['unit_type'], 'unit_type', 50),
			'identifier' => $this->normalizeNullableTrimmedString($data['identifier'] ?? null, 'identifier', 100),
			'title' => $this->normalizeNullableTrimmedString($data['title'] ?? null, 'title', 500),
			'body' => $this->normalizeNullableText($data['body'] ?? null),
			'sort_order' => $this->normalizeSortOrder($data['sort_order']),
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
	 * Normalize update payload.
	 *
	 * @param array $data Raw input data.
	 * @return array Normalized DB payload.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	private function normalizeUpdateData(array $data): array {
		$allowedKeys = [
			'parent_id',
			'unit_type',
			'identifier',
			'title',
			'body',
			'sort_order',
			'metadata_json',
			'metadata',
		];

		foreach ($data as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown or immutable unit field: ' . $key);
			}
		}

		if (\array_key_exists('metadata', $data) && \array_key_exists('metadata_json', $data)) {
			throw new \InvalidArgumentException('Use either metadata or metadata_json, not both.');
		}

		$payload = [];

		if (\array_key_exists('parent_id', $data)) {
			$payload['parent_id'] = $this->normalizeNullablePositiveInt($data['parent_id'], 'parent_id');
		}

		if (\array_key_exists('unit_type', $data)) {
			$payload['unit_type'] = $this->normalizeRequiredString($data['unit_type'], 'unit_type', 50);
		}

		if (\array_key_exists('identifier', $data)) {
			$payload['identifier'] = $this->normalizeNullableTrimmedString($data['identifier'], 'identifier', 100);
		}

		if (\array_key_exists('title', $data)) {
			$payload['title'] = $this->normalizeNullableTrimmedString($data['title'], 'title', 500);
		}

		if (\array_key_exists('body', $data)) {
			$payload['body'] = $this->normalizeNullableText($data['body']);
		}

		if (\array_key_exists('sort_order', $data)) {
			$payload['sort_order'] = $this->normalizeSortOrder($data['sort_order']);
		}

		if (\array_key_exists('metadata', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata']);
		}

		if (\array_key_exists('metadata_json', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata_json']);
		}

		if ($payload === []) {
			throw new \InvalidArgumentException('No valid fields provided for update.');
		}

		return $payload;
	}


	/**
	 * Hydrate one raw unit row.
	 *
	 * @param ?array $row Raw row.
	 * @return ?array Hydrated row or null.
	 */
	private function hydrateRow(?array $row): ?array {
		if ($row === null) {
			return null;
		}

		$row['id'] = (int)$row['id'];
		$row['document_id'] = (int)$row['document_id'];
		$row['parent_id'] = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
		$row['unit_type'] = (string)$row['unit_type'];
		$row['identifier'] = $row['identifier'] !== null ? (string)$row['identifier'] : null;
		$row['title'] = $row['title'] !== null ? (string)$row['title'] : null;
		$row['body'] = $row['body'] !== null ? (string)$row['body'] : null;
		$row['sort_order'] = (int)$row['sort_order'];
		$row['depth'] = (int)$row['depth'];
		$row['path'] = $row['path'] !== null ? (string)$row['path'] : null;
		$row['metadata_json'] = $this->decodeMetadataJson($row['metadata_json'] ?? null);
		$row['created_at'] = (string)$row['created_at'];
		$row['updated_at'] = (string)$row['updated_at'];

		return $row;
	}


	/**
	 * Hydrate many rows.
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






	// ----------------------------------------------------------------
	// Hierarchy helpers
	// ----------------------------------------------------------------

	/**
	 * Fetch one unit row or fail.
	 *
	 * Returns the raw DB row because internal hierarchy logic needs exact stored values.
	 *
	 * @param int $id Unit id.
	 * @return array Raw row.
	 * @throws \RuntimeException When the unit does not exist.
	 */
	private function getUnitRowOrFail(int $id): array {
		$row = $this->app->db->fetchRow(
			'SELECT id, document_id, parent_id, sort_order, depth, path
			FROM know_units
			WHERE id = ?
			LIMIT 1',
			[$id]
		);

		if ($row === null) {
			throw new \RuntimeException('Unit not found: ' . $id);
		}

		return $row;
	}


	/**
	 * Fetch and validate a parent row for a specific document.
	 *
	 * @param int $parentId Parent unit id.
	 * @param int $documentId Expected document id.
	 * @return array Raw parent row.
	 * @throws \RuntimeException When the parent is missing or belongs to another document.
	 */
	private function getParentRowForDocument(int $parentId, int $documentId): array {
		$parent = $this->getUnitRowOrFail($parentId);

		if ((int)$parent['document_id'] !== $documentId) {
			throw new \RuntimeException('Parent unit belongs to another document.');
		}

		return $parent;
	}


	/**
	 * Assert that a root-level sort order is available within a document.
	 *
	 * @param int $documentId Document id.
	 * @param int $sortOrder Root sort order.
	 * @param ?int $excludeId Optional current unit id to exclude on update.
	 * @return void
	 * @throws \RuntimeException When the sort order is already used.
	 */
	private function assertRootSortOrderAvailable(int $documentId, int $sortOrder, ?int $excludeId = null): void {
		$sql = 'SELECT id
			FROM know_units
			WHERE document_id = ?
				AND parent_id IS NULL
				AND sort_order = ?';
		$params = [$documentId, $sortOrder];

		if ($excludeId !== null) {
			$sql .= ' AND id <> ?';
			$params[] = $excludeId;
		}

		$sql .= ' LIMIT 1';

		$row = $this->app->db->fetchRow($sql, $params);

		if ($row !== null) {
			throw new \RuntimeException('Duplicate root-level sort_order detected for the document.');
		}
	}


	/**
	 * Update descendant path and depth values after a structural move.
	 *
	 * @param int $documentId Document id.
	 * @param string $oldPath Old path prefix.
	 * @param string $newPath New path prefix.
	 * @param int $depthDelta Depth delta to apply to descendants.
	 * @return void
	 */
	private function updateDescendantPaths(int $documentId, string $oldPath, string $newPath, int $depthDelta): void {
		$prefixLike = $oldPath . '.%';
		$substringStart = \strlen($oldPath) + 1;

		$this->app->db->execute(
			'UPDATE know_units
			SET
				path = CONCAT(?, SUBSTRING(path, ?)),
				depth = depth + ?
			WHERE document_id = ?
				AND path LIKE ?',
			[$newPath, $substringStart, $depthDelta, $documentId, $prefixLike]
		);
	}


	/**
	 * Determine whether one path is the same as or inside another subtree.
	 *
	 * @param string $candidatePath Candidate path.
	 * @param string $subtreePath Subtree root path.
	 * @return bool True when candidate is the subtree root or a descendant.
	 */
	private function isPathWithinSubtree(string $candidatePath, string $subtreePath): bool {
		return $candidatePath === $subtreePath || \str_starts_with($candidatePath, $subtreePath . '.');
	}


	/**
	 * Build a root-level path.
	 *
	 * @param int $sortOrder Root sort order.
	 * @return string Materialized path.
	 */
	private function buildRootPath(int $sortOrder): string {
		return $this->formatPathSegment($sortOrder);
	}


	/**
	 * Format one materialized path segment.
	 *
	 * @param int $sortOrder Sort order value.
	 * @return string Zero-padded path segment.
	 */
	private function formatPathSegment(int $sortOrder): string {
		return \str_pad((string)$sortOrder, 3, '0', STR_PAD_LEFT);
	}


	/**
	 * Normalize a path prefix.
	 *
	 * @param string $pathPrefix Raw path prefix.
	 * @return string Normalized path prefix.
	 * @throws \InvalidArgumentException When the path is invalid.
	 */
	private function normalizePathPrefix(string $pathPrefix): string {
		$pathPrefix = \trim($pathPrefix);

		if ($pathPrefix === '') {
			throw new \InvalidArgumentException('Path prefix must not be empty.');
		}

		if (!\preg_match('/^\d{3}(?:\.\d{3})*$/', $pathPrefix)) {
			throw new \InvalidArgumentException('Invalid path prefix format.');
		}

		return $pathPrefix;
	}







	// ----------------------------------------------------------------
	// Scalar normalization helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize a required trimmed string.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @param int $maxLength Maximum length.
	 * @return string Normalized string.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeRequiredString(mixed $value, string $field, int $maxLength): string {
		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Field must be string: ' . $field);
		}

		$value = \trim($value);

		if ($value === '') {
			throw new \InvalidArgumentException('Field must not be empty: ' . $field);
		}

		if (\mb_strlen($value, 'UTF-8') > $maxLength) {
			throw new \InvalidArgumentException('Field exceeds max length: ' . $field);
		}

		return $value;
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
	 * Normalize a nullable positive integer.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @return ?int Normalized integer or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullablePositiveInt(mixed $value, string $field): ?int {
		if ($value === null) {
			return null;
		}

		return $this->normalizePositiveInt($value, $field);
	}


	/**
	 * Normalize sort order.
	 *
	 * Notes:
	 * - Materialized path segments are three digits, so V1 allows 0..999.
	 *
	 * @param mixed $value Raw value.
	 * @return int Normalized sort order.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeSortOrder(mixed $value): int {
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('sort_order must be an unsigned integer.');
		}

		$value = (int)$value;

		if ($value < 0 || $value > 999) {
			throw new \InvalidArgumentException('sort_order must be between 0 and 999.');
		}

		return $value;
	}


	/**
	 * Normalize nullable trimmed string input.
	 *
	 * @param mixed $value Raw value.
	 * @param string $field Field name for exception text.
	 * @param int $maxLength Maximum length.
	 * @return ?string Normalized string or null.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeNullableTrimmedString(mixed $value, string $field, int $maxLength): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Field must be string or null: ' . $field);
		}

		$value = \trim($value);

		if ($value === '') {
			return null;
		}

		if (\mb_strlen($value, 'UTF-8') > $maxLength) {
			throw new \InvalidArgumentException('Field exceeds max length: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize nullable text input.
	 *
	 * @param mixed $value Raw value.
	 * @return ?string Normalized text or null.
	 * @throws \InvalidArgumentException When the value type is invalid.
	 */
	private function normalizeNullableText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('body must be string or null.');
		}

		return $value === '' ? null : $value;
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


}
