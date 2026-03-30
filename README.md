# CitOmni Provider Skeleton

Minimal, deterministic **provider template** for CitOmni (PHP 8.2+).
Contributes **config**, **service maps**, and optional **routes** via provider registry constants - with optional **controllers**, **commands**, **operations**, **repositories**, **services**, **utils**, and **exceptions**. No magic. No surprises.

♻️ **Green by design** - fewer CPU cycles, lower memory, faster deploys. **More requests per watt.**

> **Scope:** Provider/package skeleton (mode-neutral).
> Use with `citomni/http` if you expose HTTP routes; use `citomni/cli` if you wire commands into a runner.
> For application skeletons, see `citomni/http-skeleton`, `citomni/cli-skeleton`, or `citomni/app-skeleton`.

**Further reading**
- Runtime / Execution Mode Layer — why CitOmni has exactly two modes and how deterministic merging works:  
  https://github.com/citomni/docs/blob/main/concepts/runtime-modes.md
- Provider Packages: Design, Semantics, and Best Practices — MAP_*/CFG_*, routes, precedence, testing:  
  https://github.com/citomni/docs/blob/main/concepts/services-and-providers.md

---

## Requirements

* PHP **8.2+**
* Composer
* `citomni/kernel` (required)
* Optional: `citomni/http` (if you use routes/controllers), `citomni/cli` (if you expose commands)

---

## Install (scaffold a new provider repo)

This repository is a **template** (Composer project). Create a new provider from it:

```bash
composer create-project citomni/provider-skeleton my-provider
cd my-provider
```

From here you'll **rename** things (package name, namespace, classes) and turn it into your own provider **library**.

---

## What's included (in this skeleton)

```
provider-skeleton/
  ├─ src/
  │  ├─ Boot/
  │  │  └─ Registry.php         # MAP_HTTP / MAP_CLI / CFG_HTTP / CFG_CLI / ROUTES_HTTP / ROUTES_CLI
  │  ├─ Controller/
  │  │  └─ DemoController.php   # Demonstrates JSON, TemplateEngine, and raw HTML actions
  │  ├─ Command/
  │  │  └─ DemoCommand.php      # Extends Kernel BaseCommand
  │  ├─ Operation/
  │  │  └─ DemoOperation.php    # Extends Kernel BaseOperation
  │  ├─ Repository/
  │  │  └─ DemoRepository.php   # Extends Kernel BaseRepository
  │  ├─ Service/
  │  │  └─ DemoService.php      # Extends Kernel BaseService
  │  ├─ Util/
  │  │  └─ DemoUtil.php         # Pure helper, no App dependency
  │  └─ Exception/
  │     └─ DemoException.php    # Package-level example exception
  ├─ tests/
  │  └─ SkeletonSmokeTest.php   # Tiny smoke-test stub (optional)
  ├─ stubs/
  │  └─ provider.composer.json.stub   # READY-TO-PUBLISH composer.json (library)
  ├─ composer.json              # TYPE: project (template for create-project)
  ├─ README.md
  ├─ LICENSE
  └─ .gitignore  
```

**PSR-4:** `"CitOmni\\ProviderSkeleton\\": "src/"`
**SPDX header:** All PHP files should carry the standard CitOmni header.

---

## Deterministic configuration (last wins)

Per execution mode (HTTP|CLI), config merges in this order:

