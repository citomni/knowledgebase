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

namespace CitOmni\KnowledgeBase\Operation;

use CitOmni\Kernel\Operation\BaseOperation;
use CitOmni\KnowledgeBase\Exception\RetrievalException;
use CitOmni\KnowledgeBase\Repository\KnowledgeBaseRepository;
use CitOmni\KnowledgeBase\Repository\QueryLogRepository;
use CitOmni\KnowledgeBase\Repository\UnitRepository;
use CitOmni\KnowledgeBase\Util\PromptBuilder;

/**
 * Answer a question against one or more knowledge bases.
 *
 * Flow:
 *   1. Validate input
 *   2. Resolve answer language (per-call -> cfg -> knowledge base -> locale)
 *   3. Validate multi-base language consistency (fail-fast if mixed)
 *   4. Embed the question via VectorEmbedder (when vector search is active)
 *   5. Retrieve ranked chunks via Retriever service
 *   6. Apply min_score (post-fusion)
 *   7. Evaluate min_chunks threshold
 *   8. Build prompt via PromptBuilder
 *   9. Chat via HelloAi
 *  10. Return answer + status + sources + retrieval_meta + usage
 *
 * Notes:
 * - Query logging is written only when exactly one effective knowledge base is
 *   targeted, because know_query_log stores one knowledge_base_id per row.
 * - The Retriever owns lexical/vector retrieval, synonym expansion, score
 *   fusion, and rerank. This Operation owns policy and answer orchestration.
 *
 * @param array $input Query input payload.
 * @return array Query result payload.
 * @throws RetrievalException When validation or orchestration fails.
 */
final class QueryKnowledgeBase extends BaseOperation {

	/** @var int Default number of final chunks requested from the retrieval pipeline. */
	private const DEFAULT_TOP_K = 5;

	/** @var float Default post-fusion minimum score threshold for keeping retrieved chunks. */
	private const DEFAULT_MIN_SCORE = 0.0;

	/** @var int Default minimum number of qualifying chunks required before answering. */
	private const DEFAULT_MIN_CHUNKS = 1;

	/** @var int Default token budget reserved for prompt-visible chunk context. */
	private const DEFAULT_MAX_CONTEXT_TOKENS = 4000;

	/** @var string Extra system instruction used when low-context answering is explicitly allowed. */
	private const LOW_CONTEXT_INSTRUCTION = 'The retrieved context may be insufficient. State clearly if you cannot answer confidently.';

	/** @var KnowledgeBaseRepository Repository for loading and validating targeted knowledge bases. */
	private KnowledgeBaseRepository $knowledgeBaseRepo;

	/** @var QueryLogRepository Repository for optional query-log writes. */
	private QueryLogRepository $queryLogRepo;

	/** @var UnitRepository Repository for enriching units with document-rooted path identifiers. */
	private UnitRepository $unitRepo;


