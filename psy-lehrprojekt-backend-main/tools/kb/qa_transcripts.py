#!/usr/bin/env python3
"""Check every transcript against its page's text layer; flag the outliers.

The text layer has every word and number exactly (it garbles only maths), so a transcript
that lost many of them is suspect. Writes qa_report.tsv and prints the flagged pages.
Years and small integers are ignored (footers, slide numbers), and so are slides whose
text layer is nearly empty (image slides: nothing to compare against).

    python tools/kb/qa_transcripts.py ../statsbot-rag-build [--list]
"""
import csv, re, sys, unicodedata
from pathlib import Path

H = Path(sys.argv[1]).resolve()


def norm(s): return unicodedata.normalize("NFKC", s)
def words(s): return set(w.lower() for w in re.findall(r"[A-Za-zÄÖÜäöüß]{4,}", norm(s)))


def nums(s):
    s = norm(s)
    s = re.sub(r"(?<=\d),(?=\d)", ".", s)                    # 118,3658 -> 118.3658
    found = set(re.findall(r"(?<![\d.])\d+(?:\.\d+)?(?![\d.])", s))
    return {n for n in found if not (n.isdigit() and (int(n) <= 40 or 1990 <= int(n) <= 2030))}


rows = list(csv.DictReader(open(H / "manifest_pages.tsv"), delimiter="\t"))
out, flagged = [], []
for r in rows:
    if r["include"] != "1" or r.get("reason") == "docx via pandoc":
        continue
    doc, p = r["doc_id"], int(r["page"])
    t, l = H / "transcripts" / doc / f"p{p:03d}.md", H / "layer" / doc / f"p{p:03d}.txt"
    if not t.exists() or not l.exists():
        out.append((doc, p, "", "", "", "MISSING")); flagged.append((doc, p, "missing")); continue
    tx, lx = t.read_text(), l.read_text()
    rw, rn, tw, tn = words(lx), nums(lx), words(tx), nums(tx)
    wr = len(rw & tw) / len(rw) if len(rw) >= 30 else None
    nr = len(rn & tn) / len(rn) if len(rn) >= 8 else None
    why = []
    if wr is not None and wr < 0.75: why.append(f"words {wr:.2f}")
    if nr is not None and nr < 0.7: why.append(f"numbers {nr:.2f}")
    if "[unleserlich" in tx: why.append("illegible")
    out.append((doc, p, f"{wr:.2f}" if wr is not None else "", f"{nr:.2f}" if nr is not None else "",
                ",".join(sorted(rn - tn))[:120], "; ".join(why)))
    if why:
        flagged.append((doc, p, "; ".join(why)))

with open(H / "qa_report.tsv", "w") as f:
    f.write("doc_id\tpage\tword_recall\tnum_recall\tmissing_numbers\tflag\n")
    f.writelines("\t".join(map(str, o)) + "\n" for o in out)
checked = len(out)
print(f"{checked} pages checked, {len(flagged)} flagged")
for d, p, why in flagged[:80]:
    print(f"  {d} p{p}: {why}")
if "--list" in sys.argv:
    (H / "qa_flagged.txt").write_text("\n".join(f"{d}\t{p}" for d, p, _ in flagged) + "\n")
