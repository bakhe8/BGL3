# خريطة الملفات مقابل المحاور (Spines)

هذه الخريطة ناتجة من مسح تلقائي لتفادي إعادة بناء ما هو موجود بالفعل.

## نطاق المسح
- .bgl_core/brain
- .bgl_core/knowledge
- agentfrontend
- app
- api
- docs
- tests
- templates
- views
- partials
- scripts
- public

**استثناءات:** استبعاد السيناريوهات المؤرشفة/الآلية واللوجات.

## Spine 1 — Attribution & Unified Logs
**ملفات أساسية محتملة**
- .bgl_core/brain/agency_core.py
- .bgl_core/brain/agent_tasks.py
- .bgl_core/brain/apply_db_fixes.py
- .bgl_core/brain/approve_playbook.py
- .bgl_core/brain/apply_proposal.py
- .bgl_core/brain/authority.py
- .bgl_core/brain/browser_sensor.py
- .bgl_core/brain/context_digest.py
- .bgl_core/brain/checks/authority_drift.py
- .bgl_core/brain/contract_seeder.py
- .bgl_core/brain/decision_db.py
- .bgl_core/brain/checks/self_regulation_runtime_link.py
- .bgl_core/brain/experience_replay.py
- .bgl_core/brain/guardian.py
- .bgl_core/brain/hypothesis.py
- .bgl_core/brain/intent_resolver.py
- .bgl_core/brain/llm_tools.py
- .bgl_core/brain/memory.py
- .bgl_core/brain/master_verify.py
- .bgl_core/brain/migrate_decision_to_knowledge.py

**ملفات داعمة/توثيقية**
- .bgl_core/brain/AUTHORITY_INVENTORY.md
- .bgl_core/brain/db_schema.json
- .bgl_core/brain/report_template.html
- .bgl_core/brain/playbooks/PAT_JS_BLOAT.md
- .bgl_core/brain/playbooks/production_guard.md
- .bgl_core/knowledge/code_map.md
- .bgl_core/knowledge/arch_state.json
- .bgl_core/knowledge/auto_insights/get-history-snapshot.php.insight.md
- .bgl_core/knowledge/auto_insights/GuaranteeHistoryRepository.php.insight.md
- agentfrontend/bootstrap.php
- agentfrontend/layout.php
- agentfrontend/app/copilot/dist/copilot-widget.js
- agentfrontend/partials/decision_impact.php
- agentfrontend/partials/extra_widget.php

## Spine 2 — Deterministic Decision & Domain Rules
**ملفات أساسية محتملة**
- .bgl_core/brain/approve_playbook.py
- .bgl_core/brain/agency_core.py
- .bgl_core/brain/apply_db_fixes.py
- .bgl_core/brain/authority.py
- .bgl_core/brain/agent_tasks.py
- .bgl_core/brain/apply_proposal.py
- .bgl_core/brain/autonomous_policy.py
- .bgl_core/brain/brain_rules.py
- .bgl_core/brain/brain_types.py
- .bgl_core/brain/checks/self_regulation_runtime_link.py
- .bgl_core/brain/checks/authority_drift.py
- .bgl_core/brain/checks/hypothesis_meta_separation.py
- .bgl_core/brain/execution_gate.py
- .bgl_core/brain/fingerprint.py
- .bgl_core/brain/decision_db.py
- .bgl_core/brain/config_loader.py
- .bgl_core/brain/decision_engine.py
- .bgl_core/brain/check_mouse_layer.py
- .bgl_core/brain/governor.py
- .bgl_core/brain/guardian.py

**ملفات داعمة/توثيقية**
- .bgl_core/brain/AUTHORITY_INVENTORY.md
- .bgl_core/brain/business_conflicts_probe.php
- .bgl_core/brain/CORE_OPERATIONS.md
- .bgl_core/brain/db_schema.json
- .bgl_core/brain/inference_patterns.json
- .bgl_core/brain/policy_expectations.json
- .bgl_core/brain/report_template.html
- .bgl_core/brain/write_scope.yml
- .bgl_core/brain/write_capabilities.json
- .bgl_core/brain/playbooks/rename_class.md
- .bgl_core/brain/style_rules.yml
- .bgl_core/brain/playbooks/production_guard.md
- .bgl_core/knowledge/arch_state.json
- .bgl_core/knowledge/code_map.md

## Spine 3 — UI Understanding & Interaction
**ملفات أساسية محتملة**
- .bgl_core/brain/agency_core.py
- .bgl_core/brain/brain_rules.py
- .bgl_core/brain/authority.py
- .bgl_core/brain/browser_sensor.py
- .bgl_core/brain/browser_core.py
- .bgl_core/brain/context_digest.py
- .bgl_core/brain/brain_types.py
- .bgl_core/brain/browser_manager.py
- .bgl_core/brain/decision_engine.py
- .bgl_core/brain/experience_replay.py
- .bgl_core/brain/fingerprint.py
- .bgl_core/brain/governor.py
- .bgl_core/brain/hypothesis.py
- .bgl_core/brain/checks/self_regulation_runtime_link.py
- .bgl_core/brain/intent_resolver.py
- .bgl_core/brain/guardian.py
- .bgl_core/brain/hand_profile.py
- .bgl_core/brain/inference.py
- .bgl_core/brain/master_verify.py
- .bgl_core/brain/llm_tools.py