	/**
	 * Initialize repository dependencies.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->knowledgeBaseRepo = new KnowledgeBaseRepository($this->app);
		$this->queryLogRepo = new QueryLogRepository($this->app);
		$this->unitRepo = new UnitRepository($this->app);
	}




	/**
	 * Execute the knowledge-base query workflow.
	 *
	 * @param array $input Query input payload.
	 * @return array Query result payload.
	 * @throws RetrievalException When validation or orchestration fails.
	 */
	public function execute(array $input): array {
		try {
			// -- 1. Validate and resolve request ------------------------------
			$question = $this->requireNonEmptyString($input, 'question');
			$requestedKnowledgeBaseIds = $this->resolveKnowledgeBaseIds($input['knowledge_base_ids'] ?? null);
			$knowledgeBases = $this->loadKnowledgeBases($requestedKnowledgeBaseIds);
			$effectiveKnowledgeBaseIds = $this->extractKnowledgeBaseIds($knowledgeBases);
			$resolvedLanguage = $this->resolveLanguage($input, $knowledgeBases);
			$topK = $this->resolveTopK($input);
			$minScore = $this->resolveMinScore($input);
			$minChunks = $this->resolveMinChunks($input);
			$allowLowContext = $this->resolveAllowLowContext($input);
			$systemTemplate = $this->resolveSystemTemplate($input);
			$maxContextTokens = $this->resolveMaxContextTokens($input);

			// -- 2. Resolve retrieval flags ----------------------------------
			$retrievalCfg = $this->app->cfg->knowledgebase->retrieval;
			$lexicalEnabled = $this->normalizeBool($input['lexical'] ?? ($retrievalCfg->lexical ?? true), 'lexical');
			$vectorEnabled = $this->normalizeBool($input['vector'] ?? ($retrievalCfg->vector ?? true), 'vector');
			$rerankEnabled = $this->normalizeBool($input['rerank'] ?? ($retrievalCfg->rerank ?? false), 'rerank');
			$synonymExpansionEnabled = $this->normalizeBool(
				$input['synonym_expansion'] ?? ($retrievalCfg->synonym_expansion ?? true),
				'synonym_expansion'
			);

			// -- 3. Embed question when vector leg is active -----------------
			$queryVector = [];
			if ($vectorEnabled) {
				$queryVector = $this->embedQuestion($question);
			}

			// -- 4. Retrieve ranked chunks -----------------------------------
			$retrieval = $this->app->retriever->retrieve($question, $queryVector, [
				'knowledge_base_ids' => $effectiveKnowledgeBaseIds,
				'top_k' => $topK,
				'lexical' => $lexicalEnabled,
				'vector' => $vectorEnabled,
				'rerank' => $rerankEnabled,
				'synonym_expansion' => $synonymExpansionEnabled,
				'candidate_multiplier' => $input['candidate_multiplier'] ?? ($retrievalCfg->candidate_multiplier ?? null),
				'min_similarity' => $input['min_similarity'] ?? ($retrievalCfg->min_similarity ?? null),
				'rrf_k' => $input['rrf_k'] ?? (($retrievalCfg->fusion->rrf_k) ?? null),
				'rerank_profile' => $input['rerank_profile'] ?? ($retrievalCfg->rerank_profile ?? null),
			]);

			$sources = $this->filterSourcesByMinScore($retrieval['chunks'] ?? [], $minScore);
			$sources = $this->attachDocPathIdentifiersToSources($sources);
			$retrievalMeta = $this->normalizeRetrievalMeta($retrieval['meta'] ?? []);

			// -- 5. Enforce minimum context policy ---------------------------
			if (\count($sources) < $minChunks && !$allowLowContext) {
				$this->logQuery($effectiveKnowledgeBaseIds, $question, $topK, $sources, $retrievalMeta);

				return [
					'answer' => null,
					'status' => 'insufficient_context',
					'sources' => $sources,
					'retrieval_meta' => $retrievalMeta,
					'usage' => null,
				];
			}

			// -- 6. Build prompt ---------------------------------------------
			$effectiveSystemTemplate = $systemTemplate;
			if (\count($sources) < $minChunks && $allowLowContext) {
				$effectiveSystemTemplate = $this->prependLowContextInstruction($effectiveSystemTemplate);
			}

			$messages = PromptBuilder::build(
				$question,
				$sources,
				$effectiveSystemTemplate,
				$resolvedLanguage,
				$maxContextTokens
			);

			// -- 7. Chat via HelloAi -----------------------------------------
			$chatResult = $this->app->helloAi->chat([
				'profile' => $this->resolveChatProfile(),
				'messages' => $messages,
			]);

			$message = $chatResult['message'] ?? null;
			if (!\is_array($message)) {
				throw new RetrievalException('HelloAi response did not contain a normalized message array.');
			}

			$role = $message['role'] ?? null;
			if (!\is_string($role) || $role === '') {
				throw new RetrievalException('HelloAi response message did not contain a valid role.');
			}

			$answer = $this->extractAssistantText($message['content'] ?? null);
			if ($answer === '') {
				throw new RetrievalException('HelloAi response did not contain assistant text content.');
			}

			$usage = $this->normalizeUsage($chatResult['usage'] ?? null);

			// -- 8. Optionally log query -------------------------------------
			$this->logQuery($effectiveKnowledgeBaseIds, $question, $topK, $sources, $retrievalMeta);

			// -- 9. Return final result --------------------------------------
			return [
				'answer' => $answer,
				'status' => 'ok',
				'sources' => $sources,
				'retrieval_meta' => $retrievalMeta,
				'usage' => $usage,
			];
		} catch (RetrievalException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new RetrievalException('Query operation failed: ' . $e->getMessage(), 0, $e);
		}
	}








