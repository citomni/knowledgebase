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
use CitOmni\KnowledgeBase\Operation\IngestDocument;

/**
 * Import a Retsinformation HTML document into the knowledge base.
 *
 * This command reads a locally saved HTML file from retsinformation.dk,
 * parses the document structure into stable units, and delegates the
 * actual persistence/chunking/embedding flow to IngestDocument.
 *
 * Supported structure in the current importer:
 * - document title from p.Titel2
 * - chapter units from p.Kapitel
 * - chapter titles from the following p.KapitelOverskrift2
 * - paragraph-group headings from p.ParagrafGruppeOverskrift
 * - paragraph units from p.Paragraf
 * - subsection units from p.Stk2
 * - list item units from p.Liste1
 *
 * Notes:
 * - This importer is intentionally pragmatic and tuned to the observed
 *   Retsinformation HTML structure in the provided Lejeloven example.
 * - The parser is deterministic and fail-fast, not "smart".
 * - Raw persistence is still owned by IngestDocument.
 */
final class ImportRetsinformationCommand extends BaseCommand {

	/**
	 * Define CLI signature.
	 *
	 * @return array Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'file' => [
					'description' => 'Path to a locally saved Retsinformation HTML file',
					'required' => true,
				],
			],
			'options' => [
				'kb' => [
					'short' => 'k',
					'type' => 'int',
					'description' => 'Target knowledge base id',
					'default' => 0,
				],
				'slug' => [
					'short' => 's',
					'type' => 'string',
					'description' => 'Document slug override',
					'default' => '',
				],
				'title' => [
					'short' => 't',
					'type' => 'string',
					'description' => 'Document title override',
					'default' => '',
				],
				'source-ref' => [
					'short' => 'r',
					'type' => 'string',
					'description' => 'Optional source reference override',
					'default' => '',
				],
				'effective-date' => [
					'short' => 'e',
					'type' => 'string',
					'description' => 'Optional effective date (YYYY-MM-DD)',
					'default' => '',
				],
				'version-label' => [
					'short' => 'v',
					'type' => 'string',
					'description' => 'Optional version label',
					'default' => '',
				],
				'language' => [
					'short' => 'l',
					'type' => 'string',
					'description' => 'Document language override',
					'default' => '',
				],
				'status' => [
					'type' => 'string',
					'description' => 'Document status override',
					'default' => 'active',
				],
				'dry-run' => [
					'type' => 'bool',
					'description' => 'Parse and show summary without ingesting',
					'default' => false,
				],
				'json' => [
					'short' => 'j',
					'type' => 'bool',
					'description' => 'Output parsed payload/result as JSON',
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
		try {
			$filePath = $this->argString('file');
			$knowledgeBaseId = $this->requirePositiveIntOption('kb');
			$dryRun = $this->getBool('dry-run');
			$json = $this->getBool('json');

			$html = $this->readHtmlFile($filePath);
			$parsed = $this->parseRetsinformationHtml($html);

			$payload = $this->buildIngestPayload(
				$knowledgeBaseId,
				$filePath,
				$html,
				$parsed
			);

			if ($dryRun) {
				return $this->outputDryRun($payload, $json);
			}

			$operation = new IngestDocument($this->app);
			$result = $operation->execute($payload);

			if ($json) {
				$this->stdout($this->encodePrettyJson($result));
				return self::SUCCESS;
			}

			$this->info('Import completed.');
			$this->info('document_id=' . $this->stringifyScalar($result['document_id'] ?? null));
			$this->info('status=' . $this->stringifyScalar($result['status'] ?? null));
			$this->info('reason=' . $this->stringifyScalar($result['reason'] ?? null));
			$this->info('units_count=' . $this->stringifyScalar($result['units_count'] ?? null));
			$this->info('chunks_count=' . $this->stringifyScalar($result['chunks_count'] ?? null));
			$this->info('embeddings_count=' . $this->stringifyScalar($result['embeddings_count'] ?? null));
			$this->info('embedding_model=' . $this->stringifyScalar($result['embedding_model'] ?? null));

			if (\is_string($result['error'] ?? null) && $result['error'] !== '') {
				$this->warning($result['error']);
			}

			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}
	}

	/**
	 * Read HTML file contents.
	 *
	 * @param string $filePath Input file path.
	 * @return string HTML contents.
	 * @throws \RuntimeException When reading fails.
	 */
	private function readHtmlFile(string $filePath): string {
		$filePath = \trim($filePath);
		if ($filePath === '') {
			throw new \RuntimeException('The file argument must not be empty.');
		}
		if (!\is_file($filePath)) {
			throw new \RuntimeException('HTML file not found: ' . $filePath);
		}
		if (!\is_readable($filePath)) {
			throw new \RuntimeException('HTML file is not readable: ' . $filePath);
		}

		$html = \file_get_contents($filePath);
		if (!\is_string($html) || $html === '') {
			throw new \RuntimeException('Failed to read HTML file or file is empty: ' . $filePath);
		}

		return $html;
	}

