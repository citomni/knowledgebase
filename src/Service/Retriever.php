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

namespace CitOmni\KnowledgeBase\Service;

use CitOmni\Kernel\Service\BaseService;
use CitOmni\KnowledgeBase\Exception\RetrievalException;
use CitOmni\KnowledgeBase\Repository\ChunkRepository;
use CitOmni\KnowledgeBase\Repository\EmbeddingRepository;
use CitOmni\KnowledgeBase\Repository\SynonymRepository;
use CitOmni\KnowledgeBase\Util\PromptBuilder;
use CitOmni\KnowledgeBase\Util\ScoreFusion;

final class Retriever extends BaseService {

	private const DEFAULT_TOP_K = 5;
	private const DEFAULT_CANDIDATE_MULTIPLIER = 3;
	private const DEFAULT_MIN_SIMILARITY = 0.70;
	private const DEFAULT_RRF_K = 60;
	private const MAX_BOOLEAN_QUERY_LENGTH = 2000;
	private const MAX_EXPANDED_GROUPS = 8;
	private const MAX_ALTERNATIVES_PER_GROUP = 8;

	private ChunkRepository $chunkRepo;
	private EmbeddingRepository $embeddingRepo;
	private SynonymRepository $synonymRepo;



	/**
	 * Initialize repository dependencies.
	 *
	 * Behavior:
	 * - Instantiates repository objects once per service instance.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->chunkRepo = new ChunkRepository($this->app);
		$this->embeddingRepo = new EmbeddingRepository($this->app);
		$this->synonymRepo = new SynonymRepository($this->app);
	}




	/**
	 * Retrieve the most relevant chunks for a query.
	 *
	 * Behavior:
	 * - Resolves effective retrieval cfg from package baseline plus per-call overrides.
	 * - Executes lexical, vector, or hybrid retrieval according to the resolved strategy.
	 * - Applies synonym expansion only to the lexical leg.
	 * - Uses Reciprocal Rank Fusion when both legs are active.
	 * - Applies reranking as a best-effort quality enhancement with graceful fallback.
	 *
	 * Notes:
	 * - `min_score` is intentionally not applied here. The Operation owns that threshold.
	 * - `queryVector` must be empty when vector retrieval is disabled.
	 *
	 * Typical usage:
	 *   $result = $this->app->retriever->retrieve($question, $queryVector, [
	 *       'knowledge_base_ids' => [1],
	 *       'top_k' => 5,
	 *   ]);
	 *
	 * @param  string  $queryText  Raw query string.
	 * @param  array   $queryVector  Query embedding vector. Empty when vector leg is disabled.
	 * @param  array   $options  Per-call retrieval overrides.
	 * @return array  Ranked chunks and pipeline metadata.
	 * @throws RetrievalException  When retrieval configuration is invalid or the pipeline fails.
	 */
	public function retrieve(string $queryText, array $queryVector, array $options = []): array {
		$startedAt = \microtime(true);

		try {
			// -- 1. Resolve and validate runtime options -----------------------
			$queryText = $this->normalizeRequiredQueryText($queryText);
			$resolved = $this->resolveOptions($options);

			$lexicalEnabled = $resolved['lexical'];
			$vectorEnabled = $resolved['vector'];
			$rerankEnabled = $resolved['rerank'];
			$knowledgeBaseIds = $resolved['knowledge_base_ids'];
			$topK = $resolved['top_k'];
			$candidateK = $resolved['candidate_k'];
			$minSimilarity = $resolved['min_similarity'];
			$embeddingProfile = $resolved['embedding_profile'];
			$rrfK = $resolved['rrf_k'];

			if (!$lexicalEnabled && !$vectorEnabled) {
				throw new RetrievalException('At least one retrieval leg must be enabled.');
			}

			if ($vectorEnabled && $queryVector === []) {
				throw new RetrievalException('Vector retrieval is enabled but queryVector is empty.');
			}

			if (!$vectorEnabled && $queryVector !== []) {
				$queryVector = [];
			}

			// -- 2. Expand lexical query via synonyms when applicable --------
			$synonymsUsed = false;
			$lexicalMode = 'natural';
			$lexicalQuery = $queryText;

			if ($lexicalEnabled && $resolved['synonym_expansion'] && $knowledgeBaseIds !== null) {
				$expansion = $this->expandLexicalQuery($queryText, $knowledgeBaseIds);
				$lexicalQuery = $expansion['query'];
				$lexicalMode = $expansion['mode'];
				$synonymsUsed = $expansion['synonyms_used'];
			}

			// -- 3. Run lexical and/or vector retrieval ----------------------
			$lexicalResults = [];
			$vectorResults = [];

			if ($lexicalEnabled) {
				$lexicalResults = $this->chunkRepo->findByLexical(
					$lexicalQuery,
					$candidateK,
					$knowledgeBaseIds,
					$lexicalMode
				);
			}

			if ($vectorEnabled) {
				$vectorResults = $this->embeddingRepo->findByVector(
					$queryVector,
					$embeddingProfile,
					$candidateK,
					$minSimilarity,
					$knowledgeBaseIds
				);
			}

			// -- 4. Fuse or normalize result lists ---------------------------
			$ranked = $this->combineResults($lexicalResults, $vectorResults, $rrfK);

			if (\count($ranked) > $candidateK) {
				$ranked = \array_slice($ranked, 0, $candidateK);
			}

			$fusedCount = \count($ranked);
			$rerankStatus = 'skipped';

			// -- 5. Optional rerank with graceful fallback -------------------
			if ($rerankEnabled && $ranked !== []) {
				try {
					$ranked = $this->rerankResults($queryText, $ranked, $topK, $resolved['rerank_profile']);
					$rerankStatus = 'success';
				} catch (\Throwable $e) {
					$ranked = \array_slice($ranked, 0, $topK);
					$rerankStatus = 'failed';
					$this->logWarning('knowledgebase_retrieval_rerank_failed', $e->getMessage(), [
						'query' => $queryText,
						'knowledge_base_ids' => $knowledgeBaseIds,
					]);
				}
			} else {
				$ranked = \array_slice($ranked, 0, $topK);
			}

			$durationMs = (int)\round((\microtime(true) - $startedAt) * 1000);

			return [
				'chunks' => $ranked,
				'meta' => [
					'strategy' => $this->resolveStrategyLabel($lexicalEnabled, $vectorEnabled),
					'lexical_count' => \count($lexicalResults),
					'vector_count' => \count($vectorResults),
					'fused_count' => $fusedCount,
					'rerank_status' => $rerankStatus,
					'synonyms_used' => $synonymsUsed,
					'duration_ms' => $durationMs,
				],
			];
		} catch (RetrievalException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new RetrievalException('Retrieval pipeline failed: ' . $e->getMessage(), 0, $e);
		}
	}








