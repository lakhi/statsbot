# Knowledge-base build tools

Offline corpus pipeline for the RAG pilot. **None of this runs on the pod** — the
webspace container has no git and no Composer, so the runtime side is deliberately
plain PHP with zero new dependencies. Everything expensive happens here, on a dev
machine, and only the built index ships.

```
resources/kb/hyptest.pdf
  │  extract.py   docling + formula enrichment          ~12 min, one-time
  ├─────────────> resources/kb/hyptest.docling.json     lossless: text, LaTeX, page, bbox
  │
  │  chunk.py     re-sort by provenance, apply QA patch, segment on headings
  ├─────────────> storage/app/kb/chunks.json            13 chunks
  │
  │  embed.py     Azure OpenAI embeddings, L2-normalised
  ├─────────────> storage/app/kb/kb-<doc>-<tag>-<dims>.f32   packed float32
  │               storage/app/kb/kb-<doc>-<tag>-<dims>.json  metadata + chunk text
  │
  └─ eval.py      score against resources/kb/gold-questions.json
```

## Where the corpus lives

**This repository is public, so the Statistik corpus never enters it — not the
PDFs, not anything derived from them.** Two sibling folders, next to the repo:

```
~/Developer/uni-studAsst-projects/
  statsbot/                  this repo (public)
  statsbot-rag-corpus/       the course materials, as downloaded. Read-only.
  statsbot-rag-build/        everything derived: docling JSON, chunks, manifest,
                             QA patches, gold set (it holds real student questions),
                             and index/kb-corpus.{f32,json}
```

Back up the hand-made files in `statsbot-rag-build/` (manifest, patches, gold set)
somewhere private — they are deliberately not in git.

Guards, so a slip is caught rather than published: the root `.gitignore` ignores
`*.pdf *.pptx *.docx *.xlsx *.jasp *.f32 *.enc` anywhere, and `resources/kb/` is a
whitelist that admits only the pilot files below.

`resources/kb/` still holds the 4-page pilot notes (`hyptest.*`) and their gold set.
Those were made public deliberately on 2026-09-02 and stay as a small worked example;
a public push is not undoable, so removing them now would change nothing.

## Shipping the index: sealed

The index sidecar holds every chunk's text verbatim (the backend pastes it into the
prompt), and the release asset is public. So `scripts/publish-rag-pilot.sh` ships the
index **encrypted** and the pod decrypts it during the deploy:

- `seal.py` — AES-256-GCM (`cryptography`); format `SBKB1 | nonce | ciphertext | tag`,
  with the file name as additional data, so the two files cannot be swapped or renamed.
- **Key:** 32 random bytes, created once with `python3 tools/kb/seal.py keygen`. It
  lives only in the macOS Keychain (service `statsbot-corpus-key`) and in the pilot
  `.env` on the pod (`CORPUS_KEY`, pasted once with `read -rs`, see
  `scripts/rag-pilot-deploy.sh`). Lose it and nothing is lost: make a new one,
  re-publish, paste it again.
- The index always installs as `storage/app/kb/kb-corpus.{f32,json}`. Its version is
  inside the sidecar (`doc_id@built_at`, stamped on every history row as
  `kb_version`), so a new corpus never needs a `.env` edit.
- **Leak guard:** before uploading, publish refuses if the stage contains any `.f32`,
  unsealed sidecar, office/PDF file, or any of 25 random chunk-text samples.
- The deploy decrypts and checks the index (model, dims, size, JSON) *before*
  applying anything, and runs `php artisan kb:status` afterwards. Run that command by
  hand whenever in doubt: `KbRetriever` fails soft, so a broken index otherwise only
  shows as both RAG arms answering like no-RAG.

Try a publish without uploading: `DRY_RUN=1 KB_INDEX=<path without extension>
scripts/publish-rag-pilot.sh`.

## Setup

```bash
uv venv --python 3.12 .venv-kb
uv pip install --python .venv-kb/bin/python -r tools/kb/requirements.txt
```

First `extract.py` run downloads ~1.2 GB of models to `~/.cache/huggingface`.

## Rebuild the index

