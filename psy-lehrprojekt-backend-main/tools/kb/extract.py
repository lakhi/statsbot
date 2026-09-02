#!/usr/bin/env python3
"""
extract.py — PDF -> docling JSON, with formula enrichment.

Offline/dev only. Never runs on the pod; adds no Composer dependency.

WHY THE FLAGS ARE WHAT THEY ARE (measured on resources/kb/hyptest.pdf, 4 pages):

  do_formula_enrichment=True is NOT optional for maths-heavy material.
  Default docling emits "<!-- formula-not-decoded -->" — 20 times on this
  document. The mathematics is not mangled, it is ABSENT. With enrichment,
  all 20 display equations come back as clean LaTeX, including Bayes' theorem
  and a double-summation permutation estimator that pdftotext destroyed.
  Default docling is strictly WORSE than pdftotext here. Never ship it.

  do_ocr=False because a LaTeX-produced PDF has a perfect text layer. The
  enrichment run took 756 s (~3 min/page) with RapidOCR initialising and
  running on CPU for no benefit. Leave OCR off unless a scanned source needs it.

The output JSON keeps page numbers and bounding boxes, which chunk.py needs to
repair docling's predicted reading order. Do not "simplify" this to a markdown
export — markdown discards provenance, and the order defect becomes unfixable.

Usage:
    python tools/kb/extract.py --pdf resources/kb/hyptest.pdf \
        --out resources/kb/hyptest.docling.json
"""

from __future__ import annotations

import argparse
import hashlib
import time
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--ocr", action="store_true", help="enable OCR (scanned sources only)")
    ap.add_argument("--no-formulas", action="store_true",
                    help="disable formula enrichment (produces an unusable maths corpus; "
                         "only for reproducing the negative baseline)")
    ap.add_argument("--markdown", type=Path, help="also write a markdown rendering for eyeballing")
    args = ap.parse_args()

    from docling.document_converter import DocumentConverter, PdfFormatOption
    from docling.datamodel.base_models import InputFormat
    from docling.datamodel.pipeline_options import PdfPipelineOptions

    po = PdfPipelineOptions()
    po.do_table_structure = True
    po.do_ocr = args.ocr
    po.do_formula_enrichment = not args.no_formulas

    conv = DocumentConverter(format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=po)})

    sha = hashlib.sha256(args.pdf.read_bytes()).hexdigest()
    print(f"converting {args.pdf.name}  sha256={sha}")
    print(f"  formula_enrichment={po.do_formula_enrichment}  ocr={po.do_ocr}")

    t0 = time.time()
    res = conv.convert(args.pdf)
    print(f"  converted in {time.time() - t0:.1f}s")

    doc = res.document
    args.out.parent.mkdir(parents=True, exist_ok=True)
    doc.save_as_json(args.out)
    print(f"  wrote {args.out}")

    if args.markdown:
        args.markdown.write_text(doc.export_to_markdown())
        print(f"  wrote {args.markdown}")

    undecoded = sum(1 for t in doc.texts if getattr(t, "text", "") == "")
    labels: dict[str, int] = {}
    for item, _ in doc.iterate_items():
        lbl = getattr(getattr(item, "label", None), "value", "?")
        labels[lbl] = labels.get(lbl, 0) + 1
    print(f"  items: {labels}")
    if undecoded:
        print(f"  WARNING: {undecoded} empty text items — check formula enrichment ran")


if __name__ == "__main__":
    main()
