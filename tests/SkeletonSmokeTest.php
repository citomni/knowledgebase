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

namespace CitOmni\ProviderSkeleton\Tests;

use PHPUnit\Framework\TestCase;

final class SkeletonSmokeTest extends TestCase {
	public function testBootConstantsExist(): void {
		$this->assertTrue(\defined(\CitOmni\ProviderSkeleton\Boot\Services::class . '::MAP_HTTP'));
		$this->assertTrue(\defined(\CitOmni\ProviderSkeleton\Boot\Services::class . '::CFG_HTTP'));
		$this->assertTrue(\defined(\CitOmni\ProviderSkeleton\Boot\Services::class . '::MAP_CLI'));
		$this->assertTrue(\defined(\CitOmni\ProviderSkeleton\Boot\Services::class . '::CFG_CLI'));
	}
}
