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
 * Represent retrieval-pipeline failures in the knowledge-base package.
 *
 * This exception is thrown when retrieval configuration is invalid or when the
 * lexical, vector, synonym-expansion, fusion, or rerank pipeline cannot be
 * completed successfully.
 *
 * Notes:
 * - Rerank quality failures should normally degrade gracefully inside Retriever
 *   and not escape as RetrievalException unless the overall retrieval flow
 *   cannot continue.
 * - The calling Operation decides how retrieval failure is translated into
 *   application-level behavior.
 */
final class RetrievalException extends KnowledgeBaseException {
}
