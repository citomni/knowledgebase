# citomni/knowledgebase — Working Document

> A CitOmni provider package for building AI-powered question answering
> over your own knowledge bases and documents.

**Status:** Architecture finalized. DDL finalized. Ready for implementation.
**Date:** 2026-03-30


---


## 1. Package identity

| Key               | Value                                              |
|-------------------|----------------------------------------------------|
| Composer name     | `citomni/knowledgebase`                            |
| Namespace         | `CitOmni\KnowledgeBase`                            |
| Table prefix      | `know_`                                            |
| License           | MIT (CitOmni standard)                             |
| PHP               | ≥ 8.2                                              |
| Dependencies      | `citomni/kernel`, `citomni/infrastructure`, `citomni/vectorembedding`, `citomni/helloai` |

The package name optimizes for human readability and immediate understandability.
Internal class names optimize for technical precision (`Retriever`, `Chunker`,
`ScoreFusion`, `PromptBuilder`, etc.).


---


## 2. Core design invariants

These rules hold across all citomni/knowledgebase code and must not be violated.

### 2.1 Retrieval-first, not chat-first

The package is retrieval-first. LLM answer generation is built on top of retrieved
context. The package does not treat free-form chat as the primary abstraction.
Every answer is grounded in explicitly retrieved chunks from the knowledge base.
If retrieval returns nothing usable, the default behavior is to decline answering —
not to fall back to the model's parametric knowledge.

### 2.2 Units are stable, chunks are disposable

Units represent the human-readable, domain-meaningful structure of source documents.
They survive chunking strategy changes, embedding model swaps, and token budget
adjustments. Chunks are retrieval-optimized atoms derived from units. Delete and
regenerate them freely. Embeddings are isolated further — swap models without
touching chunks.

### 2.3 Grounded default prompting

The package-shipped default system prompt enforces:
- Answer only from the provided context chunks.
- State clearly when the context is insufficient to answer.
- Do not invent facts, citations, or legal references not present in context.
- Preserve uncertainty — "the provided context suggests..." over "the answer is...".
- When multiple chunks provide conflicting information, acknowledge the conflict.

This policy is baked into PromptBuilder's default system template. Applications can
override via `cfg.knowledgebase.prompt.system_template`, but the default is strict.
This is especially important for legal, policy, and compliance knowledge bases where
hallucinated answers carry real risk.

### 2.4 Util purity contract

Utils have no App dependency. They never read cfg, language files, service map, or
any global state. They receive fully resolved inputs from their callers and return
deterministic outputs. If a Util starts needing cfg or App access, it must be
refactored into a Service — not quietly given hidden dependencies.

This applies specifically to PromptBuilder: it receives resolved prompt text,
language instructions, and token budgets as explicit parameters. The Operation
is responsible for resolving cfg values and passing them in.

### 2.5 Token estimates, not guarantees

All token counts in V1 are heuristic estimates, not model-exact values. Chunker,
PromptBuilder, and cfg defaults are calibrated to have margin — better to
underutilize the context budget slightly than to exceed it. Specific estimation
strategy is an open implementation decision (see §17), but all consuming code must
treat `token_count` / `token_estimate` as approximate.

### 2.6 Standard layer boundaries

| Layer       | Base class          | Owns                                    | Must not touch                          |
|-------------|---------------------|------------------------------------------|-----------------------------------------|
| Util        | (none)              | Pure functions: math, text transforms    | App, IO, SQL, config, state             |
| Service     | BaseService         | Cfg-driven pipeline orchestration        | SQL, transport (Request/Response/CLI IO) |
| Repository  | BaseRepository      | All SQL, pack/unpack, data integrity     | Transport, workflow logic, services      |
| Operation   | BaseOperation       | Multi-step orchestration, domain returns | SQL, transport, direct service calls (except via $this->app) |
| Controller  | BaseController      | HTTP transport: input, CSRF, response    | SQL, workflow logic                      |
| Command     | BaseCommand         | CLI transport: argv, stdout/stderr       | SQL, workflow logic                      |


---


## 3. Architecture overview

### Two primary flows

**Ingest** (CLI/admin — write path):
```
Source text
  → Parser (app-side, domain-specific)
  → Units (stable structural elements)
  → Chunker (units → retrieval atoms)
  → VectorEmbedder (chunks → embeddings)
  → Persist (documents + units + chunks + embeddings)
```

**Query** (HTTP/CLI — read path):
```
Question
  → VectorEmbedder (question → query vector, when vector search is active)
  → Synonym expansion (when synonyms exist for targeted knowledge bases)
  → Retriever (hybrid pipeline → ranked chunks)
  → Context evaluation (sufficient? → proceed or decline)
  → PromptBuilder (question + chunks → messages array)
  → HelloAi (messages → answer)
  → Return answer + sources + metadata
```


---


## 4. Directory structure

```
citomni/knowledgebase/
  src/
    Boot/
      Registry.php                    – CFG, MAP, COMMANDS_CLI constants
    Service/
      Retriever.php                   – Hybrid retrieval pipeline orchestration
    Operation/
      IngestDocument.php              – chunk → embed → persist (multi-step)
      QueryKnowledgeBase.php          – retrieve → prompt → chat → answer
    Repository/
      KnowledgeBaseRepository.php     – know_bases CRUD
      DocumentRepository.php          – know_documents CRUD + content_hash check
      UnitRepository.php              – know_units CRUD + hierarchy + path management
      ChunkRepository.php             – know_chunks CRUD + lexical search
      EmbeddingRepository.php         – know_embeddings CRUD + vector search + completeness
      SynonymRepository.php           – know_synonym_groups + know_synonym_terms CRUD + bidirectional expansion
      QueryLogRepository.php          – know_query_log write + prune (optional table)
    Util/
      Chunker.php                     – Stateless text → chunks transformer
      PromptBuilder.php               – chunks + question → messages array (pure, no App)
      CosineSimilarity.php            – Cosine similarity between two vectors
      ScoreFusion.php                 – Score normalization + Reciprocal Rank Fusion
    Command/
      IngestCommand.php               – CLI: ingest a document
      SearchCommand.php               – CLI: test a retrieval query
      StatsCommand.php                – CLI: knowledge base statistics
      PurgeCommand.php                – CLI: remove a document and its descendants
      SetupQueryLogCommand.php        – CLI: create the optional know_query_log table
      PruneQueryLogCommand.php        – CLI: prune old query log entries
      SynonymsImportCommand.php       – CLI: bulk-import synonym groups from JSON/CSV
      SynonymsListCommand.php         – CLI: list all synonym groups for a knowledge base
      SynonymsAddCommand.php          – CLI: add one synonym group
      SynonymsRemoveCommand.php       – CLI: remove one synonym group
    Exception/
      KnowledgeBaseException.php      – Base exception for the package
      ChunkerException.php            – Chunking failures
      RetrievalException.php          – Retrieval pipeline failures
      IngestException.php             – Ingest pipeline failures
  language/
    en/knowledgebase.php
    da/knowledgebase.php
  sql/
    know_schema.sql                   – Core seven tables
    know_query_log.sql                – Optional query log table (used by setup command)
```


---


## 5. Database schema

### Table overview

