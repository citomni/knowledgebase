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
 * Registry: Boot contributions of citomni/knowledgebase.
 *
 * Declares what the package adds to a host app as constant arrays. Merging them with the host
 * app's own maps, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Services and cfg that work in both modes go in MAP_COMMON and CFG_COMMON. A mode-specific
 *   constant holds only values that depend on one runtime.
 * - Commands go in COMMANDS_CLI and routes in ROUTES_HTTP, never in a service map or a cfg
 *   constant.
 */
final class Registry {

	/**
	 * Services for HTTP and CLI.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const array MAP_COMMON = [
	
		'retriever' => \CitOmni\KnowledgeBase\Service\Retriever::class,
		
	];


	/**
	 * Cfg baseline for HTTP and CLI.
	 *
	 * @var array<string, mixed>
	 */
	public const array CFG_COMMON = [
	
		'knowledgebase' => [
			'embedding_profile' => 'openai-text-embedding-3-small', // vectorembedding profile for chunk and question embeddings.
			'chat_profile'      => 'gpt-5.4-nano',                  // helloai profile that writes the answer; also reranks when rerank_profile is null.
			'chunker' => [
				'strategy'       => 'fixed_size', // 'fixed_size' or 'paragraph'.
				'max_tokens'     => 512,          // Maximum chunk size in estimated tokens.
				'overlap_tokens' => 50,           // Estimated tokens shared by consecutive chunks; fixed_size only.
			],
			'ingest' => [
				'embedding_batch_size' => 100, // Chunks per embedding request, >= 1.
			],
			'retrieval' => [
				'lexical'              => true,  // Run the FULLTEXT leg.
				'vector'               => true,  // Run the embedding leg, and embed chunks at ingest.
				'rerank'               => true,  // Rerank the candidates with the rerank profile.
				'synonym_expansion'    => true,  // Expand the FULLTEXT query with synonym groups.
				'top_k'                => 7,     // Chunks returned per query, >= 1.
				'candidate_multiplier' => 3,     // Candidates fetched per returned chunk when reranking, >= 1.
				'min_similarity'       => 0.70,  // Minimum cosine similarity of a vector candidate, -1.0 to 1.0.
				'min_score'            => 0.0,   // Minimum fused score of a source the answer may use.
				'min_chunks'           => 1,     // Sources needed before an answer is generated, >= 1.
				'allow_low_context'    => false, // Answer with fewer than min_chunks sources instead of returning insufficient_context.
				'fusion' => [
					'method' => 'rrf', // Not read: hybrid retrieval always fuses with Reciprocal Rank Fusion.
					'rrf_k'  => 60,    // Reciprocal Rank Fusion constant, >= 1; higher flattens rank differences.
				],
				'rerank_profile' => null, // helloai profile for reranking; null or '' uses chat_profile.
			],
			'prompt' => [
				'system_template'    => null, // System prompt template; null or '' uses the built-in one.
				'max_context_tokens' => 4000, // Estimated tokens of chunk context in the prompt, >= 1.
				'language'           => null, // Answer language; null or '' takes the knowledge base's, then locale.language.
			],
			'query_log' => [
				'enabled' => false, // Log each query on a single knowledge base to know_query_log.
			],
		],
		
	];


	/**
	 * HTTP routes.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const array ROUTES_HTTP = [
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
	 * CLI commands.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const array COMMANDS_CLI = [
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
