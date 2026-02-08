-- unified decision schema (embedded in knowledge.db)
-- بسيط وقابل للتوسع، مع لقطة سياق ثابتة لكل intent.

CREATE TABLE IF NOT EXISTS intents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  timestamp TEXT NOT NULL,
  intent TEXT NOT NULL,
  confidence REAL NOT NULL,
  reason TEXT,
  scope TEXT,
  context_snapshot TEXT, -- JSON snapshot: health, active_route, recent_changes, guardian_top, browser_state
  source TEXT DEFAULT 'agent'
);

CREATE TABLE IF NOT EXISTS decisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  intent_id INTEGER NOT NULL REFERENCES intents(id),
  decision TEXT NOT NULL,          -- auto_fix | propose_fix | block | observe | defer
  risk_level TEXT NOT NULL,         -- low | medium | high
  requires_human INTEGER DEFAULT 0, -- 1=true
  justification TEXT,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS overrides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  decision_id INTEGER NOT NULL REFERENCES decisions(id),
  user TEXT,
  action TEXT,          -- approve | reject | defer
  reason TEXT,
  timestamp TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS outcomes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  decision_id INTEGER NOT NULL REFERENCES decisions(id),
  result TEXT,          -- e.g., success | partial | fail | skipped | prevented_regression | false_positive | confirmed_issue
  notes TEXT,
  backup_path TEXT,     -- Path to .bak file for rollback
  timestamp TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS agent_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id TEXT UNIQUE,
  mode TEXT,
  started_at REAL,
  ended_at REAL,
  duration_s REAL,
  runtime_events_count INTEGER,
  decisions_count INTEGER,
  outcomes_count INTEGER,
  attribution_class TEXT,
  attribution_conf REAL,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS proposal_outcome_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  proposal_id INTEGER,
  decision_id INTEGER,
  outcome_id INTEGER,
  created_at REAL,
  source TEXT
);

-- فهارس خفيفة
CREATE INDEX IF NOT EXISTS idx_intents_ts ON intents(timestamp);
CREATE INDEX IF NOT EXISTS idx_decisions_intent ON decisions(intent_id);
CREATE INDEX IF NOT EXISTS idx_overrides_decision ON overrides(decision_id);
CREATE INDEX IF NOT EXISTS idx_outcomes_decision ON outcomes(decision_id);
CREATE INDEX IF NOT EXISTS idx_prop_outcome ON proposal_outcome_links(proposal_id);

-- Knowledge DB: Exploration + Autonomy + Learning + Hypotheses

CREATE TABLE IF NOT EXISTS exploration_outcomes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  timestamp REAL NOT NULL,
  source TEXT,
  kind TEXT,
  value TEXT,
  route TEXT,
  payload_json TEXT,
  session TEXT
);

CREATE TABLE IF NOT EXISTS exploration_outcome_relations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at REAL NOT NULL,
  outcome_id_a INTEGER NOT NULL,
  outcome_id_b INTEGER NOT NULL,
  relation TEXT NOT NULL,
  score REAL NOT NULL,
  reason TEXT,
  FOREIGN KEY(outcome_id_a) REFERENCES exploration_outcomes(id),
  FOREIGN KEY(outcome_id_b) REFERENCES exploration_outcomes(id)
);

CREATE TABLE IF NOT EXISTS exploration_outcome_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  outcome_id INTEGER NOT NULL,
  created_at REAL NOT NULL,
  base_score REAL NOT NULL,
  relation_score REAL NOT NULL,
  total_score REAL NOT NULL,
  FOREIGN KEY(outcome_id) REFERENCES exploration_outcomes(id)
);

CREATE TABLE IF NOT EXISTS exploration_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  selector TEXT,
  href TEXT,
  tag TEXT,
  created_at REAL
);

CREATE TABLE IF NOT EXISTS exploration_novelty (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  selector TEXT,
  href_base TEXT,
  route TEXT,
  seen_count INTEGER DEFAULT 0,
  last_seen REAL,
  last_result TEXT,
  last_score_delta REAL
);

CREATE TABLE IF NOT EXISTS autonomy_goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goal TEXT,
  payload TEXT,
  source TEXT,
  created_at REAL,
  expires_at REAL
);

CREATE TABLE IF NOT EXISTS autonomy_goal_strategy (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goal TEXT,
  route_kind TEXT,
  strategy TEXT,
  success INTEGER,
  fail INTEGER,
  updated_at REAL
);

CREATE TABLE IF NOT EXISTS autonomous_plans (
  hash TEXT PRIMARY KEY,
  created_at REAL
);