	// ----------------------------------------------------------------
	// Option resolution
	// ----------------------------------------------------------------

	/**
	 * Resolve effective retrieval options.
	 *
	 * @param  array  $options  Per-call overrides.
	 * @return array  Normalized resolved options.
	 * @throws RetrievalException  When configuration is invalid.
	 */
	private function resolveOptions(array $options): array {
		$cfg = $this->app->cfg->knowledgebase->retrieval;
		$fusionCfg = $cfg->fusion;

		$lexical = $this->normalizeBool($options['lexical'] ?? ($cfg->lexical ?? true), 'lexical');
		$vector = $this->normalizeBool($options['vector'] ?? ($cfg->vector ?? true), 'vector');
		$rerank = $this->normalizeBool($options['rerank'] ?? ($cfg->rerank ?? false), 'rerank');
		$synonymExpansion = $this->normalizeBool(
			$options['synonym_expansion'] ?? ($cfg->synonym_expansion ?? true),
			'synonym_expansion'
		);

		$topK = $this->normalizePositiveInt($options['top_k'] ?? ($cfg->top_k ?? self::DEFAULT_TOP_K), 'top_k');
		$candidateMultiplier = $this->normalizePositiveInt(
			$options['candidate_multiplier'] ?? ($cfg->candidate_multiplier ?? self::DEFAULT_CANDIDATE_MULTIPLIER),
			'candidate_multiplier'
		);
		$minSimilarity = $this->normalizeSimilarity(
			$options['min_similarity'] ?? ($cfg->min_similarity ?? self::DEFAULT_MIN_SIMILARITY),
			'min_similarity'
		);
		$rrfK = $this->normalizePositiveInt($options['rrf_k'] ?? ($fusionCfg->rrf_k ?? self::DEFAULT_RRF_K), 'rrf_k');

		$embeddingProfile = \trim((string)($options['embedding_profile'] ?? ($this->app->cfg->knowledgebase->embedding_profile ?? '')));
		if ($vector && $embeddingProfile === '') {
			throw new RetrievalException('Vector retrieval is enabled but cfg.knowledgebase.embedding_profile is empty.');
		}

		$rerankProfile = $options['rerank_profile'] ?? ($cfg->rerank_profile ?? null);
		if ($rerankProfile === null || \trim((string)$rerankProfile) === '') {
			$rerankProfile = (string)($this->app->cfg->knowledgebase->chat_profile ?? '');
		}
		$rerankProfile = \trim((string)$rerankProfile);

		if ($rerank && $rerankProfile === '') {
			throw new RetrievalException('Rerank is enabled but no rerank/chat profile is configured.');
		}

		$knowledgeBaseIds = $this->normalizeNullableIdList($options['knowledge_base_ids'] ?? null, 'knowledge_base_ids');
		$candidateK = $rerank ? ($topK * $candidateMultiplier) : $topK;

		return [
			'lexical' => $lexical,
			'vector' => $vector,
			'rerank' => $rerank,
			'synonym_expansion' => $synonymExpansion,
			'top_k' => $topK,
			'candidate_multiplier' => $candidateMultiplier,
			'candidate_k' => $candidateK,
			'min_similarity' => $minSimilarity,
			'rrf_k' => $rrfK,
			'knowledge_base_ids' => $knowledgeBaseIds,
			'embedding_profile' => $embeddingProfile,
			'rerank_profile' => $rerankProfile,
		];
	}








