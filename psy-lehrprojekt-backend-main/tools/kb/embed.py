#!/usr/bin/env python3
"""
embed.py — embed chunks via Azure OpenAI and write a packed float32 index.

Offline/dev only. Never runs on the pod.

Emits, for each requested dimension, a self-contained matched pair:

    kb-<doc>-<tag>-<dims>.f32    N x dims float32, row-major, L2-NORMALISED
    kb-<doc>-<tag>-<dims>.json   metadata + chunk text/metadata, same row order

Two design points that matter at runtime:

  * Vectors are L2-normalised at BUILD time, so the PHP retriever's cosine is a
    bare dot product — no per-chunk sqrt on the request path.

  * The sidecar records model, deployment, dims and the source PDF sha256.
    KbRetriever asserts these against config on load, so pointing
    AZURE_EMBED_DEPLOYMENT at a different model without rebuilding raises an
    exception instead of silently retrieving nonsense.

Matryoshka truncation: text-embedding-3-* vectors can be truncated and
re-normalised with graceful quality loss. We embed ONCE at full width and derive
the narrower indexes locally, so 3 dimensions cost 1 API call, not 3.

The API key is read from $AZURE_API_KEY and never written to disk. Pull it just
in time:

    AZURE_API_KEY=$(az cognitiveservices account keys list \
        -n statistics-tutor -g Lehrprojekt --query key1 -o tsv) \
    python tools/kb/embed.py --deployment statsbot-embed-3-small ...
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import struct
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

BATCH = 32  # inputs per request; use --batch 8 --tpm 40000 for a full corpus on a 50k TPM deployment


def embed_batch(endpoint, deployment, api_version, key, inputs, dims=None):
    url = f"{endpoint.rstrip('/')}/openai/deployments/{deployment}/embeddings?api-version={api_version}"
    payload = {"input": inputs}
    if dims:
        payload["dimensions"] = dims
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "api-key": key},
        method="POST",
    )
    # A full corpus is ~1.5M tokens against a 50k tokens/minute deployment, so being
    # throttled is the normal case: wait as long as Azure says (retry-after), not a fixed backoff.
    for attempt in range(20):
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                body = json.loads(r.read())
            rows = sorted(body["data"], key=lambda d: d["index"])
            return [r["embedding"] for r in rows], body.get("usage", {})
        except urllib.error.HTTPError as e:
            detail = e.read().decode()[:400]
            if e.code in (429, 500, 502, 503) and attempt < 19:
                wait = float(e.headers.get("retry-after") or 0) or min(60, 2 ** attempt)
                print(f"  HTTP {e.code}, retrying in {wait:.0f}s…", file=sys.stderr)
                time.sleep(wait + 1)
                continue
            raise SystemExit(f"Azure embeddings failed: HTTP {e.code}\n{detail}")
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            print(f"  network error ({e}), retrying…", file=sys.stderr)
            time.sleep(min(60, 5 * (attempt + 1)))
    raise SystemExit("Azure embeddings failed after retries")


def l2_normalise(v):
    n = math.sqrt(sum(x * x for x in v))
    if n == 0:
        return v
    return [x / n for x in v]


def truncate(v, d):
    """Matryoshka: keep the leading d components, then re-normalise."""
    return l2_normalise(v[:d])


# What KbRetriever reads per chunk. `citation` is the full chip label and the
# cite_* fields its parts (the UI shortens only the topic); see tools/kb/README.md.
SIDECAR_REQUIRED = ("id", "text")
SIDECAR_OPTIONAL = ("doc_id", "doc_title", "heading_chain", "page_start", "page_end", "n_tokens",
                    "citation", "cite_course", "cite_doc", "cite_topic", "cite_pos",
                    "course", "doc_type", "year", "source_file")


def sidecar_chunk(c):
    missing = [k for k in SIDECAR_REQUIRED if k not in c]
    if missing:
        raise SystemExit(f"chunk {c.get('id', '?')} lacks {missing}")
    out = {k: c[k] for k in SIDECAR_REQUIRED}
    out.update({k: c[k] for k in SIDECAR_OPTIONAL if k in c})
    out.setdefault("heading_chain", [])
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--chunks", required=True, type=Path)
    ap.add_argument("--endpoint", default="https://statistics-tutor.openai.azure.com")
    ap.add_argument("--deployment", required=True)
    ap.add_argument("--model", required=True)
    ap.add_argument("--tag", required=True, help="short label used in output filenames")
    ap.add_argument("--api-version", default="2024-10-21")
    ap.add_argument("--dims", type=int, nargs="+", required=True,
                    help="first value is the full width to request; the rest are derived locally")
    ap.add_argument("--source-pdf", type=Path,
                    help="single-document corpora: recorded as source_pdf_sha256")
    ap.add_argument("--source-manifest", type=Path,
                    help="multi-document corpora: the manifest the chunks were built from, recorded as source_sha256")
    ap.add_argument("--corpus-id", help="top-level doc_id (default: the first chunk's doc_id)")
    ap.add_argument("--stem", help="output name for the FIRST --dims value, e.g. kb-corpus "
                                   "(the stable name the pod expects); further dims get <stem>-<d>")
    ap.add_argument("--out-dir", required=True, type=Path)
    ap.add_argument("--batch", type=int, default=BATCH, help="inputs per request")
    ap.add_argument("--tpm", type=int, default=0,
                    help="pace to this many tokens/minute (e.g. 40000 for a 50k TPM deployment); 0 = no pacing")
    args = ap.parse_args()

    key = os.environ.get("AZURE_API_KEY")
    if not key:
        raise SystemExit("AZURE_API_KEY not set — see the module docstring for the az one-liner")

    chunks = json.loads(args.chunks.read_text())
    texts = [c["text"] for c in chunks]
    doc_id = args.corpus_id or (chunks[0]["doc_id"] if chunks else "kb")

    src_sha = ""
    if args.source_pdf and args.source_pdf.exists():
        src_sha = hashlib.sha256(args.source_pdf.read_bytes()).hexdigest()
    manifest_sha = ""
    if args.source_manifest and args.source_manifest.exists():
        manifest_sha = hashlib.sha256(args.source_manifest.read_bytes()).hexdigest()

    full = args.dims[0]
    print(f"embedding {len(texts)} chunks via {args.deployment} @ {full}d")

    # Pace to the deployment's tokens-per-minute budget: Azure enforces it over short
    # windows, so a big request against a small deployment is refused outright, and a
    # fast loop of small ones is throttled. Small batches + pacing keep it flowing.
    vectors, total_usage = [], 0
    started = time.time()
    for i in range(0, len(texts), args.batch):
        batch = texts[i:i + args.batch]
        vecs, usage = embed_batch(args.endpoint, args.deployment, args.api_version,
                                  key, batch, dims=full)
        vectors.extend(vecs)
        total_usage += usage.get("total_tokens", 0)
        print(f"  batch {i // args.batch + 1}: {len(batch)} inputs, {usage.get('total_tokens', 0)} tokens",
              flush=True)
        if args.tpm:
            ahead = total_usage / args.tpm * 60 - (time.time() - started)
            if ahead > 0:
                time.sleep(ahead)

    if len(vectors) != len(texts):
        raise SystemExit(f"expected {len(texts)} vectors, got {len(vectors)}")
    got = len(vectors[0])
    if got != full:
        print(f"  note: API returned {got}d (requested {full}d)")
        full = got

    args.out_dir.mkdir(parents=True, exist_ok=True)

    for d in args.dims:
        if d > full:
            print(f"  skipping {d}d — wider than the {full}d returned")
            continue
        rows = [l2_normalise(v) if d == full else truncate(v, d) for v in vectors]

        if args.stem:
            stem = args.stem if d == args.dims[0] else f"{args.stem}-{d}"
        else:
            stem = f"kb-{doc_id}-{args.tag}-{d}"
        blob = b"".join(struct.pack(f"<{d}f", *r) for r in rows)
        (args.out_dir / f"{stem}.f32").write_bytes(blob)

        meta = {
            "index_version": 1,
            "doc_id": doc_id,
            "built_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "model": args.model,
            "deployment": args.deployment,
            "api_version": args.api_version,
            "dims": d,
            "derived_from_dims": full if d != full else None,
            "count": len(rows),
            "normalised": True,
            "dtype": "float32",
            "byte_order": "little",
            "source_pdf_sha256": src_sha,
            "source_sha256": manifest_sha,
            "chunks": [sidecar_chunk(c) for c in chunks],
        }
        (args.out_dir / f"{stem}.json").write_text(json.dumps(meta, indent=2, ensure_ascii=False))
        print(f"  wrote {stem}.f32 ({len(blob):,} B) + {stem}.json")

    print(f"\ntotal embedding tokens billed: {total_usage}")


if __name__ == "__main__":
    main()
