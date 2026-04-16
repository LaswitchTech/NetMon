#!/usr/bin/env bash
# =============================================================================
# scripts/build-css.sh
# Compile LESS source files into public/assets/css/app.css.
#
# Usage:
#   bash scripts/build-css.sh           # compile once
#   bash scripts/build-css.sh --watch   # compile + watch for changes
#
# Prerequisites: Node.js and npm must be installed.
# The first run installs the 'less' package locally (node_modules/).
# node_modules/ is .gitignored and never committed.
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LESS_ENTRY="$PROJECT_ROOT/public/assets/less/app.less"
CSS_OUT="$PROJECT_ROOT/public/assets/css/app.css"
LESSC="$PROJECT_ROOT/node_modules/.bin/lessc"

# ── Sanity checks ─────────────────────────────────────────────────────────────
if ! command -v node &>/dev/null; then
    echo "ERROR: Node.js is not installed or not in PATH."
    echo "       Install Node.js from https://nodejs.org/ and re-run."
    exit 1
fi

if ! command -v npm &>/dev/null; then
    echo "ERROR: npm is not installed or not in PATH."
    exit 1
fi

# ── Install deps if needed ────────────────────────────────────────────────────
if [ ! -x "$LESSC" ]; then
    echo "lessc not found — running npm install..."
    npm --prefix "$PROJECT_ROOT" install --silent
fi

# ── Build or watch ────────────────────────────────────────────────────────────
if [ "${1}" = "--watch" ]; then
    echo "Watching for LESS changes (Ctrl+C to stop)..."
    echo "  Source : $LESS_ENTRY"
    echo "  Output : $CSS_OUT"
    echo ""
    "$LESSC" --watch "$LESS_ENTRY" "$CSS_OUT"
else
    echo "Compiling LESS..."
    echo "  Source : $LESS_ENTRY"
    echo "  Output : $CSS_OUT"

    "$LESSC" "$LESS_ENTRY" "$CSS_OUT"

    LINES=$(wc -l < "$CSS_OUT" | tr -d ' ')
    SIZE=$(wc -c < "$CSS_OUT" | tr -d ' ')
    echo "Done — ${LINES} lines, ${SIZE} bytes."
fi
