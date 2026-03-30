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
use CitOmni\KnowledgeBase\Repository\KnowledgeBaseRepository;

/**
 * Create a knowledge base container in know_bases.
 *
 * This command is a thin CLI adapter over KnowledgeBaseRepository. It creates
 * one base row with explicit metadata, language, and status values.
 *
 * Behavior:
 * - Creates one knowledge base row in know_bases.
 * - Fails fast if the slug already exists.
 * - Supports metadata from either a JSON file or an inline JSON string.
 * - Normalizes metadata to one internal payload shape: array.
 *
 * Notes:
 * - This is trivial CRUD, so the command talks to the Repository directly.
 * - metadata-file and metadata-json are mutually exclusive.
 * - Metadata is optional in V1.
 */
final class CreateBaseCommand extends BaseCommand {

	/**
	 * Define CLI signature.
	 *
	 * @return array Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'slug' => [
					'description' => 'Unique knowledge base slug',
					'required' => true,
				],
				'title' => [
					'description' => 'Human-readable knowledge base title',
					'required' => true,
				],
			],
			'options' => [
				'description' => [
					'short' => 'd',
					'type' => 'string',
					'description' => 'Optional knowledge base description',
					'default' => '',
				],
				'language' => [
					'short' => 'l',
					'type' => 'string',
					'description' => 'Language code (default: da)',
					'default' => 'da',
				],
				'status' => [
					'short' => 's',
					'type' => 'string',
					'description' => 'Status: active|inactive|archived',
					'default' => 'active',
				],
				'metadata-file' => [
					'type' => 'string',
					'description' => 'Path to a JSON file containing metadata object',
					'default' => '',
				],
				'metadata-json' => [
					'type' => 'string',
					'description' => 'Inline JSON object containing metadata',
					'default' => '',
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
		try {
			$slug = $this->normalizeRequiredString($this->argString('slug'), 'slug');
			$title = $this->normalizeRequiredString($this->argString('title'), 'title');
			$description = $this->normalizeNullableString($this->getString('description'));
			$language = $this->normalizeRequiredString($this->getString('language'), 'language');
			$status = $this->normalizeStatus($this->getString('status'));
			$metadata = $this->resolveMetadata(
				$this->getString('metadata-file'),
				$this->getString('metadata-json')
			);

			$repo = new KnowledgeBaseRepository($this->app);
			$existing = $repo->findBySlug($slug);
			if ($existing !== null) {
				$this->error('Knowledge base slug already exists: ' . $slug);
				return self::FAILURE;
			}

			$payload = [
				'slug' => $slug,
				'title' => $title,
				'language' => $language,
				'status' => $status,
			];

			if ($description !== null) {
				$payload['description'] = $description;
			}
			if ($metadata !== null) {
				$payload['metadata'] = $metadata;
			}

			$knowledgeBaseId = $repo->insert($payload);
			$created = $repo->findById($knowledgeBaseId);

			$this->success('Knowledge base created successfully.');
			$this->info('ID: ' . $knowledgeBaseId);
			$this->info('Slug: ' . $slug);
			$this->info('Title: ' . $title);
			$this->info('Language: ' . $language);
			$this->info('Status: ' . $status);

			if ($created !== null && $created['description'] !== null) {
				$this->info('Description: ' . $created['description']);
			}
			if ($metadata !== null) {
				$this->info('Metadata keys: ' . \count($metadata));
			}

			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}
	}








	// ----------------------------------------------------------------
	// Metadata loading
	// ----------------------------------------------------------------

	/**
	 * Resolve metadata from either file or inline JSON.
	 *
	 * @param string $metadataFile Path to metadata JSON file.
	 * @param string $metadataJson Inline metadata JSON string.
	 * @return ?array Normalized metadata array or null.
	 */
	private function resolveMetadata(string $metadataFile, string $metadataJson): ?array {
		$metadataFile = \trim($metadataFile);
		$metadataJson = \trim($metadataJson);

		if ($metadataFile !== '' && $metadataJson !== '') {
			throw new \InvalidArgumentException(
				'Use either --metadata-file or --metadata-json, not both.'
			);
		}

		if ($metadataFile !== '') {
			return $this->loadMetadataFile($metadataFile);
		}

		if ($metadataJson !== '') {
			return $this->decodeMetadataJson($metadataJson, '--metadata-json');
		}

		return null;
	}


	/**
	 * Load metadata from a JSON file.
	 *
	 * @param string $path File path.
	 * @return array Decoded metadata array.
	 */
	private function loadMetadataFile(string $path): array {
		if (!\is_file($path)) {
			throw new \InvalidArgumentException('Metadata file was not found: ' . $path);
		}
		if (!\is_readable($path)) {
			throw new \InvalidArgumentException('Metadata file is not readable: ' . $path);
		}

		$content = \file_get_contents($path);
		if ($content === false) {
			throw new \RuntimeException('Failed to read metadata file: ' . $path);
		}

		return $this->decodeMetadataJson($content, '--metadata-file');
	}


	/**
	 * Decode and validate metadata JSON.
	 *
	 * @param string $json Raw JSON.
	 * @param string $sourceLabel Human-readable source label.
	 * @return array Decoded metadata array.
	 */
	private function decodeMetadataJson(string $json, string $sourceLabel): array {
		$decoded = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!\is_array($decoded)) {
			throw new \InvalidArgumentException($sourceLabel . ' must decode to a JSON object/array.');
		}
		return $decoded;
	}







	// ----------------------------------------------------------------
	// Scalar normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize a required non-empty string.
	 *
	 * @param string $value Raw value.
	 * @param string $field Field name.
	 * @return string Normalized string.
	 */
	private function normalizeRequiredString(string $value, string $field): string {
		$value = \trim($value);
		if ($value === '') {
			throw new \InvalidArgumentException('Field must not be empty: ' . $field);
		}
		return $value;
	}


	/**
	 * Normalize an optional string to null-or-string.
	 *
	 * @param string $value Raw value.
	 * @return ?string Normalized value.
	 */
	private function normalizeNullableString(string $value): ?string {
		$value = \trim($value);
		return $value === '' ? null : $value;
	}


	/**
	 * Normalize knowledge base status.
	 *
	 * @param string $status Raw status.
	 * @return string Normalized status.
	 */
	private function normalizeStatus(string $status): string {
		$status = \trim($status);
		if ($status !== 'active' && $status !== 'inactive' && $status !== 'archived') {
			throw new \InvalidArgumentException(
				'Status must be one of: active, inactive, archived.'
			);
		}
		return $status;
	}

}
