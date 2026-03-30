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

namespace CitOmni\ProviderSkeleton\Controller;

use CitOmni\Kernel\Controller\BaseController;
use CitOmni\ProviderSkeleton\Operation\DemoOperation;
use CitOmni\ProviderSkeleton\Util\DemoUtil;

/**
 * DemoController.
 *
 * Minimal HTTP controller example for the provider skeleton.
 *
 * Behavior:
 * - Uses Request via `$this->app->request`.
 * - Uses Response via `$this->app->response`.
 * - Uses TemplateEngine via `$this->app->tplEngine`.
 * - Instantiates DemoOperation explicitly.
 * - Uses DemoService from the service map.
 * - Uses DemoUtil for pure normalization/validation.
 *
 * Notes:
 * - Demonstrates three common controller output styles:
 *   1) JSON response
 *   2) TemplateEngine render
 *   3) Raw HTML without TemplateEngine
 */
final class DemoController extends BaseController {

	/**
	 * Lightweight per-request bootstrap.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Keep lean. No heavy I/O here.
	}

	/**
	 * GET /demo
	 *
	 * Return all demo samples as JSON.
	 *
	 * Behavior:
	 * - Reads optional `q` from the query string.
	 * - Normalizes the search term via DemoUtil.
	 * - Loads rows through DemoOperation.
	 * - Decorates rows through DemoService.
	 * - Applies optional in-memory filtering in the controller.
	 *
	 * @return never
	 *
	 * @throws \JsonException When JSON encoding fails.
	 */
	public function index(): never {
		$search = DemoUtil::normalizeSampleName((string)$this->app->request->get('q', ''));
		$operation = new DemoOperation($this->app);

		$result = $operation->listSamples();
		$items = $this->app->demoService->decorateSamples($result['items'] ?? []);

		if ($search !== '') {
			$needle = \strtolower($search);
			$filtered = [];

			foreach ($items as $item) {
				$name = \strtolower((string)($item['name'] ?? ''));
				if (\str_contains($name, $needle)) {
					$filtered[] = $item;
				}
			}

			$items = $filtered;
		}

		$diagnostics = $this->app->demoService->getDiagnostics();

		$this->app->response->jsonStatusNoCache([
			'ok' => true,
			'data' => $items,
			'meta' => [
				'total' => \count($items),
				'query_count' => (int)($diagnostics['query_count'] ?? 0),
				'request_method' => $this->app->request->method(),
				'request_path' => $this->app->request->path(),
			],
		], 200);
	}

	/**
	 * GET /demo/page
	 *
	 * Render a demo page through TemplateEngine.
	 *
	 * Behavior:
	 * - Follows the same route/template pattern as PublicController::index().
	 * - Uses route config keys `template_file` and `template_layer`.
	 * - Loads rows through DemoOperation and decorates them through DemoService.
	 * - Passes a small, deterministic view model to the template.
	 *
	 * @return void
	 *
	 * @throws \JsonException When diagnostic JSON encoding fails.
	 */
	public function page(): void {
		$operation = new DemoOperation($this->app);
		$result = $operation->listSamples();
		$items = $this->app->demoService->decorateSamples($result['items'] ?? []);
		$diagnostics = $this->app->demoService->getDiagnostics();

		$details = \json_encode([
			'citomni' => [
				'mode' => 'http',
				'environment' => \defined('CITOMNI_ENVIRONMENT') ? \CITOMNI_ENVIRONMENT : 'unknown',
			],
			'metrics' => [
				'query_count' => (int)($diagnostics['query_count'] ?? 0),
				'item_count' => \count($items),
			],
			'request' => [
				'method' => $this->app->request->method(),
				'path' => $this->app->request->path(),
			],
		], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

		$this->app->tplEngine->render($this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],	[
				'noindex' => 1,
				'canonical' => \defined('CITOMNI_PUBLIC_ROOT_URL') ? \CITOMNI_PUBLIC_ROOT_URL . '/demo/page/' : '',
				'meta_title' => 'Provider skeleton demo page',
				'meta_description' => 'Example page rendered through TemplateEngine in the provider skeleton.',
				'title' => 'Provider skeleton demo',
				'subtitle' => 'TemplateEngine example action',
				'lead_text' => 'Small, deterministic, and intentionally free of drama.',
				'items' => $items,
				'total' => \count($items),
				'details_preformatted' => $details,
		]);

	}


