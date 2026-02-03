from enum import Enum
from dataclasses import dataclass, field
from typing import List, Dict, Any, Optional


class Intent(str, Enum):
    STABILIZE = "stabilize"
    EVOLVE = "evolve"
    UNBLOCK = "unblock"
    OBSERVE = "observe"


@dataclass
class HealthScore:
    stability_index: float  # 0.0 to 1.0 (1.0 = perfect stability)
    security_index: float  # 0.0 to 1.0 (1.0 = secure)
    compliance_index: float  # 0.0 to 1.0 (1.0 = compliant)
    global_score: float  # Weighted average
    details: Dict[str, Any] = field(default_factory=dict)


@dataclass
class Context:
    """Level 3: Context Schema ◼◼"""

    query_text: str
    intent: Intent = Intent.OBSERVE
    target_url: Optional[str] = None
    file_focus: Optional[str] = None
    messages: List[Dict[str, Any]] = field(default_factory=list)
    system_health: Optional[HealthScore] = None
    env_state: Dict[str, Any] = field(default_factory=dict)


class OperationalMode(str, Enum):
    AUDIT = "audit"  # Raw pass-through, no personality
    ANALYSIS = "analysis"  # Deep reasoning, chain-of-thought
    EXECUTION = "execution"  # Fast, action-oriented, concise


@dataclass
class CognitiveState:
    """Tracks the agent's internal reasoning state."""

    step_history: List[str] = field(default_factory=list)
    confidence: float = 0.5
    policy_compliant: bool = True
    active_mode: OperationalMode = OperationalMode.AUDIT  # Default to Audit for safety
    language_lock: str = "en"  # 'en' or 'ar'


@dataclass
class Rule:
    """A deterministic logic unit."""

    name: str
    condition: str  # Description of when it triggers
    action_type: str  # 'enforce_language', 'set_mode', 'inject_context'
    params: Dict[str, Any]
    priority: int = 10


class ActionKind(str, Enum):
    """
    Unified action taxonomy for authority/gating.

    NOTE: This is about *side effects* (what can mutate state), not about "intent".
    """

    OBSERVE = "observe"  # read-only inspection (internal logs/reports allowed)
    PROBE = "probe"  # safe runtime probing with no mutation (GET/browser scan)
    PROPOSE = "propose"  # write proposals/policies (no product mutation)
    WRITE_SANDBOX = "write_sandbox"  # mutate sandbox-only artifacts (e.g. sandbox DB)
    WRITE_PROD = "write_prod"  # mutate real product surface (code/prod DB/API writes)


@dataclass
class ActionRequest:
    """
    Request to perform an action that may have side effects.
    All write-capable modules should describe their operations through this schema.
    """

    kind: ActionKind
    operation: str  # stable key (e.g., "patch.rename_class", "db.apply_fixes")
    command: str = ""  # human-readable (and logged) description/command
    scope: List[str] = field(default_factory=list)  # files/uris/targets
    reason: str = ""  # why this action is needed
    confidence: float = 0.5
    metadata: Dict[str, Any] = field(default_factory=dict)


@dataclass
class GateResult:
    """
    Output of the Authority gate.
    - allowed: whether execution may proceed now
    - requires_human: whether the action is pending explicit approval
    """

    allowed: bool
    requires_human: bool = False
    message: str = ""
    # Linkage for auditability (best-effort; not always present)
    permission_id: Optional[int] = None
    intent_id: Optional[int] = None
    decision_id: Optional[int] = None
    # Decision metadata (optional)
    decision: str = ""
    risk_level: str = "low"
    justification: List[str] = field(default_factory=list)
