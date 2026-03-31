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

use CitOmni\KnowledgeBase\Exception\ChunkerException;

/**
 * Split unit text into retrieval-oriented chunks.
 *
 * The Chunker is a pure utility with no App dependency, no config access,
 * and no side effects. It performs chunking mechanics only. Callers decide
 * strategy and sizing policy.
 *
 * Behavior:
 * - Supports two strategies: fixed_size and paragraph.
 * - Returns deterministic chunk rows with stable zero-based indexes.
 * - Uses heuristic token estimation with conservative margin.
 * - fixed_size emits context_before/context_after from adjacent overlap windows.
 * - paragraph emits null overlap fields and falls back to internal splitting
 *   for oversized paragraphs.
 *
 * Notes:
 * - Token estimates are heuristic, not model-exact.
 * - Empty or whitespace-only input returns an empty array.
 * - Line endings are normalized to "\n" before processing.
 *
 * Typical usage:
 *   $chunks = Chunker::chunk($body, 'fixed_size', 512, 50);
 *
 * @throws ChunkerException When strategy or limits are invalid.
 */
final class Chunker {

	private const STRATEGY_FIXED_SIZE = 'fixed_size';

	private const STRATEGY_PARAGRAPH = 'paragraph';


	/**
	 * Split text into retrieval-optimized chunks.
	 *
	 * @param string $text Raw text content of one unit.
	 * @param string $strategy Chunking strategy: fixed_size | paragraph.
	 * @param int $maxTokens Maximum estimated tokens per chunk.
	 * @param int $overlapTokens Overlap between consecutive chunks (fixed_size only).
	 * @return array<int, array<string, mixed>> Chunk rows ready for persistence.
	 * @throws ChunkerException When strategy or limits are invalid.
	 */
	public static function chunk(string $text, string $strategy = self::STRATEGY_FIXED_SIZE, int $maxTokens = 512, int $overlapTokens = 50): array {
		$strategy = self::normalizeStrategy($strategy);
		$maxTokens = self::normalizeMaxTokens($maxTokens);
		$overlapTokens = self::normalizeOverlapTokens($overlapTokens, $maxTokens);
		$text = self::normalizeText($text);

		if ($text === '') {
			return [];
		}

		return match ($strategy) {
			self::STRATEGY_FIXED_SIZE => self::chunkFixedSize($text, $maxTokens, $overlapTokens),
			self::STRATEGY_PARAGRAPH => self::chunkParagraph($text, $maxTokens),
			default => throw new ChunkerException('Unsupported chunking strategy: ' . $strategy),
		};
	}






	// ----------------------------------------------------------------
	// Strategy implementations
	// ----------------------------------------------------------------

	/**
	 * Chunk text by approximate token budget using word/whitespace segments.
	 *
	 * @param string $text Normalized source text.
	 * @param int $maxTokens Maximum estimated tokens per chunk.
	 * @param int $overlapTokens Overlap budget for context windows.
	 * @return array<int, array<string, mixed>> Chunk rows.
	 */
	private static function chunkFixedSize(string $text, int $maxTokens, int $overlapTokens): array {
		$segments = self::splitSegments($text);
		if ($segments === []) {
			return [];
		}

		$chunks = [];
		$current = '';
		$currentTokens = 0;

		foreach ($segments as $segment) {
			$segmentTokens = self::estimateTokens($segment);

			if ($segmentTokens > $maxTokens) {
				if ($current !== '') {
					$chunks[] = self::trimChunkContent($current);
					$current = '';
					$currentTokens = 0;
				}

				foreach (self::splitOversizedSegment($segment, $maxTokens) as $piece) {
					$piece = self::trimChunkContent($piece);
					if ($piece !== '') {
						$chunks[] = $piece;
					}
				}

				continue;
			}

			if ($current !== '' && ($currentTokens + $segmentTokens) > $maxTokens) {
				$chunks[] = self::trimChunkContent($current);
				$current = '';
				$currentTokens = 0;
			}

			$current .= $segment;
			$currentTokens += $segmentTokens;
		}

		if ($current !== '') {
			$chunks[] = self::trimChunkContent($current);
		}

		return self::buildFixedSizeRows($chunks, $overlapTokens);
	}


