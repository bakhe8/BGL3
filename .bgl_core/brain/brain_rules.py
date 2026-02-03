import sys
import re
from pathlib import Path
from typing import List, Tuple

try:
    from .brain_types import Context, Rule, CognitiveState, OperationalMode  # type: ignore
except ImportError:
    sys.path.append(str(Path(__file__).parent))
    from brain_types import Context, Rule, CognitiveState, OperationalMode  # type: ignore


class RuleRegistry:
    """
    Hardcoded structural rules that override LLM reasoning.
    """

    @staticmethod
    def get_core_rules() -> List[Rule]:
        return [
            Rule(
                name="Force Arabic Language",
                condition="input_is_arabic",
                action_type="enforce_language",
                params={"lang": "ar"},
                priority=100,
            ),
            Rule(
                name="Audit Mode Determinism",
                condition="env_mode_is_audit",
                action_type="set_mode",
                params={"mode": OperationalMode.AUDIT},
                priority=90,
            ),
            Rule(
                name="Policy Violation: New Screen",
                condition="contains_forbidden_intent_screen",
                action_type="block_interaction",
                params={"reason": "UNAUTHORIZED_SCOPE_EXPANSION"},
                priority=200,  # Highest priority
            ),
            Rule(
                name="Deep Analysis for Evolution",
                condition="intent_is_evolve",
                action_type="set_mode",
                params={"mode": OperationalMode.ANALYSIS},
                priority=80,
            ),
            Rule(
                name="Privacy Protection: Credentials",
                condition="request_credentials",
                action_type="block_interaction",
                params={"reason": "SENSITIVE_DATA_ACCESS_DENIED"},
                priority=300,  # Absolute highest priority
            ),
        ]


class RuleEngine:
    def __init__(self):
        self.rules = RuleRegistry.get_core_rules()

    def evaluate(
        self, context: Context, state: CognitiveState
    ) -> Tuple[CognitiveState, List[str]]:
        """
        Applies rules to the current context and state.
        Returns: (Updated State, List[Action Instructions for LLM/System])
        """
        instructions = []

        # 1. Condition Checkers
        is_arabic = self._check_is_arabic(context.query_text)
        is_audit = context.env_state.get("mode") == "audit"

        # 2. Rule Execution
        for rule in self.rules:
            triggered = False

            if rule.condition == "input_is_arabic" and is_arabic:
                triggered = True
            elif rule.condition == "env_mode_is_audit" and is_audit:
                triggered = True
            elif rule.condition == "contains_forbidden_intent_screen":
                if (
                    "شاشة جديدة" in context.query_text
                    or "new screen" in context.query_text.lower()
                ):
                    triggered = True
            elif rule.condition == "intent_is_evolve":
                # Assuming intent is passed in context or inferred (for now check if intent enum matches)
                if context.intent.value == "evolve":
                    triggered = True
            elif rule.condition == "request_credentials":
                sensitive_keywords = [
                    "password",
                    "كلمة مرور",
                    "كلمة سر",
                    "credentials",
                    "بيانات اعتماد",
                ]
                if any(kw in context.query_text.lower() for kw in sensitive_keywords):
                    triggered = True

            if triggered:
                instructions.append(f"RULE_TRIGGERED: {rule.name}")
                self._apply_action(rule, state, instructions)

        return state, instructions

    def _check_is_arabic(self, text: str) -> bool:
        """Returns True if text is predominantly Arabic."""
        if not text:
            return False
        ar_chars = len(re.findall(r"[\u0600-\u06FF]", text))
        total_chars = len(text.strip())
        return (ar_chars / max(1, total_chars)) > 0.3

    def _apply_action(self, rule: Rule, state: CognitiveState, instructions: List[str]):
        """Executes the side-effect of a rule."""
        if rule.action_type == "enforce_language":
            state.language_lock = rule.params["lang"]
            instructions.append(f"MUST_RESPOND_IN_LANGUAGE: {rule.params['lang']}")

        elif rule.action_type == "set_mode":
            state.active_mode = rule.params["mode"]
            instructions.append(f"ACTIVE_MODE_SWITCH: {rule.params['mode']}")

        elif rule.action_type == "block_interaction":
            state.policy_compliant = False
            # This special instruction is caught by inference.py to abort
            instructions.append(f"BLOCK_IMMEDIATE: {rule.params['reason']}")