	// ----------------------------------------------------------------
	// Synonym expansion
	// ----------------------------------------------------------------

	/**
	 * Expand the lexical query using synonym groups.
	 *
	 * @param  string  $queryText  Raw query text.
	 * @param  array   $knowledgeBaseIds  Targeted knowledge base IDs.
	 * @return array  Expanded query information.
	 */
	private function expandLexicalQuery(string $queryText, array $knowledgeBaseIds): array {
		$normalizedQuery = $this->normalizeSurfaceForm($queryText);
		$groups = $this->synonymRepo->listByKnowledgeBases($knowledgeBaseIds);

		if ($groups === []) {
			return [
				'query' => $queryText,
				'mode' => 'natural',
				'synonyms_used' => false,
			];
		}

		$singleWordMap = [];
		$phraseMap = [];

		foreach ($groups as $group) {
			$terms = $group['terms'] ?? [];
			if (!\is_array($terms) || $terms === []) {
				continue;
			}

			$terms = $this->normalizeTermsForExpansion($terms);
			if ($terms === []) {
				continue;
			}

			foreach ($terms as $term) {
				if (\str_contains($term, ' ')) {
					$phraseMap[$term] = $terms;
				} else {
					$singleWordMap[$term] = $terms;
				}
			}
		}

		$matchedGroups = [];
		$matchedKeys = [];

		foreach ($phraseMap as $phrase => $terms) {
			if (\str_contains($normalizedQuery, $phrase)) {
				$groupKey = \implode("\n", $terms);
				if (!isset($matchedKeys[$groupKey])) {
					$matchedKeys[$groupKey] = true;
					$matchedGroups[] = $terms;
				}
			}
		}

		$tokens = $this->tokenizeNormalizedQuery($normalizedQuery);
		foreach ($tokens as $token) {
			if (!isset($singleWordMap[$token])) {
				continue;
			}
			$terms = $singleWordMap[$token];
			$groupKey = \implode("\n", $terms);
			if (!isset($matchedKeys[$groupKey])) {
				$matchedKeys[$groupKey] = true;
				$matchedGroups[] = $terms;
			}
		}

		if ($matchedGroups === []) {
			return [
				'query' => $queryText,
				'mode' => 'natural',
				'synonyms_used' => false,
			];
		}

		$booleanQuery = $this->buildBooleanQuery($matchedGroups);

		if ($booleanQuery === '') {
			return [
				'query' => $queryText,
				'mode' => 'natural',
				'synonyms_used' => false,
			];
		}

		return [
			'query' => $booleanQuery,
			'mode' => 'boolean',
			'synonyms_used' => true,
		];
	}