	// ----------------------------------------------------------------
	// Knowledge base resolution
	// ----------------------------------------------------------------

	/**
	 * Resolve and validate requested knowledge base ids.
	 *
	 * @param mixed $value Raw id list or null.
	 * @return ?array Normalized id list or null.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveKnowledgeBaseIds(mixed $value): ?array {
		if ($value === null) {
			return null;
		}
		if (!\is_array($value)) {
			throw new RetrievalException('knowledge_base_ids must be an array or null.');
		}
		if ($value === []) {
			throw new RetrievalException('knowledge_base_ids must not be an empty array.');
		}

		$normalized = [];
		foreach ($value as $id) {
			$normalized[] = $this->normalizePositiveInt($id, 'knowledge_base_ids');
		}

		return \array_values(\array_unique($normalized));
	}


	/**
	 * Load targeted knowledge bases.
	 *
	 * When no explicit ids are provided, all active knowledge bases are used.
	 *
	 * @param ?array $knowledgeBaseIds Explicit ids or null.
	 * @return array Loaded knowledge base rows.
	 * @throws RetrievalException When no usable knowledge bases are available.
	 */
	private function loadKnowledgeBases(?array $knowledgeBaseIds): array {
		if ($knowledgeBaseIds === null) {
			$knowledgeBases = $this->knowledgeBaseRepo->listAll('active');
			if ($knowledgeBases === []) {
				throw new RetrievalException('No active knowledge bases are available.');
			}
			return $knowledgeBases;
		}

		$knowledgeBases = [];
		foreach ($knowledgeBaseIds as $knowledgeBaseId) {
			$row = $this->knowledgeBaseRepo->findById($knowledgeBaseId);
			if ($row === null) {
				throw new RetrievalException('Knowledge base not found: ' . $knowledgeBaseId);
			}
			$knowledgeBases[] = $row;
		}

		return $knowledgeBases;
	}


	/**
	 * Extract normalized ids from loaded knowledge base rows.
	 *
	 * @param array $knowledgeBases Loaded knowledge base rows.
	 * @return array Normalized id list.
	 * @throws RetrievalException When a row is malformed.
	 */
	private function extractKnowledgeBaseIds(array $knowledgeBases): array {
		$ids = [];
		foreach ($knowledgeBases as $knowledgeBase) {
			if (!\is_array($knowledgeBase) || !isset($knowledgeBase['id'])) {
				throw new RetrievalException('Knowledge base row is malformed.');
			}
			$ids[] = $this->normalizePositiveInt($knowledgeBase['id'], 'knowledge_base_id');
		}
		return \array_values(\array_unique($ids));
	}