| Table                 | Role                              | Stability        | FK cascade parent |
|-----------------------|-----------------------------------|------------------|-------------------|
| `know_bases`          | Knowledge domain container        | Permanent        | —                 |
| `know_documents`      | Versioned source document         | Permanent        | `know_bases`      |
| `know_units`          | Hierarchical structural unit      | Stable           | `know_documents`  |
| `know_chunks`         | Retrieval atom                    | Disposable       | `know_units`      |
| `know_embeddings`     | Vector per chunk per model        | Disposable       | `know_chunks`     |
| `know_synonym_groups` | Synonym group container           | Admin-maintained | `know_bases`      |
| `know_synonym_terms`  | One normalized term per row       | Admin-maintained | `know_synonym_groups` |
| `know_query_log`      | Retrieval analytics (opt-in)      | Pruneable        | `know_bases`      |

**Cascade chain:** Deleting a knowledge base cascades through documents → units →
chunks → embeddings. It also cascades to synonym groups → synonym terms and query log entries.
Deleting a synonym group cascades to its synonym terms.
Deleting a document cascades through units → chunks → embeddings.
Re-chunking means deleting chunks (embeddings follow) and regenerating. Units stay.

### Key schema design decisions

**Embeddings in separate table:**
- Queries not touching vectors skip BLOB scanning entirely.
- Multiple models can coexist per chunk (A/B testing, migration, benchmarking).
- Re-embedding is isolated INSERT + DELETE on know_embeddings; chunks/units untouched.
- Storage format: binary BLOB of float32 values via `pack('f*')`. Repository owns
  pack/unpack. Application code sees `float[]`.

**Materialized path (know_units.path):**
Zero-padded dot-separated sort_order chain, e.g. `"001.003.002"`. Three-digit padding
supports up to 999 siblings per level. Enables deterministic lexical ordering and
subtree queries via `WHERE path LIKE '001.003.%'` without recursive CTEs.
Repository owns path generation.

**content_hash (know_documents):**
SHA-256 hex. `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin`.
Enables idempotent re-import: matching hash = skip, mismatch = re-ingest.
NULL is valid for manually created documents.

**Sibling ordering (know_units):**
`UNIQUE(document_id, parent_id, sort_order)` prevents duplicate positions among
non-root siblings. MySQL treats NULL parent_id values as distinct in UNIQUE
constraints, so top-level units are not covered by the DB constraint.
Repository enforces root-level ordering with fail-fast behavior:
duplicate root sort_order → `IngestException`. No auto-resequencing —
if duplicates occur, it is a bug in the parser / ingest caller.

**Synonyms (`know_synonym_groups` + `know_synonym_terms`):**
Domain-specific synonym groups are scoped per knowledge base and stored relationally.

`know_synonym_groups` stores one administrative group row per synonym group.
`know_synonym_terms` stores one normalized term row per synonym surface form.

Design rules:
- each group belongs to exactly one knowledge base
- each term row belongs to exactly one group
- each normalized term may belong to only one group per knowledge base
- the group's `canonical_term` must also exist as a normalized term row in that same group
- this canonical-term invariant is enforced transactionally by the Repository layer
- terms are normalized before persistence:
  - trimmed
  - lowercased using UTF-8 aware lowercasing
  - internal whitespace collapsed to a single space
  - empty values rejected after normalization

Lookup is bidirectional — querying any stored term expands to all terms in the same group.
Overlap is invalid and must fail fast on import/update.
The relational model enables stronger data integrity, simpler overlap enforcement,
better indexing, and cleaner repository semantics than JSON-array storage.
See §6 for how synonym expansion integrates with the retrieval pipeline.

**Query log:**
Not in the base DDL. Created explicitly by `know:setup-query-log` CLI command.
QueryLogRepository no-ops when logging is enabled in cfg but the table does not exist
(checks once per process, cached).

### Full DDL

Maintained separately in `sql/know_schema.sql` (finalized).


---


## 6. Retrieval pipeline

### Flow

```
Query
  → [synonym expansion?]
  → [lexical?]
  → [vector?]
  → [fusion?]
  → top-k
  → [rerank?]
  → result
```

### Configurable legs

| Leg                | Cfg key                          | Default | Effect when disabled                          |
|--------------------|----------------------------------|---------|-----------------------------------------------|
| Synonym expansion  | `retrieval.synonym_expansion`    | `true`  | No query term expansion                       |
| Lexical            | `retrieval.lexical`              | `true`  | No FULLTEXT search                            |
| Vector             | `retrieval.vector`               | `true`  | No embedding/cosine search                    |
| Rerank             | `retrieval.rerank`               | `false` | No AI-based reranking pass                    |
| Fusion             | (automatic)                      | —       | Active only when both lexical + vector active |

**Constraint:** At least one of lexical/vector must be active. Retriever throws
`RetrievalException` otherwise.

### Pipeline steps in detail

**1. Resolve cfg** — Merge `cfg.knowledgebase.retrieval` with per-call `$options`.
Validate that at least one search leg is active.

**2. Synonym expansion** (when active and lexical search is active) —
`SynonymRepository::expand($knowledgeBaseIds, $queryText)`. Expansion is performed against synonym groups and term rows loaded for the targeted knowledge bases and assembled into in-memory lookup maps in PHP against the normalized query text.

Matching rules:
- single-word synonym entries match normalized query tokens
- multi-word synonym entries match only as exact normalized phrases
- partial token matches must not trigger a multi-word synonym group

If expansion produces additional terms, the lexical leg switches to boolean mode.
If no synonyms match, natural language mode is used unchanged.

Synonym expansion is a query-time transformation owned by Retriever. It does not
modify stored data and it affects only the lexical retrieval leg. The vector leg
always uses the original user question embedding unchanged.

**3. Determine candidate pool size** — When rerank is active: `candidate_k = top_k ×
candidate_multiplier`. When rerank is off: `candidate_k = top_k`.

**4. Lexical search** (when active) — `ChunkRepository::findByLexical($query, $limit,
$knowledgeBaseIds, $mode)`. FULLTEXT `MATCH ... AGAINST` using the mode determined by
the synonym expansion step:

- **Natural language mode** (default): used when no synonym expansion occurred.
  Standard relevance scoring.
- **Boolean mode**: used when synonym expansion produced additional terms. Retriever
  builds a boolean query string with grouped alternatives, e.g.
  `+(depositum indskud) +(opsige ophæve "bringe til ophør")`.

Returns chunks with relevance score. `$mode` parameter: `'natural'` | `'boolean'`.

Boolean query construction is owned by Retriever, not by synonym data itself.
Synonym terms are treated as plain search terms and must never inject boolean syntax
directly. Reserved FULLTEXT boolean characters are escaped or stripped before the
query string is built.

**5. Vector search** (when active) — `EmbeddingRepository::findByVector($queryVector,
$model, $candidateK, $minSimilarity)`. Loads all embeddings for the active model,
computes cosine similarity in PHP, returns top candidates above threshold. Chunks
without an embedding for the active model are silently excluded from the semantic leg
but still participate in lexical search.

**6. Score fusion** (automatic when both legs active) — `ScoreFusion::rrf($lexicalRanked,
$vectorRanked, $rrfK)`. Reciprocal Rank Fusion: for each chunk,
`score = Σ 1/(k + rank_i)`. Chunks appearing in both result sets are naturally boosted.
When only one leg is active: scores are normalized to 0–1, no fusion.

**7. Top-k selection** — Sort by fused/normalized score descending. Take top `candidate_k`
(if rerank follows) or `top_k` (if rerank is off).

