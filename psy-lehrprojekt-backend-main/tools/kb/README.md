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

`storage/app/` is gitignored, so built indexes stay out of the repo and ship as a
release asset, the same way the frontend bundle does.

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