	/**
	 * Normalize and bound term lists for expansion.
	 *
	 * @param  array  $terms  Raw group terms.
	 * @return array  Normalized deterministic term list.
	 */
	private function normalizeTermsForExpansion(array $terms): array {
		$normalized = [];

		foreach ($terms as $term) {
			if (!\is_string($term)) {
				continue;
			}
			$term = $this->normalizeSurfaceForm($term);
			$normalized[$term] = true;
		}

		$normalized = \array_keys($normalized);
		\sort($normalized, SORT_STRING);

		if (\count($normalized) > self::MAX_ALTERNATIVES_PER_GROUP) {
			$normalized = \array_slice($normalized, 0, self::MAX_ALTERNATIVES_PER_GROUP);
		}

		return $normalized;
	}


	/**
	 * Build a boolean FULLTEXT query from matched synonym groups.
	 *
	 * @param  array  $matchedGroups  Matched normalized synonym groups.
	 * @return string  Boolean FULLTEXT query.
	 */
	private function buildBooleanQuery(array $matchedGroups): string {
		if (\count($matchedGroups) > self::MAX_EXPANDED_GROUPS) {
			$matchedGroups = \array_slice($matchedGroups, 0, self::MAX_EXPANDED_GROUPS);
			$this->logWarning(
				'knowledgebase_synonym_expansion_truncated',
				'Maximum expanded synonym groups exceeded. Query expansion was truncated.'
			);
		}

		$parts = [];
		$wasLengthTruncated = false;

		foreach ($matchedGroups as $terms) {
			if (!\is_array($terms) || $terms === []) {
				continue;
			}

			$alternatives = [];
			foreach ($terms as $term) {
				if (!\is_string($term)) {
					continue;
				}
				$term = $this->sanitizeBooleanTerm($term);
				if ($term === '') {
					continue;
				}
				$alternatives[] = \str_contains($term, ' ') ? '"' . $term . '"' : $term;
			}

			$alternatives = \array_values(\array_unique($alternatives));
			if ($alternatives === []) {
				continue;
			}

			$part = '+(' . \implode(' ', $alternatives) . ')';

			if ($parts !== []) {
				$candidateQuery = \implode(' ', $parts) . ' ' . $part;
			} else {
				$candidateQuery = $part;
			}

			if (\strlen($candidateQuery) > self::MAX_BOOLEAN_QUERY_LENGTH) {
				$wasLengthTruncated = true;
				break;
			}

			$parts[] = $part;
		}

		$query = \implode(' ', $parts);
		if ($query === '') {
			return '';
		}

		if ($wasLengthTruncated) {
			$this->logWarning(
				'knowledgebase_boolean_query_truncated',
				'Maximum boolean query length exceeded. Query expansion was truncated at the last complete group.'
			);
		}

		return $query;
	}


	/**
	 * Tokenize an already normalized query string.
	 *
	 * @param  string  $normalizedQuery  Normalized query.
	 * @return array  Tokens.
	 */
	private function tokenizeNormalizedQuery(string $normalizedQuery): array {
		if ($normalizedQuery === '') {
			return [];
		}

		$tokens = \preg_split('/[^\p{L}\p{N}]+/u', $normalizedQuery) ?: [];
		$tokens = \array_values(\array_filter($tokens, static fn(string $token): bool => $token !== ''));

		return \array_values(\array_unique($tokens));
	}