**8. Rerank** (when active) — Sends question + candidate chunks to HelloAi using a
rerank prompt built by `PromptBuilder::buildRerankPrompt()`. Model scores each chunk
0–10 for relevance. Results re-sorted by AI score and trimmed to final `top_k`.
Uses `cfg.knowledgebase.retrieval.rerank_profile` (falls back to `chat_profile`).

### min_score semantics

`min_score` is a **post-fusion threshold** applied to the final normalized/fused score
after fusion (or after single-leg normalization when only one leg is active). It is
NOT applied per-leg before fusion.

Rationale: per-leg thresholds before fusion would filter candidates away before RRF
sees them, undermining fusion's ability to boost chunks that score moderately in both
legs. Lexical relevance scores and cosine similarity scores are on different scales —
fusion normalizes them; `min_score` filters after normalization.

Chunks below `min_score` are excluded before the `min_chunks` check.

### Rerank failure behavior

Reranking is a quality enhancement, not a gate. If HelloAi fails (network error,
timeout, invalid JSON, malformed scores), the Retriever:
1. Logs a warning via the log service.
2. Falls back to pre-rerank ordering, trimmed to `top_k`.
3. Sets `meta.rerank_status = 'failed'` in the return value.

The Operation and the user always see a result — just not a reranked one.

### No-hit / low-context behavior

When retrieval returns zero chunks or only very weak results, the Operation does
NOT call HelloAi by default. Instead, it returns a structured "insufficient context"
result with `answer = null` and a status indicator.

Policy:
- `min_chunks` threshold (default: 1). If retrieval returns fewer qualifying chunks
  (after `min_score` filtering), the Operation declines to answer.
- `min_score` threshold (default: 0.0). Applied post-fusion on normalized scores.
  Chunks below this score are excluded before the min_chunks check.
- When thresholds are not met, the return shape is:
  `['answer' => null, 'status' => 'insufficient_context', 'sources' => [...], ...]`
- Applications can override via per-call option `allow_low_context: true`. When set,
  the Operation sends the available chunks (even zero) to HelloAi with an explicit
  uncertainty instruction prepended to the prompt: "The retrieved context may be
  insufficient. State clearly if you cannot answer confidently."
- Default behavior is strict: no context → no AI call → controlled decline. This is
  especially important for legal, policy, and compliance knowledge bases.

### Configuration examples

**Pure vector (simple, fast):**
```php
'retrieval' => ['lexical' => false, 'vector' => true, 'rerank' => false]
```

**Hybrid without rerank (good balance):**
```php
'retrieval' => ['lexical' => true, 'vector' => true, 'rerank' => false]
```

**Hybrid with rerank (highest quality):**
```php
'retrieval' => ['lexical' => true, 'vector' => true, 'rerank' => true]
```

**Pure lexical with rerank and synonyms (no embedding cost):**
```php
'retrieval' => ['lexical' => true, 'vector' => false, 'rerank' => true, 'synonym_expansion' => true]
```

### Scaling path (future, not now)

Current architecture: MySQL + PHP-side cosine. Practical for knowledge bases up to
~50K chunks. `EmbeddingRepository::findByVector()` is the single point of ownership
for similarity search. Swap its internals to a vector DB without touching Retriever,
Operations, or Controllers.


---


## 7. Source / citation contract

Three distinct shapes for source metadata, serving different concerns.

### 7.1 Prompt context (sent to HelloAi)

Minimal. The model should not drown in metadata.

| Field              | Source                      | Example                        |
|--------------------|-----------------------------|--------------------------------|
| document_title     | `know_documents.title`      | "Lejeloven (LBK 927/2019)"    |
| unit_identifier    | `know_units.identifier`     | "§ 34, stk. 2"                |
| chunk_content      | `know_chunks.content`       | The retrieval text             |

PromptBuilder injects only these fields into the context block per chunk.
Overlap context (`context_before`/`context_after`) is excluded from prompts.

### 7.2 Return shape (Operation → adapter)

Rich enough for UI rendering and debugging.

```php
[
    'chunk_id'           => int,
    'unit_id'            => int,
    'document_id'        => int,
    'knowledge_base_id'  => int,
    'document_title'     => string,
    'document_slug'      => string,
    'unit_identifier'    => ?string,
    'unit_type'          => string,
    'unit_title'         => ?string,
    'unit_path'          => ?string,
    'content'            => string,
    'score'              => float,
    'retrieval_methods'  => string[],   // e.g. ['lexical', 'vector']
]
```

### 7.3 UI shape (app responsibility)

The package delivers the return shape. The application formats it for display.
Examples:
- Lejeloven: "Lejeloven § 34, stk. 2"
- FAQ: "Depositum → Svar"
- Manual: "Chapter 3 / Section 2.1"

The package does not own UI formatting of citations.


---


## 8. Answer language policy

Explicit precedence, highest to lowest:

1. **Per-call `language` parameter** — e.g. `$op->execute(['language' => 'en', ...])`.
2. **`cfg.knowledgebase.prompt.language`** — package-level cfg override.
3. **Knowledge base `language` field** — from `know_bases.language` for the queried base(s).
4. **`cfg.locale.language`** — CitOmni global fallback.

No auto-detection from question text. It is unreliable for short queries and mixes
responsibilities. If a user asks in English against a Danish knowledge base, the
application decides the language via cfg or per-call parameter — not the package.

### Multi-base language rule

When querying multiple knowledge bases, all targeted bases must share the same
`language` value. If `knowledge_base_ids` resolves to bases with mixed languages
and no explicit per-call `language` override is provided, the Operation fails fast
with a clear exception. This keeps answer-language resolution deterministic and
avoids mixed-language retrieval contexts.

An explicit per-call `language` parameter bypasses this check — the caller takes
responsibility for the mixed-language context.

PromptBuilder includes the resolved language as an instruction in the system prompt:
"Answer in [language]." When language is null at all levels, no language instruction
is included and the model defaults to the language of the context chunks.


---


## 9. Prompt budget trimming

When PromptBuilder needs to stay within `max_context_tokens`, it applies these rules
in order:

1. **Include chunks in score order** (highest first).
2. **For each chunk: include `content` only.** Exclude `context_before`/`context_after`
   (overlap text). Overlap is embedding input, not prompt input.
3. **Stop when the next chunk would exceed the budget.** The partial chunk is excluded
   entirely.
4. **Never trim chunk text mid-chunk.** Either the whole chunk is included or it is
   omitted. A truncated chunk risks losing its point and misleading the model. The
   Chunker already sizes chunks to a reasonable token budget (default 512).

If zero chunks fit within the budget (unrealistic with sane cfg, but defensive): the
Operation receives an empty chunk list and applies the no-hit behavior (§6).


---


## 10. Ingest replacement semantics

### V1 strategy: replace by slug (transactionally atomic)

When `IngestDocument` receives a document with a `slug` that already exists in the
target knowledge base, the flow is:

1. **Check content_hash first.** If the existing document has a `content_hash` that
   matches the new source text hash → **skip entirely** (no-op). Return
   `['skipped' => true, 'reason' => 'content_unchanged']`.
2. **If hash differs (or no hash exists):** begin transaction.
3. **Delete the existing document.** CASCADE removes all its units → chunks → embeddings.
4. **Insert the new document** with units, chunks, and embeddings.
5. **Commit.** If any step fails → rollback. The old document remains intact.

