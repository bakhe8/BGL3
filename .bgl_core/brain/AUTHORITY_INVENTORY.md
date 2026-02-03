# Authority Inventory (Execution/Approval Paths)

This document maps *where* the agent can create side effects today, and how those paths should be classified/gated going forward.

## Action Taxonomy (Proposed)

- `OBSERVE`: Read-only inspection. Internal logs/reports under `.bgl_core/` are allowed.
- `PROBE`: Safe runtime probing (HTTP GET, browser scans) with no state mutation.
- `PROPOSE`: Create recommendations/playbooks/policies, write proposals to DB/logs (no prod mutation).
- `WRITE_SANDBOX`: Mutate sandbox-only artifacts (e.g. sandbox DB) for validation.
- `WRITE_PROD`: Mutate the real product surface (code, prod DB/API writes).

## Current Side-Effect Paths

| Path | What It Does | Side Effects | Current Gate | Target Kind |
|---|---|---|---|---|
| `.bgl_core/actuators/patcher.php` | AST-based patching (`rename_class`, `rename_reference`, `add_method`) | Writes files via `file_put_contents`, may rename files | None inside PHP (caller-controlled) | `WRITE_PROD` |
| `.bgl_core/brain/patcher.py` | Calls `patcher.php`, backs up/rolls back, runs composer, reindexes | Writes project files, runs `composer dump-autoload`, writes `.bgl_core/logs`, updates `knowledge.db` | Local LLM decision + `execution_gate.check()` | `WRITE_PROD` |
| `.bgl_core/brain/apply_db_fixes.py` | Applies SQL scripts to SQLite DB | Mutates SQLite DB (`storage/database/app.sqlite` by default) | None | `WRITE_SANDBOX` (or `WRITE_PROD` if DB is not isolated) |
| `.bgl_core/brain/apply_proposal.py` | Logs an "apply proposal" action | Writes to `intents/decisions/outcomes` + `.bgl_core/logs` (actual patching is stubbed) | `--force` bypass exists but is simulated | `PROPOSE` (and `WRITE_PROD` for real future force mode) |
| `.bgl_core/brain/approve_playbook.py` | Approves proposed playbook and appends runtime rule | Moves files, writes `runtime_safety.yml` | None | `PROPOSE` (internal write) |
| `.bgl_core/brain/agent_tasks.py` | Simulated “agent task” demo | Writes to `agent_blockers` table | None | `PROPOSE` (internal DB write) |
| `.bgl_core/brain/agent_verify.py` | Runs static checks from `inference_patterns.json` | Read-only | None | `OBSERVE` |
| `.bgl_core/brain/guardian.py` | Requests approvals (`agent_permissions`), auto-promotes policies | Writes `.bgl_core/logs/*`, writes `policy_expectations.json`, writes DB rows | Custom `_has_permission/_request_permission` | `PROBE` / `PROPOSE` (internal), plus *gates* other writes |
| `.bgl_core/brain/agency_core.py` | `request_permission/is_permission_granted` | Writes to `agent_permissions` | Separate implementation from Guardian | Gate utility (no direct prod write) |
| `.bgl_core/brain/scenario_runner.py` | Runs scenarios (UI/API) | Can do HTTP writes when `danger:true` or `BGL_API_WRITE=1` | Inline gate in scenario runner | `PROBE` by default; `WRITE_PROD` when enabled |
| `.bgl_core/brain/decision_engine.py` | LLM-based risk decision + OpenAI failover | Network calls to local LLM and optional OpenAI | Not centrally enforced | Decision support |
| `.bgl_core/brain/execution_gate.py` | Simple allow/deny based on decision payload | None | Used by patcher | Gate utility |
| `.bgl_core/brain/brain_rules.py` | Deterministic block/override rules | None | Applied inside inference | Policy inputs (should feed Authority) |
| `.bgl_core/brain/brain_types.py` | Shared datatypes (Intent/Context/Mode/etc) | None | Imported widely | Types (will be extended for Authority) |

## Known Duplications (Drift Risks)

- Approval queue duplicated in `AgencyCore` vs `Guardian` (`agent_permissions` access paths).
- Execution gating duplicated across `execution_gate.py`, scenario runner, and patcher.
- Decision logging duplicated (`apply_proposal.py` hand-rolls DB inserts vs `decision_db.py` utilities).

## Next Refactor Goal

One module (`authority.py`) becomes the *only* place that:

- classifies a requested action into the taxonomy,
- decides if human approval is required,
- logs intent/decision/outcome,
- uses `agent_permissions` as the single approval queue (compatibility),
- blocks any `WRITE_*` unless explicitly approved.