```bash
PY=.venv-kb/bin/python

# 1. extract  (only when the PDF changes — 12 min; the JSON is committed)
$PY tools/kb/extract.py --pdf resources/kb/hyptest.pdf \
    --out resources/kb/hyptest.docling.json

# 2. chunk    (seconds; re-run freely while tuning)
$PY tools/kb/chunk.py \
    --doc   resources/kb/hyptest.docling.json \
    --patch resources/kb/hyptest.patch.json \
    --doc-id hyptest --title "Lecture Notes: Hypothesis Testing" \
    --out   storage/app/kb/chunks.json

# 3. embed + 4. evaluate  (key pulled just in time, never written to disk)
export AZURE_API_KEY=$(az cognitiveservices account keys list \
    -n statistics-tutor -g Lehrprojekt --query key1 -o tsv)

$PY tools/kb/embed.py --chunks storage/app/kb/chunks.json \
    --deployment statsbot-embed-3-large --model text-embedding-3-large --tag 3large \
    --dims 3072 1024 512 256 --source-pdf resources/kb/hyptest.pdf \
    --out-dir storage/app/kb

$PY tools/kb/eval.py --gold resources/kb/gold-questions.json \
    --index-dir storage/app/kb --k 3
```

## Citation labels (contract with the backend)

In the salient study arm, every answer shows chips naming the course material it drew
on, e.g. **`Statistik I · Vorlesung 14: t-Test · Folie 11`**. The backend does not
construct that string: only the pipeline knows whether a page is a slide, a handout
page or an exam question. So each chunk in the index JSON carries it, plus its parts:

```json
{ "id": "stat1-v14-011",
  "citation":    "Statistik I · Vorlesung 14: t-Test · Folie 11",
  "cite_course": "Statistik I",
  "cite_doc":    "Vorlesung 14",
  "cite_topic":  "t-Test",
  "cite_pos":    "Folie 11", ... }
```

- Format: `<Kurs> · <Dokument>: <Thema> · <Stelle>`. The topic is the document's own
  chapter heading at that slide (never a file name), and is omitted for exams, which mix
  topics. Chips are stacked one per line; when one runs out of room only `cite_topic`
  shortens, so course, document and position always stay readable.
- `Folie N` is the slide number students can see: the printed number in Statistik I
  decks, the page number in the Statistik II and Fortgeschrittene decks (which print none).

- One label per *source a student would recognise*. The backend dedupes chips by label,
  so two chunks of the same slide should get the same `citation`.
- German, as the materials are; students see it verbatim.
- Optional for backwards compatibility: without it, `KbRetriever` falls back to
  `"<heading chain>, p. <page_start>"`, which is what the hyptest pilot index shows.

**Chunk `text` starts with a topic line and must not name its source.** E.g.
`Thema: Varianzanalytische Methoden › Einfaktorielle Versuchspläne: Post-hoc Tests`,
built from the document's own headings — topic only, never a course, lecture number,
"Folie" or "Prüfung". Exam chunks additionally start with the neutral marker
`Multiple-choice item: the listed statements may be false.`, because the exams carry no
answer key and their distractors are false on purpose. The model sees each excerpt as
`[id]` plus its text, and nothing else, in BOTH RAG arms. So strip running headers and footers that
identify the document ("Vorlesung 14 | Folie 12", course name, lecturer, page numbers).
Keep the slide's own title, which is content. Otherwise the model is handed "Vorlesung 14"
in the non-salient arm too, and only a prompt instruction stops it from writing "wie in
Vorlesung 14 gezeigt". The citation belongs in `citation`, which never reaches the model.

## Things that are non-obvious and were learned the hard way

**Default docling is worse than `pdftotext` for maths.** It detects formula regions
and then emits `<!-- formula-not-decoded -->` — 20 of them on this document. Always
run with formula enrichment. Keep `pdftotext -layout` as a control; it is what caught
this.

**docling's predicted reading order is unreliable.** On this document it filed the
entire body of "2 p-Values" under "4 Permutation Tests". `chunk.py` re-sorts by
`(page_no, -bbox.top, bbox.left)` instead, which repaired both defects exactly. This
works because the source is single-column — a two-column layout needs column
detection first.

**Never merge undersized chunks across a heading.** A generic "merge chunks below N
tokens" rule filed the Statistical Power material under a chunk headed "Type II
Error". In lecture notes a short section *is* the unit of instruction.

**A formula's lead-in text is a separate docling item.** `"For a one-sided test,"`
and the formula that follows it are useless apart, so `chunk.py` glues each formula
to its preceding text item and never splits the pair.

**Patch edits are content-addressed, never index-based.** `hyptest.patch.json` matches
on text prefixes and fails loudly on zero or multiple matches, so a docling re-run
that renumbers items breaks the build instead of silently patching the wrong thing.

**Embed once at full width.** `text-embedding-3-*` vectors truncate gracefully
(Matryoshka), so narrower indexes are derived locally. Seven index variants cost two
API calls.
