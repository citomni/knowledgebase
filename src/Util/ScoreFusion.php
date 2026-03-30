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
 * Normalize and fuse ranked retrieval result sets.
 *
 * This utility is pure and deterministic. It provides:
 * - min-max normalization for single-leg score scaling
 * - Reciprocal Rank Fusion (RRF) for merging two ranked result lists
 *
 * Behavior:
 * - normalize() preserves input order and structure while rewriting one score key
 * - normalize() maps all scores to 1.0 when all raw scores are identical
 * - rrf() merges by chunk_id and accumulates reciprocal rank contributions
 * - rrf() preserves source metadata from the first seen occurrence of each chunk
 * - rrf() adds fused_score, score, and retrieval_methods to the merged rows
 *
 * Notes:
 * - This utility does not read config and does not mutate caller input.
 * - RRF uses ranking positions, not raw score magnitudes.
 *
 * Typical usage:
 *   $normalized = ScoreFusion::normalize($results, 'score');
 *   $merged = ScoreFusion::rrf($lexicalResults, $vectorResults, 60);
 */
final class ScoreFusion {

	/**
	 * Normalize scores to 0.0-1.0 range using min-max normalization.
	 *
	 * If all scores are identical, all rows receive score 1.0. This avoids
	 * division-by-zero and treats the result set as equally ranked.
	 *
	 * @param array $results Result rows.
	 * @param string $scoreKey Key containing the raw numeric score.
	 * @return array Result rows with the given score key normalized.
	 * @throws \InvalidArgumentException When scoreKey is empty, a row is invalid, or a score is non-numeric/non-finite.
	 */
	public static function normalize(array $results, string $scoreKey): array {
		$scoreKey = \trim($scoreKey);
		if ($scoreKey === '') {
			throw new \InvalidArgumentException('scoreKey must not be empty.');
		}
		if ($results === []) {
			return [];
		}

		$min = null;
		$max = null;
		$scores = [];

		foreach ($results as $index => $row) {
			if (!\is_array($row)) {
				throw new \InvalidArgumentException('Each result row must be an array.');
			}
			if (!\array_key_exists($scoreKey, $row)) {
				throw new \InvalidArgumentException('Missing score key in result row: ' . $scoreKey);
			}

			$score = $row[$scoreKey];
			if (!\is_int($score) && !\is_float($score)) {
				throw new \InvalidArgumentException('Score must be int or float for key: ' . $scoreKey);
			}

			$floatScore = (float)$score;
			if (\is_nan($floatScore) || \is_infinite($floatScore)) {
				throw new \InvalidArgumentException('Score must be finite for key: ' . $scoreKey);
			}

			$scores[$index] = $floatScore;

			if ($min === null || $floatScore < $min) {
				$min = $floatScore;
			}
			if ($max === null || $floatScore > $max) {
				$max = $floatScore;
			}
		}

		$range = (float)$max - (float)$min;
		$normalized = [];

		foreach ($results as $index => $row) {
			if ($range <= 0.0) {
				$row[$scoreKey] = 1.0;
			} else {
				$row[$scoreKey] = ($scores[$index] - (float)$min) / $range;
			}
			$normalized[] = $row;
		}

		return $normalized;
	}


	/**
	 * Merge two ranked result lists using Reciprocal Rank Fusion.
	 *
	 * Each list must be ordered best-first. Rank positions are derived from
	 * array order (index 0 = rank 1). Raw score magnitudes are ignored.
	 *
	 * The merged row shape:
	 * - preserves metadata from the first seen occurrence
	 * - adds fused_score
	 * - sets score = fused_score
	 * - merges retrieval_methods from both lists without duplicates
	 *
	 * @param array $listA Ranked results. Each row must contain chunk_id.
	 * @param array $listB Ranked results. Each row must contain chunk_id.
	 * @param int $k RRF constant. Must be >= 1.
	 * @return array Merged results sorted by fused_score desc, then chunk_id asc.
	 * @throws \InvalidArgumentException When rows are invalid or k is < 1.
	 */
	public static function rrf(array $listA, array $listB, int $k = 60): array {
		if ($k < 1) {
			throw new \InvalidArgumentException('k must be at least 1.');
		}
		if ($listA === [] && $listB === []) {
			return [];
		}

		$merged = [];

		self::mergeRrfList($merged, $listA, 'A', $k);
		self::mergeRrfList($merged, $listB, 'B', $k);

		if ($merged === []) {
			return [];
		}

		$results = \array_values($merged);

		\usort($results, static function(array $a, array $b): int {
			$scoreA = (float)$a['fused_score'];
			$scoreB = (float)$b['fused_score'];

			if ($scoreA === $scoreB) {
				return ((int)$a['chunk_id']) <=> ((int)$b['chunk_id']);
			}

			return $scoreA < $scoreB ? 1 : -1;
		});

		return $results;
	}


