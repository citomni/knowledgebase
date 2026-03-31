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
use CitOmni\KnowledgeBase\Exception\IngestException;
use CitOmni\KnowledgeBase\Repository\ChunkRepository;
use CitOmni\KnowledgeBase\Repository\DocumentRepository;
use CitOmni\KnowledgeBase\Repository\EmbeddingRepository;
use CitOmni\KnowledgeBase\Repository\KnowledgeBaseRepository;
use CitOmni\KnowledgeBase\Repository\UnitRepository;
use CitOmni\KnowledgeBase\Util\Chunker;

/**
 * Ingest a parsed document into the knowledge base.
 *
 * Receives pre-parsed structural data (units with their text content).
 * Parsing raw source formats (HTML, PDF, plain text) into unit structures
 * is an app-side concern - the package is format-agnostic.
 *
 * Behavior:
 * - Idempotent: matching content_hash for the same slug = skip (no-op).
 *   When content is unchanged but document-level metadata differs (title,
 *   effective_date, etc.), only the document row is updated - units, chunks,
 *   and embeddings are untouched.
 * - Replace-by-slug: hash mismatch = delete old + insert new in one transaction.
 *   The delete + insert of document/units/chunks is atomic. Embedding happens
 *   outside the transaction - if embedding fails, the document is committed
 *   with chunks (usable for lexical search) and the operation returns
 *   status 'partial'. Use EmbeddingRepository::countMissing() for observability
 *   and a re-embed command for recovery.
 * - Embedding is conditional on cfg.knowledgebase.retrieval.vector being true.
 *   When disabled, the document is fully usable for lexical search without
 *   embeddings.
 *
 * The `text` field is the canonical source input used to compute content_hash
 * for idempotent re-import. When `text` is provided and the hash matches an
 * existing document with the same slug, units/chunks/embeddings are not
 * re-generated. When `text` is null, content_hash is not computed and
 * idempotent skip is disabled - every call produces a full re-ingest.
 *
 * Return shape:
 *   - status 'ok':      Operation completed successfully. reason is null for
 *                        full re-ingest, 'metadata_updated' when only the
 *                        document row was updated (content hash matched).
 *   - status 'partial': Document/units/chunks committed, embedding failed.
 *                        The document exists and is usable for lexical search.
 *                        The 'error' field describes the embedding failure.
 *                        Previously committed embedding batches remain.
 *   - status 'skipped': Pure no-op. Content hash matched, no fields changed.
 *                        reason is always 'content_unchanged'.
 *
 * reason is null at normal success. error is null unless status is 'partial'.
 * embedding_model is included in all return paths for recovery/observability.
 *
 * @see §10 (ingest replacement semantics)
 * @see §14.7 (operation contract)
 */
final class IngestDocument extends BaseOperation {

	// ----------------------------------------------------------------
	// Ingest state - reset per execute() call
	// ----------------------------------------------------------------

