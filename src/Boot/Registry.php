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

namespace CitOmni\ProviderSkeleton\Boot;

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
		'greeting' => [
			'class'   => \CitOmni\ProviderSkeleton\Service\GreetingService::class,
			'options' => [
				'prefix' => 'Hello',
			],
		],
	];

	/**
	 * HTTP cfg overlay.
	 *
	 * @var array<string, mixed>
	 */
	public const CFG_HTTP = [
	
		'knowledgebase' => [
			'embedding_profile' => 'openai-text-embedding-3-small',
			'chat_profile'      => 'openai-gpt-4o-mini',
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
				'rerank'               => false,
				'synonym_expansion'    => true,
				'top_k'                => 5,
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
		'/hello' => [
			'controller' => \CitOmni\ProviderSkeleton\Controller\HelloController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
			'options'    => [
				'who' => 'world',
			],
		],
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
		'provider-skeleton:demo' => [
			'command'     => \CitOmni\ProviderSkeleton\Command\DemoCommand::class,
			'description' => 'Run the provider skeleton demo command',
		],
	];
}