	/**
	 * Merge one ranked list into the RRF accumulator.
	 *
	 * @param array $merged Accumulator keyed by chunk_id.
	 * @param array $list Ranked result list.
	 * @param string $sourceLabel Internal source label for debugging/context.
	 * @param int $k RRF constant.
	 * @return void
	 * @throws \InvalidArgumentException When a row is invalid.
	 */
	private static function mergeRrfList(array &$merged, array $list, string $sourceLabel, int $k): void {
		foreach ($list as $index => $row) {
			if (!\is_array($row)) {
				throw new \InvalidArgumentException('Each ranked result row must be an array.');
			}
			if (!\array_key_exists('chunk_id', $row)) {
				throw new \InvalidArgumentException('Each ranked result row must contain chunk_id.');
			}

			$chunkIdValue = $row['chunk_id'];
			if (!\is_int($chunkIdValue) && !(\is_string($chunkIdValue) && \ctype_digit($chunkIdValue))) {
				throw new \InvalidArgumentException('chunk_id must be an integer-compatible value.');
			}

			$chunkId = (int)$chunkIdValue;
			if ($chunkId <= 0) {
				throw new \InvalidArgumentException('chunk_id must be greater than zero.');
			}

			$rank = $index + 1;
			$rrfScore = 1.0 / ($k + $rank);

			if (!isset($merged[$chunkId])) {
				$baseRow = $row;
				$methods = self::normalizeRetrievalMethods($row['retrieval_methods'] ?? null);

				$baseRow['chunk_id'] = $chunkId;
				$baseRow['retrieval_methods'] = $methods;
				$baseRow['fused_score'] = $rrfScore;
				$baseRow['score'] = $rrfScore;
				$baseRow['_first_source'] = $sourceLabel;

				$merged[$chunkId] = $baseRow;
				continue;
			}

			$merged[$chunkId]['fused_score'] += $rrfScore;
			$merged[$chunkId]['score'] = $merged[$chunkId]['fused_score'];

			$existingMethods = self::normalizeRetrievalMethods($merged[$chunkId]['retrieval_methods'] ?? null);
			$newMethods = self::normalizeRetrievalMethods($row['retrieval_methods'] ?? null);
			$merged[$chunkId]['retrieval_methods'] = self::mergeMethods($existingMethods, $newMethods);
		}

		foreach ($merged as &$row) {
			unset($row['_first_source']);
		}
	}


	/**
	 * Normalize retrieval methods into a deterministic string list.
	 *
	 * @param mixed $value retrieval_methods value.
	 * @return array<string> Normalized method names.
	 * @throws \InvalidArgumentException When retrieval_methods contains invalid values.
	 */
	private static function normalizeRetrievalMethods(mixed $value): array {
		if ($value === null) {
			return [];
		}
		if (!\is_array($value)) {
			throw new \InvalidArgumentException('retrieval_methods must be an array when provided.');
		}

		$methods = [];

		foreach ($value as $method) {
			if (!\is_string($method)) {
				throw new \InvalidArgumentException('Each retrieval method must be a string.');
			}

			$method = \trim($method);
			if ($method === '') {
				throw new \InvalidArgumentException('Retrieval method must not be empty.');
			}

			$methods[$method] = true;
		}

		return \array_keys($methods);
	}


	/**
	 * Merge two method lists without duplicates, preserving first-seen order.
	 *
	 * @param array $a First method list.
	 * @param array $b Second method list.
	 * @return array Merged method list.
	 */
	private static function mergeMethods(array $a, array $b): array {
		if ($a === []) {
			return $b;
		}
		if ($b === []) {
			return $a;
		}

		$seen = [];
		$merged = [];

		foreach ($a as $method) {
			if (!isset($seen[$method])) {
				$seen[$method] = true;
				$merged[] = $method;
			}
		}

		foreach ($b as $method) {
			if (!isset($seen[$method])) {
				$seen[$method] = true;
				$merged[] = $method;
			}
		}

		return $merged;
	}


}