	/**
	 * Resolve the effective answer language.
	 *
	 * Precedence:
	 * - per-call language
	 * - cfg.knowledgebase.prompt.language
	 * - shared knowledge-base language
	 * - cfg.locale.language
	 *
	 * @param array $input Query input.
	 * @param array $knowledgeBases Loaded knowledge base rows.
	 * @return ?string Resolved language.
	 * @throws RetrievalException When multi-base languages conflict without override.
	 */
	private function resolveLanguage(array $input, array $knowledgeBases): ?string {
		if (\array_key_exists('language', $input) && $input['language'] !== null) {
			return $this->normalizeLanguage($input['language'], 'language');
		}

		$cfgLanguage = $this->app->cfg->knowledgebase->prompt->language ?? null;
		if ($cfgLanguage !== null && \trim((string)$cfgLanguage) !== '') {
			return $this->normalizeLanguage($cfgLanguage, 'cfg.knowledgebase.prompt.language');
		}

		$languages = [];
		foreach ($knowledgeBases as $knowledgeBase) {
			$language = $knowledgeBase['language'] ?? null;
			if (!\is_string($language)) {
				continue;
			}
			$language = \trim($language);
			if ($language === '') {
				continue;
			}
			$languages[$language] = true;
		}
		$languages = \array_keys($languages);

		if (\count($languages) > 1) {
			throw new RetrievalException(
				'Targeted knowledge bases use mixed languages. Provide an explicit per-call language override.'
			);
		}
		if (isset($languages[0])) {
			return $languages[0];
		}

		$localeLanguage = $this->app->cfg->locale->language ?? null;
		if ($localeLanguage === null || \trim((string)$localeLanguage) === '') {
			return null;
		}

		return $this->normalizeLanguage($localeLanguage, 'cfg.locale.language');
	}








	// ----------------------------------------------------------------
	// Config resolution
	// ----------------------------------------------------------------

	/**
	 * Resolve top_k.
	 *
	 * @param array $input Query input.
	 * @return int Resolved top_k.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveTopK(array $input): int {
		$value = $input['top_k'] ?? ($this->app->cfg->knowledgebase->retrieval->top_k ?? self::DEFAULT_TOP_K);
		return $this->normalizePositiveInt($value, 'top_k');
	}


	/**
	 * Resolve min_score.
	 *
	 * @param array $input Query input.
	 * @return float Resolved min_score.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveMinScore(array $input): float {
		$value = $input['min_score'] ?? ($this->app->cfg->knowledgebase->retrieval->min_score ?? self::DEFAULT_MIN_SCORE);
		return $this->normalizeScore($value, 'min_score');
	}


	/**
	 * Resolve min_chunks.
	 *
	 * @param array $input Query input.
	 * @return int Resolved min_chunks.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveMinChunks(array $input): int {
		$value = $input['min_chunks'] ?? ($this->app->cfg->knowledgebase->retrieval->min_chunks ?? self::DEFAULT_MIN_CHUNKS);
		return $this->normalizePositiveInt($value, 'min_chunks');
	}


	/**
	 * Resolve allow_low_context.
	 *
	 * @param array $input Query input.
	 * @return bool Resolved flag.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveAllowLowContext(array $input): bool {
		$value = $input['allow_low_context'] ?? ($this->app->cfg->knowledgebase->retrieval->allow_low_context ?? false);
		return $this->normalizeBool($value, 'allow_low_context');
	}


	/**
	 * Resolve system prompt template.
	 *
	 * @param array $input Query input.
	 * @return ?string Resolved system template.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveSystemTemplate(array $input): ?string {
		$value = $input['system_prompt'] ?? ($this->app->cfg->knowledgebase->prompt->system_template ?? null);
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			throw new RetrievalException('system_prompt must be a string or null.');
		}
		$value = \trim($value);
		return $value === '' ? null : $value;
	}


	/**
	 * Resolve max_context_tokens.
	 *
	 * @param array $input Query input.
	 * @return int Resolved context token budget.
	 * @throws RetrievalException When invalid.
	 */
	private function resolveMaxContextTokens(array $input): int {
		$value = $input['max_context_tokens'] ?? ($this->app->cfg->knowledgebase->prompt->max_context_tokens ?? self::DEFAULT_MAX_CONTEXT_TOKENS);
		return $this->normalizePositiveInt($value, 'max_context_tokens');
	}


	/**
	 * Resolve chat profile id.
	 *
	 * @return string Chat profile id.
	 * @throws RetrievalException When missing.
	 */
	private function resolveChatProfile(): string {
		$profile = \trim((string)($this->app->cfg->knowledgebase->chat_profile ?? ''));
		if ($profile === '') {
			throw new RetrievalException('cfg.knowledgebase.chat_profile must not be empty.');
		}
		return $profile;
	}


