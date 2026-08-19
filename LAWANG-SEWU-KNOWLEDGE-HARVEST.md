# LAWANG SEWU — WEBVIEW KNOWLEDGE HARVEST

Build-time knowledge harvester for the Lawang Sewu standalone AI tour.
Extracts webview content from [`skin.js`](skin.js), fetches remote objinfo2 pages, maps to tour scenes, and publishes [`knowledge.json`](knowledge.json). No invented facts — every entry traces to `skin.js`, a fetched webview page, or local tour data.

## Pipeline

```
skin.js ──► ls_skin_core ──► ls_parse_iframes ──► ls_classify
                                              │
              ┌────────────────────────────────┤
              │ CONTENT (objinfo2)             │ IGNORE (chatbase) / INDEX (kategori/menu)
              ▼                                ▼
      ls_parse_vb_blocks ────────────► inventory (webview-map.json)
              │
              ▼
      remote fetch objinfo2.php?info={id}&lang={id,en,sa,zh}
              │
              ▼
      ls_extract_html (h1 + p.mb-4)
              │
              ▼
      ls_build_entities ──► scene mapping (tour-data.js aliases + openNext)
              │
              ▼
      contamination check ──► publish knowledge.json (+ api/knowledge.json)
```

## Usage

```bash
php tools/harvest-webview-knowledge.php [--dry-run] [--no-fetch] [--report-only] [--rebuild-embeddings]
```

| Flag | Effect |
|---|---|
| `--dry-run` | Parse + fetch + build, write report only, no knowledge.json |
| `--no-fetch` | Use raw cache only; fail fast if a cache file is missing |
| `--report-only` | Nothing written except `webview-harvest-report.json` |
| `--rebuild-embeddings` | Rebuild `api/cache/ai/knowledge-embeddings.json` via `ai_embedding_index` |

Exit codes: `0` ok, `2` contamination blocked, `3` fetch failures (network run).

## Sources (honest provenance)

- **13 objinfo2 iframes** embedded in `skin.js` (4 langs fetched remotely — the endpoint supports `id/en/sa/zh`, skin embeds only `lang=id`). Each entry records `source` URL, `sourceFile`, `sourceLine`, `vbState`, `mappingConfidence`, `mappingEvidence`, `provenance`.
- **3 inline skin texts** (welcome, virtual guide, Semarang City) extracted from `player._("...")` at fixed line offsets.
- **chatbase.co chatbot iframe** detected and excluded from knowledge (existing third-party chatbot, requirement). Listed in `webview-map.json` → `inventory.ignored`.
- **Lampung webview-local refs** (`../webview/discover.php`, `../webview/kategori.php`) excluded — contamination risk, listed in `inventory.ignored`.

## Contamination gate

Publish is blocked (exit 2) if any entry/inline text contains `bakauheni`, `pelabuhan bakauheni`, or `menara siger`. This guards against the Lampung tour webview leaking into Semarang knowledge.

## Scene mapping

Evidence-based only, never guessed:

| Confidence | Rule | Count |
|---|---|---|
| `EXACT` | `openNext` matches a tour-data scene alias AND state matches a scene alias | 6 |
| `LIKELY` | `openNext` alone, or single state-alias match without openNext | 5 |
| `UNMAPPED` | No openNext and no alias evidence | 2 |
| `AMBIGUOUS` | Multiple candidates | 0 |

## Outputs

| File | Purpose |
|---|---|
| [`knowledge.json`](knowledge.json) | Live knowledge consumed by `api/ai-core.php` → `ai_load_knowledge()` |
| [`api/knowledge.json`](api/knowledge.json) | Spec-required duplicate |
| [`api/cache/ai/webview-map.json`](api/cache/ai/webview-map.json) | Entity ↔ VB-state ↔ scene map + ignored inventory |
| [`webview-harvest-report.json`](webview-harvest-report.json) | Per-run report (inventory, fetch, mappings, contamination) |

## Tests

```bash
php tests/test-webview-harvester.php   # 31 unit checks
php tests/test-ai-acceptance.php       # 14 offline retrieval checks
```

- Harvester tests: iframe parsing/classification, VB-block mapping, scene mapping, contamination, merge, published-file integrity.
- Acceptance tests: 9 supported questions must surface the right entry in top-3; 5 hallucination-prone questions must not surface any knowledge mentioning the forbidden topic (tiket/harga, jam buka, siger, bakauheni, hantu). Offline — embeddings forced off.

## Refresh

```bash
php tools/harvest-webview-knowledge.php --no-fetch --rebuild-embeddings
```

Manual curation: add entries to `knowledge.json` with `sourceType: "manual"` — they survive re-harvest (harvester drops only `webview:*`/`skin:*` ids it owns).

## Status

`TEST-WEBVIEW-HARVESTER: PASS` (31/31) · `AI-ACCEPTANCE: PASS` (14/14) · contamination clean.
