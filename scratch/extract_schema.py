import re
import os

with open('tidb_setup.sql', 'r', encoding='utf-8', errors='ignore') as f:
    sql = f.read()

pattern = r'CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*(?:ENGINE|;)'
tables = re.findall(pattern, sql, re.DOTALL | re.IGNORECASE)
print(f"Total tables: {len(tables)}")

output_dict = {}

for name, body in tables:
    cols = []
    pks = []
    fks = []
    for line in body.split('\n'):
        line = line.strip().rstrip(',')
        if not line:
            continue
        # Column line
        col_match = re.match(r'^`?([a-zA-Z0-9_]+)`?\s+([a-zA-Z0-9_()]+)(.*)', line)
        if col_match:
            cname = col_match.group(1)
            ctype = col_match.group(2)
            rest = col_match.group(3)
            is_pk = 'PRIMARY KEY' in rest.upper() or 'PRIMARY KEY' in line.upper()
            cols.append({
                'name': cname,
                'type': ctype,
                'pk': is_pk
            })
            if is_pk:
                pks.append(cname)
        elif line.upper().startswith('PRIMARY KEY'):
            # e.g. PRIMARY KEY (`id`)
            pk_cols = re.findall(r'`?([a-zA-Z0-9_]+)`?', line)
            pks.extend([c for c in pk_cols if c.upper() != 'PRIMARY' and c.upper() != 'KEY'])
        elif 'FOREIGN KEY' in line.upper():
            fks.append(line)

    output_dict[name] = {
        'cols': cols,
        'pks': pks,
        'fks': fks
    }

for t, data in output_dict.items():
    print(f"\nTABLE: {t} (PK: {', '.join(data['pks'])})")
    col_str = ", ".join([c['name'] + ('*' if c['name'] in data['pks'] else '') for c in data['cols']])
    print(f"  Cols: {col_str}")
