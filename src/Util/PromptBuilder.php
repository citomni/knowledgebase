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
 * Build deterministic HelloAi message arrays for grounded knowledge-base answering.
 *
 * PromptBuilder is a pure Util. It owns prompt assembly only - not retrieval,
 * cfg lookup, language resolution, tokenization libraries, logging, IO, or
 * provider-specific transport details.
 *
 * Responsibilities:
 * - Build the final messages array for question answering from:
 *   - the user question
 *   - ranked retrieved chunks
 *   - an optional custom system template
 *   - an optional resolved answer language
 *   - an explicit context-token budget
 * - Build the rerank prompt used by Retriever when AI-based reranking is enabled
 *
 * Guarantees:
 * - Injects only the minimal prompt context fields per chunk:
 *   - document_title
 *   - unit_identifier
 *   - content
 * - Excludes overlap text (`context_before` / `context_after`) and other
 *   non-essential metadata from the QA prompt context
 * - Respects `maxContextTokens` heuristically by including whole chunks in
 *   ranked order until the next chunk would exceed the budget
 * - Never truncates chunk text mid-chunk
 * - Uses a strict grounded default system prompt when no custom template is supplied
 * - Returns normalized HelloAi-compatible message arrays only
 *
 * Design notes:
 * - Token counts are estimates, not model-exact guarantees
 * - Language handling is explicit: the caller resolves the final language and
 *   passes it in; PromptBuilder does not auto-detect anything
 * - This class intentionally does not know where chunks came from, how they
 *   were ranked, or whether retrieval quality was high enough to answer
 *
 * Separation of concerns:
 * - Retriever owns retrieval, synonym expansion, fusion, and optional rerank
 * - QueryKnowledgeBase owns answer/no-answer policy, low-context handling,
 *   language precedence, and HelloAi invocation
 * - PromptBuilder only shapes already-resolved input into deterministic prompts
 *
 * Typical usage:
 *   $messages = PromptBuilder::build(
 *       question: $question,
 *       chunks: $chunks,
 *       systemTemplate: $systemTemplate,
 *       language: $language,
 *       maxContextTokens: 4000
 *   );
 *
 *   $rerankMessages = PromptBuilder::buildRerankPrompt($question, $candidateChunks);
 */
final class PromptBuilder {

	private const DEFAULT_SYSTEM_TEMPLATE = <<<'TEXT'
You answer questions using only the provided context chunks.

Rules:
- Answer only from the provided context.
- If the context is insufficient, say so clearly.
- Do not invent facts, citations, or legal references not present in the context.
- Preserve uncertainty when the context is ambiguous or incomplete.
- If the context conflicts internally, acknowledge the conflict.
TEXT;

	/**
	 * Build a chat messages array for question answering.
	 *
	 * Behavior:
	 * - Uses a strict grounded default system prompt unless a custom template is provided.
	 * - Injects only the prompt-safe source fields per chunk.
	 * - Applies budget trimming by including whole chunks in score order until the budget is exhausted.
	 * - Never truncates a chunk mid-text.
	 *
	 * Notes:
	 * - Token counts are heuristic estimates, not model-exact counts.
	 * - Chunk overlap fields are deliberately excluded from the prompt context.
	 *
	 * Typical usage:
	 *   $messages = PromptBuilder::build($question, $chunks, null, 'da', 4000);
	 *
	 * @param  string  $question  The user's question.
	 * @param  array   $chunks  Ranked chunks with return-shape metadata.
	 * @param  ?string $systemTemplate  Optional custom system template.
	 * @param  ?string $language  Optional answer language instruction.
	 * @param  int     $maxContextTokens  Maximum context token budget.
	 * @return array  Messages array ready for HelloAi.
	 */
	public static function build(string $question, array $chunks, ?string $systemTemplate = null, ?string $language = null, int $maxContextTokens = 4000): array {
		$question = self::normalizeRequiredString($question, 'question');
		$systemTemplate = self::normalizeSystemTemplate($systemTemplate);
		$language = self::normalizeNullableString($language);
		$maxContextTokens = self::normalizePositiveInt($maxContextTokens, 'maxContextTokens');

		$contextBlock = self::buildContextBlock($chunks, $maxContextTokens);
		$systemPrompt = self::buildSystemPrompt($systemTemplate, $language);

		$userText = "Question:\n" . $question . "\n\n";
		$userText .= "Context:\n";
		$userText .= $contextBlock !== '' ? $contextBlock : "[No context chunks available]";

		return [
			[
				'role' => 'system',
				'content' => [
					[
						'type' => 'text',
						'text' => $systemPrompt,
					],
				],
			],
			[
				'role' => 'user',
				'content' => [
					[
						'type' => 'text',
						'text' => $userText,
					],
				],
			],
		];
	}


