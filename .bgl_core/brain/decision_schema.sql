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

-- فهارس خفيفة
CREATE INDEX IF NOT EXISTS idx_intents_ts ON intents(timestamp);
CREATE INDEX IF NOT EXISTS idx_decisions_intent ON decisions(intent_id);
CREATE INDEX IF NOT EXISTS idx_overrides_decision ON overrides(decision_id);
CREATE INDEX IF NOT EXISTS idx_outcomes_decision ON outcomes(decision_id);

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
