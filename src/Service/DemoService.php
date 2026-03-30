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

namespace CitOmni\ProviderSkeleton\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * DemoService.
 *
 * Minimal App-aware service example for the provider skeleton.
 *
 * Behavior:
 * - Demonstrates a real service-map candidate.
 * - Normalizes sample rows into a slightly richer application-facing shape.
 * - Can expose lightweight runtime metadata through the App.
 *
 * Notes:
 * - This service intentionally contains no SQL.
 * - Keep services focused on reusable App-aware capabilities.
 *
 * Typical usage:
 *   $result = $this->app->demoService->decorateSamples($rows);
 */
final class DemoService extends BaseService {

	/**
	 * One-time setup hook.
	 *
	 * Behavior:
	 * - Reserved for future options/config normalization.
	 * - Keeps the skeleton aligned with the BaseService lifecycle.
	 *
	 * @return void
	 */
	protected function init(): void {
		// no-op
	}

	/**
	 * Decorate repository rows with small derived fields.
	 *
	 * @param array<int,array{id:int,name:string,is_active:bool}> $rows Repository rows.
	 * @return array<int,array{
	 * 	id:int,
	 * 	name:string,
	 * 	is_active:bool,
	 * 	name_upper:string,
	 * 	status_label:string
	 * }>
	 */
	public function decorateSamples(array $rows): array {
		if ($rows === []) {
			return [];
		}

		$result = [];

		foreach ($rows as $row) {
			$name = (string)($row['name'] ?? '');
			$isActive = (bool)($row['is_active'] ?? false);

			$result[] = [
				'id' => (int)($row['id'] ?? 0),
				'name' => $name,
				'is_active' => $isActive,
				'name_upper' => \strtoupper($name),
				'status_label' => $isActive ? 'active' : 'inactive',
			];
		}

		return $result;
	}

	/**
	 * Return a tiny diagnostic snapshot.
	 *
	 * @return array{query_count:int}
	 */
	public function getDiagnostics(): array {
		return [
			'query_count' => $this->app->db->countQueries(),
		];
	}
}
