-- SQL Query to check snapshot_data in guarantee_history

-- Check latest 20 events
SELECT 
    id,
    guarantee_id,
    event_type,
    CASE 
        WHEN snapshot_data IS NULL THEN 'NULL'
        WHEN snapshot_data = '' THEN 'EMPTY'
        WHEN snapshot_data = '{}' THEN 'EMPTY JSON'
        ELSE 'HAS DATA'
    END as snapshot_status,
    LENGTH(snapshot_data) as length,
    LEFT(snapshot_data, 100) as data_preview,
    created_at
FROM guarantee_history
ORDER BY id DESC
LIMIT 20;

-- Count events by snapshot status
SELECT 
    CASE 
        WHEN snapshot_data IS NULL THEN 'NULL'
        WHEN snapshot_data = '' THEN 'EMPTY'  
        WHEN snapshot_data = '{}' THEN 'EMPTY JSON'
        ELSE 'HAS DATA'
    END as status,
    COUNT(*) as count
FROM guarantee_history
GROUP BY status;
