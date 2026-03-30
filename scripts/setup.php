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

/**
 * Post-create scaffolder for citomni/provider-skeleton.
 * - Copies provider-ready composer.json and README stub into project root.
 * - Uses __DIR__-relative paths (robust vs. CWD differences / DevKit).
 */

$root   = \realpath(\dirname(__DIR__));            // project root
$scripts= __DIR__;
$stubs  = $root . DIRECTORY_SEPARATOR . 'stubs';

$srcComposer = $stubs . DIRECTORY_SEPARATOR . 'provider.composer.json.stub';
$dstComposer = $root . DIRECTORY_SEPARATOR . 'composer.json';
$srcReadme   = $stubs . DIRECTORY_SEPARATOR . 'README.provider.stub';
$dstReadme   = $root . DIRECTORY_SEPARATOR . 'README.md';

// Optional guard: only run once for skeletons
$skel = false;
if (\is_file($dstComposer)) {
	$orig = \json_decode((string)\file_get_contents($dstComposer), true);
	$skel = \is_array($orig) && ($orig['extra']['citomni-skeleton'] ?? false) === true;
}

if (!$skel) {
	echo "Skip: not a skeleton run (composer.json already converted or no marker).\n";
	exit(0);
}

$errors = [];

/** @return void */
$copy = function (string $from, string $to) use (&$errors): void {
	if (!\is_file($from)) { $errors[] = "Missing stub: {$from}"; return; }
	if (\file_put_contents($to, \file_get_contents($from)) === false) {
		$errors[] = "Failed to write: {$to}";
	}
};

// Overwrite composer.json with provider (library) stub
$copy($srcComposer, $dstComposer);
echo "Wrote provider composer.json (library).\n";

// README stub (optional)
if (\is_file($srcReadme)) {
	$copy($srcReadme, $dstReadme);
	echo "Wrote provider README stub.\n";
}

if ($errors) {
	echo "Scaffold completed with warnings:\n - " . \implode("\n - ", $errors) . "\n";
} else {
	echo "Scaffold completed.\n";
}

echo PHP_EOL, "Next steps:",
	PHP_EOL, "  1) Replace placeholders (vendor/name, namespaces, URLs).",
	PHP_EOL, "  2) composer validate",
	PHP_EOL, "  3) composer dump-autoload -o",
	PHP_EOL;