	/**
	 * GET /demo/html
	 *
	 * Output a simple HTML page without TemplateEngine.
	 *
	 * Behavior:
	 * - Mirrors the direct-response pattern used by PublicController::websiteLicense().
	 * - Builds HTML inline for tiny pages/endpoints where a template would be overkill.
	 * - Sends noindex headers before emitting the body.
	 *
	 * @return never
	 */
	public function rawHtml(): never {
		$operation = new DemoOperation($this->app);
		$result = $operation->listSamples();
		$items = $this->app->demoService->decorateSamples($result['items'] ?? []);
		$total = \count($items);

		$this->app->response->noIndex();

		if (\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			$this->app->response->setHeader(
				'Link',
				'<' . \CITOMNI_PUBLIC_ROOT_URL . '/demo/html/>; rel="canonical"'
			);
		}

		$e = static fn(?string $value): string => \htmlspecialchars((string)$value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

		$html = '<!doctype html><html lang="en"><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<meta name="robots" content="noindex,follow">';
		$html .= '<title>Provider skeleton demo HTML</title>';
		$html .= '<body style="margin:0;padding:24px;font:16px/1.6 ui-sans-serif,system-ui,sans-serif">';
		$html .= '<h1>Provider skeleton demo</h1>';
		$html .= '<p>This page is emitted directly by the controller without TemplateEngine.</p>';
		$html .= '<p>Total rows: <strong>' . $e((string)$total) . '</strong></p>';

		if ($items !== []) {
			$html .= '<ul>';

			foreach ($items as $item) {
				$html .= '<li>'
					. $e((string)($item['name'] ?? ''))
					. ' <small>(' . $e((string)($item['status_label'] ?? 'unknown')) . ')</small>'
					. '</li>';
			}

			$html .= '</ul>';
		} else {
			$html .= '<p>No demo rows found.</p>';
		}

		$html .= '<hr>';
		$html .= '<p><small>Request: '
			. $e($this->app->request->method())
			. ' '
			. $e($this->app->request->path())
			. '</small></p>';
		$html .= '</body></html>';

		$this->app->response->html($html, 200);
	}

	/**
	 * GET /demo/show?id=123
	 *
	 * Return one demo sample as JSON.
	 *
	 * @return never
	 *
	 * @throws \JsonException When JSON encoding fails.
	 */
	public function show(): never {
		$id = (int)$this->app->request->get('id', 0);

		if ($id < 1) {
			$this->app->response->jsonProblem(
				'Invalid request',
				400,
				'Parameter `id` must be a positive integer.'
			);
		}

		$operation = new DemoOperation($this->app);
		$item = $operation->findSampleById($id);

		if ($item === null) {
			$this->app->response->jsonProblem(
				'Not found',
				404,
				'Demo sample was not found.'
			);
		}

		$decorated = $this->app->demoService->decorateSamples([$item]);

		$this->app->response->jsonStatusNoCache([
			'ok' => true,
			'data' => $decorated[0] ?? null,
		], 200);
	}

	/**
	 * POST /demo/create
	 *
	 * Create one demo sample and return the new id as JSON.
	 *
	 * @return never
	 *
	 * @throws \JsonException When JSON encoding fails.
	 */
	public function create(): never {
		$name = DemoUtil::normalizeSampleName((string)$this->app->request->post('name', ''));
		$isActiveRaw = $this->app->request->post('is_active', '1');

		if (!DemoUtil::isValidSampleName($name)) {
			$this->app->response->jsonProblem(
				'Validation failed',
				422,
				'Parameter `name` is missing or outside the accepted length.'
			);
		}

		$isActive = \in_array((string)$isActiveRaw, ['1', 'true', 'on', 'yes'], true);

		$operation = new DemoOperation($this->app);
		$newId = $operation->createSample($name, $isActive);

		$this->app->response->jsonStatusNoCache([
			'ok' => true,
			'data' => [
				'id' => $newId,
			],
		], 201);
	}
}
