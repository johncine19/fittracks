import glob, re

for fpath in glob.glob('database/migrations/normalize_*.php'):
    print(f"\n=== {fpath} ===")
    with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
    tables = re.findall(r'CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*ENGINE', content, re.DOTALL | re.IGNORECASE)
    for tname, body in tables:
        cols = []
        pks = []
        fks = []
        for line in body.split('\n'):
            line = line.strip().rstrip(',')
            if not line:
                continue
            m = re.match(r'^`?([a-zA-Z0-9_]+)`?\s+([A-Za-z0-9_(),]+)', line)
            if m:
                first_word = m.group(1).upper()
                if first_word not in ['PRIMARY', 'KEY', 'UNIQUE', 'FOREIGN', 'INDEX', 'CONSTRAINT']:
                    cols.append(m.group(1))
            if 'PRIMARY KEY' in line.upper():
                pks.append(line)
            if 'FOREIGN KEY' in line.upper():
                fks.append(line)
        print(f"  Table: {tname}")
        print(f"    Columns: {', '.join(cols)}")
        if pks:
            print(f"    PK: {pks[0]}")