	private UnitRepository $unitRepo;
	private ChunkRepository $chunkRepo;
	private string $chunkerStrategy;
	private int $chunkerMaxTokens;
	private int $chunkerOverlapTokens;
	private int $unitsCount = 0;
	private int $chunksCount = 0;
	private int $embeddingsCount = 0;
	private array $embeddingQueue = [];




	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Execute the ingest pipeline.
	 *
	 * @param  array  $input  Required: knowledge_base_id, slug, title, source_type,
	 *                        units (non-empty array of unit structures).
	 *                        Optional: text (canonical source for content_hash),
	 *                        language, status, source_ref, effective_date,
	 *                        version_label, metadata.
	 * @return array  ['document_id' => int, 'units_count' => int,
	 *                 'chunks_count' => int, 'embeddings_count' => int,
	 *                 'embedding_model' => ?string,
	 *                 'status' => 'ok'|'partial'|'skipped',
	 *                 'reason' => ?string, 'error' => ?string]
	 * @throws IngestException  On input validation or pipeline-level error.
	 * @throws \InvalidArgumentException  When unit/chunk data violates repository
	 *                                    contracts (programming error in caller).
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  On database failure.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  On connection failure.
	 */
	public function execute(array $input): array {

		// -- 1. Validate input ------------------------------------------------

		$knowledgeBaseId = $this->requirePositiveInt($input, 'knowledge_base_id');
		$slug = $this->requireNonEmptyString($input, 'slug');
		$title = $this->requireNonEmptyString($input, 'title');
		$sourceType = $this->requireNonEmptyString($input, 'source_type');

		$units = $input['units'] ?? [];
		if (!\is_array($units)) {
			throw new IngestException('units must be an array.');
		}
		if ($units === []) {
			throw new IngestException('At least one unit is required.');
		}

		$text = $input['text'] ?? null;
		if ($text !== null && !\is_string($text)) {
			throw new IngestException('text must be a string or null.');
		}

		// Verify knowledge base exists
		$kbRepo = new KnowledgeBaseRepository($this->app);
		$knowledgeBase = $kbRepo->findById($knowledgeBaseId);
		if ($knowledgeBase === null) {
			throw new IngestException('Knowledge base not found: ' . $knowledgeBaseId);
		}

		// -- 2. Resolve cfg ---------------------------------------------------

		$chunkerCfg = $this->app->cfg->knowledgebase->chunker;
		$this->chunkerStrategy = (string)($chunkerCfg->strategy ?? 'fixed_size');
		$this->chunkerMaxTokens = (int)($chunkerCfg->max_tokens ?? 512);
		$this->chunkerOverlapTokens = (int)($chunkerCfg->overlap_tokens ?? 50);

		$vectorEnabled = (bool)($this->app->cfg->knowledgebase->retrieval->vector ?? true);
		$embeddingProfile = (string)($this->app->cfg->knowledgebase->embedding_profile ?? '');

		// Requires Registry baseline: cfg.knowledgebase.ingest.embedding_batch_size
		$embeddingBatchSize = (int)($this->app->cfg->knowledgebase->ingest->embedding_batch_size ?? 100);

		// Fail-fast: vector enabled but no embedding profile configured
		if ($vectorEnabled && $embeddingProfile === '') {
			throw new IngestException(
				'cfg.knowledgebase.retrieval.vector is true but '
				. 'cfg.knowledgebase.embedding_profile is empty.'
			);
		}

		if ($embeddingBatchSize < 1) {
			throw new IngestException(
				'cfg.knowledgebase.ingest.embedding_batch_size must be at least 1, got: '
				. $embeddingBatchSize
			);
		}

		// -- 3. Compute content_hash ------------------------------------------

		$contentHash = null;
		if ($text !== null && $text !== '') {
			$contentHash = \hash('sha256', $text);
		}

		// -- 4. Check for idempotent skip -------------------------------------

		$documentRepo = new DocumentRepository($this->app);
		$existing = $documentRepo->findBySlug($knowledgeBaseId, $slug);

		if ($existing !== null && $contentHash !== null && $existing['content_hash'] === $contentHash) {
			return $this->handleContentUnchanged($existing, $input, $knowledgeBase, $documentRepo);
		}

		// -- 5. Prepare document payload --------------------------------------

		$documentData = $this->buildDocumentPayload(
			$input, $knowledgeBaseId, $slug, $title, $sourceType, $knowledgeBase, $contentHash
		);

		// -- 6. Reset ingest state --------------------------------------------

		$this->unitRepo = new UnitRepository($this->app);
		$this->chunkRepo = new ChunkRepository($this->app);
		$this->resetState();

		// -- 7. Transaction: delete old + insert document + units + chunks -----
		// Embedding happens outside this transaction (step 8). If embedding
		// fails, the document is committed with chunks and usable for lexical
		// search. There is no visible gap where the document disappears during
		// re-ingest - the delete + insert is atomic.

		$documentId = $this->app->db->transaction(function() use ($existing, $documentRepo, $documentData, $units): int {
			if ($existing !== null) {
				$documentRepo->delete($existing['id']);
			}
			$documentId = $documentRepo->insert($documentData);
			$this->insertUnitsRecursive($units, $documentId, null, 'root');
			return $documentId;
		});

		// -- 8. Embed chunks (outside transaction) ----------------------------
		// Embedding is an external API call that can take seconds to minutes
		// for large documents. Holding a transaction open for that duration
		// would risk lock timeouts and block concurrent operations.
		//
		// If embedding fails, the document is already committed with chunks.
		// This is a valid state: chunks participate in lexical search, and
		// EmbeddingRepository::countMissing() provides observability.
		// Previously committed embedding batches remain in the database.

		$embeddingError = null;

		if ($vectorEnabled && $this->embeddingQueue !== []) {
			$embeddingRepo = new EmbeddingRepository($this->app);
			try {
				$this->embedInBatches($this->embeddingQueue, $embeddingProfile, $embeddingBatchSize, $embeddingRepo);
			} catch (IngestException $e) {
				// Contract violation (e.g. vector count mismatch) - programming
				// error, not a recoverable external failure. Bubble up.
				throw $e;
			} catch (\Exception $e) {
				// External/provider failure (timeout, API error, network) -
				// recoverable. Report as partial completion.
				// \Error subclasses (TypeError, etc.) are programming bugs and
				// are not caught here - they propagate as hard failures.
				$embeddingError = $e->getMessage();
			}
		}

		// -- 9. Return result -------------------------------------------------

		if ($embeddingError !== null) {
			return [
				'document_id' => $documentId,
				'units_count' => $this->unitsCount,
				'chunks_count' => $this->chunksCount,
				'embeddings_count' => $this->embeddingsCount,
				'embedding_model' => $embeddingProfile,
				'status' => 'partial',
				'reason' => null,
				'error' => 'Embedding failed after document commit. Document ID '
					. $documentId . ' exists with ' . $this->chunksCount
					. ' chunks but incomplete embeddings (' . $this->embeddingsCount
					. ' of ' . \count($this->embeddingQueue) . ' embedded). '
					. $embeddingError,
			];
		}

		return [
			'document_id' => $documentId,
			'units_count' => $this->unitsCount,
			'chunks_count' => $this->chunksCount,
			'embeddings_count' => $this->embeddingsCount,
			'embedding_model' => $vectorEnabled ? $embeddingProfile : null,
			'status' => 'ok',
			'reason' => null,
			'error' => null,
		];
	}








