#!/usr/bin/env python3
"""Hybrid transcription of every in-scope page: page image + its text layer, one call.

Chosen 2026-11-27 after the probe (probe/report.html): docling fails on the slide decks,
the text layer garbles maths, the image alone misses hidden (animation) text.

    AZURE_API_KEY=... python tools/kb/transcribe.py --build ../statsbot-rag-build \
        --corpus ../statsbot-rag-corpus [--workers 4] [--only DOC_ID ...]

Reads <build>/inventory.tsv (doc_id, course, type, path relative to --corpus, pages).

Resumable: a page whose transcript exists is skipped. Writes
    layer/<doc>/pNNN.txt         pdftotext -layout of the page (kept for slide numbers, checks)
    transcripts/<doc>/pNNN.md    the transcription
    transcripts/_log.jsonl       one line per page: tokens, seconds, attempts
The key comes from the environment only and is never written anywhere.
"""
import argparse, base64, json, os, subprocess, sys, tempfile, threading, time, urllib.error, urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

HERE = CORPUS = Path(".")          # set from --build / --corpus in main()
ENDPOINT = "https://statistics-tutor.openai.azure.com"
URL = ""

PROMPT = """You are transcribing one page of German university statistics course material
(lecture slide, exam, exercise solution or formula sheet) for a search index.

Rules:
- Transcribe ALL content text faithfully, in its original language. Do not translate,
  summarise, correct or complete anything.
- Mathematics as LaTeX: inline $...$, display $$...$$. Keep the page's own notation, and
  copy it EXACTLY as printed, including asterisks, primes, hats, bars and indices
  (e.g. $s^{*2}$, $\\hat{y}$, $\\bar{x}_1$).
- Tables as Markdown tables.
- Headings as Markdown headings (#, ##) following the page's visual hierarchy.
- OMIT running headers/footers that only identify the source: lecturer names, course
  name, semester, dates, page or slide numbers, logos.
- For a chart, diagram or software screenshot, add ONE line
  `[Abbildung: ...]` in German saying what it shows, including any numbers or labels
  that are legible. Never invent values that are not visible.
- If something is illegible, write [unleserlich].
- Ignore software and website chrome around content: video players, view counts, likes,
  subscriber counts, buttons, menus - unless the page is explaining that software.

You also get the page's embedded TEXT LAYER. It has every word and number exactly, but
garbles mathematics (doubled letters, lost superscripts, broken fractions) and has no
text that sits inside images. Use the IMAGE for structure, mathematics and text inside
pictures; use the TEXT LAYER to get every word and number exactly right. The text layer
may also hold content that is NOT visible in the image (e.g. answers revealed by a click
animation): include it where it belongs, marked with [ausgeblendet: ...].
Output only the transcription."""

KEY = os.environ.get("AZURE_API_KEY") or sys.exit("AZURE_API_KEY not set")
log_lock = threading.Lock()


def render(pdf: Path, page: int) -> bytes:
    with tempfile.TemporaryDirectory() as d:
        subprocess.run(["pdftoppm", "-r", "110", "-png", "-f", str(page), "-l", str(page), "-singlefile",
                        str(pdf), f"{d}/p"], check=True)
        return Path(f"{d}/p.png").read_bytes()


def layer(pdf: Path, page: int) -> str:
    return subprocess.run(["pdftotext", "-layout", "-f", str(page), "-l", str(page), str(pdf), "-"],
                          capture_output=True, text=True, check=True).stdout


def call(png: bytes, text_layer: str) -> tuple[str, dict, int]:
    body = json.dumps({
        "messages": [
            {"role": "system", "content": PROMPT},
            {"role": "user", "content": [
                {"type": "image_url", "image_url": {"url": "data:image/png;base64," + base64.b64encode(png).decode(),
                                                    "detail": "high"}},
                {"type": "text", "text": "TEXT LAYER:\n" + text_layer},
            ]},
        ],
        "reasoning_effort": "low",
        "max_completion_tokens": 6000,
    }).encode()
    for attempt in range(1, 9):
        req = urllib.request.Request(URL, data=body, method="POST",
                                     headers={"Content-Type": "application/json", "api-key": KEY})
        try:
            with urllib.request.urlopen(req, timeout=240) as r:
                j = json.loads(r.read())
            choice = j["choices"][0]
            if choice.get("finish_reason") == "length":
                raise RuntimeError("transcription cut off at max_completion_tokens")
            return choice["message"]["content"], j.get("usage", {}), attempt
        except urllib.error.HTTPError as e:
            if e.code in (429, 500, 502, 503, 504):
                wait = float(e.headers.get("retry-after") or 0) or min(60, 4 * attempt)
                time.sleep(wait + 1)
                continue
            raise RuntimeError(f"HTTP {e.code}: {e.read().decode()[:300]}")
        except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as e:
            # URLError, timeouts and dropped connections (http.client.RemoteDisconnected)
            time.sleep(5 * attempt)
            last = e
    raise RuntimeError(f"gave up after retries: {last if 'last' in locals() else 'rate limited'}")


def work(doc: str, rel: str, page: int) -> str:
    out = HERE / "transcripts" / doc / f"p{page:03d}.md"
    if out.exists():
        return "skip"
    pdf = CORPUS / rel
    lay = layer(pdf, page)
    (HERE / "layer" / doc).mkdir(parents=True, exist_ok=True)
    (HERE / "layer" / doc / f"p{page:03d}.txt").write_text(lay)
    t = time.time()
    text, usage, attempts = call(render(pdf, page), lay)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(text)
    rec = {"doc": doc, "page": page, "seconds": round(time.time() - t, 1), "attempts": attempts,
           "in": usage.get("prompt_tokens"), "out": usage.get("completion_tokens")}
    with log_lock, open(HERE / "transcripts" / "_log.jsonl", "a") as f:
        f.write(json.dumps(rec) + "\n")
    return "done"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", required=True, type=Path)
    ap.add_argument("--corpus", required=True, type=Path)
    ap.add_argument("--deployment", default="statsbot-5.4-mini",
                    help="an EU (Data Zone / regional) vision-capable chat deployment")
    ap.add_argument("--workers", type=int, default=4)
    ap.add_argument("--only", nargs="*")
    args = ap.parse_args()
    global HERE, CORPUS, URL
    HERE, CORPUS = args.build.resolve(), args.corpus.resolve()
    URL = f"{ENDPOINT}/openai/deployments/{args.deployment}/chat/completions?api-version=2025-04-01-preview"
    jobs = []
    for line in (HERE / "inventory.tsv").read_text().splitlines():
        doc, _course, _type, rel, pages = line.split("\t")
        if args.only and doc not in args.only:
            continue
        jobs += [(doc, rel, p) for p in range(1, int(pages) + 1)]
    (HERE / "transcripts").mkdir(exist_ok=True)
    done = skipped = failed = 0
    start = time.time()
    with ThreadPoolExecutor(args.workers) as pool:
        futs = {pool.submit(work, *j): j for j in jobs}
        for i, f in enumerate(as_completed(futs), 1):
            try:
                r = f.result()
                done += r == "done"; skipped += r == "skip"
            except Exception as e:
                failed += 1
                print(f"FAILED {futs[f][0]} p{futs[f][2]}: {e}", flush=True)
            if i % 50 == 0 or i == len(jobs):
                print(f"{i}/{len(jobs)} pages | new {done} skipped {skipped} failed {failed} | "
                      f"{(time.time() - start) / 60:.1f} min", flush=True)
    print(f"FINISHED new={done} skipped={skipped} failed={failed}", flush=True)


if __name__ == "__main__":
    main()
