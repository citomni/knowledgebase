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

namespace CitOmni\ProviderSkeleton\Util;

/**
 * DemoUtil.
 *
 * Tiny pure helper example for the provider skeleton.
 *
 * Behavior:
 * - Contains only deterministic, stateless helper logic.
 * - Performs no IO and reads no App/config state.
 *
 * Notes:
 * - If a helper starts needing App access, it is no longer a Util.
 * - Keep Utils boring. Boring Utils are trustworthy Utils.
 */
final class DemoUtil {

	/**
	 * Normalize a free-text sample name.
	 *
	 * Behavior:
	 * - Trims surrounding whitespace.
	 * - Collapses internal whitespace runs to a single space.
	 *
	 * @param string $value Raw user-supplied value.
	 * @return string Normalized value.
	 */
	public static function normalizeSampleName(string $value): string {
		$value = \trim($value);

		if ($value === '') {
			return '';
		}

		return (string)\preg_replace('/\s+/', ' ', $value);
	}

	/**
	 * Validate a normalized sample name length.
	 *
	 * @param string $value Normalized sample name.
	 * @param int $min Minimum accepted length.
	 * @param int $max Maximum accepted length.
	 * @return bool True when the length is within bounds.
	 */
	public static function isValidSampleName(string $value, int $min = 2, int $max = 100): bool {
		$length = \strlen($value);
		return ($length >= $min && $length <= $max);
	}
}