	// ----------------------------------------------------------------
	// Content-unchanged path
	// ----------------------------------------------------------------

	/**
	 * Handle the case where content_hash matches an existing document.
	 *
	 * Units, chunks, and embeddings are untouched. If document-level metadata
	 * has changed (title, effective_date, etc.), only the document row is
	 * updated.
	 *
	 * The comparison is explicit and deterministic: each field is normalized
	 * the same way before comparison to avoid false updates from trivial
	 * formatting differences.
	 */
	private function handleContentUnchanged(array $existing, array $input, array $knowledgeBase, DocumentRepository $documentRepo): array {
		$changes = $this->detectDocumentFieldChanges($existing, $input, $knowledgeBase);

		if ($changes === []) {
			return [
				'document_id' => $existing['id'],
				'units_count' => 0,
				'chunks_count' => 0,
				'embeddings_count' => 0,
				'embedding_model' => null,
				'status' => 'skipped',
				'reason' => 'content_unchanged',
				'error' => null,
			];
		}

		$documentRepo->update($existing['id'], $changes);

		return [
			'document_id' => $existing['id'],
			'units_count' => 0,
			'chunks_count' => 0,
			'embeddings_count' => 0,
			'embedding_model' => null,
			'status' => 'ok',
			'reason' => 'metadata_updated',
			'error' => null,
		];
	}