	/**
	 * Normalize one synonym/query surface form.
	 *
	 * @param  string  $value  Raw surface form.
	 * @return string  Normalized surface form.
	 */
	private function normalizeSurfaceForm(string $value): string {
		$value = \trim($value);
		$value = \mb_strtolower($value, 'UTF-8');
		$value = \preg_replace('/\s+/u', ' ', $value) ?? '';

		return $value;
	}


	/**
	 * Sanitize one boolean FULLTEXT term.
	 *
	 * @param  string  $term  Raw normalized term.
	 * @return string  Safe term for boolean FULLTEXT construction.
	 */
	private function sanitizeBooleanTerm(string $term): string {
		$term = \preg_replace('/[+\-<>\(\)~*@"]/u', ' ', $term) ?? '';
		$term = \preg_replace('/\s+/u', ' ', $term) ?? '';
		$term = \trim($term);

		return $term;
	}








	// ----------------------------------------------------------------
	// Result combination
	// ----------------------------------------------------------------

	/**
	 * Combine lexical and vector result sets.
	 *
	 * @param  array  $lexicalResults  Lexical result rows.
	 * @param  array  $vectorResults  Vector result rows.
	 * @param  int    $rrfK  Reciprocal Rank Fusion constant.
	 * @return array  Combined ranked rows.
	 */
	private function combineResults(array $lexicalResults, array $vectorResults, int $rrfK): array {
		if ($lexicalResults !== [] && $vectorResults !== []) {
			return ScoreFusion::rrf($lexicalResults, $vectorResults, $rrfK);
		}

		if ($lexicalResults !== []) {
			return ScoreFusion::normalize($lexicalResults, 'score');
		}

		if ($vectorResults !== []) {
			return ScoreFusion::normalize($vectorResults, 'score');
		}

		return [];
	}









	// ----------------------------------------------------------------
	// Rerank
	// ----------------------------------------------------------------

	/**
	 * Rerank candidate chunks with HelloAi.
	 *
	 * @param  string  $queryText  Raw user query.
	 * @param  array   $candidates  Candidate chunk rows.
	 * @param  int     $topK  Final result size.
	 * @param  string  $profile  HelloAi profile for reranking.
	 * @return array  Reranked final rows.
	 */
	private function rerankResults(string $queryText, array $candidates, int $topK, string $profile): array {
		$messages = PromptBuilder::buildRerankPrompt($queryText, $candidates);
		$result = $this->app->helloAi->chat([
			'profile' => $profile,
			'messages' => $messages,
		]);

		$content = $this->extractTextContent($result['message']['content'] ?? null);
		if ($content === '') {
			throw new \RuntimeException('HelloAi rerank response did not contain text content.');
		}

		$scores = $this->parseRerankScores($content);
		if ($scores === []) {
			throw new \RuntimeException('HelloAi rerank response did not contain usable scores.');
		}

		foreach ($candidates as $index => $row) {
			$chunkId = (int)$row['chunk_id'];
			if (!isset($scores[$chunkId])) {
				continue;
			}
			$candidates[$index]['rerank_score'] = $scores[$chunkId];
			$candidates[$index]['score'] = $scores[$chunkId];
		}

		\usort($candidates, static function(array $a, array $b): int {
			$scoreA = isset($a['rerank_score']) ? (float)$a['rerank_score'] : -1.0;
			$scoreB = isset($b['rerank_score']) ? (float)$b['rerank_score'] : -1.0;

			if ($scoreA === $scoreB) {
				$fallbackA = (float)($a['fused_score'] ?? $a['score'] ?? 0.0);
				$fallbackB = (float)($b['fused_score'] ?? $b['score'] ?? 0.0);

				if ($fallbackA === $fallbackB) {
					return ((int)$a['chunk_id']) <=> ((int)$b['chunk_id']);
				}

				return $fallbackA < $fallbackB ? 1 : -1;
			}

			return $scoreA < $scoreB ? 1 : -1;
		});

		return \array_slice($candidates, 0, $topK);
	}


