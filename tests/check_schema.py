"""Validate exact embedded CREATE TABLE / INDEX SQL against SQLite."""
import re, sqlite3
from pathlib import Path
src=(Path(__file__).resolve().parents[1]/'src/v7.php').read_text()
block=src[src.index('function v7Migrate'):src.index('function v7Role')]
queries=re.findall(r"\$db->exec\('((?:\\'|[^'])*)'\);",block,re.S)
con=sqlite3.connect(':memory:')
count=0
for sql in queries:
    sql=sql.replace("\\'", "'")
    if sql.lstrip().upper().startswith(('CREATE TABLE','CREATE INDEX')):
        con.execute(sql);count+=1
assert count>=10, f'Expected migration DDL, found {count}'
assert con.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'v7_%'").fetchone()[0]>=8
print(f'SQLite V7 schema: PASS ({count} DDL statements)')