	/**
	 * Compare document-level fields between the existing row and new input.
	 *
	 * Returns only the fields that actually differ, ready for
	 * DocumentRepository::update(). Returns an empty array when nothing
	 * has changed.
	 *
	 * Comparison rules:
	 * - Required string fields (title, source_type): trimmed, compared as-is.
	 * - Nullable string fields (source_ref, version_label): trimmed,
	 *   empty-to-null, compared. Only checked when present in input.
	 * - effective_date: compared as raw trimmed strings. If the caller sends
	 *   a different format (e.g. DD-MM-YYYY vs YYYY-MM-DD), it triggers an
	 *   update where DocumentRepository normalizes to YYYY-MM-DD. Callers
	 *   that use a consistent format will see stable comparisons.
	 * - metadata: compared via recursive key-sorted JSON serialization with
	 *   identical flags. Key ordering differences do not produce false updates.
	 */
	private function detectDocumentFieldChanges(array $existing, array $input, array $knowledgeBase): array {
		$changes = [];

		// -- Required string fields (always present in validated input) --------

		$newTitle = \trim((string)$input['title']);
		if ($newTitle !== $existing['title']) {
			$changes['title'] = $newTitle;
		}

		$newSourceType = \trim((string)$input['source_type']);
		if ($newSourceType !== $existing['source_type']) {
			$changes['source_type'] = $newSourceType;
		}

		$newLanguage = (string)($input['language'] ?? $knowledgeBase['language']);
		if ($newLanguage !== $existing['language']) {
			$changes['language'] = $newLanguage;
		}

		$newStatus = (string)($input['status'] ?? 'active');
		if ($newStatus !== $existing['status']) {
			$changes['status'] = $newStatus;
		}

		// -- Optional nullable string fields ----------------------------------
		// Only compared when present in input. If the caller does not include
		// a field, we do not touch it - there is no way to distinguish
		// "caller wants to keep the old value" from "caller forgot to send it".

		if (\array_key_exists('source_ref', $input)) {
			$newVal = $this->normalizeNullableStringForComparison($input['source_ref']);
			if ($newVal !== $existing['source_ref']) {
				$changes['source_ref'] = $input['source_ref'];
			}
		}

		if (\array_key_exists('effective_date', $input)) {
			$newVal = $this->normalizeNullableStringForComparison($input['effective_date']);
			if ($newVal !== $existing['effective_date']) {
				$changes['effective_date'] = $input['effective_date'];
			}
		}

		if (\array_key_exists('version_label', $input)) {
			$newVal = $this->normalizeNullableStringForComparison($input['version_label']);
			if ($newVal !== $existing['version_label']) {
				$changes['version_label'] = $input['version_label'];
			}
		}

		// -- Metadata ---------------------------------------------------------

		if (\array_key_exists('metadata', $input)) {
			if (!$this->metadataEquals($input['metadata'], $existing['metadata_json'])) {
				$changes['metadata'] = $input['metadata'];
			}
		}

		return $changes;
	}








	// ----------------------------------------------------------------
	// Document payload
	// ----------------------------------------------------------------

	/**
	 * Build the document row payload for DocumentRepository::insert().
	 */
	private function buildDocumentPayload(array $input, int $knowledgeBaseId, string $slug, string $title, string $sourceType, array $knowledgeBase, ?string $contentHash): array {
		$data = [
			'knowledge_base_id' => $knowledgeBaseId,
			'slug' => $slug,
			'title' => $title,
			'source_type' => $sourceType,
			'language' => $input['language'] ?? $knowledgeBase['language'],
			'status' => $input['status'] ?? 'active',
		];

		if (\array_key_exists('source_ref', $input)) {
			$data['source_ref'] = $input['source_ref'];
		}
		if (\array_key_exists('effective_date', $input)) {
			$data['effective_date'] = $input['effective_date'];
		}
		if (\array_key_exists('version_label', $input)) {
			$data['version_label'] = $input['version_label'];
		}
		if ($contentHash !== null) {
			$data['content_hash'] = $contentHash;
		}
		if (\array_key_exists('metadata', $input)) {
			$data['metadata'] = $input['metadata'];
		}

		return $data;
	}








	// ----------------------------------------------------------------
	// Unit tree insertion
	// ----------------------------------------------------------------