1. **Vendor baseline** (by mode)
2. **Providers** (classes listed in your app's `/config/providers.php`; order matters)
3. **App base** (`/config/citomni_{http|cli}_cfg.php`)
4. **Env overlay** (`/config/citomni_{http|cli}_cfg.{dev|stage|prod}.php`)

Merged config is exposed as a **deep, read-only** wrapper; large lists (e.g., `routes`) remain raw arrays for performance:

```php
$baseUrl = $this->app->cfg->http->base_url;
$routes  = $this->app->cfg->routes; // raw array by design
```

Services use the same precedence (app overrides provider overrides vendor).

See also: https://github.com/citomni/docs/blob/main/concepts/runtime-modes.md

---

## Quick start checklist (after scaffold)

1. **Switch to your identity**

   * Open `/stubs/provider.composer.json.stub`.
   * Replace placeholders:

     * `your-vendor/your-provider`
     * `YourVendor\\YourProvider\\`
     * URLs and author fields
   * Save it **as** `/composer.json` (overwriting the skeleton's), and ensure `"type": "library"`.

2. **Rename the namespace in code**

   * Update PSR-4 in `/composer.json` to `"YourVendor\\YourProvider\\": "src/"`
   * Search/replace namespaces in `src/` (and `tests/` if used):

   **GNU sed (bash):**

   ```bash
   find src tests -type f -name '*.php' -print0 \
     | xargs -0 sed -i 's/CitOmni\\ProviderSkeleton\\/YourVendor\\YourProvider\\/g'
   ```

   **PowerShell:**

   ```powershell
   gci -rec src,tests -filter *.php | % {
     (Get-Content $_.FullName) -replace 'CitOmni\\ProviderSkeleton\\','YourVendor\\YourProvider\\' |
       Set-Content $_.FullName
   }
   ```

3. **Rename the boot provider class**

   * Keep the file path: `src/Boot/Registry.php`
   * Change the class namespace to `YourVendor\YourProvider\Boot\Registry`

4. **Decide what to keep**

   * The demo examples (`DemoController`, `DemoCommand`, `DemoOperation`, `DemoRepository`, `DemoService`, `DemoUtil`, `DemoException`) are there to demonstrate the current CitOmni package structure and layer boundaries. Keep or prune freely.

5. **Validate & autoload**

   ```bash
   composer validate
   composer dump-autoload -o
   ```

6. **Publish**

   * Push to your Git hosting (e.g., GitHub)
   * Submit to Packagist (or enable auto-submit via webhook)

---

## Provider contract (registry constants)

Your provider contributes services, config, and optional routes through `src/Boot/Registry.php`.
Only present constants are read.

```php
<?php
declare(strict_types=1);

namespace CitOmni\ProviderSkeleton\Boot;

/**
 * Registry:
 * Declares this package's contributions to the host app:
 * - MAP_HTTP / MAP_CLI service bindings
 * - CFG_HTTP / CFG_CLI config overlay
 * - ROUTES_HTTP route definitions
 *
 * The App boot process will merge these into the final runtime.
 */
final class Registry {
	public const MAP_HTTP = [
		'demoService' => \CitOmni\ProviderSkeleton\Service\DemoService::class,
	];

	public const CFG_HTTP = [
		'provider_skeleton' => [
			'enabled' => true,
		],
	];

	public const ROUTES_HTTP = [
		'/demo/' => [
			'controller' => \CitOmni\ProviderSkeleton\Controller\DemoController::class,
			'action' => 'index',
			'methods' => ['GET'],
		],
		'/demo/page/' => [
			'controller' => \CitOmni\ProviderSkeleton\Controller\DemoController::class,
			'action' => 'page',
			'methods' => ['GET'],
			'template_file' => 'public/demo/page.html',
			'template_layer' => 'citomni/provider-skeleton',
		],
		'/demo/html/' => [
			'controller' => \CitOmni\ProviderSkeleton\Controller\DemoController::class,
			'action' => 'rawHtml',
			'methods' => ['GET'],
		],
	];

	public const MAP_CLI = [
		'demo' => \CitOmni\ProviderSkeleton\Command\DemoCommand::class,
		'demoService' => \CitOmni\ProviderSkeleton\Service\DemoService::class,
	];

	public const CFG_CLI = self::CFG_HTTP;
}

```

* **Service definitions**: either FQCN or `['class' => FQCN, 'options' => [...]]`
* **Ctor contract**: `__construct(App $app, array $options = [])`
* **Routes** are contributed explicitly via `ROUTES_HTTP` and remain raw arrays in the merged runtime for performance.

More details: https://github.com/citomni/docs/blob/main/concepts/services-and-providers.md

---

## Example controller actions (optional)

`DemoController` demonstrates three common CitOmni HTTP action styles:

- JSON response via the Response service
- TemplateEngine rendering
- direct HTML output without TemplateEngine

These are exposed through the example routes already shown in `Registry::ROUTES_HTTP`.

This keeps the README aligned with the current skeleton structure:

- `DemoController` owns HTTP transport concerns
- `DemoOperation` is instantiated explicitly for orchestration
- `DemoService` is accessed through the App service map
- `DemoUtil` remains a pure helper with no App dependency

The goal is not to model a real domain controller, but to show the most common CitOmni controller patterns in a small and deterministic form.

---

## Example service (optional)

`DemoService` demonstrates a minimal App-aware service-map entry.

Typical responsibilities for provider services:

- reusable App-aware behavior
- lightweight derived formatting or diagnostics
- infrastructure or cross-cutting helpers that do not belong in Repository or Util

Unlike `DemoUtil`, services are registered in the service map and accessed as `$this->app->{serviceId}`.

---

## Example command (optional)

`DemoCommand` demonstrates the CLI side of the current CitOmni package structure.

It is intentionally small and focuses on the correct layer boundaries:

- the command owns CLI transport concerns such as reading arguments and writing output
- `DemoOperation` is instantiated explicitly for transport-agnostic orchestration
- `DemoService` is accessed through the App service map for reusable App-aware behavior
- `DemoUtil` is used for pure helper logic with no App dependency

This skeleton assumes the kernel provides a minimal `\CitOmni\Kernel\Command\BaseCommand` with a constructor, an optional `init()` hook, and an abstract `run()` method, and that the CLI runner dispatches the registered `demo` command.

The purpose of `DemoCommand` is not to model a real domain command, but to show how a provider package should structure CLI code in the current CitOmni architecture.

---

## Example operation, repository, service, and util (optional)

The provider skeleton includes layered examples that reflect the current CitOmni architecture:

- `DemoOperation` demonstrates transport-agnostic orchestration.
- `DemoRepository` demonstrates repository-owned SQL through the shared Db service.
- `DemoService` demonstrates an App-aware reusable service-map entry.
- `DemoUtil` demonstrates a pure helper with no App dependency.
- `DemoException` demonstrates package-level failure semantics.

This replaces the old model-centric example structure.

---

## SPDX header template (use in every PHP file)

```php
<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (c) [START_YEAR]-[CURRENT_YEAR] [YOUR NAME]
 *
 * [PACKAGE TITLE] — [One-line description]
 * Source:  https://github.com/[your-vendor]/[your-repo]
 * License: See the LICENSE file distributed with this source code.
 */
```

---

## From skeleton -> finished provider (library)

1. **Replace composer metadata**
   Use `/stubs/provider.composer.json.stub` as your base: copy to `/composer.json`, set `"type": "library"`, and fill in identity/URLs/authors.

2. **Change namespace**
   Update PSR-4 and classes to `YourVendor\YourProvider\...` (see the sed/PowerShell snippets above).

3. **Rename boot class**
   Keep `src/Boot/Registry.php`, but set the FQCN to `YourVendor\YourProvider\Boot\Registry`.

4. **Validate & autoload**

   ```bash
   composer validate
   composer dump-autoload -o
   ```

5. **Publish**

   * Push to GitHub
   * Submit to Packagist (or enable auto-hook)

---

## Using your finished provider in an app

1. Install in the **app**:

```bash
composer require your-vendor/your-provider
```

2. Enable the provider (whitelist):

```php
<?php
// app/config/providers.php
return [
	\YourVendor\YourProvider\Boot\Registry::class,
];
```

3. (Optional) Override defaults:

```php
<?php
// app/config/citomni_http_cfg.php
return [
	'your_provider' => [
		'enabled' => true,
		'demo' => [
			'feature_flag' => true,
		],
	],
];
```

---

## Performance & caching (production)

* Use **compiled caches**:

  * `<appRoot>/var/cache/cfg.{http|cli}.php`
  * `<appRoot>/var/cache/services.{http|cli}.php`
* Generate atomically during deploy:

  ```php
  $app = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::HTTP);
  $app->warmCache(true, true);
  ```
* Enable OPcache; consider `validate_timestamps=0` (then invalidate on deploy).

---

## Testing

This skeleton ships with a tiny smoke test stub (optional).
For integrated, framework-native testing (dev-only), consider `citomni/testing`.

---

## Coding & documentation conventions

* PHP **8.2+**, PSR-1/PSR-4
* **PascalCase** classes, **camelCase** methods/vars, **UPPER_SNAKE_CASE** constants
* **K&R braces**, **tabs** for indentation
* PHPDoc & inline comments in **English**
* Fail fast; do not catch unless necessary (global handler logs)

All CitOmni projects also follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Provider Skeleton** is released under the **MIT License**.  
See [LICENSE](LICENSE) for full license terms.

For trademark usage and attribution requirements, see [NOTICE](NOTICE).

---

### FAQ

**Q1: Can I use the name "CitOmni" in my own provider package?**  
You may make factual references such as “Built for CitOmni” or “Compatible with CitOmni,”  
provided that your use is purely descriptive and **does not** imply official status, endorsement,  
or affiliation. You may **not**:
- use “CitOmni” (or a confusingly similar name) as part of your company, domain, or top-level package name;
- modify or combine the CitOmni logo with your own branding;
- present your work as “official,” “certified,” or “approved” unless such status has been formally granted.

See the [NOTICE](NOTICE) for full trademark policy.

---

**Q2: What does the MIT License allow me to do with this skeleton?**  
Everything the MIT License normally allows — copy, modify, redistribute, and include  
in your own projects (open or proprietary). You just need to:
- keep the copyright and license notice in redistributed copies, and  
- include your own license for your resulting work.

---

**Q3: If I build a proprietary or commercial provider from this skeleton, am I compliant?**  
Yes. The skeleton is intentionally MIT-licensed so you can:
- create your own provider package (open-source or closed-source),
- license it under your chosen terms,
- and distribute it via Packagist, private repositories, or direct delivery to clients.

You do **not** need to open-source your derived provider.  
However, you must **not** remove or misrepresent the CitOmni copyright  
and trademark notices that remain in any retained portions of this template.

---

**Q4: Do I need to credit CitOmni in my README or composer.json?**  
Attribution is appreciated but not legally required.  
If you want to mention it, a simple line such as:

> Built using the [CitOmni Provider Skeleton](https://github.com/citomni/provider-skeleton) (MIT)

is perfect.

---

**Q5: Where can I find official CitOmni documentation and licensing guidance?**  
- Docs hub: https://github.com/citomni/docs  
- Provider best practices: https://github.com/citomni/docs/blob/main/concepts/services-and-providers.md  
- Runtime & merge model: https://github.com/citomni/docs/blob/main/concepts/runtime-modes.md  
- Trademark policy: [NOTICE](NOTICE)

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos,  
or imply sponsorship, endorsement, or affiliation without prior written permission.  
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.  
For details, see the project's [NOTICE](NOTICE).

---

## Author

Developed by **Lars Grove Mortensen** © 2012-present
Contributions and pull requests are welcome.

---

Built with ❤️ on the CitOmni philosophy: **low overhead**, **high performance**, **ready for anything**.