	/**
	 * Parse rerank JSON into a chunk_id => score map.
	 *
	 * @param  string  $content  Model response text.
	 * @return array  Parsed numeric score map.
	 */
	private function parseRerankScores(string $content): array {
		$json = $this->extractJsonBlock($content);
		$decoded = \json_decode($json, true);

		if (!\is_array($decoded)) {
			throw new \RuntimeException('Rerank response is not valid JSON.');
		}

		$scores = [];

		if (isset($decoded['scores']) && \is_array($decoded['scores'])) {
			$scores = $this->parseRerankRows($decoded['scores']);
		} elseif (isset($decoded['results']) && \is_array($decoded['results'])) {
			$scores = $this->parseRerankRows($decoded['results']);
		} elseif (\array_is_list($decoded)) {
			$scores = $this->parseRerankRows($decoded);
		} else {
			foreach ($decoded as $chunkId => $score) {
				if ((! \is_int($chunkId) && !(\is_string($chunkId) && \ctype_digit($chunkId))) || !$this->isNumericFinite($score)) {
					continue;
				}

				$chunkId = (int)$chunkId;
				if ($chunkId <= 0) {
					continue;
				}

				$scores[$chunkId] = (float)$score;
			}
		}

		return $scores;
	}


	/**
	 * Parse a list of rerank rows into a chunk_id => score map.
	 *
	 * @param  array  $rows  Decoded JSON rows.
	 * @return array  Score map.
	 */
	private function parseRerankRows(array $rows): array {
		$scores = [];

		foreach ($rows as $row) {
			if (!\is_array($row)) {
				continue;
			}

			$chunkId = $row['chunk_id'] ?? $row['id'] ?? null;
			$score = $row['score'] ?? $row['relevance'] ?? $row['rating'] ?? null;

			if ((! \is_int($chunkId) && !(\is_string($chunkId) && \ctype_digit($chunkId))) || !$this->isNumericFinite($score)) {
				continue;
			}

			$chunkId = (int)$chunkId;
			if ($chunkId <= 0) {
				continue;
			}

			$scores[$chunkId] = (float)$score;
		}

		return $scores;
	}


	/**
	 * Extract the first plausible JSON block from model text.
	 *
	 * @param  string  $content  Raw model text.
	 * @return string  Extracted JSON text.
	 */
	private function extractJsonBlock(string $content): string {
		$content = \trim($content);
		if ($content === '') {
			throw new \RuntimeException('Rerank response text is empty.');
		}

		if (($content[0] === '{' && \str_ends_with($content, '}')) || ($content[0] === '[' && \str_ends_with($content, ']'))) {
			return $content;
		}

		if (\preg_match('/(\{.*\}|\[.*\])/s', $content, $matches) === 1) {
			return $matches[1];
		}

		throw new \RuntimeException('No JSON block found in rerank response.');
	}


	/**
	 * Extract concatenated text from normalized HelloAi content blocks.
	 *
	 * @param  mixed  $contentBlocks  Message content blocks.
	 * @return string  Concatenated text payload.
	 */
	private function extractTextContent(mixed $contentBlocks): string {
		if (!\is_array($contentBlocks) || $contentBlocks === []) {
			return '';
		}

		$textParts = [];

		foreach ($contentBlocks as $block) {
			if (!\is_array($block)) {
				continue;
			}
			if (($block['type'] ?? null) !== 'text') {
				continue;
			}
			$text = $block['text'] ?? null;
			if (!\is_string($text)) {
				continue;
			}
			$text = \trim($text);
			if ($text !== '') {
				$textParts[] = $text;
			}
		}

		return \trim(\implode("\n", $textParts));
	}






	// ----------------------------------------------------------------
	// Logging
	// ----------------------------------------------------------------