	/**
	 * Walk the unit tree depth-first, inserting units and their chunks.
	 *
	 * Each unit in the input array may contain:
	 *   - unit_type (required): string
	 *   - sort_order (required): int 0-999
	 *   - identifier: ?string (e.g. "§ 34, stk. 2")
	 *   - title: ?string
	 *   - body: ?string - text content to chunk
	 *   - metadata: ?array
	 *   - children: ?array - recursive child units
	 *
	 * Mutates instance state: $this->unitsCount, $this->chunksCount,
	 * $this->embeddingQueue.
	 *
	 * @param  array[]  $units       Flat list of sibling unit inputs.
	 * @param  int      $documentId  Parent document ID.
	 * @param  ?int     $parentId    Parent unit ID (null for root units).
	 * @param  string   $unitPath    Human-readable recursion path for error messages.
	 */
	private function insertUnitsRecursive(array $units, int $documentId, ?int $parentId, string $unitPath): void {
		foreach ($units as $index => $unitInput) {
			$currentPath = $unitPath . '[' . $index . ']';

			if (!\is_array($unitInput)) {
				throw new IngestException('Each unit must be an array (at ' . $currentPath . ').');
			}

			// -- Validate required fields -------------------------------------

			if (!isset($unitInput['unit_type']) || !\is_string($unitInput['unit_type']) || \trim($unitInput['unit_type']) === '') {
				throw new IngestException('Unit is missing required field: unit_type (at ' . $currentPath . ').');
			}
			if (!\array_key_exists('sort_order', $unitInput)) {
				throw new IngestException('Unit is missing required field: sort_order (at ' . $currentPath . ').');
			}

			// -- Build unit data for repository -------------------------------

			$unitData = [
				'document_id' => $documentId,
				'parent_id' => $parentId,
				'unit_type' => $unitInput['unit_type'],
				'sort_order' => $unitInput['sort_order'],
			];
			if (\array_key_exists('identifier', $unitInput)) {
				$unitData['identifier'] = $unitInput['identifier'];
			}
			if (\array_key_exists('title', $unitInput)) {
				$unitData['title'] = $unitInput['title'];
			}
			if (\array_key_exists('body', $unitInput)) {
				$unitData['body'] = $unitInput['body'];
			}
			if (\array_key_exists('metadata', $unitInput)) {
				$unitData['metadata'] = $unitInput['metadata'];
			}

			// -- Insert unit (UnitRepository handles path generation) ---------

			$unitId = $this->unitRepo->insert($unitData);
			$this->unitsCount++;

			// -- Chunk the unit's body text and queue for embedding ------------

			$body = $unitInput['body'] ?? null;
			if ($body !== null && \is_string($body) && $body !== '') {
				$this->chunkAndQueueUnit($unitId, $body);
			}

			// -- Recurse for children -----------------------------------------

			$children = $unitInput['children'] ?? null;
			if ($children !== null) {
				if (!\is_array($children)) {
					throw new IngestException('children must be an array or null (at ' . $currentPath . ').');
				}
				if ($children !== []) {
					$this->insertUnitsRecursive($children, $documentId, $unitId, $currentPath . '.children');
				}
			}
		}
	}


	/**
	 * Chunk one unit's body text and queue the resulting chunks for embedding.
	 *
	 * Calls Chunker to split the body text, inserts chunk rows via
	 * ChunkRepository, then loads the persisted chunks to get their
	 * auto-generated IDs for the embedding queue.
	 *
	 * Mutates instance state: $this->chunksCount, $this->embeddingQueue.
	 */
	private function chunkAndQueueUnit(int $unitId, string $body): void {
		$chunks = Chunker::chunk($body, $this->chunkerStrategy, $this->chunkerMaxTokens, $this->chunkerOverlapTokens);
		if ($chunks === []) {
			return;
		}

		$chunkRows = [];
		foreach ($chunks as $chunk) {
			$chunkRows[] = [
				'content' => $chunk['content'],
				'context_before' => $chunk['context_before'] ?? null,
				'context_after' => $chunk['context_after'] ?? null,
				'token_count' => $chunk['token_estimate'] ?? null,
				'char_count' => $chunk['char_count'] ?? null,
			];
		}

		$inserted = $this->chunkRepo->insertBatch($unitId, $chunkRows);
		$this->chunksCount += $inserted;

		// Load persisted chunks to get their auto-generated IDs for embedding.
		// insertBatch() returns affected row count, not IDs. A separate read
		// is the only reliable way to get IDs without assuming sequential
		// auto_increment (which is fragile under concurrent inserts).
		// This is an indexed lookup on (unit_id, chunk_index) - negligible cost.
		// Ordering is deterministic: ORDER BY chunk_index ASC, id ASC.
		$persistedChunks = $this->chunkRepo->findByUnit($unitId);
		foreach ($persistedChunks as $persisted) {
			$this->embeddingQueue[] = [
				'chunk_id' => $persisted['id'],
				'text' => $this->buildEmbeddingText($persisted),
			];
		}
	}








