import json
from pathlib import Path
js = json.loads(Path('.bgl_core/brain/db_schema.json').read_text())
for t in js['tables']:
    if t['name']=='guarantees':
        print('indexes:\n', json.dumps(t['indexes'], indent=2))
        print('columns:\n', json.dumps(t['columns'], indent=2))
