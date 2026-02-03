# BGL3: Architecture & Autonomous Governance 🚀

BGL3 is a high-integrity PHP application ecosystem fortified with a **Specialized Autonomous Agent** for continuous observability and verified code execution.

## 🏛️ System Philosophy: The Executive Guardian

BGL3 transcends passive monitoring. It implements an **Executive Guardian** model where architectural integrity is enforced through deterministic analysis and high-trust execution.

### 🧬 The Deterministic Evolution

The BGL3 Agent Core marks a departure from heuristic, "best-guess" automation. It is built on three pillars of absolute certainty:

- **AST Perception**: By parsing Abstract Syntax Trees instead of raw strings, the agent possesses a "True Understanding" of the code's structural intent.
- **Relational Memory**: All project metadata is stored in a structured dependency graph, allowing the agent to reason about the downstream impacts of any modification.
- **Verification First**: No change is ever applied without passing a mandatory 3-tier validation chain in an isolated sandbox environment.

## 🛡️ The Trust Contract

At the heart of the system is a **Technical Constitution** that governs all autonomous behavior. This contract ensures that the agent operates safely, predictably, and within strict boundaries.

- **Isolated Sandboxing**: All write operations are performed in transient Git worktrees.
- **Atomic Execution**: Changes are either 100% verified and applied or 100% rolled back.
- **Hard-Fail Guardrails**: Non-negotiable execution limits that prevent scope creep and corruption.

## 📁 System Architecture

Detailed technical manuals are available for each layer of the system:

- **[System Specification & Trust Contract](file:///c:/Users/Bakheet/Documents/Projects/BGL3/.bgl_core/README.md)**: The definitive guide to the agent's logic, evolution rationale, and safety protocols.
- **Perception Layer (Sensors)**: Deep structural insight powered by `nikic/php-parser`.
- **Governance Layer (Audit)**: Continuous domain rule enforcement via `BGLGovernor`.

## 🛡️ Autonomous Governance & Monitoring

BGL3 maintains a continuous audit cycle through its **Command Center**. This interface allows for real-time monitoring of agent reasoning, deployment of autonomous rules, and security clearance management.

### 🎮 The Command Center (Dashboard)

To monitor the agent's "Explained AI" reasoning and approve strategic deployments, access:
`http://localhost:8000/agent-dashboard.php`

### 🧬 Logical Core

The agent's "Brain" is located in `.bgl_core/brain/`. Key operational files:

- **[CORE_OPERATIONS.md](file:///c:/Users/Bakheet/Documents/Projects/BGL3/.bgl_core/brain/CORE_OPERATIONS.md)**: The persistent technical manual for agent behavior.
- **[Production_Readiness.md](file:///c:/Users/Bakheet/.gemini/antigravity/brain/abfa5b10-b1ec-4349-8550-5f68aa189083/Production_Readiness.md)**: Full environmental requirements.
- **Governance quick map (ما يُنفَّذ فعلياً):**
  - Domain rules: `.bgl_core/brain/domain_rules.yml` (BLOCK/WARN مع rationale/severity؛ WARN لا تحجب).
  - Style rules: `.bgl_core/brain/style_rules.yml` (غير حاجبة).
  - Runtime safety: `.bgl_core/brain/runtime_safety.yml` (إذن كتابة وفحوص تشغيلية).
  - Playbook rename: `.bgl_core/brain/playbooks/rename_class.md` + ADR `docs/adr/ADR-rename-class-sandbox-autoload.md`.
  - Adaptive route scan (mode=auto افتراضياً) يوازن عدد المسارات مع موارد الجهاز/الزمن التاريخي؛ حارس زمن مفعّل.
  - BrowserCore موحّد (متصفح/صفحة واحدة، قفل وتسلسل، heartbeat وإعادة تشغيل تلقائية).
  - Reporting: `master_verify` يولّد HTML في `.bgl_core/logs/latest_report.html` يلخّص health/permissions/routes/experiences.

### 🚀 أوامر جاهزة للوكيل
- مراجعة بصرية سريعة: `.\run_ui.ps1`
- تشغيل قياس/CI: `.\run_ci.ps1` (يشمل metrics_summary + metrics_guard + check_mouse_layer)

---
*For a complete system evolution history, see the [`Final_Handover.md`](file:///c:/Users/Bakheet/.gemini/antigravity/brain/abfa5b10-b1ec-4349-8550-5f68aa189083/Final_Handover.md).*
# Test Change
