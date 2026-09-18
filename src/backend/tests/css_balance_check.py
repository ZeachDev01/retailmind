#!/usr/bin/env python3
"""Fail when a CSS file has an unmatched structural brace."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CSS_ROOT = ROOT / "src" / "frontend" / "assets" / "css"
COMMENT_OR_STRING = re.compile(
    r"/\*.*?\*/|\"(?:\\.|[^\"\\])*\"|'(?:\\.|[^'\\])*'",
    re.DOTALL,
)

failures: list[str] = []
files = sorted(CSS_ROOT.rglob("*.css"))
for path in files:
    source = COMMENT_OR_STRING.sub("", path.read_text(encoding="utf-8"))
    depth = 0
    for line_number, line in enumerate(source.splitlines(), 1):
        for character in line:
            if character == "{":
                depth += 1
            elif character == "}":
                depth -= 1
                if depth < 0:
                    failures.append(f"{path.relative_to(ROOT)}:{line_number}: unmatched closing brace")
                    depth = 0
    if depth:
        failures.append(f"{path.relative_to(ROOT)}: {depth} unmatched opening brace(s)")

if failures:
    raise SystemExit("CSS balance failed:\n- " + "\n- ".join(failures))

print(f"CSS balance: passed ({len(files)} files)")
