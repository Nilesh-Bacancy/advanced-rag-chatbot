# Advanced RAG — Report

How the Phase 1 chatbot was extended to handle three PDFs with very
different shapes, what each change is for, and how much it helped.

> **Status of the numbers.** Cells marked **TODO** are filled from the runs
> listed in [How to reproduce](#how-to-reproduce). Everything else below was
> measured on 2026-10-23.

## 1. The challenge

| Document | Pages | Shape | Where the answers hide |
|---|---|---|---|
| GPO 2018 Annual Report | 82 | Narrative | In running sentences, sometimes split across a page break |
| World Bank Global Economic Prospects, June 2026 | 200 | Long, structured: chapters, regions, boxes | The same kind of figure (a growth forecast) appears for every region; the question names the section |
| Apple iMac Product Environmental Report, Oct 2024 | 12 | Visual | Charts, tables, call-outs, and footnotes that qualify a claim several pages away |

The requirements document gives 9 sample questions (3 per document). The
evaluation set (`resources/eval/questions.json`) adds 5 harder questions
and 3 unanswerable ones (17 in total) to test the failure modes each shape
causes.

## 2. Starting point: the Phase 1 pipeline

Fixed-size chunks (1,000 characters, 200 overlap) → Gemini embeddings →
brute-force cosine search → top 5 chunks → Gemini answer with page
citations.

Where it falls short for each document:

- **GPO (narrative):** fixed-size cuts can split a sentence in half, so a
  fact and its subject end up in different chunks. In practice the sample
  questions still passed (4 / 4), so the narrative document is the control
  case.
- **GEP (structured):** a chunk has no idea which chapter or region it
  belongs to. "What is the 2026 forecast for Latin America?" retrieves
  chunks that look similar ("growth is projected to … in 2026") from *any*
  region, and the LLM can quote the wrong region's number.
- **iMac (visual):** chart values and table cells come out of text
  extraction as scattered numbers without labels, and footnote 7 (which
  says what "reduced by more than 20 percent" is measured against) sits on
  the last page, far away from the claim it qualifies.

## 3. What changed

### 3.1 Document profiles (ingestion)

`IngestionService` picks a profile from page count and bookmark outline
(`config/rag.php`):

| Profile | Rule | Applied to | Ingestion |
|---|---|---|---|
| `structured` | ≥ 100 pages and ≥ 5 outline entries | GEP | Fixed-size chunks + section path per chunk |
| `visual` | ≤ 20 pages | iMac | Sentence-aware chunks + footnote linking + vision extraction per page |
| `narrative` | otherwise | GPO | Sentence-aware chunks + footnote linking |

**Sentence-aware chunking** (`ChunkingService`): chunks end at sentence
boundaries, and the overlap is whole sentences, so a fact is never cut in
half.

**Section paths** (`PdfLayoutService` + `SectionMapper`): the PDF bookmark
outline is read with `pdftohtml -xml` and turned into a path for every page,
e.g. `Ch. 2 Regional Outlooks > South Asia`. Every chunk stores the path of
the page it starts on (`document_chunks.section_path`).

**Footnote linking** (`FootnoteLinker`): footnote markers are found from
text height and position on the page (small digits raised right after a
word); the footnote texts are read from the
"Endnotes"/"Notes" section; the text of footnote *n* is appended to every
chunk that contains marker *n*. The claim and its conditions are then
embedded and retrieved together.

**Vision extraction** (`VisionExtractor`): each page of a visual document is
rendered (`pdftoppm`, 110 dpi) and Gemini describes its charts, tables and
call-outs as text with every value and label. These descriptions are stored
as `visual` chunks next to the normal text chunks. This costs one vision
call per page, so it only runs for documents of 20 pages or fewer.

**Why GEP keeps fixed-size chunks.** Section-bounded chunks would be
cleaner, but the section structure can be used at retrieval time instead
(filter + labels), and keeping the chunk text unchanged means its 911
embeddings stay valid in `embedding_cache`. On a free tier of 1,000
embeddings a day, re-chunking the document means losing a full day.

### 3.2 Retrieval (`RetrievalService`)

1. **Section filter** — `SectionMatcher` looks for section names in the
   question ("Chapter 3", "South Asia", "executive summary"), and only
   chunks from matching sections are searched. Very short aliases and
   sections covering more than 40% of the document are ignored, since they
   would hardly narrow the search. With no match, the whole document is
   searched.
2. **Hybrid search** — vector search plus MySQL FULLTEXT search over
   `content` + `section_path`, merged with Reciprocal Rank Fusion (k = 60).
   Keyword search catches exact terms (e.g. "115V", "FDsys") that
   embeddings blur.
3. **LLM reranking** — Gemini picks the most useful of the top 15 fused
   candidates; the remaining slots are filled in search order.

### 3.3 Generation (`RagService`)

Every chunk in the prompt is labelled with its page and section, e.g.
`[Source: p. 96 | Ch. 2 Regional Outlooks > South Asia]`. The prompt tells
the model to keep figures with the section they come from, to keep the
conditions a footnote puts on a claim, and to say so when the answer isn't
in the context.

### 3.4 Operational changes

- `embedding_cache` table: every embedding is stored by hash of model +
  text, so the same text is never embedded twice.
- Token-aware batching: besides 1,000 requests/day and 100/minute, the free
  tier allows 30,000 embedded tokens per minute. A 100-chunk batch of GEP's
  dense tables is already about that much, so the first GEP upload failed on
  every retry. Batches are now capped at ~24,000 estimated tokens and
  sending is paced over a 60-second window.
- Rate-limit handling: on a per-minute limit the app waits for the time the
  API asks for; on the daily limit it stops at once with a clear message,
  and already-cached batches are kept so the next run resumes.
- Model fallback: if the main chat model is overloaded, the app retries and
  then falls back to `gemini-flash-lite-latest`.
- `php artisan rag:ingest`, `rag:eval` and `rag:eval-compare` commands for
  repeatable ingestion and measurement.

## 4. How it is evaluated

`php artisan rag:eval` runs every question through the real pipeline and
records two things:

- **Retrieval hit** — did at least one retrieved chunk come from a page the
  answer is on (`source_pages` in the question file)? Not scored for
  unanswerable questions.
- **Answer verdict** — an LLM judge compares the answer with the expected
  answer. Hard questions list the key facts that must be present (e.g.
  imac-h3 requires all three assumptions from footnote 7).

Each run is saved to `storage/app/eval/` with its settings;
`php artisan rag:eval-compare` prints runs side by side.

**Model used for all runs:** `gemini-flash-lite-latest` for answers,
reranking, vision and judging (`GEMINI_CHAT_MODEL=gemini-flash-lite-latest`),
because `gemini-flash-latest` was returning "overloaded" errors at the time.
Embeddings: `gemini-embedding-001`. The judge is the same small model, so a
single verdict can flip between runs; differences of one question should be
read with that in mind.

## 5. Results

### 5.1 Before / after

| Document | Phase 1 baseline (old chunks, vector only) | New retrieval on old chunks | New pipeline (new ingestion, all stages) |
|---|---|---|---|
| GPO (4 questions) | 4 / 4 | 4 / 4 | **TODO** |
| GEP (6 questions) | **TODO** | — | **TODO** |
| iMac (7 questions) | 6 / 7 | 6 / 7 | **TODO** |

Notes on the measured columns:

- The first baseline run passed imac-h3. Its expected answer was then
  tightened to require the three footnote-7 assumptions (an answer saying
  only "business-as-usual scenario" is incomplete); re-run with that
  requirement, the baseline fails it, giving 6 / 7.
- "New retrieval on old chunks" isolates the retrieval changes: hybrid
  search + reranking alone did not fix imac-h3, because in the old chunks
  footnote 7 is never next to the claim. That failure needs the
  ingestion-side fix (footnote linking), which is what the third column
  measures.
- GEP was never ingested with the Phase 1 chunker in this project, so it
  has no "old chunks" run. Its baseline is `--baseline` (vector search
  only) on the new ingestion. Since GEP keeps fixed-size chunks, its chunk
  text is the same as Phase 1's; only the section labels in the prompt
  differ.

### 5.2 Per question

**TODO** — paste the output of:

```bash
php artisan rag:eval-compare baseline old-chunks-new-retrieval-v2 baseline-new full
```

### 5.3 What each retrieval stage contributes (ablation)

| Run | GPO | GEP | iMac |
|---|---|---|---|
| All stages (`full`) | **TODO** | **TODO** | **TODO** |
| Without reranking | **TODO** | **TODO** | **TODO** |
| Without hybrid search | **TODO** | **TODO** | **TODO** |
| Without section filter | **TODO** | **TODO** | **TODO** |
| Vector only (`baseline-new`) | **TODO** | **TODO** | **TODO** |

What to look for: the section filter should matter most for GEP region
questions (gep-1, gep-h1, gep-h2) and Chapter 3 (gep-2); hybrid search for
exact terms (imac-h2 "115V"); footnote linking for imac-h3 regardless of
retrieval settings.

## 6. How to reproduce

The embedding quota is 1,000 per day (resets around 07:00 UTC). Ingest in
this order so each day fits:

```bash
# Day A: GEP (911 embeddings) + iMac (~44)
GEMINI_CHAT_MODEL=gemini-flash-lite-latest php artisan rag:ingest --replace ~/Downloads/GEP-Jun-2026.pdf
GEMINI_CHAT_MODEL=gemini-flash-lite-latest php artisan rag:ingest --replace ~/Downloads/iMac_PER_Oct2024.pdf

# Day B: GPO (~229)
GEMINI_CHAT_MODEL=gemini-flash-lite-latest php artisan rag:ingest --replace ~/Downloads/GPO-Anual-report.pdf
```

Then the evaluation runs (chat calls only, no embedding quota beyond the
17 questions, which are cached after the first run):

```bash
export GEMINI_CHAT_MODEL=gemini-flash-lite-latest
php artisan rag:eval --label=full
php artisan rag:eval --baseline --label=baseline-new
php artisan rag:eval --no-rerank --label=no-rerank
php artisan rag:eval --no-hybrid --label=no-hybrid
php artisan rag:eval --no-section-filter --label=no-section-filter

php artisan rag:eval-compare baseline old-chunks-new-retrieval-v2 baseline-new full
php artisan rag:eval-compare full no-rerank no-hybrid no-section-filter baseline-new
```

A single document can be run with `--doc=GEP-Jun-2026` and full answers
printed with `--show-answers`.

## 7. Trade-offs and limits

- **Free tier first.** Every design choice that spends embeddings (chunk
  size, re-chunking GEP, re-ingesting) was weighed against the 1,000/day
  quota; the cache and the fixed chunks for GEP follow from that.
- **Profile rules are simple.** Page count + outline is enough for these
  three documents but would misclassify, for example, a long report without
  bookmarks.
- **Vision only for short documents.** Charts in GEP are read as text only;
  vision on selected chart pages would be the next step.
- **LLM reranker and judge.** Both use the same small model as the answer;
  a cross-encoder reranker and a stronger judge would make results steadier.
- **Box sections run on.** GEP's bookmarks mark where a box starts but not
  where it ends, so the chapter text after Box 1.2 or Box 3.1 is labelled
  with the box. Chapter and region questions are unaffected (their paths
  still start with the chapter); a question naming a box can pull in extra
  chapter text.
- **Footnote detection** depends on superscript markers being set in
  smaller, raised text; other layouts would need another rule.
- **Brute-force vector search** in PHP is fine for a few hundred chunks per
  document, not for a large corpus (swap `VectorSearchService` for pgvector).
