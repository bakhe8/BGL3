-- decision.db schema (مرحلة 0)
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
  timestamp TEXT NOT NULL
);

-- فهارس خفيفة
CREATE INDEX IF NOT EXISTS idx_intents_ts ON intents(timestamp);
CREATE INDEX IF NOT EXISTS idx_decisions_intent ON decisions(intent_id);
CREATE INDEX IF NOT EXISTS idx_overrides_decision ON overrides(decision_id);
CREATE INDEX IF NOT EXISTS idx_outcomes_decision ON outcomes(decision_id);
