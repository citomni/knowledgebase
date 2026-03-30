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
 * Persist and load relational synonym groups.
 *
 * Behavior:
 * - Owns all SQL for `know_synonym_groups` and `know_synonym_terms`.
 * - Normalizes canonical terms and term rows deterministically before persistence.
 * - Enforces the canonical-term invariant transactionally:
 *   the group's canonical_term must also exist as a term row in the same group.
 * - Enforces no-overlap per knowledge base across normalized term rows.
 * - Loads hydrated synonym groups and term rows for lookup/use by higher layers.
 *
 * Notes:
 * - Query-time synonym expansion belongs to Retriever, not to the Repository layer.
 * - Repository methods return normalized persisted data only.
 *
 * Typical usage:
 *   $repo = new SynonymRepository($this->app);
 *   $groupId = $repo->insertGroup(1, 'depositum', ['depositum', 'indskud', 'deposit']);
 *   $group = $repo->findByTerm(1, 'indskud');
 *   $groups = $repo->listByKnowledgeBases([1]);
 *
 * @throws \InvalidArgumentException When input data is invalid.
 * @throws \RuntimeException When synonym integrity rules are violated.
 */
final class SynonymRepository extends BaseRepository {

	/**
	 * Find one synonym group by id.
	 *
	 * @param int $groupId Group id.
	 * @return ?array Hydrated group or null when not found.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function findGroupById(int $groupId): ?array {
		$groupId = $this->normalizePositiveInt($groupId, 'groupId');

		$row = $this->app->db->fetchRow(
			'SELECT id, knowledge_base_id, canonical_term, created_at, updated_at
			FROM know_synonym_groups
			WHERE id = ?
			LIMIT 1',
			[$groupId]
		);

		if ($row === null) {
			return null;
		}

		return $this->hydrateGroupRow($row, $this->fetchTermsForGroupIds([$groupId]));
	}


	/**
	 * Find one synonym group by normalized term within one knowledge base.
	 *
	 * Returns the matching group with its full ordered term list.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param string $term Raw or normalized term.
	 * @return ?array Hydrated group or null when not found.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function findByTerm(int $knowledgeBaseId, string $term): ?array {
		$knowledgeBaseId = $this->normalizePositiveInt($knowledgeBaseId, 'knowledgeBaseId');
		$term = $this->normalizeSurfaceForm($term);

		$row = $this->app->db->fetchRow(
			'SELECT g.id, g.knowledge_base_id, g.canonical_term, g.created_at, g.updated_at
			FROM know_synonym_terms t
			INNER JOIN know_synonym_groups g ON g.id = t.group_id
			WHERE t.knowledge_base_id = ? AND t.term = ?
			LIMIT 1',
			[$knowledgeBaseId, $term]
		);

		if ($row === null) {
			return null;
		}

		$groupId = (int)$row['id'];

		return $this->hydrateGroupRow($row, $this->fetchTermsForGroupIds([$groupId]));
	}


	/**
	 * List all synonym groups for one knowledge base.
	 *
	 * Returns nested ordered term lists.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @return array Hydrated groups ordered deterministically.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function listByKnowledgeBase(int $knowledgeBaseId): array {
		$knowledgeBaseId = $this->normalizePositiveInt($knowledgeBaseId, 'knowledgeBaseId');

		$rows = $this->app->db->fetchAll(
			'SELECT id, knowledge_base_id, canonical_term, created_at, updated_at
			FROM know_synonym_groups
			WHERE knowledge_base_id = ?
			ORDER BY canonical_term ASC, id ASC',
			[$knowledgeBaseId]
		);

		return $this->hydrateGroupRows($rows);
	}


	/**
	 * Insert one synonym group and its term rows.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param string $canonicalTerm Canonical term.
	 * @param array $terms Raw term list.
	 * @return int Inserted group id.
	 * @throws \InvalidArgumentException When input data is invalid.
	 * @throws \RuntimeException When overlap is detected.
	 */
	public function insertGroup(int $knowledgeBaseId, string $canonicalTerm, array $terms): int {
		$knowledgeBaseId = $this->normalizePositiveInt($knowledgeBaseId, 'knowledgeBaseId');
		$group = $this->normalizeGroupPayload($canonicalTerm, $terms);

		return $this->app->db->transaction(function() use ($knowledgeBaseId, $group): int {
			$this->assertNoTermOverlap($knowledgeBaseId, $group['terms'], null);

			$groupId = $this->app->db->insert('know_synonym_groups', [
				'knowledge_base_id' => $knowledgeBaseId,
				'canonical_term' => $group['canonical_term'],
			]);

			$this->insertTermRows($groupId, $knowledgeBaseId, $group['terms']);

			return $groupId;
		});
	}