	/**
	 * Write a warning log entry if the log service is available.
	 *
	 * @param  string  $category  Log category.
	 * @param  string  $message  Human-readable message.
	 * @param  array   $context  Optional structured context.
	 * @return void
	 */
	private function logWarning(string $category, string $message, array $context = []): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		try {
			$this->app->log->write('knowledgebase', $category, $message, $context);
		} catch (\Throwable) {
			// Swallow logging failures to keep retrieval deterministic.
		}
	}







	// ----------------------------------------------------------------
	// Normalization helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize and validate the raw query text.
	 *
	 * @param  string  $queryText  Raw query text.
	 * @return string  Trimmed query text.
	 * @throws RetrievalException  When the query is empty.
	 */
	private function normalizeRequiredQueryText(string $queryText): string {
		$queryText = \trim($queryText);
		if ($queryText === '') {
			throw new RetrievalException('Query text must not be empty.');
		}
		return $queryText;
	}


	/**
	 * Normalize a boolean-like value.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return bool  Normalized boolean.
	 * @throws RetrievalException  When the value is not boolean-like.
	 */
	private function normalizeBool(mixed $value, string $field): bool {
		if (\is_bool($value)) {
			return $value;
		}
		if (\is_int($value) && ($value === 0 || $value === 1)) {
			return (bool)$value;
		}
		if (\is_string($value)) {
			$value = \strtolower(\trim($value));
			if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
				return true;
			}
			if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
				return false;
			}
		}
		throw new RetrievalException('Field must be boolean-like: ' . $field);
	}


	/**
	 * Normalize a positive integer.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return int  Normalized positive integer.
	 * @throws RetrievalException  When the value is invalid.
	 */
	private function normalizePositiveInt(mixed $value, string $field): int {
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new RetrievalException('Field must be a positive integer: ' . $field);
		}

		$value = (int)$value;
		if ($value <= 0) {
			throw new RetrievalException('Field must be greater than zero: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a nullable ID list.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return ?array  Normalized unique ID list.
	 * @throws RetrievalException  When the list is invalid.
	 */
	private function normalizeNullableIdList(mixed $value, string $field): ?array {
		if ($value === null) {
			return null;
		}
		if (!\is_array($value)) {
			throw new RetrievalException('Field must be an array or null: ' . $field);
		}
		if ($value === []) {
			throw new RetrievalException('Field must not be an empty array: ' . $field);
		}

		$normalized = [];
		foreach ($value as $id) {
			$normalized[] = $this->normalizePositiveInt($id, $field);
		}

		return \array_values(\array_unique($normalized));
	}


	/**
	 * Normalize a similarity threshold.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return float  Normalized similarity threshold.
	 * @throws RetrievalException  When the value is invalid.
	 */
	private function normalizeSimilarity(mixed $value, string $field): float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new RetrievalException('Field must be numeric: ' . $field);
		}

		$value = (float)$value;
		if (\is_nan($value) || \is_infinite($value)) {
			throw new RetrievalException('Field must be finite: ' . $field);
		}
		if ($value < -1.0 || $value > 1.0) {
			throw new RetrievalException('Field must be between -1.0 and 1.0: ' . $field);
		}

		return $value;
	}


	/**
	 * Determine whether a value is a finite numeric scalar.
	 *
	 * @param  mixed  $value  Input value.
	 * @return bool  True when finite numeric.
	 */
	private function isNumericFinite(mixed $value): bool {
		if (!\is_int($value) && !\is_float($value)) {
			return false;
		}

		$value = (float)$value;
		return !\is_nan($value) && !\is_infinite($value);
	}


	/**
	 * Resolve the retrieval strategy label.
	 *
	 * @param  bool  $lexicalEnabled  Whether lexical retrieval is enabled.
	 * @param  bool  $vectorEnabled  Whether vector retrieval is enabled.
	 * @return string  Strategy label.
	 */
	private function resolveStrategyLabel(bool $lexicalEnabled, bool $vectorEnabled): string {
		if ($lexicalEnabled && $vectorEnabled) {
			return 'hybrid';
		}
		if ($lexicalEnabled) {
			return 'lexical';
		}
		return 'vector';
	}

}
