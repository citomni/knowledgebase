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

namespace CitOmni\ProviderSkeleton\Command;

use CitOmni\Kernel\Command\BaseCommand;

/**
 * Demonstrate the provider skeleton CLI command contract.
 *
 * Behavior:
 * - Uses the declarative BaseCommand signature format.
 * - Reads one optional positional argument and a few typed options.
 * - Produces deterministic CLI output for smoke tests and examples.
 * - Optionally uses the provider's greeting service when available.
 *
 * Notes:
 * - Commands are transport adapters and should stay thin.
 * - This example intentionally avoids SQL and repository access.
 * - The command is registered through Registry::COMMANDS_CLI.
 */
final class DemoCommand extends BaseCommand {

	/**
	 * Declare the accepted CLI arguments and options.
	 *
	 * @return array<string, array<string, mixed>> Command signature definition.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'name' => [
					'description' => 'Name to greet',
					'required'    => false,
					'default'     => 'World',
				],
			],
			'options' => [
				'prefix' => [
					'short'       => 'p',
					'type'        => 'string',
					'description' => 'Greeting prefix override',
					'default'     => 'Hello',
				],
				'repeat' => [
					'short'       => 'r',
					'type'        => 'int',
					'description' => 'Number of times to print the greeting',
					'default'     => 1,
				],
				'shout' => [
					'short'       => 's',
					'type'        => 'bool',
					'description' => 'Uppercase the final output',
				],
			],
		];
	}

	/**
	 * Execute the command after argv parsing and validation.
	 *
	 * @return int Exit status code.
	 */
	protected function execute(): int {
		$name = $this->argString('name');
		$prefix = $this->getString('prefix');
		$repeat = $this->getInt('repeat');
		$shout = $this->getBool('shout');

		if ($repeat < 1) {
			$this->error('--repeat must be at least 1.');
			return self::FAILURE;
		}

		$line = $prefix . ', ' . $name . '!';

		if ($shout) {
			$line = \mb_strtoupper($line);
		}

		for ($i = 0; $i < $repeat; $i++) {
			$this->stdout($line);
		}

		$this->info('Command: ' . $this->commandName);
		$this->info('Completed successfully.');

		return self::SUCCESS;
	}

}
