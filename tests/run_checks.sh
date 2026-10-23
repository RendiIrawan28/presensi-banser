#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in "$ROOT/public/api.php" "$ROOT/public/material.php" "$ROOT/src/v7.php" "$ROOT/scripts/migrate.php" "$ROOT/tests/v7_smoke.php";do php -l "$f";done
node --check "$ROOT/public/v7.js"
python - "$ROOT/public/index.html" <<'PYCODE' | node --check
from pathlib import Path
import sys
s=Path(sys.argv[1]).read_text(); a=s.rfind("<script>"); b=s.find("</script>",a)
print(s[a+8:b])
PYCODE
python "$ROOT/tests/check_schema.py"
if php -m | grep -q -i pdo_sqlite;then php "$ROOT/tests/v7_smoke.php";else echo 'SKIP: PHP SQLite integration test (pdo_sqlite tidak terpasang di lingkungan ini)';fi