	/**
	 * Resolve embedding profile id.
	 *
	 * @return string Embedding profile id.
	 * @throws RetrievalException When missing.
	 */
	private function resolveEmbeddingProfile(): string {
		$profile = \trim((string)($this->app->cfg->knowledgebase->embedding_profile ?? ''));
		if ($profile === '') {
			throw new RetrievalException('cfg.knowledgebase.embedding_profile must not be empty.');
		}
		return $profile;
	}









	// ----------------------------------------------------------------
	// Embedding and prompt helpers
	// ----------------------------------------------------------------

	/**
	 * Embed the question into a query vector.
	 *
	 * @param string $question User question.
	 * @return array Query vector.
	 * @throws RetrievalException When embedding output is invalid.
	 */
	private function embedQuestion(string $question): array {
		$result = $this->app->vectorEmbedder->embed([
			'profile' => $this->resolveEmbeddingProfile(),
			'items' => [
				[
					'type' => 'text',
					'text' => $question,
				],
			],
		]);

		$vectors = $result['vectors'] ?? null;
		if (!\is_array($vectors) || !isset($vectors[0])) {
			throw new RetrievalException('VectorEmbedder did not return the first embedding row for the query item.');
		}

		$row = $vectors[0];
		if (!\is_array($row)) {
			throw new RetrievalException('VectorEmbedder returned a malformed embedding row for the query item.');
		}

		$vector = $row['vector'] ?? null;
		if (!\is_array($vector) || $vector === []) {
			throw new RetrievalException('VectorEmbedder did not return a non-empty query vector.');
		}

		$normalized = [];
		foreach ($vector as $index => $value) {
			if (!\is_int($value) && !\is_float($value)) {
				throw new RetrievalException('VectorEmbedder returned a non-numeric query vector element at index ' . $index . '.');
			}
			$floatValue = (float)$value;
			if (\is_nan($floatValue) || \is_infinite($floatValue)) {
				throw new RetrievalException('VectorEmbedder returned a non-finite query vector element at index ' . $index . '.');
			}
			$normalized[] = $floatValue;
		}

		if ($normalized === []) {
			throw new RetrievalException('VectorEmbedder returned an empty query vector.');
		}

		return $normalized;
	}


	/**
	 * Prepend the low-context instruction to the system template.
	 *
	 * @param ?string $systemTemplate Existing system template.
	 * @return string Effective system template.
	 */
	private function prependLowContextInstruction(?string $systemTemplate): string {
		if ($systemTemplate === null || \trim($systemTemplate) === '') {
			return self::LOW_CONTEXT_INSTRUCTION;
		}

		return self::LOW_CONTEXT_INSTRUCTION . "\n\n" . \trim($systemTemplate);
	}


