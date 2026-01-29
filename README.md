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

## 🛠️ Entry Point

To interact with the BGL3 Agent or trigger an architectural audit:

```bash
python .bgl_core/brain/orchestrator.py --task <spec.json>
```

---
*For a developer handover and technical implementation details, see [`.bgl_core/README.md`](file:///c:/Users/Bakheet/Documents/Projects/BGL3/.bgl_core/README.md).*