The entire replace operation (delete old + insert new + all descendants) happens
inside a single transaction. There is no visible gap where the document disappears.
This is the only ingest strategy in V1.

### Future: supersede strategy (V2)

For knowledge bases that need version history (e.g. law consolidations over time):
- Existing document gets `status = 'superseded'` instead of being deleted.
- New document is inserted with `status = 'active'`.
- Retrieval filters on `status = 'active'` by default.
- Requires rethinking slug uniqueness (currently UNIQUE per knowledge_base + slug
  regardless of status). Likely solution: scoped uniqueness or versioned slugs
  (e.g. `lejeloven-2024`, `lejeloven-2025`).
- Documented as a known future extension, not a V1 concern.


---


## 11. Return shape naming convention

Consistent naming across layers to prevent drift:

### Retriever service returns:
```php
[
    'chunks' => [...],   // ranked chunk rows with source metadata
    'meta'   => [        // pipeline metadata owned by Retriever
        'strategy'       => string,   // 'lexical', 'vector', 'hybrid'
        'lexical_count'  => int,
        'vector_count'   => int,
        'fused_count'    => int,
        'rerank_status'  => string,   // 'success' | 'skipped' | 'failed'
        'synonyms_used'  => bool,     // whether synonym expansion was applied
        'duration_ms'    => int,
    ],
]
```

### QueryKnowledgeBase operation returns:
```php
[
    'answer'         => ?string,                  // null when insufficient context
    'status'         => string,                   // 'ok' | 'insufficient_context'
    'sources'        => [...],                    // chunks in return shape (§7.2)
    'retrieval_meta' => [...],                    // Retriever's meta, passed through
    'usage'          => [...],                    // HelloAi token usage (null when no AI call)
]
```

Rule: Retriever uses `meta`. Operation wraps it as `retrieval_meta` to avoid
collision with other top-level keys. All other components follow this convention.


---


## 12. Query log contract

### Canonical payload shape

`QueryLogRepository::write()` accepts and persists this fixed set of fields:

| Field               | Type              | Source                                 |
|---------------------|-------------------|----------------------------------------|
| `knowledge_base_id` | int               | From query input                       |
| `query_text`        | string            | The user's question                    |
| `strategy`          | string            | Resolved strategy: 'lexical'/'vector'/'hybrid' |
| `chunk_limit`       | int               | Resolved top_k                         |
| `results_count`     | int               | Number of chunks returned              |
| `top_chunk_ids`     | int[] (as JSON)   | Ordered chunk IDs in result            |
| `reranked`          | bool (0/1)        | Whether reranking was applied          |
| `duration_ms`       | int               | Total retrieval pipeline duration      |
| `metadata_json`     | ?array (as JSON)  | Extensible: caller context, filters    |

This shape is the V1 contract. Extensions go into `metadata_json`, not new columns.

When synonym expansion is active, useful optional metadata in `metadata_json` includes:
- `synonyms_used` — boolean
- `expanded_terms` — mapping from matched query terms/phrases to their expanded synonym groups
- `lexical_mode` — `'natural'` | `'boolean'`

This information is intended for retrieval tuning and explainability, not for schema
columns of its own.

---


## 13. Synonym expansion

### Purpose

Domain-specific lexical query expansion. Many knowledge domains have terminology
where raw text matching is insufficient:
- Legal: "depositum" vs. "indskud", "opsige" vs. "ophæve" vs. "bringe til ophør"
- Technical: product names, abbreviations, internal terms
- Policy: old and new designations across document versions

Vector search captures some semantic similarity, but the lexical leg in hybrid
retrieval depends on exact word matches. Without synonyms, a search for "indskud"
will not find chunks using "depositum" — and these are not edge cases but everyday
usage in legal text. Synonym expansion ensures the lexical leg pulls in relevant
chunks regardless of which surface form the user happens to use.

### Data model

Synonyms are stored in two relational tables:

- `know_synonym_groups`
  - one administrative synonym group row per knowledge base
  - stores the group's `canonical_term`

- `know_synonym_terms`
  - one normalized term row per synonym surface form
  - each row belongs to exactly one synonym group

Example:

Group row:
```json
{"id": 10, "knowledge_base_id": 1, "canonical_term": "depositum"}
```

Term rows:

```json
[
    {"group_id": 10, "term": "depositum"},
    {"group_id": 10, "term": "indskud"},
    {"group_id": 10, "term": "deposit"}
]
```

Lookup is bidirectional. When a user searches for `indskud`, SynonymRepository
resolves the matching term row, loads its group, and returns all normalized terms
in that group.

### Normalization rules

Synonym matching uses one strict normalization pipeline everywhere:
- trim leading and trailing whitespace
- lowercase using UTF-8 aware lowercasing
- collapse internal whitespace runs to a single space
- reject empty terms after normalization
- de-duplicate normalized members within a synonym group

No stemming, fuzzy matching, diacritic stripping, or lemmatization is performed in V1.
The same normalization rules apply during import, insert/update, and query-time lookup.

For deterministic persistence and easier debugging, synonym terms should be:
1. normalized,
2. de-duplicated,
3. sorted deterministically,
before being written as term rows.

`canonical_term` on the group row must be normalized by the same rules and must
also be present among the persisted term rows.

### Integration with retrieval pipeline

Synonym expansion is a **query-time transformation** owned by Retriever:

1. Retriever tokenizes the query text.
2. For each token, SynonymRepository checks if it belongs to a synonym group
   in any of the targeted knowledge bases.
3. If matches are found, the token is replaced by a group of alternatives.
4. Retriever builds the expanded query and switches the FULLTEXT mode:
   - **No expansion occurred** → natural language mode (standard scoring).
   - **Expansion occurred** → boolean mode with grouped alternatives:
     `+(depositum indskud) +(opsige ophæve "bringe til ophør")`
5. The expanded query and mode are passed to `ChunkRepository::findByLexical()`.

This keeps the query transformation in Retriever and the FULLTEXT execution in
ChunkRepository — clean separation of concerns.

### Lookup execution model

V1 performs synonym expansion primarily in PHP, not through repeated per-token SQL
lookups during retrieval.

Flow:
1. Retriever resolves the targeted knowledge base(s).
2. SynonymRepository loads synonym groups and synonym term rows for those knowledge bases.
3. Retriever (or SynonymRepository::expand()) builds in-memory lookup maps for:
   - single-word terms
   - exact multi-word phrases
4. Query-time expansion runs against that in-memory map.

Rationale:
- simpler and more deterministic than repeated SQL/JSON lookups
- easier to normalize consistently
- easier to test
- avoids awkward per-token JSON search in SQL

A per-request/process cache for loaded synonym groups is allowed.

### Single-word and multi-word matching rules

Multi-word synonym entries are allowed in V1, but they are matched only as exact
normalized phrases in the normalized query text.

Rules:
- Single-word entries match normalized tokens.
- Multi-word entries match only when the full normalized phrase occurs in the
  normalized query text.
- Multi-word entries are emitted as quoted boolean terms during lexical expansion.
- Partial token matches must not trigger a multi-word synonym group.

Example:
- Synonym group: `["opsige", "ophæve", "bringe til ophør"]`
- Query: `kan udlejer bringe til ophør lejemålet?`
  → phrase match, expand the group
