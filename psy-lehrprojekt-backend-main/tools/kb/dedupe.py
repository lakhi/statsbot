#!/usr/bin/env python3
"""
dedupe.py — collapse near-duplicate chunks in a built index, keeping the best copy.

Offline/dev only. Two kinds of duplicate, both measured by cosine on the index's own vectors:

  * ACROSS documents: the same exercise in four years of solutions, the same slide in two
    terms. Keep the NEWEST year (ties: the longer text, i.e. the more complete solution).
  * WITHIN a document: consecutive slides of an animation build, where each page adds a
    line to the last. Keep the LATER, more complete page.

A pair counts as duplicate only if the vectors agree (cosine >= --threshold) AND the
text does (>= --contain of the shorter chunk found in the longer). Vectors alone merge
different chunks made mostly of number tables.

Why it matters: top-3 retrieval spends its slots on one passage three times, and the
salient arm would show three chips for the same exercise.

Rewrites the index in place (stem.f32 + stem.json, rows filtered, `dedupe` recorded in the
sidecar) and writes <stem>.dedupe.txt listing every group plus the near misses just below
the threshold, for checking by eye.

    python tools/kb/dedupe.py ../statsbot-rag-build/index/kb-corpus --threshold 0.97
"""
from __future__ import annotations

import argparse
import difflib
import json
from pathlib import Path

import numpy as np


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("stem", type=Path, help="index path without extension")
    ap.add_argument("--threshold", type=float, default=0.97)
    ap.add_argument("--near", type=float, default=0.94, help="report pairs from here up to the threshold")
    ap.add_argument("--contain", type=float, default=0.9,
                    help="share of the shorter chunk's text that must appear in the kept one")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    meta = json.loads(args.stem.with_suffix(".json").read_text())
    n, d = meta["count"], meta["dims"]
    X = np.frombuffer(args.stem.with_suffix(".f32").read_bytes(), dtype="<f4").reshape(n, d)
    chunks = meta["chunks"]
    if len(chunks) != n:
        raise SystemExit("sidecar and vectors disagree")

    S = X @ X.T
    np.fill_diagonal(S, -1)

    def rank(i: int) -> tuple:
        c = chunks[i]
        return (int(c.get("year") or 0), len(c["text"]), c.get("page_start") or 0)

    def body(i: int) -> str:
        # compare content, not the shared topic line / exam marker
        lines = [l for l in chunks[i]["text"].splitlines()
                 if not l.startswith(("Thema:", "Multiple-choice item:"))]
        return " ".join(" ".join(lines).split())

    def contained(i: int, j: int) -> float:
        """Share of the SHORTER text found in the longer one (identical copy ~1.0; an
        animation build's earlier slide inside the later one ~1.0; two different solution
        parts made of number tables ~0.05 even when their vectors are 0.97+ alike)."""
        a, b = body(i), body(j)
        if len(a) > len(b):
            a, b = b, a
        if not a:
            return 0.0
        sm = difflib.SequenceMatcher(None, a, b, autojunk=False)
        return sum(m.size for m in sm.get_matching_blocks()) / len(a)

    def may_merge(i: int, j: int) -> bool:
        # within one document only neighbouring pages (animation builds), never e.g. two
        # Aufgaben of one sheet that happen to read alike
        if chunks[i]["doc_id"] == chunks[j]["doc_id"] and \
                abs((chunks[i]["page_start"] or 0) - (chunks[j]["page_start"] or 0)) > 1:
            return False
        # vectors alone over-merge tables of numbers: the text must agree too
        return contained(i, j) >= args.contain

    # Greedy, best copy first, and NO chaining: a chunk is dropped only when it is itself
    # >= threshold to a copy already kept. Grouping transitively (union-find) would let two
    # different exam questions that share a stem fall into one group through a third that
    # resembles both - which is how "power 0.90" and "power 0.80" got merged in the first run.
    order = sorted(range(n), key=rank, reverse=True)
    kept: list[int] = []
    dup_of: dict[int, int] = {}
    for i in order:
        if kept:
            sims = S[i, kept]
            for j in np.argsort(-sims):
                if sims[j] < args.threshold:
                    break
                if may_merge(i, kept[j]):
                    dup_of[i] = kept[j]
                    break
            if i in dup_of:
                continue
        kept.append(i)

    drop = set(dup_of)
    groups: dict[int, list[int]] = {}
    for d_, k in dup_of.items():
        groups.setdefault(k, []).append(d_)
    report = [(k, sorted(v)) for k, v in groups.items()]

    near = []
    ii, jj = np.where(np.triu(S, 1) >= args.near)
    for i, j in zip(ii.tolist(), jj.tolist()):
        if i not in drop and j not in drop:
            near.append((float(S[i, j]), i, j))

    lines = [f"threshold {args.threshold}: {len(report)} groups, {len(drop)} of {n} chunks dropped\n"]
    for keep, gone in sorted(report, key=lambda r: chunks[r[0]]["citation"]):
        lines.append(f"KEEP {chunks[keep]['citation']}  [{chunks[keep]['id']}]")
        for g in gone:
            lines.append(f"  drop {S[keep, g]:.3f}  {chunks[g]['citation']}  [{chunks[g]['id']}]")
    lines.append(f"\nnear misses ({args.near}–{args.threshold}), kept both — check a few by eye:")
    for s, i, j in sorted(near, reverse=True)[:60]:
        lines.append(f"  {s:.3f}  {chunks[i]['citation']}  <>  {chunks[j]['citation']}")
    args.stem.with_suffix(".dedupe.txt").write_text("\n".join(lines) + "\n")
    print(lines[0].strip(), f"-> {args.stem.with_suffix('.dedupe.txt')}")

    if args.dry_run:
        return
    keep_idx = [i for i in range(n) if i not in drop]
    args.stem.with_suffix(".f32").write_bytes(X[keep_idx].astype("<f4").tobytes())
    meta["chunks"] = [chunks[i] for i in keep_idx]
    meta["count"] = len(keep_idx)
    meta["dedupe"] = {"threshold": args.threshold, "dropped": len(drop), "groups": len(report)}
    args.stem.with_suffix(".json").write_text(json.dumps(meta, indent=1, ensure_ascii=False))
    print(f"index rewritten: {len(keep_idx)} chunks")


if __name__ == "__main__":
    main()
