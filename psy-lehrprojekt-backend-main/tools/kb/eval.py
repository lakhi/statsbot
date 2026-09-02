#!/usr/bin/env python3
"""
eval.py — score a built index against the gold set.

Offline/dev only.

Reports, per index:
  hit@1 / hit@3 / MRR over the positive questions, split EN vs DE, and a
  separation statistic for the negatives.

The separation statistic is the point of the negatives. A grounded tutor must say
"the material doesn't cover that" rather than answer from the model's own training.
The cheapest way to make it do that is a similarity floor: retrieve nothing when the
best chunk scores below a threshold. That only works if there is daylight between

    worst top-1 score among positives   (the floor must sit below this)
    best  top-1 score among negatives   (the floor must sit above this)

If those overlap, no threshold can separate them and the refusal has to be handled
in the system prompt instead. Either way the number, not a guess, decides it.

Usage:
    AZURE_API_KEY=$(az cognitiveservices account keys list \
        -n statistics-tutor -g Lehrprojekt --query key1 -o tsv) \
    python tools/kb/eval.py --gold resources/kb/gold-questions.json \
        --index-dir storage/app/kb --k 3
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import struct
import sys
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from embed import embed_batch, l2_normalise, truncate  # noqa: E402


def load_index(stem: Path):
    meta = json.loads(stem.with_suffix(".json").read_text())
    d, n = meta["dims"], meta["count"]
    blob = stem.with_suffix(".f32").read_bytes()
    expect = n * d * 4
    if len(blob) != expect:
        raise SystemExit(f"{stem.name}: expected {expect} bytes, found {len(blob)}")
    vecs = [list(struct.unpack_from(f"<{d}f", blob, i * d * 4)) for i in range(n)]
    return meta, vecs


def search(qvec, vecs, k):
    scores = [(sum(a * b for a, b in zip(qvec, v)), i) for i, v in enumerate(vecs)]
    scores.sort(reverse=True)
    return scores[:k]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--gold", required=True, type=Path)
    ap.add_argument("--index-dir", required=True, type=Path)
    ap.add_argument("--endpoint", default="https://statistics-tutor.openai.azure.com")
    ap.add_argument("--api-version", default="2024-10-21")
    ap.add_argument("--k", type=int, default=3)
    args = ap.parse_args()

    key = os.environ.get("AZURE_API_KEY")
    if not key:
        raise SystemExit("AZURE_API_KEY not set")

    gold = json.loads(args.gold.read_text())
    qs = gold["questions"]
    pos = [q for q in qs if q["expect"]]
    neg = [q for q in qs if not q["expect"]]

    stems = sorted({p.with_suffix("") for p in args.index_dir.glob("kb-*.f32")})
    if not stems:
        raise SystemExit(f"no kb-*.f32 indexes in {args.index_dir}")

    # One embedding call per DEPLOYMENT at full width; narrower dims are derived.
    by_deploy: dict[tuple[str, int], list] = {}
    for stem in stems:
        meta = json.loads(stem.with_suffix(".json").read_text())
        full = meta.get("derived_from_dims") or meta["dims"]
        by_deploy.setdefault((meta["deployment"], full), [])

    qvecs: dict[tuple[str, int], list] = {}
    for (deployment, full) in by_deploy:
        vecs, usage = embed_batch(args.endpoint, deployment, args.api_version,
                                  key, [q["q"] for q in qs], dims=full)
        qvecs[(deployment, full)] = vecs
        print(f"embedded {len(qs)} questions via {deployment} @ {full}d "
              f"({usage.get('total_tokens', 0)} tokens)")
    print()

    rows = []
    for stem in stems:
        meta, vecs = load_index(stem)
        ids = [c["id"] for c in meta["chunks"]]
        full = meta.get("derived_from_dims") or meta["dims"]
        d = meta["dims"]
        raw = qvecs[(meta["deployment"], full)]
        q_at_d = [l2_normalise(v) if d == full else truncate(v, d) for v in raw]

        h1 = h3 = 0
        rr = 0.0
        en_h3 = de_h3 = en_n = de_n = 0
        worst_pos_top = 1.0
        detail = []

        for q, qv in zip(qs, q_at_d):
            hits = search(qv, vecs, max(args.k, 1))
            top_ids = [ids[i] for _, i in hits]
            top_score = hits[0][0]
            if not q["expect"]:
                continue
            ok1 = top_ids[0] in q["expect"]
            ok3 = any(t in q["expect"] for t in top_ids[:args.k])
            h1 += ok1
            h3 += ok3
            rank = next((n for n, t in enumerate(top_ids, 1) if t in q["expect"]), 0)
            rr += 1.0 / rank if rank else 0.0
            worst_pos_top = min(worst_pos_top, top_score)
            if q["lang"] == "de":
                de_n += 1
                de_h3 += ok3
            else:
                en_n += 1
                en_h3 += ok3
            detail.append((q["id"], q["lang"], ok1, ok3, top_score, top_ids[0]))

        best_neg_top = 0.0
        for q, qv in zip(qs, q_at_d):
            if q["expect"]:
                continue
            best_neg_top = max(best_neg_top, search(qv, vecs, 1)[0][0])

        n = len(pos)
        rows.append({
            "index": stem.name, "model": meta["model"], "dims": d,
            "hit1": h1 / n, "hit3": h3 / n, "mrr": rr / n,
            "en_hit3": en_h3 / en_n if en_n else 0,
            "de_hit3": de_h3 / de_n if de_n else 0,
            "worst_pos": worst_pos_top, "best_neg": best_neg_top,
            "gap": worst_pos_top - best_neg_top,
            "detail": detail,
        })

    hdr = f"{'index':28s} {'hit@1':>6s} {'hit@3':>6s} {'MRR':>6s} {'EN@3':>6s} {'DE@3':>6s} {'worstP':>7s} {'bestN':>7s} {'gap':>7s}"
    print(hdr)
    print("-" * len(hdr))
    for r in sorted(rows, key=lambda r: (-r["hit3"], -r["mrr"])):
        flag = "" if r["gap"] > 0 else "   <- negatives overlap positives"
        print(f"{r['index']:28s} {r['hit1']:6.2f} {r['hit3']:6.2f} {r['mrr']:6.2f} "
              f"{r['en_hit3']:6.2f} {r['de_hit3']:6.2f} {r['worst_pos']:7.3f} "
              f"{r['best_neg']:7.3f} {r['gap']:+7.3f}{flag}")

    best = max(rows, key=lambda r: (r["hit3"], r["mrr"]))
    print(f"\nmisses in best index ({best['index']}):")
    any_miss = False
    for qid, lang, ok1, ok3, score, got in best["detail"]:
        if not ok3:
            any_miss = True
            q = next(x for x in qs if x["id"] == qid)
            print(f"  {qid} [{lang}] {q['q']!r}\n       got {got} @ {score:.3f}, wanted {q['expect']}")
    if not any_miss:
        print("  none — every positive question retrieved an acceptable chunk in the top 3")

    out = args.index_dir / "eval-results.json"
    out.write_text(json.dumps([{k: v for k, v in r.items() if k != "detail"} for r in rows],
                              indent=2))
    print(f"\nwrote {out}")


if __name__ == "__main__":
    main()
