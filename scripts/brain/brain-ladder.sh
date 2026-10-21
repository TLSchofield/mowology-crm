#!/usr/bin/env bash
# brain-ladder.sh — regenerate the brain's 500-stage shape ladder in public/crm/js/head-brain.js (2026-10-20).
#
#   scripts/brain/brain-ladder.sh            # rewrites the RECIPES line, then runs tests/js/brain-ladder-test.js
#
# Needs: ~/Projects/monkeymind (Shape Lab's generators.js), python3 with numpy + scipy, node, network once for three.js.
# The solids' coordinates (SOLIDS in head-brain.js) come from solids.py; paste them in if they ever change.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export WORK="${TMPDIR:-/tmp}/brain-ladder"; mkdir -p "$WORK"
GEN="$HOME/Projects/monkeymind/tools/shape-lab/generators.js"
[ -f "$GEN" ] || { echo "Shape Lab not found at $GEN" >&2; exit 1; }
sed 's#from .three.;#from "./three.module.js";#' "$GEN" > "$WORK/gen.mjs"
[ -f "$WORK/three.module.js" ] || curl -sSf -o "$WORK/three.module.js" https://cdn.jsdelivr.net/npm/three@0.162.0/build/three.module.js
python3 "$ROOT/scripts/brain/solids.py"
node "$ROOT/scripts/brain/pick.mjs"
node "$ROOT/scripts/brain/recipes.mjs"
python3 - "$ROOT/public/crm/js/head-brain.js" "$WORK/recipes.json" <<'PY'
import json, re, sys
p, r = sys.argv[1], sys.argv[2]
s = open(p).read(); rec = ' '.join(json.load(open(r)))
s2 = re.sub(r"var RECIPES = '[^']*'\.split\(' '\);", "var RECIPES = '" + rec + "'.split(' ');", s, count=1)
open(p, 'w').write(s2); print('RECIPES', 'unchanged' if s2 == s else 'updated')
PY
node "$ROOT/tests/js/brain-ladder-test.js"