- Query: `kan udlejer bringe lejemålet til noget ophør?`
  → no phrase match
- Query: `kan udlejer ophæve lejemålet?`
  → single-word match via `ophæve`
  
### Overlap and ambiguity rule

Within one knowledge base, the same normalized surface form may belong to at most
one synonym group.

If a normalized term appears in multiple groups within the same knowledge base,
import/update fails fast with a clear validation error.

V1 does not perform silent transitive merging of overlapping groups.
Ambiguous overlap is treated as invalid synonym data.

### Expansion limits

To keep lexical expansion bounded and predictable, V1 applies deterministic caps.

Suggested limits:
- maximum alternatives per matched group
- maximum expanded groups per query
- maximum final boolean query length

If a limit is exceeded:
- expansion is truncated deterministically,
- a warning is logged,
- retrieval continues with the truncated expanded query.

Expansion overflow does not fail the whole query in V1 unless the synonym data is
itself malformed.

### Scope and retrieval-leg boundaries

Synonym expansion is scoped per knowledge base, not globally merged across all data.

Terms from one knowledge base must not silently expand lexical queries for another
knowledge base.

Synonym expansion affects only the lexical retrieval leg.
The vector retrieval leg always uses the original user question embedding unchanged.

### Administration

Synonyms are admin-maintained via CLI commands or application-level admin UI.

Import semantics in V1:
- `importBatch()` is a per-group upsert scoped to the target knowledge base.
- Existing groups are matched by `canonical_term`.
- Match = replace that group's persisted term rows transactionally.
- No match = insert a new group.
- Groups not present in the import batch are left untouched.
- Import is not a full replacement of all synonym groups for the knowledge base.

The package ships four CLI commands:

| Command                  | Purpose                                          |
|--------------------------|--------------------------------------------------|
| `know:synonyms:import`   | Bulk-import synonym groups from JSON or CSV      |
| `know:synonyms:list`     | List all synonym groups for a knowledge base     |
| `know:synonyms:add`      | Add one synonym group and its term rows          |
| `know:synonyms:remove`   | Remove one synonym group and its term rows       |

Import format (JSON):
```json
[
    {"canonical_term": "depositum", "terms": ["depositum", "indskud", "deposit"]},
    {"canonical_term": "opsige",    "terms": ["opsige", "ophæve", "bringe til ophør"]}
]
```


---


## 14. Component contracts

### 14.1 Util — Chunker

Stateless text-to-chunks transformer. No App dependency. Chunking *mechanics* only.
Chunking *policy* (which strategy, token limits) is decided by the calling Operation.

```php
final class Chunker {

    /**
     * Split text into retrieval-optimized chunks.
     *
     * @param  string  $text      Raw text content of one unit.
     * @param  string  $strategy  Chunking strategy: 'fixed_size' | 'paragraph'.
     * @param  int     $maxTokens   Maximum estimated tokens per chunk.
     * @param  int     $overlapTokens  Overlap between consecutive chunks (fixed_size only).
     * @return array   [['content' => string, 'index' => int, 'token_estimate' => int,
     *                   'char_count' => int, 'context_before' => ?string,
     *                   'context_after' => ?string], ...]
     */
    public static function chunk(string $text, string $strategy = 'fixed_size', int $maxTokens = 512, int $overlapTokens = 50): array
}
```

Strategies:
- `fixed_size` — Token-based splitting with configurable overlap. Produces
  `context_before` / `context_after` from the overlap regions.
- `paragraph` — Split on natural paragraph boundaries (double newline), merge
  small paragraphs up to `maxTokens`, split oversized paragraphs.

### 14.2 Util — PromptBuilder

Assembles messages arrays for HelloAi. Pure Util: no App, no cfg, no language files,
no IO. Receives all resolved values as explicit parameters from the calling Operation.

```php
final class PromptBuilder {

    /**
     * Build a chat messages array for question answering.
     *
     * Injects only prompt context fields per chunk: document_title,
     * unit_identifier, chunk content. Excludes overlap context and
     * non-essential metadata.
     *
     * Respects max_context_tokens by including chunks in score order
     * and stopping when the budget is exhausted. Never truncates
     * a chunk mid-text.
     *
     * @param  string  $question          The user's question.
     * @param  array   $chunks            Ranked chunks with return shape metadata.
     * @param  ?string $systemTemplate    Custom system prompt template. Null = package default.
     * @param  ?string $language          Resolved answer language. Null = no instruction.
     * @param  int     $maxContextTokens  Token budget for chunk context.
     * @return array   Messages array ready for HelloAi chat().
     */
    public static function build(string $question, array $chunks, ?string $systemTemplate = null, ?string $language = null, int $maxContextTokens = 4000): array

    /**
     * Build a rerank prompt for AI-based reranking.
     *
     * @param  string  $question         The user's question.
     * @param  array   $candidateChunks  Candidate chunks to score.
     * @return array   Messages array instructing the model to return JSON scores.
     */
    public static function buildRerankPrompt(string $question, array $candidateChunks): array
}
```

### 14.3 Util — CosineSimilarity

```php
final class CosineSimilarity {

    /**
     * Compute cosine similarity between two vectors of equal dimension.
     *
     * @param  float[]  $a
     * @param  float[]  $b
     * @return float    Similarity in range [-1, 1]. Higher = more similar.
     * @throws \InvalidArgumentException  When dimensions differ or vectors are empty.
     */
    public static function compute(array $a, array $b): float
}
```

### 14.4 Util — ScoreFusion

```php
final class ScoreFusion {

    /**
     * Normalize scores to 0–1 range (min-max normalization).
     *
     * @param  array   $results   Each element must have $scoreKey.
     * @param  string  $scoreKey  Key containing the raw score.
     * @return array   Same structure with scores normalized.
     */
    public static function normalize(array $results, string $scoreKey): array

    /**
     * Reciprocal Rank Fusion.
     *
     * Merges two ranked result lists by chunk_id. Chunks appearing in both
     * lists get accumulated scores. Returns merged list sorted descending.
     *
     * @param  array  $listA  Ranked results (index 0 = rank 1). Each must have 'chunk_id'.
     * @param  array  $listB  Ranked results (index 0 = rank 1). Each must have 'chunk_id'.
     * @param  int    $k      RRF constant (default 60).
     * @return array  Merged results with 'fused_score' and 'retrieval_methods' keys added.
     */
    public static function rrf(array $listA, array $listB, int $k = 60): array
}
```

### 14.5 Service — Retriever

Registered in service map as `retriever`. Owns the full hybrid retrieval pipeline
including synonym expansion. Reads cfg, calls Repositories for synonym expansion,
lexical/vector hits, calls Utils for score fusion, calls HelloAi for rerank
(with graceful fallback on failure).

```php
final class Retriever extends BaseService {

    /**
     * Retrieve the most relevant chunks for a query.
     *
     * @param  string  $queryText    Raw query string (used for lexical + rerank).
     * @param  array   $queryVector  Embedding of the query (used for vector search).
     *                               Empty array when vector search is disabled.
     * @param  array   $options      Per-call overrides: top_k, min_similarity,
     *                               knowledge_base_ids, etc.
     * @return array   ['chunks' => [...ranked chunk rows with source metadata...],
     *                  'meta' => ['strategy', 'lexical_count', 'vector_count',
     *                  'fused_count', 'rerank_status', 'synonyms_used', 'duration_ms']]
     * @throws RetrievalException  When no search leg is active or pipeline fails.
     */
    public function retrieve(string $queryText, array $queryVector, array $options = []): array
}
```

