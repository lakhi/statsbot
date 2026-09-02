#!/usr/bin/env python3
"""
chunk.py — turn a docling JSON export into retrieval chunks.

Offline/dev only. Never runs on the pod; adds no Composer dependency.

Pipeline:
    docling JSON
      -> flatten body to a linear item list
      -> apply the human QA patch (content-addressed, fails loudly)
      -> RE-SORT by physical provenance (page, -bbox.top, bbox.left)
      -> drop page furniture
      -> derive heading hierarchy (docling emits every header at level=1)
      -> segment into heading-bounded units
      -> glue each formula to the text that introduces it
      -> split oversized units at glue boundaries; merge undersized siblings
      -> serialise to markdown + emit chunk records

Why the re-sort: docling's *predicted* reading order misfiled the whole body of
"2 p-Values" under "4 Permutation Tests" on this document. The provenance data is
the input to that prediction, not its output, so it is still correct. We keep
docling's strong prediction (what each region is) and discard its weak one (order).

Usage:
    python tools/kb/chunk.py \
        --doc      resources/kb/hyptest.docling.json \
        --patch    resources/kb/hyptest.patch.json \
        --doc-id   hyptest \
        --title    "Lecture Notes: Hypothesis Testing" \
        --out      storage/app/kb/chunks.json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from dataclasses import dataclass, field, asdict
from pathlib import Path

try:
    import tiktoken

    _ENC = tiktoken.get_encoding("cl100k_base")  # correct for text-embedding-3-*
except Exception:  # pragma: no cover - tokenizer is advisory, not load-bearing
    _ENC = None


TARGET_TOKENS = 250
MAX_TOKENS = 400

# Deliberately NO minimum-size merge. In lecture notes a short section ("Type I
# Error": one definition, one formula) is the unit of instruction and the ideal
# retrieval atom. Merging undersized siblings the way generic chunkers do both
# loses precision and produces actively misleading headings — an early run filed
# the Statistical Power material under a chunk headed "Type II Error".
# Heading boundaries are hard boundaries here.

# Items that carry no retrievable content.
FURNITURE = {"page_footer", "page_header"}
# Items a formula can attach itself to.
GLUE_HOSTS = {"text", "list_item"}


def ntok(s: str) -> int:
    if _ENC is not None:
        return len(_ENC.encode(s))
    return max(1, len(s) // 4)  # rough fallback


def norm(s: str) -> str:
    """Normalise for patch matching: NFC, collapse whitespace, strip."""
    return re.sub(r"\s+", " ", unicodedata.normalize("NFC", s or "")).strip()


@dataclass
class Item:
    label: str
    text: str
    page: int
    top: float
    left: float
    ref: str
    dropped: bool = False


@dataclass
class Block:
    """A lead item plus any formulas that belong to it. Never split internally."""

    items: list[Item] = field(default_factory=list)

    @property
    def page(self) -> int:
        return min(i.page for i in self.items)

    def tokens(self) -> int:
        return ntok(render_block(self))


# --------------------------------------------------------------------------- load


def load_items(doc: dict) -> list[Item]:
    texts = {t["self_ref"]: t for t in doc.get("texts", [])}
    tables = {t["self_ref"]: t for t in doc.get("tables", [])}
    groups = {g["self_ref"]: g for g in doc.get("groups", [])}

    def prov_of(o):
        pr = o.get("prov") or []
        if not pr:
            return (999, 0.0, 0.0)
        b = pr[0]["bbox"]
        return (pr[0]["page_no"], -float(b["t"]), float(b["l"]))

    out: list[Item] = []

    def add(o, label, text):
        p, t, l = prov_of(o)
        out.append(Item(label=label, text=text or "", page=p, top=t, left=l, ref=o["self_ref"]))

    def walk(ref: str):
        if ref in texts:
            t = texts[ref]
            add(t, t["label"], t.get("text", ""))
        elif ref in tables:
            t = tables[ref]
            add(t, "table", table_to_markdown(t))
        elif ref in groups:
            for c in groups[ref].get("children", []):
                walk(c["$ref"])

    for child in doc["body"]["children"]:
        walk(child["$ref"])

    return out


def table_to_markdown(tbl: dict) -> str:
    data = tbl.get("data") or {}
    nrows, ncols = data.get("num_rows", 0), data.get("num_cols", 0)
    if not nrows or not ncols:
        return ""
    grid = [["" for _ in range(ncols)] for _ in range(nrows)]
    for c in data.get("table_cells", []):
        r0 = c.get("start_row_offset_idx", 0)
        c0 = c.get("start_col_offset_idx", 0)
        if 0 <= r0 < nrows and 0 <= c0 < ncols:
            grid[r0][c0] = norm(c.get("text", ""))
    lines = ["| " + " | ".join(grid[0]) + " |",
             "| " + " | ".join("---" for _ in range(ncols)) + " |"]
    for row in grid[1:]:
        lines.append("| " + " | ".join(row) + " |")
    return "\n".join(lines)


# -------------------------------------------------------------------------- patch


def apply_patch(items: list[Item], patch: dict) -> list[str]:
    """Apply content-addressed edits. Raises on any ambiguous or unmatched op."""
    log: list[str] = []
    table_seen = 0

    for op in patch.get("ops", []):
        kind = op["op"]

        if kind == "replace_table":
            want = op["match_table_index"]
            targets = [i for i in items if i.label == "table"]
            if want >= len(targets):
                raise SystemExit(f"patch: replace_table index {want} but only {len(targets)} tables")
            targets[want].text = op["markdown"]
            log.append(f"replace_table[{want}]: {op['why'][:70]}")
            table_seen += 1
            continue

        prefix = norm(op["match_prefix"])
        hits = [i for i in items if not i.dropped and norm(i.text).startswith(prefix)]
        if len(hits) != 1:
            raise SystemExit(
                f"patch: match_prefix {op['match_prefix']!r} matched {len(hits)} items "
                f"(expected exactly 1). Patch is stale — re-check against the docling export."
            )
        it = hits[0]
        exp = op.get("expect_label")
        if exp and it.label != exp:
            raise SystemExit(
                f"patch: {op['match_prefix']!r} has label {it.label!r}, patch expected {exp!r}"
            )

        if kind == "drop":
            it.dropped = True
            log.append(f"drop {it.ref}: {op['why'][:70]}")
        elif kind == "relabel":
            it.label = op["to_label"]
            if "set_text" in op:
                it.text = op["set_text"]
            log.append(f"relabel {it.ref} -> {op['to_label']}: {op['why'][:70]}")
        elif kind == "set_text":
            it.text = op["set_text"]
            log.append(f"set_text {it.ref}: {op['why'][:70]}")
        else:
            raise SystemExit(f"patch: unknown op {kind!r}")

    return log


# ------------------------------------------------------------------- segmentation


def derive_levels(items: list[Item], patch: dict) -> dict[str, int]:
    """docling emits every section_header at level=1. Numbered headings are top
    level; unnumbered ones nest under the most recent numbered heading."""
    rule = (patch.get("heading_hierarchy") or {}).get("numbered_pattern", r"^\d+\s")
    pat = re.compile(rule)
    levels = {}
    for it in items:
        if it.label == "section_header":
            levels[it.ref] = 1 if pat.match(norm(it.text)) else 2
    return levels


def build_blocks(items: list[Item]) -> list[Block]:
    """Group each formula with the text/list_item that introduces it."""
    blocks: list[Block] = []
    for it in items:
        if it.label == "formula" and blocks and blocks[-1].items[0].label in GLUE_HOSTS:
            blocks[-1].items.append(it)
        else:
            blocks.append(Block([it]))
    return blocks


def render_block(b: Block) -> str:
    parts = []
    for it in b.items:
        t = norm(it.text)
        if not t:
            continue
        if it.label == "formula":
            parts.append(f"$$\n{t}\n$$")
        elif it.label == "list_item":
            parts.append(f"- {t}")
        elif it.label == "table":
            parts.append(it.text)  # already markdown, keep newlines
        else:
            parts.append(t)
    return "\n\n".join(parts)


# ------------------------------------------------------------------------- chunks


def chunk_document(items: list[Item], levels: dict[str, int], doc_id: str, title: str):
    items = [i for i in items if not i.dropped and i.label not in FURNITURE]

    # Drop front matter (cover title, author line) — it is document metadata, not
    # retrievable content, and it embeds as a near-duplicate of every heading.
    first = next((n for n, i in enumerate(items)
                  if i.label == "section_header" and levels.get(i.ref) == 1), 0)
    items = items[first:]

    # split into heading-bounded units
    units = []  # (chain: list[str], blocks: list[Block])
    h1 = h2 = None
    pending: list[Item] = []

    def flush():
        if pending:
            chain = [x for x in (h1, h2) if x]
            units.append((chain, build_blocks(pending.copy())))
            pending.clear()

    for it in items:
        if it.label == "section_header":
            flush()
            if levels.get(it.ref, 1) == 1:
                h1, h2 = norm(it.text), None
            else:
                h2 = norm(it.text)
            continue
        pending.append(it)
    flush()

    # size pass: split oversized units at block boundaries, merge tiny siblings
    chunks = []

    def emit(chain, blocks):
        if not blocks:
            return
        body = "\n\n".join(render_block(b) for b in blocks).strip()
        if not body:
            return
        heading = " › ".join(chain) if chain else title
        text = f"## {heading}\n\n{body}"
        pages = sorted({b.page for b in blocks})
        chunks.append(
            {
                "id": f"{doc_id}-{len(chunks):03d}",
                "doc_id": doc_id,
                "doc_title": title,
                "heading_chain": chain,
                "page_start": pages[0],
                "page_end": pages[-1],
                "n_tokens": ntok(text),
                "n_chars": len(text),
                "item_refs": [i.ref for b in blocks for i in b.items],
                "text": text,
            }
        )

    for chain, blocks in units:
        total = sum(b.tokens() for b in blocks)
        if total <= MAX_TOKENS:
            emit(chain, blocks)
            continue

        # Oversized: split at glue-group boundaries so a formula is never
        # separated from the sentence that introduces it.
        cur, run = [], 0
        for b in blocks:
            bt = b.tokens()
            if cur and run + bt > TARGET_TOKENS:
                emit(chain, cur)
                cur, run = [], 0
            cur.append(b)
            run += bt
        emit(chain, cur)

    return chunks


# --------------------------------------------------------------------------- main


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--doc", required=True, type=Path)
    ap.add_argument("--patch", type=Path)
    ap.add_argument("--doc-id", required=True)
    ap.add_argument("--title", required=True)
    ap.add_argument("--out", required=True, type=Path)
    args = ap.parse_args()

    doc = json.loads(args.doc.read_text())
    patch = json.loads(args.patch.read_text()) if args.patch else {}

    items = load_items(doc)
    print(f"loaded {len(items)} items from {args.doc.name}")

    if patch:
        for line in apply_patch(items, patch):
            print(f"  patch  {line}")

    items.sort(key=lambda i: (i.page, i.top, i.left))
    print("re-sorted by provenance (page, -bbox.top, bbox.left)")

    levels = derive_levels(items, patch)
    chunks = chunk_document(items, levels, args.doc_id, args.title)

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(chunks, indent=2, ensure_ascii=False))

    toks = [c["n_tokens"] for c in chunks]
    print(f"\n{len(chunks)} chunks -> {args.out}")
    print(f"tokens: min={min(toks)} median={sorted(toks)[len(toks)//2]} max={max(toks)} total={sum(toks)}")
    for c in chunks:
        print(f"  {c['id']}  p{c['page_start']}-{c['page_end']}  {c['n_tokens']:4d}t  {' › '.join(c['heading_chain'])}")


if __name__ == "__main__":
    main()
