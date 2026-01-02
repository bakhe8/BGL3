/**
 * Phase 5: Pilot Metrics Tracker
 * 
 * Simple logging system to track user decisions on Level B suggestions
 * NO LEARNING - METRICS ONLY
 * 
 * Scope: 20 guarantees only
 */

// Pilot metrics storage
window.pilotMetrics = {
    sessions: [],
    startTime: null
};

/**
 * Start tracking a Level B decision
 */
function startLevelBDecisionTracking(suggestionData) {
    window.pilotMetrics.startTime = Date.now();

    // Store session start
    console.log('[Pilot] Decision tracking started for:', suggestionData);
}

/**
 * Log Level B decision outcome
 */
function logLevelBDecision(action, suggestionData) {
    const decisionTime = window.pilotMetrics.startTime
        ? (Date.now() - window.pilotMetrics.startTime) / 1000
        : null;

    const logEntry = {
        timestamp: new Date().toISOString(),
        action: action, // 'confirm', 'reject', 'cancel'
        supplier_id: suggestionData.supplierId,
        supplier_name: suggestionData.supplierName,
        confidence: suggestionData.data?.confidence,
        matched_anchor: suggestionData.data?.matched_anchor,
        decision_time_seconds: decisionTime,
        guarantee_id: getCurrentGuaranteeId() // Helper function
    };

    // Store in session storage
    window.pilotMetrics.sessions.push(logEntry);

    // Send to server for persistent storage
    fetch('/api/pilot-metrics.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(logEntry)
    }).catch(err => console.error('[Pilot] Failed to log:', err));

    console.log('[Pilot] Decision logged:', logEntry);

    // Reset timer
    window.pilotMetrics.startTime = null;
}

/**
 * Get current guarantee ID from UI
 */
function getCurrentGuaranteeId() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('id') || null;
}

/**
 * Export metrics summary (for final report)
 */
function exportPilotMetrics() {
    const metrics = window.pilotMetrics.sessions;

    const summary = {
        total_decisions: metrics.length,
        confirmed: metrics.filter(m => m.action === 'confirm').length,
        rejected: metrics.filter(m => m.action === 'reject').length,
        cancelled: metrics.filter(m => m.action === 'cancel').length,
        avg_decision_time: metrics
            .filter(m => m.decision_time_seconds !== null)
            .reduce((sum, m) => sum + m.decision_time_seconds, 0) / metrics.length,
        sessions: metrics
    };

    console.log('[Pilot] Metrics Summary:', summary);
    return summary;
}

console.log('[Phase 5] Pilot Metrics Tracker loaded');
