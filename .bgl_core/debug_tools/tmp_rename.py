from pathlib import Path
import json, sys, os
sys.path.insert(0, str((Path('.bgl_core/brain')).resolve()))
from orchestrator import BGLOrchestrator
root=Path('.').resolve()
spec={
    'task':'rename_class',
    'target': {'path':'app/Services/AuthManager.php'},
    'params': {
        'old_name':'AuthManagerService',
        'new_name':'AuthManager',
        'dry_run': False
    }
}
res=BGLOrchestrator(root).execute_task(spec)
print(json.dumps(res, indent=2))
