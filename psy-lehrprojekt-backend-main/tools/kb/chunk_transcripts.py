#!/usr/bin/env python3
"""
chunk_transcripts.py — page transcripts of the Statistik corpus -> chunks.json for embed.py.

Offline/dev only. Reads everything from the BUILD folder (outside this public repo):

    manifest_docs.tsv    doc_id course type cite_doc doc_topic year pages excluded_pages
    manifest_pages.tsv   doc_id page include reason folie chapter subtitle
    transcripts/<doc>/pNNN.md   one transcription per page (image + text layer, see README)
    strip_patterns.txt   optional: regexes, one per line, for lines that only identify the
                         source (lecturer/tutor names, course footers). Kept in the build folder
                         so no personal names land in this repository.

One chunk per unit a student would recognise:
    lecture   one per slide        Statistik I · Vorlesung 14: t-Test · Folie 20
    exam      one per question     Statistik I · Prüfung SoSe 2023, 1. Termin · Frage 5
              (+ the shared scenario of the calculation part, "Erhebungssetting")
    exercise  one per Aufgabe      Statistik I · Übungsblatt 3 Lösungen (SoSe 2025): <Aufgabe title> · Aufgabe 2
              (long ones split into parts that share the label, so they dedupe to one chip)
    handout   one per page         Statistik I · Formelsammlung (SoSe 2024) · S. 4

What the MODEL sees is `[id]` + text (TutorPrompt::renderMaterials), in BOTH RAG arms, so:
  * ids are opaque hashes - "s1-exam-2023-1-f05" would tell it the passage is an exam;
  * text starts with a topic line ("Thema: …") and never names its source;
  * exam questions start with a neutral marker, because the exams carry no answer key and
    their distractors are false on purpose.

    python tools/kb/chunk_transcripts.py --build ../statsbot-rag-build
"""
from __future__ import annotations

import argparse
import collections
import csv
import hashlib
import json
import re
from pathlib import Path

EXAM_MARKER = "Multiple-choice item: the listed statements may be false."
MAX_TOKENS = 650      # longer Aufgaben are split into parts at paragraph boundaries
MIN_WORDS = 12        # title-only slides and "… …" carry nothing retrievable

GENERIC_STRIP = [
    r"^#*\s*VO-Prüfung\b.*Gruppe\s+[A-Z]\s*$",          # exam running header
    r"VO-Prüfung\s+\S+\s*\|\s*Seite\s+\d+",             # exam footer "VO-Prüfung 30/06/2023 | Seite 9 / 11"
    r"^#*\s*\d+\.\s*Termin\s+SoSe\s+\d{4},\s*Gruppe\s+[A-Z].*$",
    r"^\s*Seite\s+\d+\s*/\s*\d+\s*$",
    r"^\s*\d{1,3}\s*$",                                  # stray page/slide numbers
]
SOURCE_WORDS = ["Vorlesung", "Folie", "Prüfung", "Klausur", "Übungsblatt", "Aufgabenblatt",
                "Tutorium", "Moodle", "lecture", "slide"]

try:
    import tiktoken
    _enc = tiktoken.get_encoding("cl100k_base")   # text-embedding-3-* tokenizer

    def ntok(s: str) -> int:
        return len(_enc.encode(s))
except ImportError:  # pragma: no cover - rough fallback
    def ntok(s: str) -> int:
        return len(s) // 4


def opaque_id(doc: str, key: str) -> str:
    return "c" + hashlib.sha1(f"{doc}|{key}".encode()).hexdigest()[:7]


def read_tsv(p: Path) -> list[dict]:
    with p.open(newline="") as f:
        return list(csv.DictReader(f, delimiter="\t"))


class Cleaner:
    def __init__(self, extra: list[str]):
        self.res = [re.compile(r, re.I) for r in GENERIC_STRIP + extra]

    def __call__(self, text: str) -> str:
        out = []
        for line in text.splitlines():
            if any(r.search(line) for r in self.res):
                continue
            # hidden-by-animation content is real course content: keep it, drop the tag
            line = re.sub(r"\[ausgeblendet:\s*(.*?)\]", r"\1", line)
            out.append(line.rstrip())
        text = "\n".join(out)
        return re.sub(r"\n{3,}", "\n\n", text).strip()


def words(s: str) -> int:
    return len(re.findall(r"\w{2,}", re.sub(r"^#+.*$", "", s, flags=re.M)))


def strip_num(s: str) -> str:
    """'4.2 Einstichproben t-Test' -> 'Einstichproben t-Test'; 'Grundlagen 4' -> 'Grundlagen'."""
    s = re.sub(r"^\d+(?:\.\d+)*\.?\s+", "", s.strip())
    s = re.sub(r"\s*\(Forts\.\)\s*$", "", s)
    return re.sub(r"\s+\d{1,2}$", "", s).strip()


