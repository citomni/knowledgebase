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

namespace CitOmni\ProviderSkeleton\Operation;

use CitOmni\Kernel\Operation\BaseOperation;
use CitOmni\ProviderSkeleton\Repository\DemoRepository;

/**
 * DemoOperation.
 *
 * Minimal operation example for the provider skeleton.
 *
 * Behavior:
 * - Coordinates repository calls without owning transport shaping.
 * - Returns domain-shaped arrays.
 * - Contains no SQL.
 */
final class DemoOperation extends BaseOperation {

	/**
	 * Return all demo samples.
	 *
	 * @return array{
	 * 	items: array<int,array{id:int,name:string,is_active:bool}>,
	 * 	total: int
	 * }
	 */
	public function listSamples(): array {
		$repository = new DemoRepository($this->app);
		$items = $repository->listSamples();

		return [
			'items' => $items,
			'total' => \count($items),
		];
	}

	/**
	 * Return one demo sample by id.
	 *
	 * @param int $id Sample id.
	 * @return array{id:int,name:string,is_active:bool}|null
	 */
	public function findSampleById(int $id): ?array {
		if ($id < 1) {
			return null;
		}

		$repository = new DemoRepository($this->app);
		return $repository->findSampleById($id);
	}

	/**
	 * Create one demo sample and return the new id.
	 *
	 * @param string $name Sample name.
	 * @param bool $isActive Active flag.
	 * @return int New row id.
	 */
	public function createSample(string $name, bool $isActive = true): int {
		$repository = new DemoRepository($this->app);
		return $repository->createSample($name, $isActive);
	}

}
