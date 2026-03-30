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
 * Exception thrown for knowledge base ingest failures.
 *
 * Used for domain-level ingest errors such as:
 * - invalid ingest input
 * - missing knowledge base
 * - invalid ingest configuration
 * - pipeline-level contract violations during ingest
 *
 * Notes:
 * - Keep this exception minimal in V1.
 * - Partial completion after document commit is reported via return shape,
 *   not via custom exception properties.
 */
final class IngestException extends KnowledgeBaseException {
}
