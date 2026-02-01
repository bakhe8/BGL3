from pathlib import Path
import json, sys
sys.path.insert(0, str((Path('.bgl_core/brain')).resolve()))
from orchestrator import BGLOrchestrator
root=Path('.').resolve()
spec={
    'task':'rename_class',
    'target': {'path':'app/Services/AuthManagerService.php'},
    'params': {
        'old_name':'AuthManagerService',
        'new_name':'AuthManagerAgentService',
        'dry_run': False
    }
}
res=BGLOrchestrator(root).execute_task(spec)
print(json.dumps(res, indent=2))