### 14.6 Repositories

**KnowledgeBaseRepository** — `know_bases` CRUD:
- `insert(array $data): int`
- `findBySlug(string $slug): ?array`
- `findById(int $id): ?array`
- `listAll(?string $status = null): array`
- `update(int $id, array $data): int`
- `delete(int $id): int` (cascades to all descendants)

**DocumentRepository** — `know_documents` CRUD + content_hash:
- `insert(array $data): int`
- `findBySlug(int $knowledgeBaseId, string $slug): ?array`
- `findById(int $id): ?array`
- `listByKnowledgeBase(int $knowledgeBaseId, ?string $status = null): array`
- `findByContentHash(int $knowledgeBaseId, string $hash): ?array`
- `update(int $id, array $data): int`
- `updateStatus(int $id, string $status): int`
- `delete(int $id): int` (cascades to units → chunks → embeddings)

**UnitRepository** — `know_units` CRUD + hierarchy:
- `insert(array $data): int` — validates parent belongs to same document, generates path
- `findById(int $id): ?array`
- `findChildren(int $parentId): array` — ordered by sort_order
- `findRootUnits(int $documentId): array` — top-level units, ordered
- `findByPath(int $documentId, string $pathPrefix): array` — subtree query
- `findByDocument(int $documentId): array` — all units flat, ordered by path
- `update(int $id, array $data): int`
- `delete(int $id): int` (cascades to child units → chunks → embeddings)
- `deleteByDocument(int $documentId): int`
- `buildPath(int $parentId, int $sortOrder): string` — generates zero-padded path segment
- Root-level sibling ordering: fail-fast on duplicate sort_order (`IngestException`).
  No auto-resequencing — duplicates are a bug in the parser / ingest caller.
- Parent-document consistency: validated in insert/move, fail-fast with exception.

**ChunkRepository** — `know_chunks` CRUD + lexical search:
- `insertBatch(int $unitId, array $chunks): int`
- `findByUnit(int $unitId): array`
- `findById(int $id): ?array`
- `findByLexical(string $query, int $limit, ?array $knowledgeBaseIds = null, string $mode = 'natural'): array`
  — FULLTEXT MATCH...AGAINST using the specified mode.
  — `$mode = 'natural'`: standard natural language relevance scoring.
  — `$mode = 'boolean'`: boolean mode for structured synonym-expanded queries.
  — Returns rows with `relevance_score`, ordered descending.
  — Optional knowledge base scoping via JOIN through units → documents → bases.
  — Retriever determines mode based on synonym expansion results.
- `deleteByUnit(int $unitId): int` (embeddings cascade)
- `deleteByDocument(int $documentId): int` (via unit JOIN)
- `countByDocument(int $documentId): int`

**EmbeddingRepository** — `know_embeddings` CRUD + vector search:
- `insertBatch(array $embeddings): int` — each row: chunk_id, model, dimension, vector (float[])
  — Repository handles `pack('f*', ...$vector)` internally.
- `findByVector(array $queryVector, string $model, int $limit, float $minSimilarity, ?array $knowledgeBaseIds = null): array`
  — Loads all embeddings for the model (scoped to knowledge bases when specified).
  — Computes cosine similarity in PHP via CosineSimilarity util.
  — Returns rows with `similarity_score`, filtered and sorted descending.
  — Repository handles `unpack('f*', $blob)` internally.
- `deleteByModel(string $model): int`
- `deleteByChunkIds(array $chunkIds): int`
- `countByModel(string $model): int`
- `countMissing(int $knowledgeBaseId, string $model): int`
  — Count of chunks that lack an embedding for this model.

**SynonymRepository** — `know_synonym_groups` + `know_synonym_terms` CRUD + bidirectional expansion:
- `expand(array $knowledgeBaseIds, string $queryText): array`
  — Loads synonym groups and term rows for the targeted knowledge bases.
  — Applies the package normalization rules to the query text.
  — Single-word entries match normalized tokens.
  — Multi-word entries match only as exact normalized phrases.
  — Returns expansion result:
    `['expanded_query' => string, 'mode' => 'natural'|'boolean',
      'expansions' => ['indskud' => ['deposit', 'depositum', 'indskud'], ...]]`

- `findGroupById(int $groupId): ?array`
- `findByTerm(int $knowledgeBaseId, string $term): ?array`
  — Returns the matching group with its full ordered term list.

- `listByKnowledgeBase(int $knowledgeBaseId): array`
  — Returns all groups with nested ordered term lists.

- `insertGroup(int $knowledgeBaseId, string $canonicalTerm, array $terms): int`
  — Normalizes all terms, de-duplicates them, and ensures the canonical term is included.
  — Persists one group row plus one term row per normalized term in one transaction.
  — Ensures the canonical term exists as a persisted normalized term row in that same group.
  — Fails fast on overlap within the same knowledge base.

- `updateGroup(int $groupId, string $canonicalTerm, array $terms): int`
  — Replaces the group's canonical term and term rows transactionally.
  — Ensures the canonical term exists as a persisted normalized term row in that same group.
  — Same normalization and overlap validation as insert.

- `deleteGroup(int $groupId): int`

- `importBatch(int $knowledgeBaseId, array $entries): int`
  — Bulk upsert: each entry is `['canonical_term' => string, 'terms' => string[]]`
  — Upsert key: `canonical_term` within the target knowledge base.
  — Matching canonical term: replace that group's canonical term value and all its persisted term rows transactionally.
  — No match: insert a new group and its term rows.
  — Groups not mentioned in the batch remain untouched.
  — Import is NOT full replacement for the knowledge base.
  — Fails fast on ambiguous overlap within the same knowledge base.

**QueryLogRepository** — `know_query_log` (optional table):
- `write(array $data): ?int` — no-ops if table does not exist (checks once, cached).
  Accepts the canonical payload shape defined in §12.
- `prune(int $maxAgeDays): int` — deletes entries older than threshold.
- `tableExists(): bool` — cached per-process check.
- `createTable(): void` — executes DDL to create the table (used by setup command).

### 14.7 Operations

**IngestDocument:**

```php
/**
 * Ingest a parsed document into the knowledge base.
 *
 * Receives pre-parsed structural data (units with their text content).
 * Parsing raw source formats (HTML, PDF, plain text) into unit structures
 * is an app-side concern — the package is format-agnostic.
 *
 * Flow:
 *   1. Validate input
 *   2. Check content_hash for idempotent skip (match = no-op)
 *   3. Begin transaction
 *   4. Delete existing document if re-ingesting (same slug, hash mismatch)
 *   5. Insert document row
 *   6. Insert unit rows with hierarchy and path generation
 *   7. Chunk each unit via Chunker util (policy from cfg)
 *   8. Insert chunk rows
 *   9. Embed chunks via VectorEmbedder (when vector search is active in cfg)
 *  10. Insert embedding rows
 *  11. Commit (rollback on any failure — old document remains intact)
 *
 * @param  array  $input  ['knowledge_base_id', 'slug', 'title', 'source_type',
 *                         'text', 'units' => [...], 'metadata' => [...], ...]
 * @return array  ['document_id', 'units_count', 'chunks_count', 'embeddings_count',
 *                 'skipped' => bool, 'reason' => ?string]
 */
```

