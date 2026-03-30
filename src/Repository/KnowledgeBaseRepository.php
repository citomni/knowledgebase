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
 * Persist and read knowledge base rows.
 *
 * Behavior:
 * - Owns all SQL for the `know_bases` table.
 * - Encodes metadata arrays to JSON on write and decodes JSON on read.
 * - Validates known enum-like inputs fail-fast before hitting the database.
 *
 * Notes:
 * - This repository intentionally exposes a small, explicit field surface.
 * - Unknown write fields are rejected to keep persistence deterministic.
 * - Deleting a knowledge base cascades to documents, units, chunks, embeddings,
 *   synonyms, and optional query log rows via foreign keys.
 *
 * Typical usage:
 *   $repo = new KnowledgeBaseRepository($this->app);
 *   $id = $repo->insert([
 *       'slug' => 'lejeloven',
 *       'title' => 'Lejeloven',
 *       'language' => 'da',
 *       'status' => 'active',
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 */
final class KnowledgeBaseRepository extends BaseRepository {

	/**
	 * Allowed knowledge base statuses.
	 */
	private const ALLOWED_STATUSES = ['active', 'inactive', 'archived'];


	/**
	 * Insert one knowledge base row.
	 *
	 * Accepted fields:
	 * - slug (required)
	 * - title (required)
	 * - description
	 * - language
	 * - status
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 *
	 * @param array $data Row data.
	 * @return int Inserted knowledge base id.
	 * @throws \InvalidArgumentException When required or invalid data is provided.
	 */
	public function insert(array $data): int {
		$payload = $this->normalizeWriteData($data, false);

		return $this->app->db->insert('know_bases', $payload);
	}


	/**
	 * Find one knowledge base by slug.
	 *
	 * @param string $slug Knowledge base slug.
	 * @return ?array Hydrated knowledge base row or null when not found.
	 */
	public function findBySlug(string $slug): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, slug, title, description, language, status, metadata_json, created_at, updated_at
			FROM know_bases
			WHERE slug = ?
			LIMIT 1',
			[\trim($slug)]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * Find one knowledge base by id.
	 *
	 * @param int $id Knowledge base id.
	 * @return ?array Hydrated knowledge base row or null when not found.
	 */
	public function findById(int $id): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, slug, title, description, language, status, metadata_json, created_at, updated_at
			FROM know_bases
			WHERE id = ?
			LIMIT 1',
			[$id]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * List all knowledge bases, optionally filtered by status.
	 *
	 * @param ?string $status Optional status filter.
	 * @return array Hydrated knowledge base rows ordered deterministically.
	 * @throws \InvalidArgumentException When status is invalid.
	 */
	public function listAll(?string $status = null): array {
		if ($status !== null) {
			$status = $this->normalizeStatus($status);
		}

		$sql = 'SELECT id, slug, title, description, language, status, metadata_json, created_at, updated_at
			FROM know_bases';
		$params = [];

		if ($status !== null) {
			$sql .= ' WHERE status = ?';
			$params[] = $status;
		}

		$sql .= ' ORDER BY title ASC, id ASC';

		$rows = $this->app->db->fetchAll($sql, $params);

		if ($rows === []) {
			return [];
		}

		foreach ($rows as $index => $row) {
			$rows[$index] = $this->hydrateRow($row);
		}

		return $rows;
	}


	/**
	 * Update one knowledge base row.
	 *
	 * Accepted fields:
	 * - slug
	 * - title
	 * - description
	 * - language
	 * - status
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 *
	 * @param int $id Knowledge base id.
	 * @param array $data Fields to update.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When no valid fields or invalid data is provided.
	 */
	public function update(int $id, array $data): int {
		$payload = $this->normalizeWriteData($data, true);

		return $this->app->db->update('know_bases', $payload, 'id = ?', [$id]);
	}


	/**
	 * Delete one knowledge base row.
	 *
	 * Notes:
	 * - The schema uses ON DELETE CASCADE for dependent rows.
	 *
	 * @param int $id Knowledge base id.
	 * @return int Affected rows.
	 */
	public function delete(int $id): int {
		return $this->app->db->delete('know_bases', 'id = ?', [$id]);
	}









	// ----------------------------------------------------------------
	// Row normalization and hydration
	// ----------------------------------------------------------------

	/**
	 * Normalize write payload for insert or update.
	 *
	 * @param array $data Raw input data.
	 * @param bool $allowPartial True for update payloads.
	 * @return array Normalized DB payload.
	 * @throws \InvalidArgumentException When data is invalid.
	 */
	private function normalizeWriteData(array $data, bool $allowPartial): array {
		$allowedKeys = [
			'slug',
			'title',
			'description',
			'language',
			'status',
			'metadata_json',
			'metadata',
		];

		$payload = [];

		foreach ($data as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown knowledge base field: ' . $key);
			}
		}

		if (\array_key_exists('slug', $data)) {
			$payload['slug'] = $this->normalizeRequiredString($data['slug'], 'slug', 100);
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: slug');
		}

		if (\array_key_exists('title', $data)) {
			$payload['title'] = $this->normalizeRequiredString($data['title'], 'title', 255);
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: title');
		}

		if (\array_key_exists('description', $data)) {
			$payload['description'] = $this->normalizeNullableText($data['description']);
		}

		if (\array_key_exists('language', $data)) {
			$payload['language'] = $this->normalizeRequiredString($data['language'], 'language', 10);
		}

		if (\array_key_exists('status', $data)) {
			$payload['status'] = $this->normalizeStatus($data['status']);
		} elseif (!$allowPartial) {
			$payload['status'] = 'active';
		}

		if (\array_key_exists('metadata', $data) && \array_key_exists('metadata_json', $data)) {
			throw new \InvalidArgumentException('Use either metadata or metadata_json, not both.');
		}

		if (\array_key_exists('metadata', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata']);
		}

		if (\array_key_exists('metadata_json', $data)) {
			$payload['metadata_json'] = $this->normalizeMetadataJson($data['metadata_json']);
		}

		if ($allowPartial && $payload === []) {
			throw new \InvalidArgumentException('No valid fields provided for update.');
		}

		return $payload;
	}


	/**
	 * Hydrate one raw database row.
	 *
	 * @param ?array $row Raw row.
	 * @return ?array Hydrated row or null.
	 */
	private function hydrateRow(?array $row): ?array {
		if ($row === null) {
			return null;
		}

		$row['id'] = (int)$row['id'];
		$row['slug'] = (string)$row['slug'];
		$row['title'] = (string)$row['title'];
		$row['description'] = $row['description'] !== null ? (string)$row['description'] : null;
		$row['language'] = (string)$row['language'];
		$row['status'] = (string)$row['status'];
		$row['metadata_json'] = $this->decodeMetadataJson($row['metadata_json'] ?? null);
		$row['created_at'] = (string)$row['created_at'];
		$row['updated_at'] = (string)$row['updated_at'];

		return $row;
	}


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
			throw new \InvalidArgumentException('Description must be string or null.');
		}

		$value = \trim($value);

		return $value === '' ? null : $value;
	}


	/**
	 * Normalize status to one of the allowed values.
	 *
	 * @param mixed $value Raw status value.
	 * @return string Normalized status.
	 * @throws \InvalidArgumentException When the status is invalid.
	 */
	private function normalizeStatus(mixed $value): string {
		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Status must be string.');
		}

		$value = \trim($value);

		if (!\in_array($value, self::ALLOWED_STATUSES, true)) {
			throw new \InvalidArgumentException('Invalid knowledge base status: ' . $value);
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

}
