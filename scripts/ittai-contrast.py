#!/usr/bin/env python3
"""ittai-contrast.py — every Ittai text/background pairing checked against WCAG AAA (owner, 2026-10-19:
"the design master needs to ensure buttons etc are AAA standard").

Reads the palettes straight from the iPhone app's ItTokens.swift (light, dark, field) so the check can
never drift from the shipped colours. AAA: 7:1 for normal text, 4.5:1 for large text (18 pt+, or 14 pt+
bold) and for icons/UI parts we hold to 4.5:1 too (AAA itself asks 3:1 for non-text; we want more outdoors).

Usage:
  scripts/ittai-contrast.py [path/to/ItTokens.swift]      # the iPhone palette (default: ios/ beside this repo)
  scripts/ittai-contrast.py docs/design/ittai-v1.2/tokens.json   # the website's source (+ tenants.generated.json)
Exit code 1 when any pairing fails, so it can gate a build or a test.
"""
import re
import sys
from pathlib import Path

DEFAULT = [
    Path(__file__).resolve().parent.parent / 'ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/ItTokens.swift',
]

# (foreground, background, what it is, minimum ratio)
NORMAL, LARGE = 7.0, 4.5
PAIRS = [
    ('text', 'page', 'body text on the page', NORMAL),
    ('text', 'surface', 'body text on a card', NORMAL),
    ('text', 'sunk', 'body text on a sunk panel / bordered button', NORMAL),
    ('text', 'selected', 'text on a selected row or tab', NORMAL),
    ('text', 'tenantSoft', 'text on a soft-green chip', NORMAL),
    ('meta', 'page', 'secondary text on the page', NORMAL),
    ('meta', 'surface', 'secondary text on a card', NORMAL),
    ('meta', 'sunk', 'secondary text on a sunk panel', NORMAL),
    ('onTenant', 'tenant', 'label on a filled green button', NORMAL),
    ('tenant', 'surface', 'green link / text button on a card', NORMAL),
    ('tenant', 'page', 'green link / text button on the page', NORMAL),
    ('tenant', 'sunk', 'green label on a bordered button', NORMAL),
    ('statusAttention', 'surface', 'attention (amber) text on a card', NORMAL),
    ('statusBlocked', 'surface', 'blocked (red) text on a card', NORMAL),
    ('statusProgress', 'surface', 'in-progress (blue) text on a card', NORMAL),
    ('statusComplete', 'surface', 'complete (green) text on a card', NORMAL),
    ('focus', 'surface', 'focus ring on a card', LARGE),
    ('line', 'surface', 'hairline (decorative — reported, not gated)', None),
]


def palettes_json(tokens_path: Path) -> dict:
    """The website's source: docs/design/ittai-v1.2/tokens.json (+ tenants.generated.json for tenant-*)."""
    import json
    t = json.loads(tokens_path.read_text())
    out = {'light': {}, 'dark': {}, 'field': {}}
    raw = {}
    camel = lambda n: re.sub(r'-(\w)', lambda m: m.group(1).upper(), n)
    for c in (t.get('color') or {}).get('tokens', []):
        if isinstance(c, dict) and isinstance(c.get('value'), (dict, str)):
            vals = c['value'] if isinstance(c['value'], dict) else {m: c['value'] for m in out}   # one value for every mode
            for mode, v in vals.items():
                if mode in out and isinstance(v, str):
                    raw.setdefault(mode, {})[c['name']] = v
    gen = tokens_path.with_name('tenants.generated.json')
    tenant = {}
    if gen.exists():
        g0 = json.loads(gen.read_text())
        tenant = g0.get('mowology') or next(iter(g0.values()))
    for mode, vals in raw.items():
        merged = {**vals, **tenant.get(mode, {})}
        def res(v, depth=0):
            m = re.fullmatch(r'\{([\w-]+)\}', v.strip())
            return res(merged.get(m.group(1), ''), depth + 1) if m and depth < 5 else v
        for name, v in merged.items():
            v = res(v)
            if re.fullmatch(r'#[0-9A-Fa-f]{6}', v): out[mode][camel(name)] = int(v[1:], 16)
    if gen.exists():
        g = json.loads(gen.read_text())
        ten = g.get('mowology') or next(iter(g.values()))
        for mode in out:
            for k, v in ten.get(mode, {}).items():
                out[mode][camel(k)] = int(v[1:], 16)
    return out


def palettes(src: str) -> dict:
    out = {}
    for m in re.finditer(r'static let (\w+): \[ItColorToken: UInt32\] = \[(.*?)\]', src, re.S):
        out[m.group(1)] = {k: int(v, 16) for k, v in re.findall(r'\.(\w+):\s*0x([0-9A-Fa-f]{6})', m.group(2))}
    return out


def lum(rgb: int) -> float:
    def ch(c):
        c /= 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(rgb >> 16 & 255) + 0.7152 * ch(rgb >> 8 & 255) + 0.0722 * ch(rgb & 255)


def ratio(a: int, b: int) -> float:
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)


def main() -> int:
    path = Path(sys.argv[1]) if len(sys.argv) > 1 else next((p for p in DEFAULT if p.exists()), None)
    if not path or not path.exists():
        print('ItTokens.swift not found — pass its path', file=sys.stderr)
        return 2
    pals = palettes_json(path) if path.suffix == '.json' else palettes(path.read_text())
    fails = 0
    for mode, pal in pals.items():
        print(f'\n{mode}')
        for fg, bg, what, need in PAIRS:
            if fg not in pal or bg not in pal:
                if need is not None:
                    fails += 1
                    print(f'  XX  missing {fg} or {bg} (a pairing that cannot be checked counts as a failure)')
                continue
            r = ratio(pal[fg], pal[bg])
            ok = need is None or r >= need
            fails += 0 if ok else 1
            mark = '  ' if ok else 'XX'
            gate = f'≥ {need:g}' if need else 'info'
            print(f'  {mark} {r:5.2f}  {gate:6}  {fg} on {bg} — {what}')
    print(f'\n{fails} pairing(s) below AAA' if fails else '\nAll pairings meet AAA')
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
