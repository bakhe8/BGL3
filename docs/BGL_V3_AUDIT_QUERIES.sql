-- ========================================
-- BGL V3 - Safe Learning Audit Queries
-- ========================================
-- Purpose: Monitor and audit learned aliases for safety
-- Date: 2025-12-26

-- ========================================
-- VIEW 1: Risky Aliases (Single Use, Never Reviewed)
-- ========================================
CREATE VIEW IF NOT EXISTS risky_aliases_view AS
SELECT 
    a.id as alias_id,
    a.supplier_id,
    s.official_name as current_supplier,
    a.alternative_name as raw_name_from_excel,
    a.normalized_name,
    a.usage_count,
    a.source,
    a.created_at,
    a.last_used_at,
    julianday('now') - julianday(a.created_at) as days_old
FROM supplier_alternative_names a
JOIN suppliers s ON a.supplier_id = s.id
WHERE a.source = 'learning' 
  AND a.usage_count = 1
  AND julianday('now') - julianday(a.created_at) > 7
ORDER BY a.created_at DESC;

-- ========================================
-- VIEW 2: Active Learning Aliases
-- ========================================
CREATE VIEW IF NOT EXISTS active_learning_aliases AS
SELECT 
    a.id as alias_id,
    a.supplier_id,
    s.official_name,
    a.alternative_name,
    a.usage_count,
    a.created_at,
    a.last_used_at,
    julianday('now') - julianday(a.last_used_at) as days_since_last_use
FROM supplier_alternative_names a
JOIN suppliers s ON a.supplier_id = s.id
WHERE a.source = 'learning'
ORDER BY a.usage_count DESC, a.last_used_at DESC;

-- ========================================
-- VIEW 3: Potential Duplicates (Same normalized name -> different suppliers)
-- ========================================
CREATE VIEW IF NOT EXISTS duplicate_aliases AS
SELECT 
    a.normalized_name,
    COUNT(DISTINCT a.supplier_id) as conflicting_suppliers,
    GROUP_CONCAT(DISTINCT s.official_name) as supplier_names,
    GROUP_CONCAT(DISTINCT a.alternative_name) as raw_names
FROM supplier_alternative_names a
JOIN suppliers s ON a.supplier_id = s.id
WHERE a.source = 'learning'
GROUP BY a.normalized_name
HAVING COUNT(DISTINCT a.supplier_id) > 1;

-- ========================================
-- QUERY 1: Get risky aliases count
-- ========================================
-- Usage: Quick check for how many risky aliases exist
SELECT COUNT(*) as risky_count FROM risky_aliases_view;

-- ========================================
-- QUERY 2: Find aliases that may be mapping to wrong supplier
-- ========================================
-- Find cases where alias raw name closely matches ANOTHER supplier's official name
SELECT 
    a.id as alias_id,
    a.alternative_name as alias_raw_name,
    a.supplier_id as mapped_to_supplier_id,
    s1.official_name as mapped_to_name,
    s2.id as possible_correct_supplier_id,
    s2.official_name as possible_correct_name,
    a.usage_count
FROM supplier_alternative_names a
JOIN suppliers s1 ON a.supplier_id = s1.id
JOIN suppliers s2 ON LOWER(s2.official_name) LIKE '%' || LOWER(a.alternative_name) || '%'
WHERE a.source = 'learning'
  AND s2.id != s1.id
  AND LENGTH(a.alternative_name) > 5
ORDER BY a.usage_count DESC;

-- ========================================
-- QUERY 3: Session load monitoring
-- ========================================
-- See current session activity (last 30 minutes)
SELECT 
    COUNT(*) as decisions_last_30min,
    COUNT(CASE WHEN source = 'manual' THEN 1 END) as manual_decisions,
    COUNT(CASE WHEN source = 'auto' THEN 1 END) as auto_decisions
FROM supplier_decisions_log
WHERE decided_at >= datetime('now', '-30 minutes');

-- ========================================
-- QUERY 4: Learning effectiveness over time
-- ========================================
-- Track how many aliases are being created vs used
SELECT 
    DATE(created_at) as date,
    COUNT(*) as aliases_created,
    SUM(usage_count) as total_usage,
    AVG(usage_count) as avg_usage_per_alias,
    COUNT(CASE WHEN usage_count = 1 THEN 1 END) as single_use_aliases,
    COUNT(CASE WHEN usage_count > 5 THEN 1 END) as frequently_used_aliases
FROM supplier_alternative_names
WHERE source = 'learning'
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;

-- ========================================
-- QUERY 5: Find aliases that blocked auto-approval
-- ========================================
-- NOTE: This relies on error_log parsing
-- Run this grep command on your error log:
-- grep "\[SAFE_LEARNING\] Auto-approval blocked" /path/to/php_error.log | tail -20

-- ========================================
-- MAINTENANCE QUERY: Clean up old single-use aliases
-- ========================================
-- CAUTION: Only run this manually after review
-- DELETE FROM supplier_alternative_names
-- WHERE source = 'learning'
--   AND usage_count = 1
--   AND julianday('now') - julianday(created_at) > 30;

-- ========================================
-- MONITORING QUERY: Daily safety report
-- ========================================
SELECT 
    'Risky Aliases (usage=1, age>7days)' as metric,
    COUNT(*) as count
FROM risky_aliases_view

UNION ALL

SELECT 
    'Total Learning Aliases' as metric,
    COUNT(*) as count
FROM supplier_alternative_names
WHERE source = 'learning'

UNION ALL

SELECT 
    'Duplicate Normalized Names' as metric,
    COUNT(*) as count
FROM duplicate_aliases

UNION ALL

SELECT 
    'Decisions (last 30min)' as metric,
    COUNT(*) as count
FROM supplier_decisions_log
WHERE decided_at >= datetime('now', '-30 minutes');