	/**
	 * Build a rerank prompt for AI-based reranking.
	 *
	 * Behavior:
	 * - Sends the user question plus candidate chunks in a deterministic numbered format.
	 * - Requires JSON-only output containing one numeric relevance score per chunk.
	 * - Uses chunk_id as the stable identifier for the caller.
	 *
	 * Notes:
	 * - The expected score scale is 0.0 to 10.0.
	 * - Higher means more relevant to answering the question.
	 *
	 * Typical usage:
	 *   $messages = PromptBuilder::buildRerankPrompt($question, $candidateChunks);
	 *
	 * @param  string  $question  The user's question.
	 * @param  array   $candidateChunks  Candidate chunk rows to score.
	 * @return array  Messages array ready for HelloAi.
	 */
	public static function buildRerankPrompt(string $question, array $candidateChunks): array {
		$question = self::normalizeRequiredString($question, 'question');

		$systemPrompt = <<<'TEXT'
You are a retrieval reranker.

Task:
- Score each candidate chunk for relevance to the user's question.
- Use a score from 0.0 to 10.0.
- Higher means more relevant for answering the question from the chunk itself.
- Consider direct answerability, factual usefulness, and specificity.
- Do not reward general background if a chunk is only loosely related.

Output format:
- Return JSON only.
- Return an array of objects.
- Each object must contain:
  - "chunk_id": integer
  - "score": number

Example:
[
  {"chunk_id": 123, "score": 9.5},
  {"chunk_id": 456, "score": 2.0}
]
TEXT;

		$userText = "Question:\n" . $question . "\n\n";
		$userText .= "Candidate chunks:\n";
		$userText .= self::buildRerankCandidatesBlock($candidateChunks);

		return [
			[
				'role' => 'system',
				'content' => [
					[
						'type' => 'text',
						'text' => $systemPrompt,
					],
				],
			],
			[
				'role' => 'user',
				'content' => [
					[
						'type' => 'text',
						'text' => $userText,
					],
				],
			],
		];
	}







	// ----------------------------------------------------------------
	// Build helpers
	// ----------------------------------------------------------------

	/**
	 * Build the final system prompt.
	 *
	 * @param  string  $systemTemplate  Resolved system template.
	 * @param  ?string $language  Optional language instruction.
	 * @return string  Final system prompt text.
	 */
	private static function buildSystemPrompt(string $systemTemplate, ?string $language): string {
		$systemPrompt = $systemTemplate;

		if ($language !== null) {
			$systemPrompt .= "\n\nAnswer in " . $language . '.';
		}

		return $systemPrompt;
	}


	/**
	 * Build the prompt context block within the token budget.
	 *
	 * @param  array  $chunks  Ranked chunk rows.
	 * @param  int    $maxContextTokens  Token budget.
	 * @return string  Final context block.
	 */
	private static function buildContextBlock(array $chunks, int $maxContextTokens): string {
		if ($chunks === []) {
			return '';
		}

		$parts = [];
		$usedTokens = 0;

		foreach ($chunks as $index => $chunk) {
			if (!\is_array($chunk)) {
				throw new \InvalidArgumentException('Each chunk must be an array.');
			}

			$chunkText = self::buildPromptChunkBlock($chunk, $index + 1);
			$chunkTokens = self::estimateTokens($chunkText);

			if ($chunkTokens > $maxContextTokens && $usedTokens === 0) {
				continue;
			}

			if (($usedTokens + $chunkTokens) > $maxContextTokens) {
				break;
			}

			$parts[] = $chunkText;
			$usedTokens += $chunkTokens;
		}

		return \implode("\n\n", $parts);
	}


	/**
	 * Build one prompt-visible chunk block.
	 *
	 * @param  array  $chunk  One chunk row.
	 * @param  int    $position  1-based chunk position.
	 * @return string  Chunk block text.
	 */
	private static function buildPromptChunkBlock(array $chunk, int $position): string {
		$documentTitle = self::normalizeRequiredString($chunk['document_title'] ?? null, 'document_title');
		$chunkContent = self::normalizeRequiredString($chunk['content'] ?? null, 'content');
		$unitIdentifier = self::normalizeNullableString(
			$chunk['doc_path_identifier'] ?? ($chunk['unit_identifier'] ?? null)
		);

		$lines = [];
		$lines[] = '[Chunk ' . $position . ']';
		$lines[] = 'Document: ' . $documentTitle;

		if ($unitIdentifier !== null) {
			$lines[] = 'Unit: ' . $unitIdentifier;
		}

		$lines[] = 'Content:';
		$lines[] = $chunkContent;

		return \implode("\n", $lines);
	}