CREATE TABLE IF NOT EXISTS hypotheses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fingerprint TEXT UNIQUE,
  created_at REAL NOT NULL,
  updated_at REAL NOT NULL,
  status TEXT NOT NULL,
  source TEXT,
  title TEXT,
  statement TEXT NOT NULL,
  confidence REAL,
  priority REAL,
  evidence_json TEXT,
  tags_json TEXT,
  related_intent TEXT,
  related_goal TEXT,
  last_outcome_at REAL
);

CREATE TABLE IF NOT EXISTS hypothesis_outcomes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  hypothesis_id INTEGER NOT NULL,
  outcome_table TEXT NOT NULL,
  outcome_id INTEGER NOT NULL,
  relation TEXT NOT NULL,
  score REAL,
  created_at REAL NOT NULL,
  notes TEXT,
  FOREIGN KEY (hypothesis_id) REFERENCES hypotheses(id)
);

CREATE TABLE IF NOT EXISTS learning_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fingerprint TEXT UNIQUE,
  created_at REAL NOT NULL,
  source TEXT,
  event_type TEXT,
  item_key TEXT,
  status TEXT,
  confidence REAL,
  detail_json TEXT
);

CREATE TABLE IF NOT EXISTS volitions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at REAL NOT NULL,
  run_id TEXT NOT NULL,
  source TEXT,
  volition TEXT NOT NULL,
  confidence REAL,
  payload_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_volitions_time ON volitions(created_at DESC);

-- Agent queues (single approval + visibility)
CREATE TABLE IF NOT EXISTS agent_permissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  operation TEXT NOT NULL,
  command TEXT,
  status TEXT DEFAULT 'PENDING',
  timestamp REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS agent_activity (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  timestamp REAL NOT NULL,
  type TEXT,
  message TEXT,
  status TEXT,
  activity TEXT,
  source TEXT,
  details TEXT
);

CREATE TABLE IF NOT EXISTS agent_blockers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  timestamp REAL NOT NULL,
  task_name TEXT,
  reason TEXT,
  complexity_level TEXT,
  status TEXT DEFAULT 'PENDING'
);

-- Knowledge curation (conflict resolution + weighting)
CREATE TABLE IF NOT EXISTS knowledge_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL,
  source_path TEXT NOT NULL,
  source_type TEXT,
  status TEXT,
  confidence REAL,
  mtime REAL,
  fingerprint TEXT UNIQUE,
  notes TEXT,
  created_at REAL,
  updated_at REAL
);

CREATE INDEX IF NOT EXISTS idx_knowledge_items_key ON knowledge_items(key, status);
CREATE INDEX IF NOT EXISTS idx_knowledge_items_path ON knowledge_items(source_path);

CREATE TABLE IF NOT EXISTS knowledge_conflicts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key TEXT NOT NULL,
  created_at REAL NOT NULL,
  winner_path TEXT,
  candidates_json TEXT,
  reason TEXT
);

CREATE INDEX IF NOT EXISTS idx_knowledge_conflicts_key ON knowledge_conflicts(key, created_at DESC);

CREATE TABLE IF NOT EXISTS learning_feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at REAL NOT NULL,
  source TEXT,
  signal TEXT,
  delta REAL,
  confidence REAL,
  details_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_learning_feedback_time ON learning_feedback(created_at DESC);

CREATE TABLE IF NOT EXISTS long_term_goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goal_key TEXT UNIQUE,
  title TEXT,
  goal TEXT,
  payload_json TEXT,
  source TEXT,
  status TEXT,
  priority REAL,
  created_at REAL,
  updated_at REAL,
  last_scheduled_at REAL,
  next_due_at REAL,
  success_count INTEGER DEFAULT 0,
  fail_count INTEGER DEFAULT 0,
  last_outcome TEXT,
  last_outcome_at REAL,
  notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_long_term_goals_status ON long_term_goals(status, priority DESC);
CREATE INDEX IF NOT EXISTS idx_long_term_goals_due ON long_term_goals(next_due_at, priority DESC);

CREATE TABLE IF NOT EXISTS long_term_goal_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goal_key TEXT,
  created_at REAL,
  event_type TEXT,
  delta REAL,
  confidence REAL,
  details_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_long_term_goal_events_time ON long_term_goal_events(created_at DESC);

CREATE TABLE IF NOT EXISTS canary_releases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  release_id TEXT UNIQUE,
  plan_id TEXT,
  source TEXT,
  status TEXT,
  created_at REAL,
  updated_at REAL,
  baseline_json TEXT,
  current_json TEXT,
  backup_dir TEXT,
  change_scope_json TEXT,
  notes TEXT
);

CREATE INDEX IF NOT EXISTS idx_canary_releases_status ON canary_releases(status, created_at DESC);

CREATE TABLE IF NOT EXISTS canary_release_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  release_id TEXT,
  created_at REAL,
  event_type TEXT,
  detail_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_canary_release_events_time ON canary_release_events(created_at DESC);

