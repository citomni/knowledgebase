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
 * Persist and read document rows for knowledge bases.
 *
 * Behavior:
 * - Owns all SQL for the `know_documents` table.
 * - Encodes metadata arrays to JSON on write and decodes JSON on read.
 * - Validates required fields and known enum-like inputs fail-fast before write.
 * - Exposes content-hash lookup for idempotent re-ingest decisions.
 *
 * Notes:
 * - Unknown write fields are rejected to keep persistence deterministic.
 * - Deleting a document cascades to units, chunks, and embeddings via foreign keys.
 * - This repository returns hydrated PHP arrays; metadata_json is decoded to ?array.
 *
 * Typical usage:
 *   $repo = new DocumentRepository($this->app);
 *   $id = $repo->insert([
 *       'knowledge_base_id' => 1,
 *       'slug' => 'lejeloven-2026',
 *       'title' => 'Lejeloven',
 *       'source_type' => 'text',
 *       'content_hash' => $hash,
 *       'status' => 'active',
 *   ]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 */
final class DocumentRepository extends BaseRepository {

	/**
	 * Allowed document statuses.
	 */
	private const ALLOWED_STATUSES = ['draft', 'active', 'superseded', 'archived'];



	/**
	 * Insert one document row.
	 *
	 * Accepted fields:
	 * - knowledge_base_id (required)
	 * - slug (required)
	 * - title (required)
	 * - source_type (required)
	 * - source_ref
	 * - effective_date
	 * - version_label
	 * - content_hash
	 * - language
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 * - status
	 *
	 * @param array $data Row data.
	 * @return int Inserted document id.
	 * @throws \InvalidArgumentException When required or invalid data is provided.
	 */
	public function insert(array $data): int {
		$payload = $this->normalizeWriteData($data, false);

		return $this->app->db->insert('know_documents', $payload);
	}


	/**
	 * Find one document by knowledge base id and slug.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param string $slug Document slug.
	 * @return ?array Hydrated document row or null when not found.
	 */
	public function findBySlug(int $knowledgeBaseId, string $slug): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, knowledge_base_id, slug, title, source_type, source_ref, effective_date,
				version_label, content_hash, language, metadata_json, status, created_at, updated_at
			FROM know_documents
			WHERE knowledge_base_id = ? AND slug = ?
			LIMIT 1',
			[$knowledgeBaseId, \trim($slug)]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * Find one document by id.
	 *
	 * @param int $id Document id.
	 * @return ?array Hydrated document row or null when not found.
	 */
	public function findById(int $id): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, knowledge_base_id, slug, title, source_type, source_ref, effective_date,
				version_label, content_hash, language, metadata_json, status, created_at, updated_at
			FROM know_documents
			WHERE id = ?
			LIMIT 1',
			[$id]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * List all documents for one knowledge base, optionally filtered by status.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param ?string $status Optional status filter.
	 * @return array Hydrated document rows ordered deterministically.
	 * @throws \InvalidArgumentException When status is invalid.
	 */
	public function listByKnowledgeBase(int $knowledgeBaseId, ?string $status = null): array {
		if ($status !== null) {
			$status = $this->normalizeStatus($status);
		}

		$sql = 'SELECT id, knowledge_base_id, slug, title, source_type, source_ref, effective_date,
				version_label, content_hash, language, metadata_json, status, created_at, updated_at
			FROM know_documents
			WHERE knowledge_base_id = ?';
		$params = [$knowledgeBaseId];

		if ($status !== null) {
			$sql .= ' AND status = ?';
			$params[] = $status;
		}

		$sql .= ' ORDER BY effective_date DESC, id DESC';

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
	 * Find one document by content hash inside one knowledge base.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param string $hash SHA-256 hex hash.
	 * @return ?array Hydrated document row or null when not found.
	 * @throws \InvalidArgumentException When hash is invalid.
	 */
	public function findByContentHash(int $knowledgeBaseId, string $hash): ?array {
		$hash = $this->normalizeContentHash($hash);

		$row = $this->app->db->fetchRow(
			'SELECT id, knowledge_base_id, slug, title, source_type, source_ref, effective_date,
				version_label, content_hash, language, metadata_json, status, created_at, updated_at
			FROM know_documents
			WHERE knowledge_base_id = ? AND content_hash = ?
			LIMIT 1',
			[$knowledgeBaseId, $hash]
		);

		return $this->hydrateRow($row);
	}


	/**
	 * Update one document row.
	 *
	 * Accepted fields:
	 * - knowledge_base_id
	 * - slug
	 * - title
	 * - source_type
	 * - source_ref
	 * - effective_date
	 * - version_label
	 * - content_hash
	 * - language
	 * - metadata_json (array|string|null)
	 * - metadata (array|null) alias of metadata_json
	 * - status
	 *
	 * @param int $id Document id.
	 * @param array $data Fields to update.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When no valid fields or invalid data is provided.
	 */
	public function update(int $id, array $data): int {
		$payload = $this->normalizeWriteData($data, true);

		return $this->app->db->update('know_documents', $payload, 'id = ?', [$id]);
	}


	/**
	 * Update only the document status.
	 *
	 * @param int $id Document id.
	 * @param string $status New status.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When status is invalid.
	 */
	public function updateStatus(int $id, string $status): int {
		return $this->app->db->update(
			'know_documents',
			['status' => $this->normalizeStatus($status)],
			'id = ?',
			[$id]
		);
	}


	/**
	 * Delete one document row.
	 *
	 * Notes:
	 * - The schema uses ON DELETE CASCADE for descendants.
	 *
	 * @param int $id Document id.
	 * @return int Affected rows.
	 */
	public function delete(int $id): int {
		return $this->app->db->delete('know_documents', 'id = ?', [$id]);
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
			'knowledge_base_id',
			'slug',
			'title',
			'source_type',
			'source_ref',
			'effective_date',
			'version_label',
			'content_hash',
			'language',
			'metadata_json',
			'metadata',
			'status',
		];

		$payload = [];

		foreach ($data as $key => $value) {
			if (!\in_array($key, $allowedKeys, true)) {
				throw new \InvalidArgumentException('Unknown document field: ' . $key);
			}
		}

		if (\array_key_exists('knowledge_base_id', $data)) {
			$payload['knowledge_base_id'] = $this->normalizePositiveInt($data['knowledge_base_id'], 'knowledge_base_id');
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: knowledge_base_id');
		}

		if (\array_key_exists('slug', $data)) {
			$payload['slug'] = $this->normalizeRequiredString($data['slug'], 'slug', 150);
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: slug');
		}

		if (\array_key_exists('title', $data)) {
			$payload['title'] = $this->normalizeRequiredString($data['title'], 'title', 500);
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: title');
		}

		if (\array_key_exists('source_type', $data)) {
			$payload['source_type'] = $this->normalizeRequiredString($data['source_type'], 'source_type', 50);
		} elseif (!$allowPartial) {
			throw new \InvalidArgumentException('Missing required field: source_type');
		}

		if (\array_key_exists('source_ref', $data)) {
			$payload['source_ref'] = $this->normalizeNullableTrimmedString($data['source_ref'], 'source_ref', 255);
		}

		if (\array_key_exists('effective_date', $data)) {
			$payload['effective_date'] = $this->normalizeNullableDate($data['effective_date']);
		}

		if (\array_key_exists('version_label', $data)) {
			$payload['version_label'] = $this->normalizeNullableTrimmedString($data['version_label'], 'version_label', 100);
		}

		if (\array_key_exists('content_hash', $data)) {
			$payload['content_hash'] = $this->normalizeNullableContentHash($data['content_hash']);
		}

		if (\array_key_exists('language', $data)) {
			$payload['language'] = $this->normalizeRequiredString($data['language'], 'language', 10);
		}

		if (\array_key_exists('status', $data)) {
			$payload['status'] = $this->normalizeStatus($data['status']);
		} elseif (!$allowPartial) {
			$payload['status'] = 'draft';
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
		$row['knowledge_base_id'] = (int)$row['knowledge_base_id'];
		$row['slug'] = (string)$row['slug'];
		$row['title'] = (string)$row['title'];
		$row['source_type'] = (string)$row['source_type'];
		$row['source_ref'] = $row['source_ref'] !== null ? (string)$row['source_ref'] : null;
		$row['effective_date'] = $row['effective_date'] !== null ? (string)$row['effective_date'] : null;
		$row['version_label'] = $row['version_label'] !== null ? (string)$row['version_label'] : null;
		$row['content_hash'] = $row['content_hash'] !== null ? (string)$row['content_hash'] : null;
		$row['language'] = (string)$row['language'];
		$row['metadata_json'] = $this->decodeMetadataJson($row['metadata_json'] ?? null);
		$row['status'] = (string)$row['status'];
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
			throw new \InvalidArgumentException('Invalid document status: ' . $value);
		}

		return $value;
	}


	/**
	 * Normalize nullable date input to YYYY-MM-DD.
	 *
	 * @param mixed $value Raw value.
	 * @return ?string Normalized date or null.
	 * @throws \InvalidArgumentException When the date is invalid.
	 */
	private function normalizeNullableDate(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('effective_date must be string or null.');
		}

		$value = \trim($value);

		if ($value === '') {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
		$errors = \DateTimeImmutable::getLastErrors();

		if ($date === false || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
			throw new \InvalidArgumentException('Invalid effective_date. Expected YYYY-MM-DD.');
		}

		return $date->format('Y-m-d');
	}


	/**
	 * Normalize nullable content hash.
	 *
	 * @param mixed $value Raw value.
	 * @return ?string Normalized hash or null.
	 * @throws \InvalidArgumentException When the hash is invalid.
	 */
	private function normalizeNullableContentHash(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('content_hash must be string or null.');
		}

		$value = \trim($value);

		if ($value === '') {
			return null;
		}

		return $this->normalizeContentHash($value);
	}


	/**
	 * Normalize a required SHA-256 hex hash.
	 *
	 * @param string $value Raw hash value.
	 * @return string Normalized lowercase hash.
	 * @throws \InvalidArgumentException When the hash is invalid.
	 */
	private function normalizeContentHash(string $value): string {
		$value = \strtolower(\trim($value));

		if (!\preg_match('/^[a-f0-9]{64}$/', $value)) {
			throw new \InvalidArgumentException('content_hash must be a 64-character SHA-256 hex string.');
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
