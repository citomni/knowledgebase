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

namespace CitOmni\KnowledgeBase\Command;

use CitOmni\Kernel\Command\BaseCommand;
use CitOmni\KnowledgeBase\Operation\QueryKnowledgeBase;

/**
 * Smoke-test retrieval and answer flow from the CLI.
 *
 * This command is intentionally practical: It lets developers validate
 * question answering, retrieval thresholds, and source output without
 * building an HTTP layer first.
 *
 * Behavior:
 * - Calls QueryKnowledgeBase directly.
 * - Supports one or more target knowledge bases via comma-separated ids.
 * - Can print either the final answer or the full normalized result as JSON.
 * - Can optionally print retrieved source rows and retrieval metadata.
 *
 * Notes:
 * - This command is a developer/admin smoke-test tool, not an end-user UI.
 * - Numeric options are parsed explicitly and fail fast on invalid values.
 */
final class SearchCommand extends BaseCommand {

	/**
	 * Define CLI signature.
	 *
	 * @return array Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'question' => [
					'description' => 'Question to ask against the knowledge base',
					'required' => true,
				],
			],
			'options' => [
				'kb' => [
					'short' => 'k',
					'type' => 'string',
					'description' => 'Comma-separated knowledge base ids',
					'default' => '',
				],
				'top-k' => [
					'short' => 't',
					'type' => 'int',
					'description' => 'Maximum number of chunks to keep after retrieval',
					'default' => 0,
				],
				'language' => [
					'short' => 'l',
					'type' => 'string',
					'description' => 'Explicit answer language override',
					'default' => '',
				],
				'system-prompt' => [
					'short' => 'p',
					'type' => 'string',
					'description' => 'Optional system prompt override',
					'default' => '',
				],
				'max-context-tokens' => [
					'short' => 'm',
					'type' => 'int',
					'description' => 'Prompt context token budget override',
					'default' => 0,
				],
				'min-score' => [
					'short' => 's',
					'type' => 'string',
					'description' => 'Post-fusion score threshold',
					'default' => '',
				],
				'min-chunks' => [
					'short' => 'c',
					'type' => 'int',
					'description' => 'Minimum qualifying chunks required before answering',
					'default' => 0,
				],
				'allow-low-context' => [
					'short' => 'a',
					'type' => 'bool',
					'description' => 'Allow answer generation even when min_chunks is not met',
					'default' => false,
				],
				'lexical' => [
					'type' => 'string',
					'description' => 'Override lexical leg: true|false',
					'default' => '',
				],
				'vector' => [
					'type' => 'string',
					'description' => 'Override vector leg: true|false',
					'default' => '',
				],
				'rerank' => [
					'type' => 'string',
					'description' => 'Override rerank leg: true|false',
					'default' => '',
				],
				'synonyms' => [
					'type' => 'string',
					'description' => 'Override synonym expansion: true|false',
					'default' => '',
				],
				'candidate-multiplier' => [
					'type' => 'int',
					'description' => 'Override candidate multiplier used before rerank',
					'default' => 0,
				],
				'min-similarity' => [
					'type' => 'string',
					'description' => 'Override vector min_similarity threshold',
					'default' => '',
				],
				'rrf-k' => [
					'type' => 'int',
					'description' => 'Override Reciprocal Rank Fusion k value',
					'default' => 0,
				],
				'rerank-profile' => [
					'type' => 'string',
					'description' => 'Optional rerank profile override',
					'default' => '',
				],
				'json' => [
					'short' => 'j',
					'type' => 'bool',
					'description' => 'Output the full normalized result as JSON',
					'default' => false,
				],
				'show-sources' => [
					'type' => 'bool',
					'description' => 'Print retrieved sources after the answer',
					'default' => false,
				],
				'show-meta' => [
					'type' => 'bool',
					'description' => 'Print retrieval meta after the answer',
					'default' => false,
				],
			],
		];
	}


	/**
	 * Execute the command.
	 *
	 * @return int Exit code.
	 */
	protected function execute(): int {
		$question = $this->argString('question');
		$json = $this->getBool('json');
		$showSources = $this->getBool('show-sources');
		$showMeta = $this->getBool('show-meta');

		try {
			$input = $this->buildOperationInput();
		} catch (\InvalidArgumentException $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		try {
			$operation = new QueryKnowledgeBase($this->app);
			$result = $operation->execute($input);
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		if ($json) {
			return $this->outputJson($result);
		}

		$status = (string)($result['status'] ?? '');
		$answer = $result['answer'] ?? null;
		$sources = \is_array($result['sources'] ?? null) ? $result['sources'] : [];
		$retrievalMeta = \is_array($result['retrieval_meta'] ?? null) ? $result['retrieval_meta'] : [];
		$usage = \is_array($result['usage'] ?? null) ? $result['usage'] : null;

		if (\is_string($answer) && $answer !== '') {
			$this->stdout($answer);
		} elseif ($status === 'insufficient_context') {
			$this->warning('Insufficient context. No answer was generated.');
		} else {
			$this->warning('No answer text was returned.');
		}

		$this->info(
			'status=' . $status
			. ' sources=' . \count($sources)
			. ' strategy=' . (string)($retrievalMeta['strategy'] ?? '')
			. ' rerank=' . (string)($retrievalMeta['rerank_status'] ?? '')
			. ' duration_ms=' . (string)($retrievalMeta['duration_ms'] ?? '')
		);

		if ($usage !== null) {
			$this->info(
				'usage'
				. ' input_tokens=' . $this->stringifyScalar($usage['input_tokens'] ?? null)
				. ' output_tokens=' . $this->stringifyScalar($usage['output_tokens'] ?? null)
				. ' total_tokens=' . $this->stringifyScalar($usage['total_tokens'] ?? null)
			);
		}

		if ($showMeta) {
			$this->stdout('');
			$this->stdout('Retrieval meta:');
			$this->stdout($this->encodePrettyJson($retrievalMeta));
		}

		if ($showSources) {
			$this->stdout('');
			$this->stdout('Sources:');
			$this->outputSources($sources);
		}

		return self::SUCCESS;
	}


	/**
	 * Build QueryKnowledgeBase input from CLI arguments and options.
	 *
	 * @return array Operation input.
	 * @throws \InvalidArgumentException When an option is invalid.
	 */
	private function buildOperationInput(): array {
		$input = [
			'question' => $this->argString('question'),
		];

		$knowledgeBaseIds = $this->parseKnowledgeBaseIds($this->getString('kb'));
		if ($knowledgeBaseIds !== null) {
			$input['knowledge_base_ids'] = $knowledgeBaseIds;
		}

		$topK = $this->getInt('top-k');
		if ($topK < 0) {
			throw new \InvalidArgumentException('--top-k must be >= 0.');
		}
		if ($topK > 0) {
			$input['top_k'] = $topK;
		}

		$language = $this->getString('language');
		if ($language !== '') {
			$input['language'] = $language;
		}

		$systemPrompt = $this->getString('system-prompt');
		if ($systemPrompt !== '') {
			$input['system_prompt'] = $systemPrompt;
		}

		$maxContextTokens = $this->getInt('max-context-tokens');
		if ($maxContextTokens < 0) {
			throw new \InvalidArgumentException('--max-context-tokens must be >= 0.');
		}
		if ($maxContextTokens > 0) {
			$input['max_context_tokens'] = $maxContextTokens;
		}

		$minScore = $this->getString('min-score');
		if ($minScore !== '') {
			$input['min_score'] = $this->parseFloatOption($minScore, '--min-score');
		}

		$minChunks = $this->getInt('min-chunks');
		if ($minChunks < 0) {
			throw new \InvalidArgumentException('--min-chunks must be >= 0.');
		}
		if ($minChunks > 0) {
			$input['min_chunks'] = $minChunks;
		}

		if ($this->getBool('allow-low-context')) {
			$input['allow_low_context'] = true;
		}

		$this->applyBoolStringOverride($input, 'lexical', $this->getString('lexical'));
		$this->applyBoolStringOverride($input, 'vector', $this->getString('vector'));
		$this->applyBoolStringOverride($input, 'rerank', $this->getString('rerank'));
		$this->applyBoolStringOverride($input, 'synonym_expansion', $this->getString('synonyms'));

		$candidateMultiplier = $this->getInt('candidate-multiplier');
		if ($candidateMultiplier < 0) {
			throw new \InvalidArgumentException('--candidate-multiplier must be >= 0.');
		}
		if ($candidateMultiplier > 0) {
			$input['candidate_multiplier'] = $candidateMultiplier;
		}

		$minSimilarity = $this->getString('min-similarity');
		if ($minSimilarity !== '') {
			$input['min_similarity'] = $this->parseFloatOption($minSimilarity, '--min-similarity');
		}

		$rrfK = $this->getInt('rrf-k');
		if ($rrfK < 0) {
			throw new \InvalidArgumentException('--rrf-k must be >= 0.');
		}
		if ($rrfK > 0) {
			$input['rrf_k'] = $rrfK;
		}

		$rerankProfile = $this->getString('rerank-profile');
		if ($rerankProfile !== '') {
			$input['rerank_profile'] = $rerankProfile;
		}

		return $input;
	}


	/**
	 * Parse a comma-separated knowledge base id list.
	 *
	 * @param string $value Raw option value.
	 * @return ?array Parsed ids or null.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function parseKnowledgeBaseIds(string $value): ?array {
		$value = \trim($value);
		if ($value === '') {
			return null;
		}

		$parts = \array_filter(
			\array_map(static fn(string $part): string => \trim($part), \explode(',', $value)),
			static fn(string $part): bool => $part !== ''
		);

		if ($parts === []) {
			throw new \InvalidArgumentException('--kb must contain at least one id when provided.');
		}

		$ids = [];
		foreach ($parts as $part) {
			if (!\ctype_digit($part)) {
				throw new \InvalidArgumentException('--kb must be a comma-separated list of positive integers.');
			}
			$id = (int)$part;
			if ($id <= 0) {
				throw new \InvalidArgumentException('--kb ids must be greater than zero.');
			}
			$ids[] = $id;
		}

		return \array_values(\array_unique($ids));
	}


	/**
	 * Apply a true|false string override to the operation input.
	 *
	 * @param array $input Operation input.
	 * @param string $key Target key.
	 * @param string $rawValue Raw CLI value.
	 * @return void
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function applyBoolStringOverride(array &$input, string $key, string $rawValue): void {
		$rawValue = \trim($rawValue);
		if ($rawValue === '') {
			return;
		}

		$input[$key] = $this->parseBoolString($rawValue, '--' . \str_replace('_', '-', $key));
	}


	/**
	 * Parse a boolean-like CLI string.
	 *
	 * @param string $value Raw value.
	 * @param string $optionName Option name for errors.
	 * @return bool Parsed boolean.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function parseBoolString(string $value, string $optionName): bool {
		$value = \strtolower(\trim($value));

		if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
			return true;
		}
		if ($value === '0' || $value === 'false' || $value === 'no' || $value === 'off') {
			return false;
		}

		throw new \InvalidArgumentException($optionName . ' must be one of: true, false, 1, 0, yes, no, on, off.');
	}


	/**
	 * Parse a float-like CLI option.
	 *
	 * @param string $value Raw value.
	 * @param string $optionName Option name for errors.
	 * @return float Parsed float.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function parseFloatOption(string $value, string $optionName): float {
		$value = \trim($value);
		if ($value === '' || !\is_numeric($value)) {
			throw new \InvalidArgumentException($optionName . ' must be numeric.');
		}

		$floatValue = (float)$value;
		if (\is_nan($floatValue) || \is_infinite($floatValue)) {
			throw new \InvalidArgumentException($optionName . ' must be finite.');
		}

		return $floatValue;
	}


	/**
	 * Output the full result as pretty JSON.
	 *
	 * @param array $result Normalized result.
	 * @return int Exit code.
	 */
	private function outputJson(array $result): int {
		try {
			$this->stdout($this->encodePrettyJson($result));
		} catch (\Throwable $e) {
			$this->error('Failed to encode result as JSON: ' . $e->getMessage());
			return self::FAILURE;
		}

		return self::SUCCESS;
	}


	/**
	 * Output a compact human-readable source list.
	 *
	 * @param array $sources Source rows.
	 * @return void
	 */
	private function outputSources(array $sources): void {
		if ($sources === []) {
			$this->stdout('[none]');
			return;
		}

		foreach ($sources as $index => $source) {
			if (!\is_array($source)) {
				continue;
			}

			$line = '[' . ($index + 1) . ']';
			$line .= ' chunk_id=' . $this->stringifyScalar($source['chunk_id'] ?? null);
			$line .= ' score=' . $this->formatScore($source['score'] ?? null);

			$documentTitle = $source['document_title'] ?? null;
			if (\is_string($documentTitle) && $documentTitle !== '') {
				$line .= ' document="' . $documentTitle . '"';
			}

			$unitIdentifier = $source['unit_identifier'] ?? null;
			if (\is_string($unitIdentifier) && $unitIdentifier !== '') {
				$line .= ' unit="' . $unitIdentifier . '"';
			}

			$methods = $source['retrieval_methods'] ?? null;
			if (\is_array($methods) && $methods !== []) {
				$line .= ' methods=' . \implode(',', $methods);
			}

			$this->stdout($line);

			$content = $source['content'] ?? null;
			if (\is_string($content) && $content !== '') {
				$this->stdout('    ' . $this->singleLinePreview($content, 220));
			}
		}
	}


	/**
	 * Format a score for CLI output.
	 *
	 * @param mixed $value Raw score.
	 * @return string Formatted score.
	 */
	private function formatScore(mixed $value): string {
		if (!\is_int($value) && !\is_float($value)) {
			return 'null';
		}

		return \number_format((float)$value, 4, '.', '');
	}


	/**
	 * Create a single-line preview string.
	 *
	 * @param string $text Raw text.
	 * @param int $maxLength Maximum output length.
	 * @return string Preview text.
	 */
	private function singleLinePreview(string $text, int $maxLength): string {
		$text = \preg_replace('/\s+/u', ' ', \trim($text)) ?? '';
		if ($text === '') {
			return '';
		}
		if (\mb_strlen($text, 'UTF-8') <= $maxLength) {
			return $text;
		}

		return \mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...';
	}


	/**
	 * Encode a value as pretty JSON.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON string.
	 * @throws \JsonException When encoding fails.
	 */
	private function encodePrettyJson(mixed $value): string {
		return \json_encode(
			$value,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
		);
	}


	/**
	 * Convert a scalar-ish value to a CLI-safe string.
	 *
	 * @param mixed $value Value to stringify.
	 * @return string String value.
	 */
	private function stringifyScalar(mixed $value): string {
		if ($value === null) {
			return 'null';
		}
		if (\is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (\is_int($value) || \is_float($value) || \is_string($value)) {
			return (string)$value;
		}

		return '[complex]';
	}


}