	/**
	 * Parse Retsinformation HTML into a normalized ingest-ready structure.
	 *
	 * @param string $html Raw HTML.
	 * @return array Parsed document structure.
	 * @throws \RuntimeException When parsing fails or document structure is missing.
	 */
	private function parseRetsinformationHtml(string $html): array {
		$dom = new \DOMDocument('1.0', 'UTF-8');

		$previousUseInternalErrors = \libxml_use_internal_errors(true);
		try {
			$loaded = $dom->loadHTML(
				'<?xml encoding="UTF-8">' . $html,
				LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT
			);
		} finally {
			\libxml_clear_errors();
			\libxml_use_internal_errors($previousUseInternalErrors);
		}

		if ($loaded !== true) {
			throw new \RuntimeException('Failed to parse the HTML document.');
		}

		$xpath = new \DOMXPath($dom);
		$contentRoot = $this->locateContentRoot($xpath);
		$paragraphNodes = $this->collectParagraphNodes($xpath, $contentRoot);

		if ($paragraphNodes === []) {
			throw new \RuntimeException('No paragraph nodes were found in the Retsinformation document.');
		}

		$documentTitle = null;
		$documentUnits = [];

		$currentChapterIndex = null;
		$currentGroupIndex = null;
		$currentParagraphIndex = null;

		$chapterSortOrder = 0;
		$chapterChildSortOrder = 0;
		$groupChildSortOrder = 0;
		$paragraphChildSortOrder = 0;

		$pendingChapterTitle = null;

		foreach ($paragraphNodes as $node) {
			$className = $this->normalizeClassName($node->getAttribute('class'));
			if ($className === '') {
				continue;
			}

			if ($className === 'Titel2') {
				if ($documentTitle === null) {
					$documentTitle = $this->normalizeText($node->textContent);
				}
				continue;
			}

			if ($className === 'Kapitel') {
				$identifier = $this->extractSpanTextByPrefix($node, 'Kap');
				if ($identifier === null) {
					$identifier = $this->normalizeText($node->textContent);
				}
				if ($identifier === '') {
					continue;
				}

				$chapterSortOrder++;
				$chapterChildSortOrder = 0;
				$groupChildSortOrder = 0;
				$paragraphChildSortOrder = 0;
				$currentGroupIndex = null;
				$currentParagraphIndex = null;
				$pendingChapterTitle = null;

				$documentUnits[] = $this->createUnit(
					'chapter',
					$identifier,
					null,
					null,
					$chapterSortOrder
				);
				$currentChapterIndex = \count($documentUnits) - 1;
				continue;
			}

			if ($className === 'KapitelOverskrift2') {
				$title = $this->normalizeText($node->textContent);
				if ($title === '') {
					continue;
				}

				if ($currentChapterIndex !== null && !isset($documentUnits[$currentChapterIndex]['title'])) {
					$documentUnits[$currentChapterIndex]['title'] = $title;
				} else {
					$pendingChapterTitle = $title;
				}
				continue;
			}

			if ($className === 'ParagrafGruppeOverskrift') {
				$title = $this->normalizeText($node->textContent);
				if ($title === '') {
					continue;
				}

				if ($currentChapterIndex === null) {
					$chapterSortOrder++;
					$groupSortOrder = 0;
					$paragraphSortOrder = 0;
					$childSortOrder = 0;
					$currentParagraphIndex = null;

					$documentUnits[] = $this->createUnit(
						'chapter',
						'Kapitel 0',
						$pendingChapterTitle,
						null,
						$chapterSortOrder
					);
					$currentChapterIndex = \count($documentUnits) - 1;
					$pendingChapterTitle = null;
				}

				$chapterChildSortOrder++;
				$groupChildSortOrder = 0;
				$paragraphChildSortOrder = 0;
				$currentParagraphIndex = null;

				$documentUnits[$currentChapterIndex]['children'][] = $this->createUnit(
					'paragraph_group',
					null,
					$title,
					null,
					$chapterChildSortOrder
				);
				$currentGroupIndex = \count($documentUnits[$currentChapterIndex]['children']) - 1;
				continue;
			}

			if ($className === 'Paragraf') {
				$paragraph = $this->parseParagraphNode($node);
				if ($paragraph === null) {
					continue;
				}

				if ($currentChapterIndex === null) {
					$chapterSortOrder++;
					$groupSortOrder = 0;
					$paragraphSortOrder = 0;
					$childSortOrder = 0;

					$documentUnits[] = $this->createUnit(
						'chapter',
						'Kapitel 0',
						$pendingChapterTitle,
						null,
						$chapterSortOrder
					);
					$currentChapterIndex = \count($documentUnits) - 1;
					$pendingChapterTitle = null;
				}

				$paragraphChildSortOrder = 0;

				if ($currentGroupIndex !== null) {
					$groupChildSortOrder++;

					$paragraphUnit = $this->createUnit(
						'paragraph',
						$paragraph['identifier'],
						null,
						$paragraph['body'],
						$groupChildSortOrder,
						[
							'source_class' => 'Paragraf',
							'source_id' => $this->normalizeNullableText($node->getAttribute('id')),
						]
					);

					$documentUnits[$currentChapterIndex]['children'][$currentGroupIndex]['children'][] = $paragraphUnit;
					$currentParagraphIndex = \count($documentUnits[$currentChapterIndex]['children'][$currentGroupIndex]['children']) - 1;
				} else {
					$chapterChildSortOrder++;

					$paragraphUnit = $this->createUnit(
						'paragraph',
						$paragraph['identifier'],
						null,
						$paragraph['body'],
						$chapterChildSortOrder,
						[
							'source_class' => 'Paragraf',
							'source_id' => $this->normalizeNullableText($node->getAttribute('id')),
						]
					);

					$documentUnits[$currentChapterIndex]['children'][] = $paragraphUnit;
					$currentParagraphIndex = \count($documentUnits[$currentChapterIndex]['children']) - 1;
				}
				continue;
			}

			if ($className === 'Stk2') {
				$subsection = $this->parseSubsectionNode($node);
				if ($subsection === null || $currentChapterIndex === null || $currentParagraphIndex === null) {
					continue;
				}

				$paragraphChildSortOrder++;
				$childUnit = $this->createUnit(
					'subsection',
					$subsection['identifier'],
					null,
					$subsection['body'],
					$paragraphChildSortOrder,
					[
						'source_class' => 'Stk2',
						'source_id' => $this->normalizeNullableText($node->getAttribute('id')),
					]
				);

				if ($currentGroupIndex !== null) {
					$documentUnits[$currentChapterIndex]['children'][$currentGroupIndex]['children'][$currentParagraphIndex]['children'][] = $childUnit;
				} else {
					$documentUnits[$currentChapterIndex]['children'][$currentParagraphIndex]['children'][] = $childUnit;
				}
				continue;
			}

			if ($className === 'Liste1') {
				$listItem = $this->parseListItemNode($node);
				if ($listItem === null || $currentChapterIndex === null || $currentParagraphIndex === null) {
					continue;
				}

				$paragraphChildSortOrder++;
				$childUnit = $this->createUnit(
					'list_item',
					$listItem['identifier'],
					null,
					$listItem['body'],
					$paragraphChildSortOrder,
					[
						'source_class' => 'Liste1',
						'source_id' => $this->normalizeNullableText($node->getAttribute('id')),
					]
				);

				if ($currentGroupIndex !== null) {
					$documentUnits[$currentChapterIndex]['children'][$currentGroupIndex]['children'][$currentParagraphIndex]['children'][] = $childUnit;
				} else {
					$documentUnits[$currentChapterIndex]['children'][$currentParagraphIndex]['children'][] = $childUnit;
				}
				continue;
			}
		}

		$documentTitle = $documentTitle !== null && $documentTitle !== '' ? $documentTitle : 'Retsinformation document';
		$documentUnits = $this->stripEmptyChildrenRecursively($documentUnits);

		if ($documentUnits === []) {
			throw new \RuntimeException('The importer could not build any units from the HTML document.');
		}

		return [
			'title' => $documentTitle,
			'units' => $documentUnits,
			'text' => $this->buildCanonicalSourceText($documentUnits),
		];
	}