**ملفات داعمة/توثيقية**
- .bgl_core/brain/AUTHORITY_INVENTORY.md
- .bgl_core/brain/CORE_OPERATIONS.md
- .bgl_core/brain/css_inventory.json
- .bgl_core/brain/domain_rules.yml
- analysis/metrics_summary.json
- .bgl_core/brain/patch_templates/js_split_placeholder.md
- .bgl_core/brain/runtime_safety.yml
- .bgl_core/brain/report_template.html
- .bgl_core/brain/style_rules.yml
- .bgl_core/brain/write_capabilities.json
- .bgl_core/brain/write_scope.yml
- .bgl_core/brain/playbooks/rename_class.md
- .bgl_core/knowledge/code_map.md
- .bgl_core/knowledge/business_rules.md

## Spine 4 — Learning & Intent Updates
**ملفات أساسية محتملة**
- .bgl_core/brain/apply_proposal.py
- .bgl_core/brain/agency_core.py
- .bgl_core/brain/brain_types.py
- .bgl_core/brain/commit_rule.py
- .bgl_core/brain/context_digest.py
- .bgl_core/brain/checks/hypothesis_meta_separation.py
- .bgl_core/brain/contract_tests.py
- .bgl_core/brain/experience_replay.py
- .bgl_core/brain/generate_patch_plan.py
- .bgl_core/brain/checks/self_regulation_runtime_link.py
- .bgl_core/brain/guardian.py
- .bgl_core/brain/hypothesis.py
- .bgl_core/brain/inference.py
- .bgl_core/brain/intent_resolver.py
- .bgl_core/brain/learning_core.py
- .bgl_core/brain/interpretation.py
- .bgl_core/brain/llm_tools.py
- .bgl_core/brain/memory.py
- .bgl_core/brain/master_verify.py
- .bgl_core/brain/outcome_signals.py

**ملفات داعمة/توثيقية**
- .bgl_core/brain/AUTHORITY_INVENTORY.md
- .bgl_core/brain/business_conflicts_probe.php
- .bgl_core/brain/db_schema.json
- .bgl_core/brain/inference_patterns.json
- .bgl_core/brain/policy_expectations.json
- .bgl_core/brain/report_template.html
- .bgl_core/knowledge/arch_state.json
- .bgl_core/knowledge/code_map.md
- .bgl_core/knowledge/auto_insights/AliasSignalFeeder.php.insight.md
- .bgl_core/knowledge/auto_insights/AnchorSignalFeeder.php.insight.md
- .bgl_core/knowledge/auto_insights/AuthorityFactory.php.insight.md
- .bgl_core/knowledge/auto_insights/ConfidenceCalculatorV2.php.insight.md
- .bgl_core/knowledge/auto_insights/CreateSupplierRequest.php.insight.md
- .bgl_core/knowledge/auto_insights/DataValidationTest.php.insight.md

## Spine 5 — Long-term Goals
**ملفات أساسية محتملة**
- .bgl_core/brain/apply_proposal.py
- .bgl_core/brain/agency_core.py
- .bgl_core/brain/authority.py
- .bgl_core/brain/generate_playbooks.py
- .bgl_core/brain/generate_patch_plan.py
- .bgl_core/brain/hypothesis.py
- .bgl_core/brain/inference.py
- .bgl_core/brain/orchestrator.py
- .bgl_core/brain/observations.py
- .bgl_core/brain/patch_plan.py
- .bgl_core/brain/plan_generator.py
- .bgl_core/brain/scenario_runner.py
- .bgl_core/brain/volition.py
- .bgl_core/brain/write_engine.py

**ملفات داعمة/توثيقية**
- .bgl_core/brain/AUTHORITY_INVENTORY.md
- .bgl_core/brain/patch_plan.schema.json
- .bgl_core/brain/write_capabilities.json
- .bgl_core/brain/write_scope.yml
- .bgl_core/knowledge/code_map.md
- .bgl_core/knowledge/arch_state.json
- .bgl_core/knowledge/auto_insights/metrics_summary.py.insight.md
- agentfrontend/layout.php
- agentfrontend/bootstrap.php
- agentfrontend/partials/autonomy_goals.php
- agentfrontend/partials/agent_autonomy_state.php
- agentfrontend/partials/operator_goals.php
- agentfrontend/partials/plan_library.php
- agentfrontend/partials/proposals_simple.php

