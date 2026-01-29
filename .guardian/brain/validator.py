from typing import Dict, Any

try:
    from schema import AgentEvent, SemanticEvent, AdvisoryProposal, ValidationResult
except ImportError:
    from .schema import AgentEvent, SemanticEvent, AdvisoryProposal, ValidationResult  # type: ignore


class BrainValidator:
    """The Gatekeeper: Ensures only valid data enters the logic engine."""

    def __init__(self, strict_mode: bool = True):
        self.strict_mode = strict_mode
        self.invalid_count = 0

    def validate_agent_event(self, raw_data: Dict[str, Any]) -> ValidationResult:
        try:
            # Pydantic will coerce valid types (e.g. str -> datetime)
            event = AgentEvent(**raw_data)
            return ValidationResult(is_valid=True, sanitized_data=event.model_dump())
        except Exception as e:
            self.invalid_count += 1
            return ValidationResult(is_valid=False, error=str(e))

    def validate_semantic_output(self, data: Dict[str, Any]) -> ValidationResult:
        try:
            # Check Advisory Proposal if present
            if "advisory_proposal" in data and data["advisory_proposal"]:
                try:
                    AdvisoryProposal(**data["advisory_proposal"])
                except Exception as ape:
                    # If proposal is invalid, strip it but keep event?
                    # For strict mode, we reject.
                    raise ValueError(f"Invalid proposal: {ape}")

            event = SemanticEvent(**data)
            return ValidationResult(is_valid=True, sanitized_data=event.model_dump())
        except Exception as e:
            return ValidationResult(is_valid=False, error=str(e))

    def get_stats(self) -> Dict[str, int]:
        return {"invalid_events_blocked": self.invalid_count}