	/**
	 * Locate the main content root.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @return \DOMNode Content root node.
	 * @throws \RuntimeException When not found.
	 */
	private function locateContentRoot(\DOMXPath $xpath): \DOMNode {
		$nodes = $xpath->query('//*[@id="restylingRoot"]');
		if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
			return $nodes->item(0);
		}

		throw new \RuntimeException('Could not find the expected Retsinformation content root (#restylingRoot).');
	}

	/**
	 * Collect all paragraph-like nodes in document order.
	 *
	 * @param \DOMXPath $xpath XPath instance.
	 * @param \DOMNode $contentRoot Content root.
	 * @return array Paragraph nodes.
	 */
	private function collectParagraphNodes(\DOMXPath $xpath, \DOMNode $contentRoot): array {
		$nodeList = $xpath->query('.//p', $contentRoot);
		if (!$nodeList instanceof \DOMNodeList || $nodeList->length === 0) {
			return [];
		}

		$nodes = [];
		foreach ($nodeList as $node) {
			if ($node instanceof \DOMElement) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Build the final ingest payload.
	 *
	 * @param int $knowledgeBaseId Target knowledge base id.
	 * @param string $filePath Source file path.
	 * @param string $html Raw HTML.
	 * @param array $parsed Parsed structure.
	 * @return array Ingest payload.
	 */
	private function buildIngestPayload(int $knowledgeBaseId, string $filePath, string $html, array $parsed): array {
		$titleOverride = $this->getString('title');
		$title = $titleOverride !== '' ? \trim($titleOverride) : (string)$parsed['title'];

		$slugOverride = $this->getString('slug');
		$slug = $slugOverride !== '' ? \trim($slugOverride) : $this->slugify($title);

		$sourceRefOverride = $this->getString('source-ref');
		$sourceRef = $sourceRefOverride !== '' ? \trim($sourceRefOverride) : $filePath;

		$payload = [
			'knowledge_base_id' => $knowledgeBaseId,
			'slug' => $slug,
			'title' => $title,
			'source_type' => 'retsinformation_html',
			'source_ref' => $sourceRef,
			'status' => $this->normalizeDocumentStatus($this->getString('status')),
			'text' => (string)$parsed['text'],
			'units' => $parsed['units'],
			'metadata' => [
				'source_system' => 'retsinformation',
				'source_file' => \basename($filePath),
				'importer' => 'ImportRetsinformationCommand',
				'raw_html_bytes' => \strlen($html),
			],
		];

		$effectiveDate = $this->getString('effective-date');
		if ($effectiveDate !== '') {
			$payload['effective_date'] = $this->normalizeDateString($effectiveDate, '--effective-date');
		}

		$versionLabel = $this->getString('version-label');
		if ($versionLabel !== '') {
			$payload['version_label'] = \trim($versionLabel);
		}

		$language = $this->getString('language');
		if ($language !== '') {
			$payload['language'] = \trim($language);
		}

		return $payload;
	}

	/**
	 * Output dry-run summary or payload.
	 *
	 * @param array $payload Parsed ingest payload.
	 * @param bool $json Whether to output JSON.
	 * @return int Exit code.
	 */
	private function outputDryRun(array $payload, bool $json): int {
		if ($json) {
			$this->stdout($this->encodePrettyJson($payload));
			return self::SUCCESS;
		}

		$this->info('Dry-run completed.');
		$this->info('slug=' . $payload['slug']);
		$this->info('title=' . $payload['title']);
		$this->info('source_type=' . $payload['source_type']);
		$this->info('units=' . $this->countUnitsRecursive($payload['units']));
		$this->info('top_level_units=' . \count($payload['units']));
		$this->info('canonical_text_bytes=' . \strlen((string)$payload['text']));

		return self::SUCCESS;
	}

	/**
	 * Parse one paragraph node.
	 *
	 * @param \DOMElement $node Paragraph element.
	 * @return ?array Parsed paragraph or null.
	 */
	private function parseParagraphNode(\DOMElement $node): ?array {
		$identifier = $this->extractChildTextByClass($node, 'ParagrafNr');
		if ($identifier === null || $identifier === '') {
			return null;
		}

		$body = $this->extractOwnTextExcludingChildClass($node, 'ParagrafNr');
		$body = $this->stripLeadingPunctuation($body);

		return [
			'identifier' => $identifier,
			'body' => $body !== '' ? $body : null,
		];
	}

	/**
	 * Parse one subsection node.
	 *
	 * @param \DOMElement $node Subsection element.
	 * @return ?array Parsed subsection or null.
	 */
	private function parseSubsectionNode(\DOMElement $node): ?array {
		$identifier = $this->extractChildTextByClass($node, 'StkNr');
		if ($identifier === null || $identifier === '') {
			return null;
		}

		$body = $this->extractOwnTextExcludingChildClass($node, 'StkNr');
		$body = $this->stripLeadingPunctuation($body);

		return [
			'identifier' => $identifier,
			'body' => $body !== '' ? $body : null,
		];
	}

	/**
	 * Parse one list item node.
	 *
	 * @param \DOMElement $node List item element.
	 * @return ?array Parsed list item or null.
	 */
	private function parseListItemNode(\DOMElement $node): ?array {
		$identifier = $this->extractChildTextByClass($node, 'Liste1Nr');
		if ($identifier === null || $identifier === '') {
			return null;
		}

		$body = $this->extractOwnTextExcludingChildClass($node, 'Liste1Nr');
		$body = $this->stripLeadingPunctuation($body);

		return [
			'identifier' => $identifier,
			'body' => $body !== '' ? $body : null,
		];
	}

	/**
	 * Create a normalized unit row.
	 *
	 * @param string $unitType Unit type.
	 * @param ?string $identifier Optional identifier.
	 * @param ?string $title Optional title.
	 * @param ?string $body Optional body.
	 * @param int $sortOrder Sort order.
	 * @param ?array $metadata Optional metadata.
	 * @return array Unit row.
	 */
	private function createUnit(
		string $unitType,
		?string $identifier,
		?string $title,
		?string $body,
		int $sortOrder,
		?array $metadata = null
	): array {
		$unit = [
			'unit_type' => $unitType,
			'sort_order' => $sortOrder,
			'children' => [],
		];

		$identifier = $this->normalizeNullableText($identifier);
		if ($identifier !== null) {
			$unit['identifier'] = $identifier;
		}

		$title = $this->normalizeNullableText($title);
		if ($title !== null) {
			$unit['title'] = $title;
		}

		$body = $this->normalizeNullableText($body);
		if ($body !== null) {
			$unit['body'] = $body;
		}

		if (\is_array($metadata) && $metadata !== []) {
			$unit['metadata'] = $metadata;
		}

		return $unit;
	}

	/**
	 * Strip empty children arrays recursively.
	 *
	 * @param array $units Units.
	 * @return array Normalized units.
	 */
	private function stripEmptyChildrenRecursively(array $units): array {
		foreach ($units as $index => $unit) {
			if (!\is_array($unit)) {
				unset($units[$index]);
				continue;
			}

			$children = $unit['children'] ?? [];
			if (\is_array($children) && $children !== []) {
				$children = $this->stripEmptyChildrenRecursively($children);
				if ($children !== []) {
					$units[$index]['children'] = $children;
				} else {
					unset($units[$index]['children']);
				}
			} else {
				unset($units[$index]['children']);
			}
		}

		return \array_values($units);
	}

	/**
	 * Build canonical source text from parsed units.
	 *
	 * This avoids hashing the raw HTML wrapper and instead hashes the
	 * logical parsed document text.
	 *
	 * @param array $units Units.
	 * @return string Canonical text.
	 */
	private function buildCanonicalSourceText(array $units): string {
		$parts = [];
		$this->appendCanonicalUnitText($parts, $units);
		return \implode("\n\n", $parts);
	}

	/**
	 * Append canonical text recursively.
	 *
	 * @param array $parts Output text parts.
	 * @param array $units Units.
	 * @return void
	 */
	private function appendCanonicalUnitText(array &$parts, array $units): void {
		foreach ($units as $unit) {
			if (!\is_array($unit)) {
				continue;
			}

			$lines = [];
			if (\is_string($unit['identifier'] ?? null) && $unit['identifier'] !== '') {
				$lines[] = $unit['identifier'];
			}
			if (\is_string($unit['title'] ?? null) && $unit['title'] !== '') {
				$lines[] = $unit['title'];
			}
			if (\is_string($unit['body'] ?? null) && $unit['body'] !== '') {
				$lines[] = $unit['body'];
			}

			if ($lines !== []) {
				$parts[] = \implode("\n", $lines);
			}

			$children = $unit['children'] ?? null;
			if (\is_array($children) && $children !== []) {
				$this->appendCanonicalUnitText($parts, $children);
			}
		}
	}

	/**
	 * Count units recursively.
	 *
	 * @param array $units Units.
	 * @return int Unit count.
	 */
	private function countUnitsRecursive(array $units): int {
		$count = 0;

		foreach ($units as $unit) {
			if (!\is_array($unit)) {
				continue;
			}

			$count++;
			$children = $unit['children'] ?? null;
			if (\is_array($children) && $children !== []) {
				$count += $this->countUnitsRecursive($children);
			}
		}

		return $count;
	}

	/**
	 * Extract text from the first descendant span whose id starts with a prefix.
	 *
	 * @param \DOMElement $node Parent node.
	 * @param string $prefix Id prefix.
	 * @return ?string Text or null.
	 */
	private function extractSpanTextByPrefix(\DOMElement $node, string $prefix): ?string {
		$xpath = new \DOMXPath($node->ownerDocument);
		$query = './/span[starts-with(@id, "' . $this->escapeForXpathLiteral($prefix) . '")]';
		$list = $xpath->query($query, $node);

		if (!$list instanceof \DOMNodeList || $list->length === 0) {
			return null;
		}

		$text = $this->normalizeText($list->item(0)->textContent);
		return $text !== '' ? $text : null;
	}

	/**
	 * Extract child text by descendant class name.
	 *
	 * @param \DOMElement $node Parent node.
	 * @param string $className Class name to match.
	 * @return ?string Text or null.
	 */
	private function extractChildTextByClass(\DOMElement $node, string $className): ?string {
		$xpath = new \DOMXPath($node->ownerDocument);
		$query = './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $this->escapeForXpathLiteral($className) . ' ")]';
		$list = $xpath->query($query, $node);

		if (!$list instanceof \DOMNodeList || $list->length === 0) {
			return null;
		}

		$text = $this->normalizeText($list->item(0)->textContent);
		return $text !== '' ? $text : null;
	}

	/**
	 * Extract node text while excluding descendants that carry a specific class.
	 *
	 * @param \DOMElement $node Parent node.
	 * @param string $excludedClass Class to exclude.
	 * @return string Remaining text.
	 */
	private function extractOwnTextExcludingChildClass(\DOMElement $node, string $excludedClass): string {
		$clone = $node->cloneNode(true);
		if (!$clone instanceof \DOMElement) {
			return '';
		}

		$xpath = new \DOMXPath($clone->ownerDocument);
		$query = './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $this->escapeForXpathLiteral($excludedClass) . ' ")]';
		$list = $xpath->query($query, $clone);

		if ($list instanceof \DOMNodeList) {
			$toRemove = [];
			foreach ($list as $child) {
				if ($child instanceof \DOMNode) {
					$toRemove[] = $child;
				}
			}
			foreach ($toRemove as $child) {
				if ($child->parentNode !== null) {
					$child->parentNode->removeChild($child);
				}
			}
		}

		return $this->normalizeText($clone->textContent);
	}

	/**
	 * Normalize a class string to its primary class token.
	 *
	 * @param string $className Raw class attribute.
	 * @return string Primary class.
	 */
	private function normalizeClassName(string $className): string {
		$className = \trim($className);
		if ($className === '') {
			return '';
		}

		$parts = \preg_split('/\s+/u', $className) ?: [];
		return isset($parts[0]) ? (string)$parts[0] : '';
	}

	/**
	 * Normalize text with whitespace collapse.
	 *
	 * @param string $text Raw text.
	 * @return string Normalized text.
	 */
	private function normalizeText(string $text): string {
		$text = \html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = \preg_replace('/\x{00A0}/u', ' ', $text) ?? $text;
		$text = \preg_replace('/\s+/u', ' ', $text) ?? $text;
		return \trim($text);
	}

	/**
	 * Normalize nullable text.
	 *
	 * @param ?string $text Raw text.
	 * @return ?string Normalized text or null.
	 */
	private function normalizeNullableText(?string $text): ?string {
		if ($text === null) {
			return null;
		}

		$text = $this->normalizeText($text);
		return $text === '' ? null : $text;
	}

	/**
	 * Strip leading punctuation that remains after identifier removal.
	 *
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	private function stripLeadingPunctuation(string $text): string {
		$text = \preg_replace('/^[\.\,\:\;\---\)\]]+\s*/u', '', $text) ?? $text;
		return \trim($text);
	}

	/**
	 * Build a slug from title text.
	 *
	 * @param string $value Input title.
	 * @return string Slug.
	 */
	private function slugify(string $value): string {
		$value = \mb_strtolower(\trim($value), 'UTF-8');
		$value = \strtr($value, [
			'æ' => 'ae',
			'ø' => 'oe',
			'å' => 'aa',
		]);
		$value = \preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
		$value = \trim($value, '-');

		if ($value === '') {
			return 'retsinformation-document';
		}

		return $value;
	}

	/**
	 * Normalize document status.
	 *
	 * @param string $status Raw status.
	 * @return string Status.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function normalizeDocumentStatus(string $status): string {
		$status = \trim($status);
		if ($status === '') {
			return 'active';
		}

		$allowed = ['draft', 'active', 'superseded', 'archived'];
		if (!\in_array($status, $allowed, true)) {
			throw new \InvalidArgumentException(
				'--status must be one of: ' . \implode(', ', $allowed) . '.'
			);
		}

		return $status;
	}

	/**
	 * Normalize a YYYY-MM-DD date string.
	 *
	 * @param string $value Raw date.
	 * @param string $optionName Option name for error messages.
	 * @return string Normalized date.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function normalizeDateString(string $value, string $optionName): string {
		$value = \trim($value);
		if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			throw new \InvalidArgumentException($optionName . ' must use YYYY-MM-DD.');
		}

		$date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
		$errors = \DateTimeImmutable::getLastErrors();

		if (!$date instanceof \DateTimeImmutable || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
			throw new \InvalidArgumentException($optionName . ' is not a valid calendar date.');
		}

		return $date->format('Y-m-d');
	}

	/**
	 * Read a required positive integer option.
	 *
	 * @param string $name Option name.
	 * @return int Value.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function requirePositiveIntOption(string $name): int {
		$value = $this->getInt($name);
		if ($value <= 0) {
			throw new \InvalidArgumentException('--' . $name . ' must be greater than zero.');
		}
		return $value;
	}

	/**
	 * Escape a literal string for safe insertion into simple XPath literals.
	 *
	 * @param string $value Raw string.
	 * @return string Escaped string.
	 */
	private function escapeForXpathLiteral(string $value): string {
		return \str_replace('"', '', $value);
	}

	/**
	 * Encode pretty JSON.
	 *
	 * @param mixed $value Value to encode.
	 * @return string JSON output.
	 * @throws \JsonException When encoding fails.
	 */
	private function encodePrettyJson(mixed $value): string {
		return \json_encode(
			$value,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
		);
	}

	/**
	 * Convert a scalar-ish value to string for CLI output.
	 *
	 * @param mixed $value Input value.
	 * @return string Output string.
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