	/**
	 * Build the rerank candidate block.
	 *
	 * @param  array  $candidateChunks  Candidate chunk rows.
	 * @return string  Candidate block text.
	 */
	private static function buildRerankCandidatesBlock(array $candidateChunks): string {
		if ($candidateChunks === []) {
			throw new \InvalidArgumentException('candidateChunks must not be empty.');
		}

		$parts = [];

		foreach ($candidateChunks as $index => $chunk) {
			if (!\is_array($chunk)) {
				throw new \InvalidArgumentException('Each candidate chunk must be an array.');
			}

			$chunkId = self::normalizePositiveInt($chunk['chunk_id'] ?? null, 'chunk_id');
			$documentTitle = self::normalizeRequiredString($chunk['document_title'] ?? null, 'document_title');
			$chunkContent = self::normalizeRequiredString($chunk['content'] ?? null, 'content');
			$unitIdentifier = self::normalizeNullableString(
				$chunk['doc_path_identifier'] ?? ($chunk['unit_identifier'] ?? null)
			);

			$lines = [];
			$lines[] = '[Candidate ' . ($index + 1) . ']';
			$lines[] = 'chunk_id: ' . $chunkId;
			$lines[] = 'document_title: ' . $documentTitle;

			if ($unitIdentifier !== null) {
				$lines[] = 'unit_identifier: ' . $unitIdentifier;
			}

			$lines[] = 'content:';
			$lines[] = $chunkContent;

			$parts[] = \implode("\n", $lines);
		}

		return \implode("\n\n", $parts);
	}


	/**
	 * Normalize the system template.
	 *
	 * @param  ?string $systemTemplate  Optional system template.
	 * @return string  Final template text.
	 */
	private static function normalizeSystemTemplate(?string $systemTemplate): string {
		if ($systemTemplate === null) {
			return self::DEFAULT_SYSTEM_TEMPLATE;
		}

		$systemTemplate = \trim($systemTemplate);
		if ($systemTemplate === '') {
			return self::DEFAULT_SYSTEM_TEMPLATE;
		}

		return $systemTemplate;
	}


	/**
	 * Estimate token count heuristically.
	 *
	 * Notes:
	 * - This is intentionally approximate.
	 * - The heuristic favors a little slack over aggressive packing.
	 *
	 * @param  string  $text  Input text.
	 * @return int  Estimated tokens.
	 */
	private static function estimateTokens(string $text): int {
		$text = \trim($text);
		if ($text === '') {
			return 0;
		}

		$charCount = \mb_strlen($text, 'UTF-8');
		return (int)\ceil($charCount / 4);
	}








	// ----------------------------------------------------------------
	// Normalization helpers
	// ----------------------------------------------------------------

	/**
	 * Normalize a required non-empty string.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return string  Normalized string.
	 */
	private static function normalizeRequiredString(mixed $value, string $field): string {
		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Field must be a string: ' . $field);
		}

		$value = \trim($value);
		if ($value === '') {
			throw new \InvalidArgumentException('Field must not be empty: ' . $field);
		}

		return $value;
	}


	/**
	 * Normalize a nullable trimmed string.
	 *
	 * @param  mixed  $value  Input value.
	 * @return ?string  Normalized string or null.
	 */
	private static function normalizeNullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (!\is_string($value)) {
			throw new \InvalidArgumentException('Field must be a string or null.');
		}

		$value = \trim($value);
		return $value === '' ? null : $value;
	}


	/**
	 * Normalize a positive integer.
	 *
	 * @param  mixed   $value  Input value.
	 * @param  string  $field  Field name.
	 * @return int  Normalized integer.
	 */
	private static function normalizePositiveInt(mixed $value, string $field): int {
		if (!\is_int($value) && !(\is_string($value) && \ctype_digit($value))) {
			throw new \InvalidArgumentException('Field must be a positive integer: ' . $field);
		}

		$value = (int)$value;
		if ($value <= 0) {
			throw new \InvalidArgumentException('Field must be greater than zero: ' . $field);
		}

		return $value;
	}

}
