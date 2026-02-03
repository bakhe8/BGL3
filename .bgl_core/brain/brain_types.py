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