	// ----------------------------------------------------------------
	// Embedding
	// ----------------------------------------------------------------

	/**
	 * Embed all queued chunks in batches and persist each batch immediately.
	 *
	 * Processes the embedding queue in batches of $batchSize. Each batch is
	 * sent to VectorEmbedder as one call, and the resulting embedding rows
	 * are inserted immediately. This bounds peak memory to one batch of
	 * vectors at a time and provides natural retry boundaries.
	 *
	 * On failure, previously committed batches remain in the database.
	 * $this->embeddingsCount reflects the actual number of rows inserted
	 * before the failure - the caller can use this for accurate partial
	 * completion reporting.
	 *
	 * Mutates instance state: $this->embeddingsCount.
	 *
	 * @param  array[]              $queue      Each: ['chunk_id' => int, 'text' => string]
	 * @param  string               $profile    Embedding profile from cfg.
	 * @param  int                  $batchSize  Chunks per embedding call.
	 * @param  EmbeddingRepository  $repo       Embedding persistence.
	 * @throws IngestException  On vector count mismatch from VectorEmbedder.
	 * @throws \Throwable  \Error subclasses propagate uncaught. \Exception
	 *                     subclasses from VectorEmbedder or EmbeddingRepository
	 *                     propagate when called directly; the caller (execute)
	 *                     catches \Exception for partial completion reporting.
	 */
	private function embedInBatches(array $queue, string $profile, int $batchSize, EmbeddingRepository $repo): void {
		$batches = \array_chunk($queue, $batchSize);

		foreach ($batches as $batch) {
			$items = [];
			foreach ($batch as $entry) {
				$items[] = [
					'type' => 'text',
					'text' => $entry['text'],
				];
			}

			$result = $this->app->vectorEmbedder->embed([
				'profile' => $profile,
				'items' => $items,
			]);

			$vectors = $result['vectors'] ?? [];
			
			if (\count($vectors) !== \count($batch)) {
				throw new IngestException(
					'VectorEmbedder returned ' . \count($vectors) . ' vectors for '
					. \count($batch) . ' chunks. Expected counts to match.'
				);
			}
			
			$model = (string)($result['model'] ?? $profile);
			
			$embeddingRows = [];
			foreach ($batch as $i => $entry) {
				$vector = $this->extractEmbeddingVector($vectors[$i] ?? null, $i);
				$embeddingRows[] = [
					'chunk_id' => $entry['chunk_id'],
					'model' => $model,
					'dimension' => \count($vector),
					'vector' => $vector,
				];
			}
			
			$this->embeddingsCount += $repo->insertBatch($embeddingRows);
		}
	}


	/**
	 * Build the text sent to VectorEmbedder for one chunk.
	 *
	 * Includes overlap context (context_before / context_after) because
	 * overlap is embedding input - not prompt input (§9). This gives the
	 * embedding model richer positional context for the chunk.
	 */
	private function buildEmbeddingText(array $chunk): string {
		$parts = [];
		if (($chunk['context_before'] ?? null) !== null && $chunk['context_before'] !== '') {
			$parts[] = $chunk['context_before'];
		}
		$parts[] = $chunk['content'];
		if (($chunk['context_after'] ?? null) !== null && $chunk['context_after'] !== '') {
			$parts[] = $chunk['context_after'];
		}
		return \implode("\n", $parts);
	}








	// ----------------------------------------------------------------
	// State management
	// ----------------------------------------------------------------

	/**
	 * Reset all mutable ingest counters and queues.
	 *
	 * Called at the start of the ingest transaction phase to ensure clean
	 * state. All fields that accumulate during insertUnitsRecursive() and
	 * embedInBatches() must be listed here.
	 */
	private function resetState(): void {
		$this->unitsCount = 0;
		$this->chunksCount = 0;
		$this->embeddingsCount = 0;
		$this->embeddingQueue = [];
	}







	// ----------------------------------------------------------------
	// Input validation
	// ----------------------------------------------------------------

