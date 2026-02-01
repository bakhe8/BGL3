from pathlib import Path
import json, sys, os
os.environ['BGL_HEADLESS']='1'
os.environ['BGL_BASE_URL']='http://localhost:8000'
sys.path.insert(0, str((Path('.bgl_core/brain')).resolve()))
from orchestrator import BGLOrchestrator
root=Path('.').resolve()
spec={
    'task':'add_method',
    'target':{'path':'app/Services/AuthManager.php'},
    'params':{
        'target_class':'AuthManagerService',
        'method_name':'debugPing',
        'content':'return "pong";',
        'dry_run':False
    }
}
res=BGLOrchestrator(root).execute_task(spec)
print(json.dumps(res, indent=2))
