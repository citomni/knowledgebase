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

namespace CitOmni\KnowledgeBase\Exception;

/**
 * Base exception for the knowledge base package.
 *
 * Provides one package-level root exception type for callers that want to
 * catch all citomni/knowledgebase-specific failures in one place while still
 * allowing more specific exception subclasses for narrower handling.
 *
 * Notes:
 * - Intentionally contains no extra behavior.
 * - Concrete package exceptions should extend this class.
 */
class KnowledgeBaseException extends \RuntimeException {
}
