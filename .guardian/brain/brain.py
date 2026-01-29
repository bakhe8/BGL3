#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
BGL3 Semantic Intelligence Agent (The Brain)
- Phase 2.7: Intent Inference
- Deciphers developer motives using the Intent Matrix (intents.yml)
"""

import json
import time
import uuid
import yaml
import re
from typing import Dict, Any, List, Set, cast
from pathlib import Path
from datetime import datetime, timezone

# Configuration
CONFIG: Dict[str, Any] = {
    "SENSOR_LOG": "../agent/events.jsonl",
    "SEMANTIC_LOG": "semantic_events.jsonl",
    "ONTOLOGY_FILE": "ontology.yml",
    "INTENT_FILE": "intents.yml",
    "DECISION_FILE": "decisions.yml",
    "BURST_WINDOW_SEC": 5.0,
    "POLL_INTERVAL_SEC": 2.0,
}


class LogicalScanner:
    def __init__(self, project_root: Path) -> None:
        self.project_root = project_root
        self.logic_patterns = {
            "functional_anchor": re.compile(r"(?:function|def)\s+([a-zA-Z0-9_]+)"),
            "config_anchor": re.compile(r"(['\"])([A-Z0-9_]{5,})\1\s*[:=>]"),
            "critical_logic": re.compile(
                r"(threshold|score|weight|match|calculate|verify|validate|cache|auth|session|token|optimize)",
                re.I,
            ),
        }

    def scan_file(self, rel_path: str) -> Dict[str, Any]:
        abs_path = (self.project_root / rel_path).resolve()
        findings: Dict[str, Any] = {"anchors": [], "logic_density": 0, "raw_hits": []}

        if not abs_path.exists() or not abs_path.is_file():
            return findings

        try:
            if abs_path.suffix.lower() not in [".php", ".py", ".js", ".json"]:
                return findings

            with open(abs_path, "r", encoding="utf-8", errors="ignore") as f:
                content = f.read()

                # Find anchors
                functions = self.logic_patterns["functional_anchor"].findall(content)
                findings["anchors"].extend(functions[:5])

                configs = [
                    c[1] for c in self.logic_patterns["config_anchor"].findall(content)
                ]
                findings["anchors"].extend(list(set(configs))[:3])

                # Find raw logic keyword hits
                hits = self.logic_patterns["critical_logic"].findall(content)
                findings["raw_hits"] = [h.lower() for h in hits]
                findings["logic_density"] = min(10, len(hits) // 2)

        except Exception:
            pass

        return findings


class IntentResolver:
    def __init__(self, matrix_path: Path) -> None:
        self.matrix_path = matrix_path
        self.matrix_data: Dict[str, Any] = {
            "intents": [],
            "default_intent": "general_development",
            "confidence_weights": {},
        }
        self.load()

    def load(self) -> None:
        if self.matrix_path.exists():
            try:
                with open(self.matrix_path, "r", encoding="utf-8") as f:
                    loaded = yaml.safe_load(f)
                    if isinstance(loaded, dict):
                        self.matrix_data.update(loaded)
            except Exception as e:
                print(f"⚠️ Failed to load intent matrix: {e}")

    def resolve(
        self, roles: Set[str], logic_hits: List[str], behavior: str
    ) -> Dict[str, Any]:
        best_intent = str(self.matrix_data.get("default_intent", "general_development"))
        max_confidence = 0.0

        weights: Dict[str, float] = self.matrix_data.get(
            "confidence_weights",
            {"role_match": 0.4, "logic_hit": 0.4, "behavior_match": 0.2},
        )

        for intent in self.matrix_data.get("intents", []):
            if not isinstance(intent, dict):
                continue
            signals = intent.get("signals", {})
            confidence = 0.0

            # 1. Role Match
            if any(role in signals.get("roles", []) for role in roles):
                confidence += float(weights.get("role_match", 0.4))

            # 2. Logic Hit Match
            hit_list = signals.get("logic_hits", [])
            # Count variety of hits, not total hits
            matching_signals = [
                s for s in hit_list if any(s in hit for hit in logic_hits)
            ]
            hit_variety = len(set(matching_signals))

            if hit_variety > 0:
                base_w = float(weights.get("logic_hit", 0.4))
                # If we hit at least 2 distinct signal words, we get full logic weight
                hit_score = (min(2, hit_variety) / 2) * base_w
                confidence += hit_score

            # 3. Behavior Match
            if behavior in signals.get("behavior", []):
                confidence += float(weights.get("behavior_match", 0.2))

            if confidence > max_confidence:
                max_confidence = confidence
                best_intent = str(intent.get("id", "unknown"))

        return {
            "intent": best_intent,
            "confidence": round(max_confidence, 2),
            "is_reliable": max_confidence >= 0.5,
        }


class DecisionEngine:
    def __init__(self, matrix_path: Path) -> None:
        self.matrix_path = matrix_path
        self.matrix_data: Dict[str, Any] = {
            "decisions": [],
            "default_proposal": {
                "action": "LOG_ONLY",
                "priority": "INFO",
                "reason": "No specific rule matched.",
            },
        }
        self.load()

    def load(self) -> None:
        if self.matrix_path.exists():
            try:
                with open(self.matrix_path, "r", encoding="utf-8") as f:
                    loaded = yaml.safe_load(f)
                    if isinstance(loaded, dict):
                        self.matrix_data.update(loaded)
            except Exception as e:
                print(f"⚠️ Failed to load decision matrix: {e}")

    def decide(self, intent: str, confidence: float) -> Dict[str, Any]:
        best_proposal = self.matrix_data.get("default_proposal", {})

        for rule in self.matrix_data.get("decisions", []):
            if rule.get("intent") == intent:
                if confidence >= float(rule.get("min_confidence", 1.0)):
                    best_proposal = rule.get("proposal", best_proposal)
                    break

        return {
            "action": best_proposal.get("action"),
            "priority": best_proposal.get("priority"),
            "reason": best_proposal.get("reason"),
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }


class ProjectKnowledge:
    def __init__(self, ontology_path: Path) -> None:
        self.ontology_path = ontology_path
        self.dna: Dict[str, Any] = {
            "ontology": [],
            "default_sensitivity": 4,
            "file_type_weights": {},
        }
        self.load()

    def load(self) -> None:
        if self.ontology_path.exists():
            try:
                with open(self.ontology_path, "r", encoding="utf-8") as f:
                    loaded = yaml.safe_load(f)
                    if isinstance(loaded, dict):
                        self.dna.update(loaded)
                print(
                    f"DEBUG: Loaded Ontology from {self.ontology_path}: {len(self.dna.get('ontology', []))} rules",
                    flush=True,
                )
            except Exception as e:
                print(f"⚠️ Failed to load ontology: {e}", flush=True)

    def classify_path(self, rel_path: str) -> Dict[str, Any]:
        norm_path = rel_path.replace("\\", "/").lower()
        ext = Path(norm_path).suffix.lower()
        best_match: Any = None
        max_sensitivity = 0
        for item in self.dna.get("ontology", []):
            target = str(item["path"]).replace("\\", "/").lower()
            if norm_path == target or norm_path.startswith(target.rstrip("/") + "/"):
                if item["sensitivity"] >= max_sensitivity:
                    best_match = item
                    max_sensitivity = item["sensitivity"]

        if not best_match:
            print(
                f"DEBUG: No match for {norm_path} in {len(self.dna.get('ontology', []))} rules. First rule: {self.dna.get('ontology', [])[0] if self.dna.get('ontology') else 'NONE'}",
                flush=True,
            )

        base_sensitivity = (
            max_sensitivity if best_match else self.dna.get("default_sensitivity", 4)
        )
        weight = self.dna.get("file_type_weights", {}).get(ext, 1.0)
        final_score = round(base_sensitivity * weight, 1)
        return {
            "role": best_match["role"] if best_match else "UNKNOWN",
            "sensitivity": final_score,
        }


class SemanticBrain:
    base_dir: Path
    project_root: Path
    sensor_path: Path
    output_path: Path
    knowledge: ProjectKnowledge
    scanner: LogicalScanner
    resolver: IntentResolver
    advisor: DecisionEngine
    last_pos: int
    current_burst: List[Dict[str, Any]]
    last_event_ts: float

    def __init__(self) -> None:
        self.base_dir = Path(__file__).parent
        self.project_root = self.base_dir.parent.parent
        self.sensor_path = (self.base_dir / str(CONFIG["SENSOR_LOG"])).resolve()
        self.output_path = (self.base_dir / str(CONFIG["SEMANTIC_LOG"])).resolve()
        self.knowledge = ProjectKnowledge(
            self.base_dir / Path(str(CONFIG["ONTOLOGY_FILE"]))
        )
        self.scanner = LogicalScanner(self.project_root)
        self.resolver = IntentResolver(self.base_dir / Path(str(CONFIG["INTENT_FILE"])))
        self.advisor = DecisionEngine(
            self.base_dir / Path(str(CONFIG["DECISION_FILE"]))
        )
        self.last_pos = 0
        self.current_burst = []
        self.last_event_ts = 0.0

    def log_semantic(self, event_type: str, details: Dict[str, Any]) -> None:
        payload = {
            "id": str(uuid.uuid4()),
            "ts": datetime.now(timezone.utc).isoformat(),
            "type": event_type,
            "details": details,
        }
        with open(self.output_path, "a", encoding="utf-8") as f:
            f.write(json.dumps(payload, ensure_ascii=False) + "\n")

        icon = "🧠"
        if details.get("impact_score", 0) > 8:
            icon = "🚨"
        elif details.get("confidence", 0) > 0.6:
            icon = "🎯"

        intent_display = str(details.get("intent", "UNKNOWN")).upper()
        print(f"{icon} [SEMANTIC] {intent_display}: {details.get('summary', '')}")

    def process_line(self, line: str) -> None:
        try:
            raw = json.loads(line)

            # --- VALIDATION GATE ---
            validation = self.validator.validate_agent_event(raw)
            if not validation.is_valid:
                print(f"⚠️ Dropped Invalid Event: {validation.error}")
                return

            event = validation.sanitized_data
            # -----------------------

            # Check ignored types (internal logic not validation)
            if event["event"] not in ["created", "modified", "deleted"]:
                return

            # Ignore internal agent/brain paths
            path = event.get("path_rel", "").lower()
            if any(x in path for x in ["agent\\", "agent/", "brain\\", "brain/"]):
                return

            ts = datetime.fromisoformat(event["ts"].replace("Z", "+00:00")).timestamp()
            if not self.current_burst:
                self.current_burst = [event]
            elif (ts - self.last_event_ts) < float(
                cast(float, CONFIG["BURST_WINDOW_SEC"])
            ):
                self.current_burst.append(event)
            else:
                self.finalize_burst()
                self.current_burst = [event]
            self.last_event_ts = ts
        except json.JSONDecodeError:
            pass
        except Exception as e:
            print(f"Error processing line: {e}")

    def classify_behavior(
        self, count: int, duration: float, unique_files: Set[str]
    ) -> str:
        effective_duration = max(duration, 0.1)
        density = count / effective_duration
        if count == 1:
            return "single_tweak"
        if density > 10.0:
            return "bulk_action"
        if density > 2.0:
            return "active_refactoring" if len(unique_files) > 1 else "rapid_focus"
        return "manual_coding"

    def finalize_burst(self) -> None:
        if not self.current_burst:
            return

        # Reload configs to pick up any manual edits without restart
        self.knowledge.load()
        self.resolver.load()
        self.advisor.load()

        # Process Burst
        roles: Set[str] = set()
        anchors: List[str] = []
        logic_hits: List[str] = []
        paths: List[str] = []
        max_impact = 0.0
        count = len(self.current_burst)

        for event in self.current_burst:
            p = event.get("path_rel", "")
            paths.append(p)

            # Use Knowledge Engine
            classification = self.knowledge.classify_path(p)
            role = classification["role"]
            sens = classification["sensitivity"]

            if role != "UNKNOWN":
                roles.add(role)
            if sens > max_impact:
                max_impact = sens

            scan = self.scanner.scan_file(p)
            logic_hits.extend(scan["raw_hits"])
            anchors.extend(scan["anchors"])

        # Determine primary role for summary
        primary_role = "UNKNOWN"
        if roles:
            primary_role = sorted(list(roles))[0]

        first_ts = datetime.fromisoformat(
            self.current_burst[0]["ts"].replace("Z", "+00:00")
        ).timestamp()
        duration = max(0.0, round(self.last_event_ts - first_ts, 2))
        density = round(count / (duration if duration > 0.05 else 1), 2)
        behavior = self.classify_behavior(count, duration, paths)

        # If no roles matched, pass UNKNOWN to resolver
        effective_roles = roles if roles else {"UNKNOWN"}
        intent_info = self.resolver.resolve(effective_roles, logic_hits, behavior)

        # Get Advisory Proposal
        proposal = self.advisor.decide(
            str(intent_info["intent"]), float(intent_info["confidence"])
        )

        summary = f"Intent identified as '{intent_info['intent']}' (Confidence: {intent_info['confidence']}) affecting {primary_role}"
        if anchors:
            summary += f" | Anchors: {', '.join(list(set(anchors))[:3])}"

        self.log_semantic(
            "intent_resolved",
            {
                "intent": intent_info["intent"],
                "confidence": intent_info["confidence"],
                "is_reliable": intent_info["is_reliable"],
                "roles": list(effective_roles),
                "primary_role": primary_role,
                "impact_score": max_impact,
                "behavior": behavior,
                "anchors": list(set(anchors))[:5],
                "summary": summary,
                "density_ev_s": density,
                "advisory_proposal": proposal,
            },
        )
        self.current_burst = []

    def run(self) -> None:
        print(
            "🧠 Semantic Intelligence Agent (Phase 2.10: Sanity Layer Active) starting..."
        )
        if self.sensor_path.exists():
            self.last_pos = self.sensor_path.stat().st_size
        try:
            while True:
                if not self.sensor_path.exists():
                    time.sleep(float(cast(float, CONFIG["POLL_INTERVAL_SEC"])))
                    continue
                with open(self.sensor_path, "r", encoding="utf-8") as f:
                    f.seek(self.last_pos)
                    lines = f.readlines()
                    self.last_pos = f.tell()
                for line in lines:
                    if line.strip():
                        self.process_line(line)
                if self.current_burst and (time.time() - self.last_event_ts) > float(
                    cast(float, CONFIG["BURST_WINDOW_SEC"])
                ):
                    self.finalize_burst()
                time.sleep(float(cast(float, CONFIG["POLL_INTERVAL_SEC"])))
        except KeyboardInterrupt:
            self.finalize_burst()
            print("\n🧠 Brain shutting down.")


if __name__ == "__main__":
    brain = SemanticBrain()
    brain.run()