	/**
	 * Chunk text by paragraph boundaries and merge adjacent small paragraphs.
	 *
	 * Oversized paragraphs are split internally without overlap metadata.
	 *
	 * @param string $text Normalized source text.
	 * @param int $maxTokens Maximum estimated tokens per chunk.
	 * @return array<int, array<string, mixed>> Chunk rows.
	 */
	private static function chunkParagraph(string $text, int $maxTokens): array {
		$paragraphs = self::extractParagraphs($text);
		if ($paragraphs === []) {
			return [];
		}

		$chunks = [];
		$current = '';
		$currentTokens = 0;

		foreach ($paragraphs as $paragraph) {
			$paragraphTokens = self::estimateTokens($paragraph);

			if ($paragraphTokens > $maxTokens) {
				if ($current !== '') {
					$chunks[] = self::trimChunkContent($current);
					$current = '';
					$currentTokens = 0;
				}

				foreach (self::chunkFixedSize($paragraph, $maxTokens, 0) as $row) {
					$row['context_before'] = null;
					$row['context_after'] = null;
					$row['index'] = \count($chunks);
					$chunks[] = $row;
				}

				continue;
			}

			if ($current === '') {
				$current = $paragraph;
				$currentTokens = $paragraphTokens;
				continue;
			}

			$merged = $current . "\n\n" . $paragraph;
			$mergedTokens = self::estimateTokens($merged);

			if ($mergedTokens <= $maxTokens) {
				$current = $merged;
				$currentTokens = $mergedTokens;
				continue;
			}

			$chunks[] = self::buildChunkRow(\count($chunks), self::trimChunkContent($current), null, null);
			$current = $paragraph;
			$currentTokens = $paragraphTokens;
		}

		if ($current !== '') {
			$chunks[] = self::buildChunkRow(\count($chunks), self::trimChunkContent($current), null, null);
		}

		return $chunks;
	}







	// ----------------------------------------------------------------
	// Row building
	// ----------------------------------------------------------------

	/**
	 * Build fixed-size chunk rows with adjacent overlap windows.
	 *
	 * @param array<int, string> $chunks Main chunk texts.
	 * @param int $overlapTokens Overlap budget for context windows.
	 * @return array<int, array<string, mixed>> Chunk rows.
	 */
	private static function buildFixedSizeRows(array $chunks, int $overlapTokens): array {
		$rows = [];
		$count = \count($chunks);

		for ($i = 0; $i < $count; $i++) {
			$content = $chunks[$i];
			$contextBefore = null;
			$contextAfter = null;

			if ($overlapTokens > 0 && $i > 0) {
				$contextBefore = self::sliceTailByTokens($chunks[$i - 1], $overlapTokens);
			}

			if ($overlapTokens > 0 && $i < ($count - 1)) {
				$contextAfter = self::sliceHeadByTokens($chunks[$i + 1], $overlapTokens);
			}

			$rows[] = self::buildChunkRow($i, $content, $contextBefore, $contextAfter);
		}

		return $rows;
	}


	/**
	 * Build one normalized chunk row.
	 *
	 * @param int $index Zero-based chunk index.
	 * @param string $content Main chunk content.
	 * @param ?string $contextBefore Left-side contextual overlap.
	 * @param ?string $contextAfter Right-side contextual overlap.
	 * @return array<string, mixed> Chunk row.
	 */
	private static function buildChunkRow(int $index, string $content, ?string $contextBefore, ?string $contextAfter): array {
		$content = self::trimChunkContent($content);
		$contextBefore = self::normalizeNullableContext($contextBefore);
		$contextAfter = self::normalizeNullableContext($contextAfter);

		return [
			'content' => $content,
			'index' => $index,
			'token_estimate' => self::estimateTokens($content),
			'char_count' => self::charLength($content),
			'context_before' => $contextBefore,
			'context_after' => $contextAfter,
		];
	}







	// ----------------------------------------------------------------
	// Text normalization and segmentation
	// ----------------------------------------------------------------