	/**
	 * Update one synonym group and replace its term rows transactionally.
	 *
	 * @param int $groupId Group id.
	 * @param string $canonicalTerm Canonical term.
	 * @param array $terms Raw term list.
	 * @return int Affected rows for the group row update.
	 * @throws \InvalidArgumentException When input data is invalid.
	 * @throws \RuntimeException When overlap is detected or the row does not exist.
	 */
	public function updateGroup(int $groupId, string $canonicalTerm, array $terms): int {
		$groupId = $this->normalizePositiveInt($groupId, 'groupId');
		$group = $this->normalizeGroupPayload($canonicalTerm, $terms);

		return $this->app->db->transaction(function() use ($groupId, $group): int {
			$current = $this->getGroupRowOrFail($groupId);
			$knowledgeBaseId = (int)$current['knowledge_base_id'];

			$this->assertNoTermOverlap($knowledgeBaseId, $group['terms'], $groupId);

			$affected = $this->app->db->update(
				'know_synonym_groups',
				['canonical_term' => $group['canonical_term']],
				'id = ?',
				[$groupId]
			);

			$this->app->db->delete('know_synonym_terms', 'group_id = ?', [$groupId]);
			$this->insertTermRows($groupId, $knowledgeBaseId, $group['terms']);

			return $affected;
		});
	}


	/**
	 * Delete one synonym group.
	 *
	 * Child term rows cascade automatically.
	 *
	 * @param int $groupId Group id.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function deleteGroup(int $groupId): int {
		$groupId = $this->normalizePositiveInt($groupId, 'groupId');

		return $this->app->db->delete('know_synonym_groups', 'id = ?', [$groupId]);
	}


	/**
	 * Bulk upsert synonym groups for one knowledge base.
	 *
	 * Each entry must be:
	 * - ['canonical_term' => string, 'terms' => string[]]
	 *
	 * Upsert key:
	 * - existing group with the same canonical_term in the same knowledge base
	 *
	 * Behavior:
	 * - matching canonical term => update that group transactionally
	 *   by replacing its canonical_term value and all persisted term rows
	 * - no match => insert a new group and its term rows
	 * - groups not mentioned in the batch remain untouched
	 * - import is not a full replacement of all groups in the knowledge base
	 * - batch is validated fail-fast before writes
	 * - term overlap across existing groups and batch entries is rejected
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param array $entries Batch entries.
	 * @return int Number of inserted + updated groups.
	 * @throws \InvalidArgumentException When input is invalid.
	 * @throws \RuntimeException When overlap is detected.
	 */
	public function importBatch(int $knowledgeBaseId, array $entries): int {
		$knowledgeBaseId = $this->normalizePositiveInt($knowledgeBaseId, 'knowledgeBaseId');

		if ($entries === []) {
			return 0;
		}

		return $this->app->db->transaction(function() use ($knowledgeBaseId, $entries): int {
			$existingGroups = $this->listByKnowledgeBase($knowledgeBaseId);
			$existingByCanonical = [];
			$existingById = [];
			$termOwnerMap = [];

			foreach ($existingGroups as $group) {
				$existingByCanonical[$group['canonical_term']] = $group;
				$existingById[$group['id']] = $group;

				foreach ($group['terms'] as $term) {
					$termOwnerMap[$term] = $group['id'];
				}
			}

			$normalizedEntries = [];

			foreach ($entries as $entry) {
				if (!\is_array($entry)) {
					throw new \InvalidArgumentException('Each import entry must be an array.');
				}

				if (!isset($entry['canonical_term']) || !isset($entry['terms']) || !\is_array($entry['terms'])) {
					throw new \InvalidArgumentException('Each import entry must contain canonical_term and terms[].');
				}

				$normalized = $this->normalizeGroupPayload((string)$entry['canonical_term'], $entry['terms']);
				$existing = $existingByCanonical[$normalized['canonical_term']] ?? null;

				// Per-group upsert key in V1:
				// canonical_term within the target knowledge base.
				// Groups not present in the batch remain untouched.
				$normalizedEntries[] = [
					'group_id' => $existing['id'] ?? null,
					'canonical_term' => $normalized['canonical_term'],
					'terms' => $normalized['terms'],
				];
			}

			// -- 1. Validate overlap across planned final state ------------------
			$plannedOwners = $termOwnerMap;

			foreach ($normalizedEntries as $entry) {
				$groupId = $entry['group_id'];

				if ($groupId !== null && isset($existingById[$groupId])) {
					foreach ($existingById[$groupId]['terms'] as $oldTerm) {
						unset($plannedOwners[$oldTerm]);
					}
				}

				foreach ($entry['terms'] as $term) {
					if (isset($plannedOwners[$term]) && $plannedOwners[$term] !== $groupId) {
						throw new \RuntimeException('Synonym overlap detected for normalized term: ' . $term);
					}

					$plannedOwners[$term] = $groupId ?? ('new:' . $entry['canonical_term']);
				}
			}

			// -- 2. Apply per-group upsert by canonical_term --------------------
			$affected = 0;

			foreach ($normalizedEntries as $entry) {
				if ($entry['group_id'] !== null) {
					$affected += $this->app->db->update(
						'know_synonym_groups',
						['canonical_term' => $entry['canonical_term']],
						'id = ?',
						[$entry['group_id']]
					);

					$this->app->db->delete('know_synonym_terms', 'group_id = ?', [$entry['group_id']]);
					$this->insertTermRows((int)$entry['group_id'], $knowledgeBaseId, $entry['terms']);
					continue;
				}

				$newGroupId = $this->app->db->insert('know_synonym_groups', [
					'knowledge_base_id' => $knowledgeBaseId,
					'canonical_term' => $entry['canonical_term'],
				]);

				$this->insertTermRows($newGroupId, $knowledgeBaseId, $entry['terms']);
				$affected++;
			}

			return $affected;
		});
	}







