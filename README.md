# knowledgebase

A CitOmni provider based on citomni/provider-skeleton

## Repository
https://github.com/citomni/knowledgebase

---

**CitOmni Knowledgebase** is a minimal, deterministic **CitOmni provider** (PHP 8.2+) – more requests per watt.
Contributes **config**, **services**, and **HTTP routes** via a package **Registry**, with optional
**controllers**, **commands**, and **models** – more requests per watt.

> **Scope:** Mode-neutral provider package.  
> Add `citomni/http` if you expose HTTP routes; add `citomni/cli` if you wire commands into a runner.

---

## Requirements

- PHP **8.2+**
- Composer
- `citomni/kernel` (required)
- Optional: `citomni/http` (for HTTP routes/controllers), `citomni/cli` (for commands)

---

## Install (as a library, in an app)

```bash
composer require citomni/knowledgebase
```

Enable the provider in your app:

```php
<?php
// app/config/providers.php
return [
	\CitOmni\Knowledgebase\Boot\Registry::class,
];
```

(Optional) override defaults. The baseline is shared by HTTP and CLI, so override it in the common app config:

```php
<?php
// app/config/citomni_cfg.php
return [
	'knowledgebase' => [
		'retrieval' => [
			'top_k' => 10, // Chunks returned per query.
		],
	],
];
```

---

## What this provider ships

* **Registry constants** (declared in `src/Boot/Registry.php`):
  * `MAP_COMMON`, `CFG_COMMON` (both modes)
  * `ROUTES_HTTP`, `COMMANDS_CLI`

* **Service(s)**:
  * **Best practice:** extend `\CitOmni\Kernel\Service\BaseService`
  * Registered via `MAP_COMMON`

* **(Optional) HTTP controller(s)**:
  * **Best practice:** extend `\CitOmni\Kernel\Controller\BaseController`
  * Referenced by `ROUTES_HTTP`

* **(Optional) CLI command(s)**:
  * **Best practice:** extend `\CitOmni\Kernel\Command\BaseCommand`

* **(Optional) Model(s)**:
  * **Best practice:** extend `\CitOmni\Kernel\Model\BaseModel`

### Constructor contract (best practice)

When extending the CitOmni base classes, the expected constructor shape is:

```php
__construct(\CitOmni\Kernel\App $app, array $options = [])
```

Controllers receive App and a small route hint array (template_file/template_layer) from Router.

```php
__construct(\CitOmni\Kernel\App $app, array $routeConfig = [])
```

### Lifecycle hook (best practice)

Base classes provide an `init()` hook for lightweight one-time setup.
Keep it fast. Defer heavy work until first actual use.

---

## Deterministic configuration (last wins)

Per mode (**HTTP|CLI**), config merges in this order:

1. Vendor baseline (by mode)
2. Providers (in `/config/providers.php`, order matters), each with `CFG_COMMON` then its mode constant
3. App common base (`/config/citomni_cfg.php`), then mode base (`/config/citomni_{http|cli}_cfg.php`)
4. App common env overlay (`/config/citomni_cfg.{dev|stage|prod}.php`), then mode env overlay (`/config/citomni_{http|cli}_cfg.{dev|stage|prod}.php`)

Config is exposed as a **deep, read-only** wrapper. A direct read throws `\OutOfBoundsException` at the first missing key; `??` and `isset()` never throw on a missing key, so they hide it.

The package reads the cfg its Registry ships directly. Cfg owned by the app or another package, or present in one mode only, is read with `?? <default>`:

```php
// Own cfg: CFG_COMMON ships knowledgebase.retrieval.top_k.
$topK = (int)$this->app->cfg->knowledgebase->retrieval->top_k;

// locale.timezone is app cfg.
$timezone = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

> **Note:** Routes are **not** part of configuration.
> HTTP routes are merged separately from provider registries (`ROUTES_HTTP`) and app routing sources.

---

## Provider registry (template)

Inside `src/Boot/Registry.php`:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Knowledgebase\Boot;

/**
 * Registry: Boot contributions of citomni/knowledgebase.
 *
 * Rules:
 * - Services and cfg that work in both modes go in MAP_COMMON and CFG_COMMON. A mode-specific
 *   constant (MAP_HTTP, MAP_CLI, CFG_HTTP, CFG_CLI) holds only values that depend on one runtime.
 */
final class Registry {

	/** Services for HTTP and CLI. */
	public const array MAP_COMMON = [
		'greeting' => \CitOmni\Knowledgebase\Service\GreetingService::class,
	];

	/** Cfg baseline for HTTP and CLI. */
	public const array CFG_COMMON = [
		'knowledgebase' => [
			'enabled'  => true,
			'greeting' => ['prefix' => 'Hello'],
		],
	];

	/** HTTP routes. */
	public const array ROUTES_HTTP = [
		'/hello' => [
			'controller' => \CitOmni\Knowledgebase\Controller\HelloController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
		],
	];
}
```

---

## Example service

```php
<?php
declare(strict_types=1);

namespace CitOmni\Knowledgebase\Service;

use CitOmni\Kernel\Service\BaseService;

final class GreetingService extends BaseService {

	/** Text placed before the name. */
	private string $prefix;

	protected function init(): void {
		// A prefix option, set in the service map, wins over cfg knowledgebase.greeting.prefix.
		// The cfg read is not guarded: CFG_COMMON ships it.
		$this->prefix = (string)($this->options['prefix'] ?? $this->app->cfg->knowledgebase->greeting->prefix);
	}

	public function make(string $name): string {
		return $this->prefix . ', ' . $name;
	}
}
```

---

## (Optional) Example route & controller

```php
<?php
declare(strict_types=1);

namespace CitOmni\Knowledgebase\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class HelloController extends BaseController {

	protected function init(): void {
		// Read/validate $this->routeConfig if needed
	}

	public function index(): void {
		$who = 'world';
		$msg = $this->app->greeting->make($who);

		echo "<!doctype html><meta charset=\"utf-8\"><title>Hello</title>";
		echo "<p>{$msg}</p>";
	}
}
```

---

## (Optional) Example command

```php
<?php
declare(strict_types=1);

namespace CitOmni\Knowledgebase\Command;

use CitOmni\Kernel\Command\BaseCommand;

final class HelloCommand extends BaseCommand {

	protected function init(): void {
		// Validate/normalize $this->options if needed
	}

	public function run(array $argv = []): int {
		$name = $argv[0] ?? ($this->options['default_name'] ?? 'world');
		$line = $this->app->greeting->make($name);
		\fwrite(\STDOUT, $line . \PHP_EOL);
		return 0;
	}
}
```

---

## Performance & caching (production)

* Prefer **compiled caches**:

  * `<appRoot>/var/cache/cfg.{http|cli}.php`
  * `<appRoot>/var/cache/services.{http|cli}.php`
  * `<appRoot>/var/cache/routes.http.php`

* Generate atomically during deploy:

```php
<?php
$app = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::HTTP);
$app->warmCache(true, true);
```

* Enable OPcache; consider `validate_timestamps=0` (invalidate on deploy).

---

## Coding & documentation conventions

* PHP **8.2+**, PSR-1/PSR-4
* **PascalCase** classes, **camelCase** methods/vars, **UPPER_SNAKE_CASE** constants
* **K&R braces**, **tabs** for indentation
* PHPDoc & inline comments in **English**
* Fail fast; do not catch unless necessary (global handler logs)

---

## License

CitOmni Knowledgebase is released under the **MIT** license. See `LICENSE` for details.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of Lars Grove Mortensen. Factual references are allowed; do not imply endorsement.

---

## Appendix: SPDX header template (MIT)

```php
<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (c) 2026-present Lars Grove Mortensen
 *
 * CitOmni Knowledgebase - A CitOmni provider based on citomni/provider-skeleton
 * Source: https://github.com/citomni/knowledgebase
 * License: See the LICENSE file for full terms.
 */
```
