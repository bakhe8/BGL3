#!/usr/bin/env bash
# Learning Merge: Full Execution Script
# Run this to execute the entire migration with verification

set -e  # Exit on any error

echo "======================================================="
echo "Learning Merge: Full Migration Execution"
echo "======================================================="
echo ""

# Configuration
DB_PATH="storage/database/app.sqlite"
BACKUP_PATH="storage/database/app.sqlite.backup-$(date +%Y%m%d-%H%M%S)"
MIGRATION_SQL="database/migrations/2026_01_04_learning_merge_schema.sql"

# ============================================================
# STEP 1: Pre-Flight Checks
# ============================================================

echo "[1/8] Pre-Flight Checks..."

# Check database exists
if [ ! -f "$DB_PATH" ]; then
    echo "❌ ERROR: Database not found: $DB_PATH"
    exit 1
fi

# Check migration SQL exists
if [ ! -f "$MIGRATION_SQL" ]; then
    echo "❌ ERROR: Migration SQL not found: $MIGRATION_SQL"
    exit 1
fi

# Check baselines directory
if [ ! -d "baselines" ]; then
    mkdir -p baselines
    echo "Created baselines directory"
fi

echo "✅ Pre-flight checks passed"
echo ""

# ============================================================
# STEP 2: Backup Database
# ============================================================

echo "[2/8] Backing up database..."
cp "$DB_PATH" "$BACKUP_PATH"

# Verify backup
if [ ! -f "$BACKUP_PATH" ]; then
    echo "❌ ERROR: Backup failed"
    exit 1
fi

echo "✅ Backup created: $BACKUP_PATH"
echo ""

# ============================================================
# STEP 3: Capture Baselines
# ============================================================

echo "[3/8] Capturing baselines..."

echo "  - Capturing historical query baseline..."
php scripts/capture_historical_baseline.php
if [ $? -ne 0 ]; then
    echo "❌ Historical baseline capture failed"
    exit 1
fi

echo "  - Capturing E2E suggestion baseline..."
php scripts/create_e2e_baseline.php
if [ $? -ne 0 ]; then
    echo "❌ E2E baseline capture failed"
    exit 1
fi

echo "✅ Baselines captured"
echo ""

# ============================================================
# STEP 4: Run Schema Migration
# ============================================================

echo "[4/8] Running schema migration..."
sqlite3 "$DB_PATH" < "$MIGRATION_SQL"

if [ $? -ne 0 ]; then
    echo "❌ Schema migration failed"
    echo "Rolling back..."
    cp "$BACKUP_PATH" "$DB_PATH"
    exit 1
fi

echo "✅ Schema migration completed"
echo ""

# ============================================================
# STEP 5: Populate Normalized Columns
# ============================================================

echo "[5/8] Populating normalized columns..."
php scripts/populate_normalized_columns.php

if [ $? -ne 0 ]; then
    echo "❌ Population failed"
    echo "Rolling back..."
    cp "$BACKUP_PATH" "$DB_PATH"
    exit 1
fi

echo "✅ Columns populated"
echo ""

# ============================================================
# STEP 6: Verify Normalization
# ============================================================

echo "[6/8] Verifying normalization correctness..."
php scripts/verify_normalization.php

if [ $? -ne 0 ]; then
    echo "❌ Normalization verification failed"
    echo "Rolling back..."
    cp "$BACKUP_PATH" "$DB_PATH"
    exit 1
fi

echo "✅ Normalization verified"
echo ""

# ============================================================
# STEP 7: Compare Historical Queries
# ============================================================

echo "[7/8] Comparing historical query results..."
php scripts/compare_historical_queries.php

if [ $? -ne 0 ]; then
    echo "❌ Historical query comparison failed"
    echo "CRITICAL: Query results changed!"
    echo "Rolling back..."
    cp "$BACKUP_PATH" "$DB_PATH"
    exit 1
fi

echo "✅ Historical queries match baseline"
echo ""

# ============================================================
# NOTE: E2E comparison runs AFTER code updates
# ============================================================

echo "[8/8] Schema migration complete!"
echo ""
echo "======================================================="
echo "✅ Migration Successful"
echo "======================================================="
echo ""
echo "Backup: $BACKUP_PATH"
echo ""
echo "Next steps:"
echo "1. Update code to use normalized columns (see Data Refactor Plan)"
echo "2. Run: php scripts/compare_e2e_results.php"
echo "3. If E2E test passes → Migration complete!"
echo ""
echo "To rollback if needed:"
echo "  cp $BACKUP_PATH $DB_PATH"
echo ""
