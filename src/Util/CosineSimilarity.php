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

namespace CitOmni\KnowledgeBase\Util;

/**
 * Compute cosine similarity between two numeric vectors.
 *
 * This utility is pure and deterministic. It accepts two non-empty vectors
 * of equal dimension and returns their cosine similarity in the range [-1, 1].
 *
 * Behavior:
 * - Validates that both vectors are non-empty.
 * - Validates that both vectors have identical dimensions.
 * - Validates that all elements are numeric and finite.
 * - Rejects zero-magnitude vectors because cosine similarity is undefined.
 *
 * Notes:
 * - Integer values are accepted and cast to float internally.
 * - The implementation is single-pass over the vectors for low overhead.
 *
 * Typical usage:
 *   $score = CosineSimilarity::compute($queryVector, $chunkVector);
 *
 * @param array $a First vector.
 * @param array $b Second vector.
 * @return float Cosine similarity in range [-1, 1].
 * @throws \InvalidArgumentException When vectors are empty, dimensions differ, contain non-numeric values, contain non-finite values, or have zero magnitude.
 */
final class CosineSimilarity {

	/**
	 * Compute cosine similarity between two vectors of equal dimension.
	 *
	 * @param array $a First vector.
	 * @param array $b Second vector.
	 * @return float Cosine similarity in range [-1, 1].
	 * @throws \InvalidArgumentException When vectors are invalid or cosine similarity is undefined.
	 */
	public static function compute(array $a, array $b): float {

		$countA = \count($a);
		$countB = \count($b);

		if ($countA === 0 || $countB === 0) {
			throw new \InvalidArgumentException('Vectors must not be empty.');
		}
		if ($countA !== $countB) {
			throw new \InvalidArgumentException('Vectors must have the same dimension.');
		}

		$dotProduct = 0.0;
		$normA = 0.0;
		$normB = 0.0;

		for ($i = 0; $i < $countA; $i++) {
			$valueA = $a[$i] ?? null;
			$valueB = $b[$i] ?? null;

			if ((! \is_int($valueA) && ! \is_float($valueA)) || (! \is_int($valueB) && ! \is_float($valueB))) {
				throw new \InvalidArgumentException('Vectors must contain only int or float values.');
			}

			$floatA = (float)$valueA;
			$floatB = (float)$valueB;

			if (\is_nan($floatA) || \is_infinite($floatA) || \is_nan($floatB) || \is_infinite($floatB)) {
				throw new \InvalidArgumentException('Vectors must contain only finite numeric values.');
			}

			$dotProduct += $floatA * $floatB;
			$normA += $floatA * $floatA;
			$normB += $floatB * $floatB;
		}

		if ($normA <= 0.0 || $normB <= 0.0) {
			throw new \InvalidArgumentException('Cosine similarity is undefined for zero-magnitude vectors.');
		}

		$similarity = $dotProduct / (\sqrt($normA) * \sqrt($normB));

		if ($similarity > 1.0) {
			return 1.0;
		}
		if ($similarity < -1.0) {
			return -1.0;
		}

		return $similarity;
	}

}
