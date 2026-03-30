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

namespace CitOmni\ProviderSkeleton\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * DemoRepository.
 *
 * Minimal repository example for the provider skeleton.
 *
 * Behavior:
 * - Demonstrates repository-owned SQL through the shared Db service.
 * - Returns predictable array shapes.
 * - Keeps persistence concerns in one place.
 *
 * Notes:
 * - This example assumes a tiny `demo_samples` table exists if these methods are used
 *   against a real database.
 * - The purpose is architectural demonstration, not production domain modeling.
 *
 * Typical usage:
 *   $repository = new DemoRepository($this->app);
 *   $items = $repository->listSamples();
 */
final class DemoRepository extends BaseRepository {

	/**
	 * Return all sample rows ordered by id.
	 *
	 * @return array<int,array{id:int,name:string,is_active:bool}>
	 */
	public function listSamples(): array {
		$rows = $this->app->db->fetchAll(
			'SELECT id, name, is_active FROM demo_samples ORDER BY id ASC'
		);

		if ($rows === []) {
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			$result[] = $this->mapSampleRow($row);
		}

		return $result;
	}

	/**
	 * Return one sample row by id.
	 *
	 * @param int $id Sample id.
	 * @return array{id:int,name:string,is_active:bool}|null
	 */
	public function findSampleById(int $id): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT id, name, is_active FROM demo_samples WHERE id = ? LIMIT 1',
			[$id]
		);

		if ($row === null) {
			return null;
		}

		return $this->mapSampleRow($row);
	}

	/**
	 * Insert one sample row and return the new id.
	 *
	 * @param string $name Sample name.
	 * @param bool $isActive Active flag.
	 * @return int New row id.
	 */
	public function createSample(string $name, bool $isActive = true): int {
		return $this->app->db->insert('demo_samples', [
			'name' => $name,
			'is_active' => $isActive ? 1 : 0,
		]);
	}

	/**
	 * Map a raw DB row into the repository contract shape.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array{id:int,name:string,is_active:bool}
	 */
	private function mapSampleRow(array $row): array {
		return [
			'id' => (int)($row['id'] ?? 0),
			'name' => (string)($row['name'] ?? ''),
			'is_active' => ((int)($row['is_active'] ?? 0) === 1),
		];
	}
}
