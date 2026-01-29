from datetime import datetime
from typing import List, Optional, Any, Dict
from pydantic import BaseModel, Field, field_validator


class AgentEvent(BaseModel):
    """Raw event from the file system sensor."""

    id: str
    seq: int
    ts: datetime
    event: str = Field(..., pattern="^(created|modified|deleted)$")
    path_rel: str
    path_abs: str
    is_dir: bool


class AdvisoryProposal(BaseModel):
    """A proposed action from the Decision Engine."""

    action: str
    priority: str = Field(..., pattern="^(CRITICAL|HIGH|MEDIUM|LOW)$")
    reason: str


class SemanticEvent(BaseModel):
    """Enriched event with intent and context."""

    intent: str
    confidence: float = Field(..., ge=0.0, le=1.0)
    is_reliable: bool
    roles: List[str]
    primary_role: str
    impact_score: float
    behavior: str
    anchors: List[str]
    summary: str
    density_ev_s: float
    advisory_proposal: Optional[AdvisoryProposal] = None


class ValidationResult(BaseModel):
    """Outcome of a validation check."""

    is_valid: bool
    error: Optional[str] = None
    sanitized_data: Optional[Dict[str, Any]] = None
