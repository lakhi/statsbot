#!/usr/bin/env python3
"""
seal.py — encrypt the built index for the PUBLIC release, and decrypt it again.

Offline/dev only. The pod decrypts with plain PHP (openssl_decrypt, aes-256-gcm),
embedded in scripts/rag-pilot-deploy.sh, so nothing here runs there.

Why: the index sidecar holds every chunk's text verbatim (the backend pastes it
into the prompt), and lakhi/statsbot is a public repository whose release assets
anyone can download. The lecturers' slides, exams and solutions must not be
readable there. So the index travels sealed:

    file format   b"SBKB1" | 12-byte random nonce | ciphertext | 16-byte GCM tag
    AAD           the plaintext file's NAME (e.g. b"kb-corpus.json"), so the two
                  files of one index cannot be swapped or renamed undetected
    key           32 random bytes, base64 - lives ONLY in the publisher's macOS
                  Keychain (service statsbot-corpus-key) and in the pilot .env on
                  the pod (CORPUS_KEY). Never in a file, never in git, never echoed.

Usage:
    seal.py keygen                         # once; refuses if a key already exists
    seal.py seal IN.f32 IN.json --out DIR  # writes DIR/IN.f32.enc, DIR/IN.json.enc
    seal.py open IN.enc... --out DIR       # local round-trip checks only

The key is read from $CORPUS_KEY if set, else from the Keychain.
"""

from __future__ import annotations

import argparse
import base64
import os
import secrets
import subprocess
import sys
from pathlib import Path

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

MAGIC = b"SBKB1"
NONCE = 12
SERVICE = "statsbot-corpus-key"
ACCOUNT = "statsbot"


def keychain_key() -> str | None:
    r = subprocess.run(["security", "find-generic-password", "-s", SERVICE, "-a", ACCOUNT, "-w"],
                       capture_output=True, text=True)
    if r.returncode != 0:
        return None
    return r.stdout.strip() or None


def load_key() -> bytes:
    b64 = os.environ.get("CORPUS_KEY") or keychain_key()
    if not b64:
        raise SystemExit(f"no key: set CORPUS_KEY or run `seal.py keygen` (Keychain service {SERVICE})")
    key = base64.b64decode(b64, validate=True)
    if len(key) != 32:
        raise SystemExit(f"key must be 32 bytes, got {len(key)}")
    return key


def seal_bytes(key: bytes, plaintext: bytes, name: str) -> bytes:
    nonce = secrets.token_bytes(NONCE)
    return MAGIC + nonce + AESGCM(key).encrypt(nonce, plaintext, name.encode())


def open_bytes(key: bytes, sealed: bytes, name: str) -> bytes:
    if not sealed.startswith(MAGIC) or len(sealed) < len(MAGIC) + NONCE + 16:
        raise SystemExit(f"{name}: not a sealed index file")
    nonce = sealed[len(MAGIC):len(MAGIC) + NONCE]
    return AESGCM(key).decrypt(nonce, sealed[len(MAGIC) + NONCE:], name.encode())


def cmd_keygen(_args) -> None:
    if keychain_key():
        raise SystemExit(f"a key already exists in the Keychain ({SERVICE}); refusing to replace it")
    b64 = base64.b64encode(secrets.token_bytes(32)).decode()
    # -w with the value: visible in the process list for the instant `security`
    # runs, to this user only. -U is NOT passed, so an existing item is never
    # overwritten.
    subprocess.run(["security", "add-generic-password", "-s", SERVICE, "-a", ACCOUNT, "-w", b64],
                   check=True)
    print(f"key stored in the Keychain as service '{SERVICE}'. Copy it for the pod with:")
    print(f"  security find-generic-password -s {SERVICE} -a {ACCOUNT} -w | pbcopy")


def cmd_seal(args) -> None:
    key = load_key()
    args.out.mkdir(parents=True, exist_ok=True)
    for src in args.files:
        sealed = seal_bytes(key, src.read_bytes(), src.name)
        dst = args.out / (src.name + ".enc")
        dst.write_bytes(sealed)
        print(f"sealed {src.name} -> {dst} ({len(sealed):,} B)")


def cmd_open(args) -> None:
    key = load_key()
    args.out.mkdir(parents=True, exist_ok=True)
    for src in args.files:
        if not src.name.endswith(".enc"):
            raise SystemExit(f"{src}: expected a .enc file")
        name = src.name[:-4]
        try:
            plain = open_bytes(key, src.read_bytes(), name)
        except Exception as e:  # InvalidTag carries no message
            raise SystemExit(f"{src.name}: refused ({type(e).__name__}) - wrong key, renamed or tampered")
        (args.out / name).write_bytes(plain)
        print(f"opened {src.name} -> {args.out / name}")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("keygen").set_defaults(fn=cmd_keygen)
    for name, fn in (("seal", cmd_seal), ("open", cmd_open)):
        p = sub.add_parser(name)
        p.add_argument("files", nargs="+", type=Path)
        p.add_argument("--out", required=True, type=Path)
        p.set_defaults(fn=fn)
    args = ap.parse_args()
    args.fn(args)


if __name__ == "__main__":
    sys.exit(main())