	// ----------------------------------------------------------------
	// Loading helpers
	// ----------------------------------------------------------------

	/**
	 * List synonym groups across multiple knowledge bases.
	 *
	 * @param array $knowledgeBaseIds Knowledge base ids.
	 * @return array Hydrated groups with nested ordered term lists.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function listByKnowledgeBases(array $knowledgeBaseIds): array {
		$knowledgeBaseIds = $this->normalizeIdList($knowledgeBaseIds, 'knowledgeBaseIds');

		if ($knowledgeBaseIds === []) {
			return [];
		}

		$placeholders = \implode(', ', \array_fill(0, \count($knowledgeBaseIds), '?'));

		$rows = $this->app->db->fetchAll(
			'SELECT id, knowledge_base_id, canonical_term, created_at, updated_at
			FROM know_synonym_groups
			WHERE knowledge_base_id IN (' . $placeholders . ')
			ORDER BY knowledge_base_id ASC, canonical_term ASC, id ASC',
			$knowledgeBaseIds
		);

		return $this->hydrateGroupRows($rows);
	}


	/**
	 * Fetch term rows for the given group ids.
	 *
	 * Return shape:
	 * - groupId => ['term1', 'term2', ...]
	 *
	 * @param array $groupIds Group ids.
	 * @return array Nested term map.
	 */
	private function fetchTermsForGroupIds(array $groupIds): array {
		if ($groupIds === []) {
			return [];
		}

		$placeholders = \implode(', ', \array_fill(0, \count($groupIds), '?'));

		$rows = $this->app->db->fetchAll(
			'SELECT group_id, term
			FROM know_synonym_terms
			WHERE group_id IN (' . $placeholders . ')
			ORDER BY group_id ASC, sort_order ASC, term ASC, id ASC',
			$groupIds
		);

		$termsByGroupId = [];

		foreach ($rows as $row) {
			$groupId = (int)$row['group_id'];
			$termsByGroupId[$groupId] ??= [];
			$termsByGroupId[$groupId][] = (string)$row['term'];
		}

		return $termsByGroupId;
	}


	/**
	 * Hydrate one group row.
	 *
	 * @param array $row Raw group row.
	 * @param array $termsByGroupId Group id => terms[] map.
	 * @return array Hydrated group.
	 */
	private function hydrateGroupRow(array $row, array $termsByGroupId): array {
		$groupId = (int)$row['id'];

		return [
			'id' => $groupId,
			'knowledge_base_id' => (int)$row['knowledge_base_id'],
			'canonical_term' => (string)$row['canonical_term'],
			'terms' => $termsByGroupId[$groupId] ?? [],
			'created_at' => (string)$row['created_at'],
			'updated_at' => (string)$row['updated_at'],
		];
	}


	/**
	 * Hydrate many group rows with nested term lists.
	 *
	 * @param array $rows Raw group rows.
	 * @return array Hydrated groups.
	 */
	private function hydrateGroupRows(array $rows): array {
		if ($rows === []) {
			return [];
		}

		$groupIds = [];

		foreach ($rows as $row) {
			$groupIds[] = (int)$row['id'];
		}

		$termsByGroupId = $this->fetchTermsForGroupIds($groupIds);

		foreach ($rows as $index => $row) {
			$rows[$index] = $this->hydrateGroupRow($row, $termsByGroupId);
		}

		return $rows;
	}







	// ----------------------------------------------------------------
	// Persistence helpers
	// ----------------------------------------------------------------

