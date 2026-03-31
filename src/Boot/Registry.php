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

namespace CitOmni\KnowledgeBase\Boot;

/**
 * Declare this provider package's boot contributions.
 *
 * Behavior:
 * - Registers HTTP and CLI service bindings.
 * - Registers HTTP cfg overlays.
 * - Registers HTTP routes.
 * - Registers CLI commands through COMMANDS_CLI.
 *
 * Notes:
 * - Commands belong in COMMANDS_CLI, not in MAP_CLI.
 * - Dispatch maps must remain separate from CFG constants.
 * - CLI mode may reuse the same provider cfg/service baselines as HTTP mode.
 */
final class Registry {

	/**
	 * HTTP service map.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const MAP_HTTP = [
	
		'retriever' => \CitOmni\KnowledgeBase\Service\Retriever::class,
		
	];


	/**
	 * HTTP cfg overlay.
	 *
	 * @var array<string, mixed>
	 */
	public const CFG_HTTP = [
	
		'knowledgebase' => [
			'embedding_profile' => 'openai-text-embedding-3-small',
			'chat_profile'      => 'gpt-5.4-nano',
			'chunker' => [
				'strategy'       => 'fixed_size',
				'max_tokens'     => 512,
				'overlap_tokens' => 50,
			],
			'ingest' => [
				'embedding_batch_size' => 100,
			],
			'retrieval' => [
				'lexical'              => true,
				'vector'               => true,
				'rerank'               => true,
				'synonym_expansion'    => true,
				'top_k'                => 7,
				'candidate_multiplier' => 3,
				'min_similarity'       => 0.70,
				'min_score'            => 0.0,
				'min_chunks'           => 1,
				'allow_low_context'    => false,
				'fusion' => [
					'method' => 'rrf',
					'rrf_k'  => 60,
				],
				'rerank_profile' => null,
			],
			'prompt' => [
				'system_template'    => null,
				'max_context_tokens' => 4000,
				'language'           => null,
			],
			'query_log' => [
				'enabled' => false,
			],
		],
		
	];


	/**
	 * HTTP routes.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const ROUTES_HTTP = [
		// '/hello' => [
			// 'controller' => \CitOmni\ProviderSkeleton\Controller\HelloController::class,
			// 'action'     => 'index',
			// 'methods'    => ['GET'],
			// 'options'    => [
				// 'who' => 'world',
			// ],
		// ],
	];

	/**
	 * CLI service map.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const MAP_CLI = self::MAP_HTTP;

	/**
	 * CLI cfg overlay.
	 *
	 * @var array<string, mixed>
	 */
	public const CFG_CLI = self::CFG_HTTP;

	/**
	 * CLI commands.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const COMMANDS_CLI = [
		'know:create-base' => [
			'command'     => \CitOmni\KnowledgeBase\Command\CreateBaseCommand::class,
			'description' => 'Create a knowledge base container',
		],
		// 'know:ingest' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\IngestCommand::class,
			// 'description' => 'Ingest a document into the knowledge base',
		// ],
		'know:search' => [
			'command'     => \CitOmni\KnowledgeBase\Command\SearchCommand::class,
			'description' => 'Test a retrieval query from the command line',
		],
		// 'know:stats' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\StatsCommand::class,
			// 'description' => 'Show knowledge base statistics',
		// ],
		// 'know:purge' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\PurgeCommand::class,
			// 'description' => 'Remove a document and its descendants from the knowledge base',
		// ],
		// 'know:setup-query-log' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\SetupQueryLogCommand::class,
			// 'description' => 'Create the optional query log table',
		// ],
		// 'know:prune-query-log' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\PruneQueryLogCommand::class,
			// 'description' => 'Prune old query log entries',
		// ],
		// 'know:synonyms:import' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\SynonymsImportCommand::class,
			// 'description' => 'Bulk-import synonym groups from JSON or CSV',
		// ],
		// 'know:synonyms:list' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\SynonymsListCommand::class,
			// 'description' => 'List all synonym groups for a knowledge base',
		// ],
		// 'know:synonyms:add' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\SynonymsAddCommand::class,
			// 'description' => 'Create one synonym group and its term rows',
		// ],
		// 'know:synonyms:remove' => [
			// 'command'     => \CitOmni\KnowledgeBase\Command\SynonymsRemoveCommand::class,
			// 'description' => 'Remove one synonym group and its term rows',
		// ],
		'know:import-retsinformation' => [
			'command'     => \CitOmni\KnowledgeBase\Command\ImportRetsinformationCommand::class,
			'description' => 'Importer HTML med lovtekst fra Retsinformation.dk',
		],
	];
}