	/**
	 * Normalize raw source text for deterministic chunking.
	 *
	 * @param string $text Raw input text.
	 * @return string Normalized text.
	 */
	private static function normalizeText(string $text): string {
		$text = \str_replace(["\r\n", "\r"], "\n", $text);
		$text = \preg_replace("/\t/u", ' ', $text) ?? $text;
		$text = \preg_replace("/\x{00A0}/u", ' ', $text) ?? $text;
		$text = \preg_replace("/[ ]+\n/u", "\n", $text) ?? $text;
		$text = \preg_replace("/\n[ ]+/u", "\n", $text) ?? $text;
		$text = \preg_replace("/[ ]{2,}/u", ' ', $text) ?? $text;
		$text = \trim($text);

		return $text;
	}


	/**
	 * Extract non-empty paragraphs separated by blank lines.
	 *
	 * @param string $text Normalized text.
	 * @return array<int, string> Paragraph texts.
	 */
	private static function extractParagraphs(string $text): array {
		$parts = \preg_split("/\n\s*\n+/u", $text) ?: [];
		$paragraphs = [];

		foreach ($parts as $part) {
			$part = self::trimChunkContent($part);
			if ($part !== '') {
				$paragraphs[] = $part;
			}
		}

		return $paragraphs;
	}


	/**
	 * Split text into alternating content/whitespace segments.
	 *
	 * @param string $text Normalized text.
	 * @return array<int, string> Ordered text segments.
	 */
	private static function splitSegments(string $text): array {
		$segments = \preg_split('/(\s+)/u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);

		return \is_array($segments) ? $segments : [];
	}


	/**
	 * Split one oversized segment into smaller pieces by token budget.
	 *
	 * This is a defensive fallback for pathological inputs such as a very long
	 * token with no whitespace or a very large uninterrupted sequence.
	 *
	 * @param string $segment Oversized segment.
	 * @param int $maxTokens Maximum estimated tokens per piece.
	 * @return array<int, string> Smaller pieces.
	 */
	private static function splitOversizedSegment(string $segment, int $maxTokens): array {
		$segment = self::trimChunkContent($segment);
		if ($segment === '') {
			return [];
		}

		$maxChars = self::maxCharsForTokenBudget($maxTokens);
		$length = self::charLength($segment);

		if ($length <= $maxChars) {
			return [$segment];
		}

		$pieces = [];
		$offset = 0;

		while ($offset < $length) {
			$remaining = $length - $offset;
			$take = $remaining > $maxChars ? $maxChars : $remaining;
			$piece = \mb_substr($segment, $offset, $take, 'UTF-8');
			$piece = self::trimChunkContent($piece);

			if ($piece !== '') {
				$pieces[] = $piece;
			}

			$offset += $take;
		}

		return $pieces;
	}








	// ----------------------------------------------------------------
	// Context slicing
	// ----------------------------------------------------------------

	/**
	 * Return the first text slice that fits the token budget.
	 *
	 * @param string $text Source text.
	 * @param int $tokenBudget Maximum estimated tokens.
	 * @return string Head slice.
	 */
	private static function sliceHeadByTokens(string $text, int $tokenBudget): string {
		if ($tokenBudget <= 0) {
			return '';
		}

		$segments = self::splitSegments($text);
		$current = '';
		$currentTokens = 0;

		foreach ($segments as $segment) {
			$segmentTokens = self::estimateTokens($segment);

			if ($segmentTokens > $tokenBudget) {
				break;
			}

			if (($currentTokens + $segmentTokens) > $tokenBudget) {
				break;
			}

			$current .= $segment;
			$currentTokens += $segmentTokens;
		}

		return self::trimChunkContent($current);
	}


	/**
	 * Return the last text slice that fits the token budget.
	 *
	 * @param string $text Source text.
	 * @param int $tokenBudget Maximum estimated tokens.
	 * @return string Tail slice.
	 */
	private static function sliceTailByTokens(string $text, int $tokenBudget): string {
		if ($tokenBudget <= 0) {
			return '';
		}

		$segments = self::splitSegments($text);
		$current = '';
		$currentTokens = 0;

		for ($i = \count($segments) - 1; $i >= 0; $i--) {
			$segment = $segments[$i];
			$segmentTokens = self::estimateTokens($segment);

			if ($segmentTokens > $tokenBudget) {
				break;
			}

			if (($currentTokens + $segmentTokens) > $tokenBudget) {
				break;
			}

			$current = $segment . $current;
			$currentTokens += $segmentTokens;
		}

		return self::trimChunkContent($current);
	}








	// ----------------------------------------------------------------
	// Validation and estimation
	// ----------------------------------------------------------------

	/**
	 * Normalize and validate strategy.
	 *
	 * @param string $strategy Requested strategy.
	 * @return string Normalized strategy.
	 * @throws ChunkerException When the strategy is unsupported.
	 */
	private static function normalizeStrategy(string $strategy): string {
		$strategy = \trim($strategy);

		if ($strategy === '') {
			throw new ChunkerException('Chunking strategy must not be empty.');
		}

		if ($strategy !== self::STRATEGY_FIXED_SIZE && $strategy !== self::STRATEGY_PARAGRAPH) {
			throw new ChunkerException('Unsupported chunking strategy: ' . $strategy);
		}

		return $strategy;
	}


	/**
	 * Validate maxTokens.
	 *
	 * @param int $maxTokens Requested max token budget.
	 * @return int Normalized max token budget.
	 * @throws ChunkerException When the value is invalid.
	 */
	private static function normalizeMaxTokens(int $maxTokens): int {
		if ($maxTokens < 1) {
			throw new ChunkerException('maxTokens must be at least 1.');
		}

		return $maxTokens;
	}


	/**
	 * Validate and clamp overlapTokens.
	 *
	 * @param int $overlapTokens Requested overlap budget.
	 * @param int $maxTokens Max token budget for the main chunk.
	 * @return int Normalized overlap budget.
	 * @throws ChunkerException When the value is invalid.
	 */
	private static function normalizeOverlapTokens(int $overlapTokens, int $maxTokens): int {
		if ($overlapTokens < 0) {
			throw new ChunkerException('overlapTokens must not be negative.');
		}

		if ($overlapTokens >= $maxTokens) {
			return $maxTokens > 1 ? ($maxTokens - 1) : 0;
		}

		return $overlapTokens;
	}


	/**
	 * Estimate token count for a text fragment.
	 *
	 * Heuristic:
	 * - Empty/whitespace-only text -> 0
	 * - Otherwise use the larger of:
	 *   - word-like item count
	 *   - UTF-8 character count divided by 4, rounded up
	 *
	 * This intentionally leaves safety margin versus exact provider tokenizers.
	 *
	 * @param string $text Text fragment.
	 * @return int Estimated token count.
	 */
	private static function estimateTokens(string $text): int {
		$text = \trim($text);
		if ($text === '') {
			return 0;
		}

		$wordLikeCount = \preg_match_all('/[\p{L}\p{N}_]+/u', $text) ?: 0;
		$charCount = self::charLength($text);
		$charEstimate = (int)\ceil($charCount / 4);

		return $wordLikeCount > $charEstimate ? $wordLikeCount : $charEstimate;
	}


	/**
	 * Convert a token budget into an approximate UTF-8 character budget.
	 *
	 * @param int $maxTokens Token budget.
	 * @return int Approximate character budget.
	 */
	private static function maxCharsForTokenBudget(int $maxTokens): int {
		return $maxTokens * 4;
	}


	/**
	 * Return UTF-8 character length.
	 *
	 * @param string $text Text to measure.
	 * @return int Character count.
	 */
	private static function charLength(string $text): int {
		return \mb_strlen($text, 'UTF-8');
	}


	/**
	 * Trim chunk content without rewriting its internal structure.
	 *
	 * @param string $text Text to trim.
	 * @return string Trimmed text.
	 */
	private static function trimChunkContent(string $text): string {
		return \trim($text);
	}


	/**
	 * Normalize nullable context text.
	 *
	 * @param ?string $text Context text.
	 * @return ?string Normalized context or null.
	 */
	private static function normalizeNullableContext(?string $text): ?string {
		if ($text === null) {
			return null;
		}

		$text = self::trimChunkContent($text);

		return $text === '' ? null : $text;
	}


}