	/**
	 * Extract assistant text from HelloAi content blocks.
	 *
	 * @param mixed $contentBlocks Normalized content blocks.
	 * @return string Extracted text.
	 */
	private function extractAssistantText(mixed $contentBlocks): string {
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
	// Result shaping
	// ----------------------------------------------------------------

	/**
	 * Apply post-fusion min_score filtering.
	 *
	 * @param array $sources Ranked sources.
	 * @param float $minScore Minimum allowed score.
	 * @return array Filtered sources.
	 * @throws RetrievalException When a source row is invalid.
	 */
	private function filterSourcesByMinScore(array $sources, float $minScore): array {
		if ($sources === []) {
			return [];
		}

		$filtered = [];
		foreach ($sources as $source) {
			if (!\is_array($source)) {
				throw new RetrievalException('Retriever returned an invalid source row.');
			}

			$score = $source['score'] ?? null;
			if (!\is_int($score) && !\is_float($score)) {
				throw new RetrievalException('Retriever returned a source without a numeric score.');
			}

			$score = (float)$score;
			if (\is_nan($score) || \is_infinite($score)) {
				throw new RetrievalException('Retriever returned a source with a non-finite score.');
			}

			if ($score < $minScore) {
				continue;
			}

			$source['score'] = $score;
			$filtered[] = $source;
		}

		return $filtered;
	}


	/**
	 * Normalize retrieval metadata into the public return shape.
	 *
	 * @param array $meta Raw retriever meta.
	 * @return array Normalized retrieval meta.
	 */
	private function normalizeRetrievalMeta(array $meta): array {
		return [
			'strategy' => (string)($meta['strategy'] ?? ''),
			'lexical_count' => (int)($meta['lexical_count'] ?? 0),
			'vector_count' => (int)($meta['vector_count'] ?? 0),
			'fused_count' => (int)($meta['fused_count'] ?? 0),
			'rerank_status' => (string)($meta['rerank_status'] ?? 'skipped'),
			'synonyms_used' => (bool)($meta['synonyms_used'] ?? false),
			'duration_ms' => (int)($meta['duration_ms'] ?? 0),
		];
	}


	/**
	 * Normalize HelloAi usage payload.
	 *
	 * @param mixed $usage Raw usage payload.
	 * @return ?array Usage payload or null.
	 */
	private function normalizeUsage(mixed $usage): ?array {
		return \is_array($usage) ? $usage : null;
	}


	/**
	 * Attach document-rooted path identifiers to source rows when unit metadata is available.
	 *
	 * Added fields per source row:
	 * - unit_id
	 * - unit_identifier
	 * - doc_path_identifier
	 *
	 * @param array $sources Ranked source rows.
	 * @return array Enriched source rows.
	 */
	private function attachDocPathIdentifiersToSources(array $sources): array {
		if ($sources === []) {
			return [];
		}

		$unitRows = [];
		$sourceIndexes = [];

		foreach ($sources as $index => $source) {
			if (!\is_array($source)) {
				continue;
			}

			$unitId = $source['unit_id'] ?? null;
			$documentId = $source['document_id'] ?? null;
			$path = $source['unit_path'] ?? null;
			$identifier = $source['unit_identifier'] ?? null;

			if (!\is_int($unitId) || $unitId <= 0) {
				continue;
			}
			if (!\is_int($documentId) || $documentId <= 0) {
				continue;
			}
			if (!\is_string($path) || $path === '') {
				continue;
			}

			$unitRows[] = [
				'id' => $unitId,
				'document_id' => $documentId,
				'identifier' => \is_string($identifier) && \trim($identifier) !== '' ? \trim($identifier) : null,
				'path' => $path,
			];

			$sourceIndexes[$unitId] = $index;
		}

		if ($unitRows === []) {
			return $sources;
		}

		$enrichedUnits = $this->unitRepo->attachDocPathIdentifiers($unitRows);

		foreach ($enrichedUnits as $unitRow) {
			if (!\is_array($unitRow)) {
				continue;
			}

			$unitId = $unitRow['unit_id'] ?? null;
			if (!\is_int($unitId) || !isset($sourceIndexes[$unitId])) {
				continue;
			}

			$sourceIndex = $sourceIndexes[$unitId];

			if (\array_key_exists('unit_id', $unitRow)) {
				$sources[$sourceIndex]['unit_id'] = $unitRow['unit_id'];
			}

			if (\array_key_exists('unit_identifier', $unitRow)) {
				$sources[$sourceIndex]['unit_identifier'] = $unitRow['unit_identifier'];
			}

			if (\array_key_exists('doc_path_identifier', $unitRow)) {
				$sources[$sourceIndex]['doc_path_identifier'] = $unitRow['doc_path_identifier'];
			}
		}

		return $sources;
	}








	// ----------------------------------------------------------------
	// Query logging
	// ----------------------------------------------------------------

	/**
	 * Optionally write one query-log entry.
	 *
	 * Logging is skipped when the effective scope contains zero or multiple
	 * knowledge bases, because the schema stores only one knowledge_base_id.
	 *
	 * @param array $effectiveKnowledgeBaseIds Effective targeted ids.
	 * @param string $question Query text.
	 * @param int $topK Resolved top_k.
	 * @param array $sources Final sources.
	 * @param array $retrievalMeta Final retrieval meta.
	 * @return void
	 */
	private function logQuery(array $effectiveKnowledgeBaseIds, string $question, int $topK, array $sources, array $retrievalMeta): void {
		$enabled = (bool)($this->app->cfg->knowledgebase->query_log->enabled ?? false);
		if (!$enabled) {
			return;
		}
		if (\count($effectiveKnowledgeBaseIds) !== 1) {
			return;
		}

		try {
			$topChunkIds = [];
			foreach ($sources as $source) {
				if (\is_array($source) && isset($source['chunk_id'])) {
					$topChunkIds[] = (int)$source['chunk_id'];
				}
			}

			$this->queryLogRepo->write([
				'knowledge_base_id' => $effectiveKnowledgeBaseIds[0],
				'query_text' => $question,
				'strategy' => $retrievalMeta['strategy'] ?? 'hybrid',
				'chunk_limit' => $topK,
				'results_count' => \count($sources),
				'top_chunk_ids' => $topChunkIds,
				'reranked' => (($retrievalMeta['rerank_status'] ?? 'skipped') === 'success'),
				'duration_ms' => $retrievalMeta['duration_ms'] ?? null,
				'metadata' => [
					'synonyms_used' => (bool)($retrievalMeta['synonyms_used'] ?? false),
				],
			]);
		} catch (\Throwable $e) {
			$this->logWarning('knowledgebase_query_log_failed', $e->getMessage(), [
				'knowledge_base_ids' => $effectiveKnowledgeBaseIds,
			]);
		}
	}


	/**
	 * Write a warning log entry when the log service exists.
	 *
	 * @param string $category Log category.
	 * @param string $message Log message.
	 * @param array $context Log context.
	 * @return void
	 */
	private function logWarning(string $category, string $message, array $context = []): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		try {
			$this->app->log->write('knowledgebase', $category, $message, $context);
		} catch (\Throwable) {
		}
	}