Ingest replacement semantics: see §10.

**QueryKnowledgeBase:**

```php
/**
 * Answer a question against the knowledge base.
 *
 * Flow:
 *   1. Validate input
 *   2. Resolve answer language (per-call → cfg → knowledge base → locale; §8)
 *   3. Validate multi-base language consistency (fail-fast if mixed; §8)
 *   4. Embed the question via VectorEmbedder (when vector search is active)
 *   5. Retrieve ranked chunks via Retriever service
 *      (Retriever handles synonym expansion, lexical, vector, fusion, rerank)
 *   6. Apply min_score filter (post-fusion; §6)
 *   7. Evaluate context sufficiency (min_chunks threshold)
 *   8. If insufficient and allow_low_context is false: return decline result
 *   9. Build prompt via PromptBuilder (resolved language, budget-trimmed; §9)
 *  10. Chat via HelloAi
 *  11. Optionally log query via QueryLogRepository (§12)
 *  12. Return answer + sources + retrieval_meta + usage
 *
 * @param  array  $input  ['question', 'knowledge_base_ids' => ?array,
 *                         'top_k' => ?int, 'language' => ?string,
 *                         'system_prompt' => ?string,
 *                         'allow_low_context' => ?bool]
 * @return array  ['answer' => ?string, 'status' => 'ok'|'insufficient_context',
 *                 'sources' => [...return shape §7.2...],
 *                 'retrieval_meta' => [...Retriever meta...],
 *                 'usage' => [...HelloAi usage, null when no AI call...]]
 */
```

### 14.8 CLI commands

| Command                  | Class                    | Purpose                                           |
|--------------------------|--------------------------|---------------------------------------------------|
| `know:ingest`            | IngestCommand            | Ingest a document (reads file, parses, delegates) |
| `know:search`            | SearchCommand            | Test a retrieval query from the terminal          |
| `know:stats`             | StatsCommand             | Show knowledge base / document / chunk statistics |
| `know:purge`             | PurgeCommand             | Remove a document and all its descendants         |
| `know:setup-query-log`   | SetupQueryLogCommand     | Create the optional query log table               |
| `know:prune-query-log`   | PruneQueryLogCommand     | Prune old query log entries                       |
| `know:synonyms:import`   | SynonymsImportCommand    | Bulk-import synonym groups from JSON or CSV       |
| `know:synonyms:list`     | SynonymsListCommand      | List all synonym groups for a knowledge base      |
| `know:synonyms:add`      | SynonymsAddCommand       | Create one synonym group and its term rows        |
| `know:synonyms:remove`   | SynonymsRemoveCommand    | Remove one synonym group and its term rows        |


---


## 15. Boot Registry

```php
<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * ... MIT license header ...
 */
namespace CitOmni\KnowledgeBase\Boot;

final class Registry {

    public const MAP_HTTP = [
        'retriever' => \CitOmni\KnowledgeBase\Service\Retriever::class,
    ];

    public const MAP_CLI = self::MAP_HTTP;

    public const CFG_HTTP = [
        'knowledgebase' => [
            'embedding_profile' => 'openai-text-embedding-3-small',
            'chat_profile'      => 'openai-gpt-4o-mini',
            'chunker' => [
                'strategy'       => 'fixed_size',
                'max_tokens'     => 512,
                'overlap_tokens' => 50,
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
                'system_template'      => null,
                'max_context_tokens'   => 4000,
                'language'             => null,
            ],
            'query_log' => [
                'enabled' => false,
            ],
        ],
    ];

    public const CFG_CLI = self::CFG_HTTP;

    // No ROUTES_HTTP — app defines its own routes calling Operations.

    public const COMMANDS_CLI = [
        'know:ingest' => [
            'command'     => \CitOmni\KnowledgeBase\Command\IngestCommand::class,
            'description' => 'Ingest a document into the knowledge base',
        ],
        'know:search' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SearchCommand::class,
            'description' => 'Test a retrieval query from the command line',
        ],
        'know:stats' => [
            'command'     => \CitOmni\KnowledgeBase\Command\StatsCommand::class,
            'description' => 'Show knowledge base statistics',
        ],
        'know:purge' => [
            'command'     => \CitOmni\KnowledgeBase\Command\PurgeCommand::class,
            'description' => 'Remove a document and its descendants from the knowledge base',
        ],
        'know:setup-query-log' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SetupQueryLogCommand::class,
            'description' => 'Create the optional query log table',
        ],
        'know:prune-query-log' => [
            'command'     => \CitOmni\KnowledgeBase\Command\PruneQueryLogCommand::class,
            'description' => 'Prune old query log entries',
        ],
        'know:synonyms:import' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SynonymsImportCommand::class,
            'description' => 'Bulk-import synonym groups from JSON or CSV',
        ],
        'know:synonyms:list' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SynonymsListCommand::class,
            'description' => 'List all synonym groups for a knowledge base',
        ],
        'know:synonyms:add' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SynonymsAddCommand::class,
            'description' => 'Create one synonym group and its term rows',
        ],
        'know:synonyms:remove' => [
            'command'     => \CitOmni\KnowledgeBase\Command\SynonymsRemoveCommand::class,
            'description' => 'Remove one synonym group and its term rows',
        ],
    ];
}
```


---


## 16. App-side wiring (example: lejelov-demo)

The package provides no HTTP routes. The application defines its own routes
and controllers that instantiate Operations directly.

```php
// config/citomni_http_routes.php
'/lejelov/spoerg.json' => [
    'controller' => \App\Http\Controller\LejelovController::class,
    'action'     => 'query',
    'methods'    => ['POST'],
],
```

```php
// src/Http/Controller/LejelovController.php
public function query(): never {
    $this->app->csrf->requireValid();

    $question = $this->app->request->post('question');
    // ... validate ...

    $op = new \CitOmni\KnowledgeBase\Operation\QueryKnowledgeBase($this->app);
    $result = $op->execute([
        'question'           => $question,
        'knowledge_base_ids' => [1],
    ]);

    if ($result['status'] === 'insufficient_context') {
        $this->app->response->json([
            'answer'  => null,
            'message' => 'Der blev ikke fundet tilstrækkeligt grundlag til at besvare spørgsmålet.',
        ]);
    }

    $this->app->response->json([
        'answer'  => $result['answer'],
        'sources' => $result['sources'],
    ]);
}
```

Parsing source documents (HTML law text, FAQ markdown, PDF manuals) into the unit
structure expected by IngestDocument is an app-side responsibility. The package is
source-format-agnostic.


---