def label(course: str, doc: str, topic: str | None, pos: str) -> str:
    return f"{course} · {doc}" + (f": {topic}" if topic else "") + f" · {pos}"


def split_parts(text: str, limit: int = MAX_TOKENS) -> list[str]:
    """Split at paragraph boundaries; a paragraph that alone is too long (typically a big
    table or software output) is split by lines, and a single over-long line by tokens."""
    if ntok(text) <= limit:
        return [text]
    units = []   # (separator before this unit, text): lines of one paragraph rejoin with "\n"
    for para in re.split(r"\n\s*\n", text):
        if ntok(para) <= limit:
            units.append(("\n\n", para))
            continue
        for i, line in enumerate(para.splitlines()):
            while ntok(line) > limit:                 # pathological: one huge line
                cut = len(line) * limit // ntok(line)
                units.append(("\n" if i else "\n\n", line[:cut])); line = line[cut:]; i = 1
            units.append(("\n" if i else "\n\n", line))
    parts, cur = [], ""
    for sep, u in units:
        cand = f"{cur}{sep}{u}" if cur else u
        if cur and ntok(cand) > limit:
            parts.append(cur)
            cur = u
        else:
            cur = cand
    if cur:
        parts.append(cur)
    return parts


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", required=True, type=Path)
    ap.add_argument("--out", type=Path, help="default: <build>/chunks.json")
    args = ap.parse_args()
    B = args.build
    out = args.out or B / "chunks.json"

    extra = []
    if (B / "strip_patterns.txt").exists():
        extra = [l.strip() for l in (B / "strip_patterns.txt").read_text().splitlines()
                 if l.strip() and not l.startswith("#")]
    clean = Cleaner(extra)

    docs = {d["doc_id"]: d for d in read_tsv(B / "manifest_docs.tsv")}
    pages = collections.defaultdict(list)
    for r in read_tsv(B / "manifest_pages.tsv"):
        pages[r["doc_id"]].append(r)

    chunks, skipped, missing = [], collections.Counter(), []

    def transcript(doc: str, page: int) -> str | None:
        f = B / "transcripts" / doc / f"p{page:03d}.md"
        if not f.exists():
            missing.append(f"{doc} p{page}")
            return None
        return clean(f.read_text())

    def emit(doc: dict, key: str, text: str, topic_line: str | None, cite_topic: str | None, pos: str,
             p0: int, p1: int, heading: list[str]):
        """One chunk, or several parts sharing the label (and so one chip) when it is too long.
        Every part repeats the topic line, so each still says what it is about."""
        for k, part in enumerate(split_parts(text, MAX_TOKENS - (ntok(topic_line) + 2 if topic_line else 0))):
            emit_one(doc, f"{key}.{k}", part, topic_line, cite_topic, pos, p0, p1, heading)

    def emit_one(doc: dict, key: str, text: str, topic_line: str | None, cite_topic: str | None, pos: str,
                 p0: int, p1: int, heading: list[str]):
        body = f"{topic_line}\n\n{text}" if topic_line else text
        chunks.append({
            "id": opaque_id(doc["doc_id"], key),
            "doc_id": doc["doc_id"],
            "doc_title": doc["cite_doc"],
            "heading_chain": [h for h in heading if h],
            "page_start": p0, "page_end": p1,
            "n_tokens": ntok(body),
            "text": body,
            "citation": label(doc["course"], doc["cite_doc"], cite_topic, pos),
            "cite_course": doc["course"], "cite_doc": doc["cite_doc"],
            **({"cite_topic": cite_topic} if cite_topic else {}),
            "cite_pos": pos,
            "course": doc["course"], "doc_type": doc["type"], "year": doc.get("year") or "",
        })

    for doc_id, doc in docs.items():
        rows = [r for r in pages[doc_id] if r["include"] == "1"]
        typ = doc["type"]

        if typ == "lecture":
            for r in rows:
                p = int(r["page"])
                t = transcript(doc_id, p)
                if t is None:
                    continue
                if words(t) < MIN_WORDS:
                    skipped["slide too short"] += 1
                    continue
                chapter, sub = strip_num(r["chapter"]), strip_num(r["subtitle"])
                topic_line = "Thema: " + chapter + (f" › {sub}" if sub and sub != chapter else "") if chapter else None
                emit(doc, f"p{p}", t, topic_line, chapter or None, f"Folie {r['folie']}", p, p, [chapter, sub])

        elif typ == "handout":
            for r in rows:
                p = int(r["page"])
                t = transcript(doc_id, p)
                if t is None:
                    continue
                table_rows = [l for l in t.splitlines() if l.startswith("|")]
                digits = len(re.findall(r"\d", t)); letters = len(re.findall(r"[^\W\d_]", t))
                if len(table_rows) > 15 and digits > 2 * letters:
                    skipped["numeric lookup table"] += 1
                    continue
                if words(t) < MIN_WORDS:
                    skipped["page too short"] += 1
                    continue
                head = next((re.sub(r"^#+\s*", "", l) for l in t.splitlines() if l.startswith("#")), "")
                emit(doc, f"p{p}", t, f"Thema: {strip_num(head)}" if head else None, None, f"S. {p}", p, p, [head])

        elif typ == "exam":
            # one stream across pages, remembering where each line came from
            stream = []
            for r in rows:
                t = transcript(doc_id, int(r["page"]))
                if t:
                    stream += [(int(r["page"]), l) for l in t.splitlines()]
            q_start = re.compile(r"^\s*(?:\*\*)?(\d{1,2})\)\s*(?:\*\*)?\s*")
            blocks, cur, cur_q, intro = [], [], None, []
            for page, line in stream:
                m = q_start.match(line)
                if m:
                    if cur_q is not None:
                        blocks.append((cur_q, cur))
                    elif cur:
                        intro.append(cur)
                    cur_q, cur = int(m.group(1)), [(page, line)]
                elif re.match(r"^#+\s*(Rechenaufgaben|Verständnisaufgaben|JASP)", line) and cur_q is not None:
                    blocks.append((cur_q, cur))     # a section heading ends the previous question;
                    cur_q, cur = None, [(page, line)]   # what follows is that section's scenario
                else:
                    cur.append((page, line))
            if cur_q is not None:
                blocks.append((cur_q, cur))
            elif cur:
                intro.append(cur)
            for n, block in enumerate(intro):           # scenario text before a run of questions
                text = "\n".join(l for _, l in block).strip()
                if words(text) >= 40:
                    emit(doc, f"setting{n}", text, None, None, "Erhebungssetting",
                         block[0][0], block[-1][0], ["Erhebungssetting"])
            seen = collections.Counter()
            for q, block in blocks:
                seen[q] += 1
                text = "\n".join(l for _, l in block).strip()
                emit(doc, f"q{q}.{seen[q]}", text, EXAM_MARKER, None, f"Frage {q}",
                     block[0][0], block[-1][0], [f"Frage {q}"])

        elif typ == "exercise":
            stream = []
            for r in rows:
                t = transcript(doc_id, int(r["page"]))
                if t:
                    stream += [(int(r["page"]), l) for l in t.splitlines()]
            a_start = re.compile(r"^#*\s*\**\s*Aufgabe\s+(\d+)\b\**\s*[:.–-]?\s*(.*?)\**\s*$")
            blocks, cur, cur_a = [], [], None
            for page, line in stream:
                m = a_start.match(line)
                if m:
                    if cur_a is not None:
                        blocks.append((cur_a, cur))
                    else:
                        skipped["intro before Aufgabe 1 (organisation, data excerpt)"] += 1 if cur else 0
                    cur_a, cur = (int(m.group(1)), m.group(2).strip(" –-:*")), [(page, line)]
                else:
                    cur.append((page, line))
            if cur_a is not None:
                blocks.append((cur_a, cur))
            seen = collections.Counter()
            for (n, title), block in blocks:
                seen[n] += 1
                text = "\n".join(l for _, l in block).strip()
                topic = title or doc["doc_topic"] or None
                topic_line = "Thema: " + " › ".join(x for x in (doc["doc_topic"], title) if x) \
                    if (doc["doc_topic"] or title) else None
                emit(doc, f"a{n}.{seen[n]}", text, topic_line, topic, f"Aufgabe {n}",
                     block[0][0], block[-1][0], [doc["doc_topic"], title])

    ids = [c["id"] for c in chunks]
    dup = [i for i, c in collections.Counter(ids).items() if c > 1]
    if dup:
        raise SystemExit(f"id collision: {dup[:5]}")
    out.write_text(json.dumps(chunks, ensure_ascii=False, indent=1))

    by_type = collections.Counter(c["doc_type"] for c in chunks)
    toks = sorted(c["n_tokens"] for c in chunks)
    src = collections.Counter()
    for c in chunks:
        for w in SOURCE_WORDS:
            if re.search(rf"\b{w}", c["text"]):
                src[w] += 1
    print(f"{len(chunks)} chunks -> {out}")
    print("  by type:", dict(by_type))
    print(f"  tokens: median {toks[len(toks) // 2]}, p95 {toks[int(len(toks) * .95)]}, max {toks[-1]}, "
          f"total {sum(toks):,}")
    print("  skipped:", dict(skipped))
    print("  chunks whose text names a source type:", dict(src))
    if missing:
        print(f"  MISSING transcripts ({len(missing)}):", ", ".join(missing[:10]), "…" if len(missing) > 10 else "")


if __name__ == "__main__":
    main()