	// ----------------------------------------------------------------
	// Validation helpers
	// ----------------------------------------------------------------

	/**
	 * Require a non-empty string field from input.
	 *
	 * @param array $input Input payload.
	 * @param string $field Field name.
	 * @return string Normalized string.
	 * @throws RetrievalException When missing or invalid.
	 */
	private function requireNonEmptyString(array $input, string $field): string {
		if (!\array_key_exists($field, $input)) {
			throw new RetrievalException('Missing required field: ' . $field);
		}
		if (!\is_string($input[$field])) {
			throw new RetrievalException('Field must be a string: ' . $field);
		}

		$value = \trim($input[$field]);
		if ($value === '') {
			throw new RetrievalException('Field must not be empty: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a positive integer.
	 *
	 * @param mixed $value Input value.
	 * @param string $field Field name.
	 * @return int Normalized integer.
	 * @throws RetrievalException When invalid.
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
	 * Normalize a language string.
	 *
	 * @param mixed $value Input value.
	 * @param string $field Field name.
	 * @return string Normalized language string.
	 * @throws RetrievalException When invalid.
	 */
	private function normalizeLanguage(mixed $value, string $field): string {
		if (!\is_string($value)) {
			throw new RetrievalException('Field must be a string: ' . $field);
		}

		$value = \trim($value);
		if ($value === '') {
			throw new RetrievalException('Field must not be empty: ' . $field);
		}
		if (\mb_strlen($value, 'UTF-8') > 20) {
			throw new RetrievalException('Field exceeds max length: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a numeric score.
	 *
	 * @param mixed $value Input value.
	 * @param string $field Field name.
	 * @return float Normalized score.
	 * @throws RetrievalException When invalid.
	 */
	private function normalizeScore(mixed $value, string $field): float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new RetrievalException('Field must be numeric: ' . $field);
		}

		$value = (float)$value;
		if (\is_nan($value) || \is_infinite($value)) {
			throw new RetrievalException('Field must be finite: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a boolean-like value.
	 *
	 * @param mixed $value Input value.
	 * @param string $field Field name.
	 * @return bool Normalized boolean.
	 * @throws RetrievalException When invalid.
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


}