## 17. Design decisions log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Package name `citomni/knowledgebase`, not `citomni/rag` | Human readability > technical jargon for Composer names. Internals stay precise. |
| 2 | Seven-table schema with units layer + relational synonyms | Units are stable structural elements. Synonyms improve lexical retrieval for domain-specific terminology and are stored as relational groups and term rows for stronger integrity. |
| 3 | Embeddings in separate table | Multiple models per chunk, re-embedding isolation, vector-free queries skip BLOBs. |
| 4 | BLOB with `pack('f*')` for vector storage | ~4× more compact than JSON. Faster unpack. Repository owns the format. |
| 5 | Chunker as Util, not Service | Stateless, no App dependency. Mechanics in Util, policy in Operation. |
| 6 | Retriever as Service | Cfg-driven pipeline orchestration. Needs App for cfg, Repositories, HelloAi. |
| 7 | Hybrid retrieval with configurable legs | Lexical, vector, synonym expansion, rerank all toggleable. Fusion automatic. At least one search leg required. |
| 8 | RRF for score fusion | Robust, parameter-light, handles heterogeneous score distributions. |
| 9 | Rerank via HelloAi with graceful fallback | Opt-in quality pass. Failure falls back to pre-rerank ordering, never blocks results. |
| 10 | Zero-padded materialized path | Deterministic lexical ordering, subtree queries without recursive CTEs. |
| 11 | content_hash for idempotent re-import | SHA-256 hex, ascii_bin. Match = skip. Avoids unnecessary re-chunking. |
| 12 | UNIQUE sibling ordering with fail-fast root enforcement | Prevents duplicate positions. Root-level: Repository fails fast, no auto-resequencing. |
| 13 | Query log as optional table via explicit CLI command | No hidden runtime DDL. Repository no-ops when absent. |
| 14 | No HTTP routes in package | Package ships Commands + Operations. HTTP routing is app-domain. |
| 15 | Silent exclusion for incomplete embeddings | Chunks without embeddings participate in lexical only. Completeness is observability. |
| 16 | Parent-document consistency via Repository | Not enforceable in MySQL CHECK. Validated in insert/move, fail-fast. |
| 17 | Retrieval-first, not chat-first | Every answer grounded in retrieved context. No fallback to parametric knowledge. |
| 18 | Grounded default prompting | Default system template: answer from context only, state uncertainty, never invent. |
| 19 | Strict no-hit behavior | No context → no AI call → controlled decline. Override via allow_low_context. |
| 20 | Three-layer citation contract | Prompt context (minimal), return shape (rich), UI shape (app-owned). |
| 21 | Explicit language precedence, no auto-detection | Per-call → cfg → knowledge base → locale. Mixed-language multi-base = fail-fast. |
| 22 | Never truncate chunks mid-text | Whole chunks or nothing. Budget exceeded = drop lowest-ranked. |
| 23 | Replace-by-slug for V1 ingest, transactionally atomic | content_hash check first, then delete+reinsert in one transaction. Supersede is V2. |
| 24 | No denormalized chunks_count | Derived via COUNT query. Not worth maintenance cost on re-chunk operations. |
| 25 | Util purity contract | Utils never read cfg, language files, or App. Receive resolved inputs only. |
| 26 | Token estimates, not guarantees | All V1 token counts are heuristic. Consuming code must treat them as approximate. |
| 27 | min_score is post-fusion | Applied after normalization/fusion, not per-leg. Preserves fusion integrity. |
| 28 | Adaptive FULLTEXT mode | Natural language mode by default. Boolean mode when synonym expansion is active. Retriever decides mode; ChunkRepository accepts it as parameter. |
| 29 | Query log canonical payload shape | Fixed field set documented in §12. Extensions go in metadata_json. |
| 30 | Return shape naming: Retriever `meta`, Operation `retrieval_meta` | Prevents key collision, clear ownership per layer. |
| 31 | Synonym expansion for domain-specific lexical quality | Admin-maintained synonym groups per knowledge base. Bidirectional lookup. Expansion is a query-time transformation in Retriever, not a storage-time concern. |
| 32 | Synonym expansion executes primarily in PHP | Synonym groups are loaded per targeted knowledge base and matched in PHP for deterministic normalization and simpler lookup logic than repeated SQL/JSON membership queries. |
| 33 | Multi-word synonyms are exact-phrase matches only | Prevents overly broad lexical expansion. Partial token matches must not trigger multi-word synonym groups. |
| 34 | Synonym overlap is invalid | A normalized surface form may belong to only one synonym group per knowledge base. Import/update fails fast on overlap. |
| 35 | Synonym expansion is bounded | Expansion uses deterministic caps on group alternatives, expanded groups, and/or final boolean query size. Overflow truncates with warning instead of failing the whole query. |
| 36 | Synonym expansion affects lexical only | The vector leg always uses the original user question embedding unchanged. |
| 37 | Relational synonym storage replaces JSON arrays | `know_synonym_groups` + `know_synonym_terms` provide row-level integrity, overlap enforcement via unique indexes, better lookup indexes, and cleaner repository semantics. |
| 38 | Canonical synonym term must also exist as a term row | `canonical_term` lives on the group row for administration, but must also be persisted in `know_synonym_terms` so all lookupable surface forms remain row-based and bidirectional. Repository enforces this transactionally. |


---


## 18. Open items / next steps

| #  | Item                           | Status       | Notes |
|----|--------------------------------|--------------|-------|
| 1  | Repository classes             | **Next**     | Seven repositories. Define exact SQL patterns. |
| 2  | Retriever service              | Pending      | Depends on Repository contracts being finalized. |
| 3  | Util implementations           | Pending      | Chunker, PromptBuilder, CosineSimilarity, ScoreFusion. |
| 4  | Operations                     | Pending      | IngestDocument, QueryKnowledgeBase. |
| 5  | CLI commands                   | Pending      | Ten commands with signatures. |
| 6  | Boot Registry (final)          | Pending      | Draft exists; finalize after all components. |
| 7  | Cfg baseline (final)           | Pending      | Draft exists; verify all runtime-read paths have baselines. |
| 8  | Language files                 | Pending      | en + da for error messages, CLI output. |
| 9  | Default system prompt template | Pending      | Package-shipped QA prompt with grounding instructions. |
| 10 | Default rerank prompt template | Pending      | Package-shipped rerank scoring prompt. |
| 11 | Exception hierarchy            | Pending      | Base + specific exceptions with clear throw sites. |
| 12 | Token estimation strategy      | To decide    | char/4 heuristic vs. tiktoken vs. cl100k. Tradeoff: accuracy vs. dependencies. |
| 13 | Ingest transaction boundaries  | Decided      | Single transaction for full replace-by-slug (§10). |
| 14 | KB scoping in retrieval        | To verify    | Consistent knowledge_base_ids filtering through all layers. |
| 15 | FULLTEXT language config       | To evaluate  | MySQL defaults may not be optimal for Danish. ft_min_word_len, stopwords. |
| 16 | Boolean query builder          | To implement | Retriever must build safe bounded boolean FULLTEXT queries from synonym expansion results, including exact-phrase handling for multi-word synonyms and escaping/stripping of reserved boolean characters. |
| 17 | Synonym canonical-term enforcement | Decided | `canonical_term` lives on the group row and must also exist as a normalized term row in that same group. Repository enforces this transactionally. |
| 18 | Synonym import replace semantics | Decided | `importBatch()` is per-group upsert by `canonical_term` within the target knowledge base. Matching canonical term = update that group transactionally (replace canonical term row value + replace all term rows for that group). No matching canonical term = insert new group. Import does NOT perform full replacement of all synonym groups in the knowledge base, and groups not mentioned in the batch remain untouched. Batch validation is fail-fast on overlap or invalid normalization. |
| 19 | Synonym transaction scope | Decided | Group writes are transactional; batch import fail-fast behavior must be explicit. |
| 20 | Synonym overlap DB enforcement | Decided | `UNIQUE(knowledge_base_id, term)` on `know_synonym_terms` enforces one group per normalized surface form per knowledge base. |