	/**
	 * Fetch one raw group row or fail.
	 *
	 * @param int $groupId Group id.
	 * @return array Raw row.
	 * @throws \RuntimeException When the group does not exist.
	 */
	private function getGroupRowOrFail(int $groupId): array {
		$row = $this->app->db->fetchRow(
			'SELECT id, knowledge_base_id, canonical_term, created_at, updated_at
			FROM know_synonym_groups
			WHERE id = ?
			LIMIT 1',
			[$groupId]
		);

		if ($row === null) {
			throw new \RuntimeException('Synonym group not found: ' . $groupId);
		}

		return $row;
	}


	/**
	 * Insert normalized term rows for one group.
	 *
	 * @param int $groupId Group id.
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param array $terms Normalized sorted terms.
	 * @return void
	 */
	private function insertTermRows(int $groupId, int $knowledgeBaseId, array $terms): void {
		$rows = [];

		foreach ($terms as $index => $term) {
			$rows[] = [
				'group_id' => $groupId,
				'knowledge_base_id' => $knowledgeBaseId,
				'term' => $term,
				'sort_order' => $index,
			];
		}

		if ($rows !== []) {
			$this->app->db->insertBatch('know_synonym_terms', $rows);
		}
	}


	/**
	 * Assert that normalized terms do not overlap another group in the same knowledge base.
	 *
	 * @param int $knowledgeBaseId Knowledge base id.
	 * @param array $terms Normalized terms.
	 * @param ?int $excludeGroupId Optional group id to exclude during update.
	 * @return void
	 * @throws \RuntimeException When overlap is detected.
	 */
	private function assertNoTermOverlap(int $knowledgeBaseId, array $terms, ?int $excludeGroupId): void {
		if ($terms === []) {
			return;
		}

		$placeholders = \implode(', ', \array_fill(0, \count($terms), '?'));
		$sql = 'SELECT group_id, term
			FROM know_synonym_terms
			WHERE knowledge_base_id = ?
				AND term IN (' . $placeholders . ')';
		$params = \array_merge([$knowledgeBaseId], $terms);

		if ($excludeGroupId !== null) {
			$sql .= ' AND group_id <> ?';
			$params[] = $excludeGroupId;
		}

		$sql .= ' LIMIT 1';

		$row = $this->app->db->fetchRow($sql, $params);

		if ($row !== null) {
			throw new \RuntimeException('Synonym overlap detected for normalized term: ' . (string)$row['term']);
		}
	}


	/**
	 * Normalize one group payload.
	 *
	 * Behavior:
	 * - normalizes canonical term and all term rows
	 * - ensures the canonical term is included
	 * - de-duplicates terms
	 * - sorts terms deterministically
	 *
	 * @param string $canonicalTerm Canonical term.
	 * @param array $terms Raw term list.
	 * @return array ['canonical_term' => string, 'terms' => string[]]
	 * @throws \InvalidArgumentException When the payload is invalid.
	 */
	private function normalizeGroupPayload(string $canonicalTerm, array $terms): array {
		$canonicalTerm = $this->normalizeSurfaceForm($canonicalTerm);
		$normalizedTerms = [$canonicalTerm];

		foreach ($terms as $term) {
			if (!\is_string($term)) {
				throw new \InvalidArgumentException('Each synonym term must be a string.');
			}

			$normalizedTerms[] = $this->normalizeSurfaceForm($term);
		}

		$normalizedTerms = \array_values(\array_unique($normalizedTerms));
		\sort($normalizedTerms, SORT_STRING);

		return [
			'canonical_term' => $canonicalTerm,
			'terms' => $normalizedTerms,
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
	 * Normalize an id list to unique positive ints.
	 *
	 * @param array $ids Raw ids.
	 * @param string $field Field name for exception text.
	 * @return array Normalized ids.
	 * @throws \InvalidArgumentException When the list is invalid.
	 */
	private function normalizeIdList(array $ids, string $field): array {
		$normalized = [];

		foreach ($ids as $index => $id) {
			$normalized[$index] = $this->normalizePositiveInt($id, $field);
		}

		return \array_values(\array_unique($normalized));
	}


	/**
	 * Normalize one surface form.
	 *
	 * Rules:
	 * - trim
	 * - UTF-8 lowercase
	 * - collapse internal whitespace
	 * - reject empty result
	 *
	 * @param string $value Raw value.
	 * @return string Normalized surface form.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private function normalizeSurfaceForm(string $value): string {
		$value = \trim($value);
		$value = \mb_strtolower($value, 'UTF-8');
		$value = \preg_replace('/\s+/u', ' ', $value) ?? '';

		if ($value === '') {
			throw new \InvalidArgumentException('Synonym term must not be empty after normalization.');
		}

		if (\mb_strlen($value, 'UTF-8') > 200) {
			throw new \InvalidArgumentException('Synonym term exceeds max length.');
		}

		return $value;
	}


}