	private function requirePositiveInt(array $input, string $field): int {
		if (!\array_key_exists($field, $input)) {
			throw new IngestException('Missing required field: ' . $field);
		}
		$value = $input[$field];
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new IngestException('Field must be a positive integer: ' . $field);
		}
		$value = (int)$value;
		if ($value <= 0) {
			throw new IngestException('Field must be greater than zero: ' . $field);
		}
		return $value;
	}

	private function requireNonEmptyString(array $input, string $field): string {
		if (!\array_key_exists($field, $input)) {
			throw new IngestException('Missing required field: ' . $field);
		}
		$value = $input[$field];
		if (!\is_string($value)) {
			throw new IngestException('Field must be a string: ' . $field);
		}
		$value = \trim($value);
		if ($value === '') {
			throw new IngestException('Field must not be empty: ' . $field);
		}
		return $value;
	}







	// ----------------------------------------------------------------
	// Comparison helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize a nullable string value for deterministic comparison.
	 *
	 * Trims whitespace and collapses empty strings to null - matching the
	 * normalization that DocumentRepository applies on write.
	 */
	private function normalizeNullableStringForComparison(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			return null;
		}
		$value = \trim($value);
		return $value === '' ? null : $value;
	}


	/**
	 * Compare two metadata values for equality.
	 *
	 * Both values are recursively key-sorted and JSON-encoded with identical
	 * flags before comparison. This ensures deterministic output regardless
	 * of PHP's internal array key ordering, avoiding false positives when
	 * the same data arrives with different insertion order.
	 *
	 * @param  mixed   $new  New metadata from input (array, null, or other).
	 * @param  ?array  $old  Existing metadata from hydrated document row.
	 */
	private function metadataEquals(mixed $new, ?array $old): bool {
		return $this->canonicalizeForComparison($new) === $this->canonicalizeForComparison($old);
	}


	/**
	 * Canonicalize a value to a deterministic JSON string for comparison.
	 *
	 * Recursively sorts associative array keys before encoding. Sequential
	 * lists (as determined by array_is_list()) preserve their original order
	 * because list element ordering is semantically significant. Returns null
	 * for null, non-array, or empty-array input.
	 */
	private function canonicalizeForComparison(mixed $value): ?string {
		if (!\is_array($value) || $value === []) {
			return null;
		}
		$this->ksortRecursive($value);
		return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
	}


	/**
	 * Recursively sort associative array keys for deterministic comparison.
	 *
	 * Associative arrays are sorted by key using ksort(). Sequential lists
	 * (as determined by array_is_list()) keep their original order because
	 * list element ordering is semantically significant.
	 *
	 * This helper is used only for metadata comparison canonicalization.
	 *
	 * @param array $array Array to normalize in place.
	 * @return void
	 */
	private function ksortRecursive(array &$array): void {
		if (!\array_is_list($array)) {
			\ksort($array);
		}
		foreach ($array as &$value) {
			if (\is_array($value)) {
				$this->ksortRecursive($value);
			}
		}
	}


	/**
	 * Extract the raw float vector from a normalized VectorEmbedder row.
	 *
	 * @param mixed $row Vector row.
	 * @param int $index Batch index for diagnostics.
	 * @return array Extracted float vector.
	 * @throws IngestException When the vector row is malformed.
	 */
	private function extractEmbeddingVector(mixed $row, int $index): array {
		if (!\is_array($row)) {
			throw new IngestException('VectorEmbedder row at index ' . $index . ' must be an array.');
		}

		$vector = $row['vector'] ?? null;
		if (!\is_array($vector) || $vector === []) {
			throw new IngestException('VectorEmbedder row at index ' . $index . ' is missing a non-empty vector array.');
		}

		foreach ($vector as $vectorIndex => $value) {
			if (!\is_int($value) && !\is_float($value)) {
				throw new IngestException(
					'VectorEmbedder returned a non-numeric vector element at batch index '
					. $index . ', vector index ' . $vectorIndex . '.'
				);
			}
			$floatValue = (float)$value;
			if (\is_nan($floatValue) || \is_infinite($floatValue)) {
				throw new IngestException(
					'VectorEmbedder returned a non-finite vector element at batch index '
					. $index . ', vector index ' . $vectorIndex . '.'
				);
			}
		}

		return $vector;
	}


}
