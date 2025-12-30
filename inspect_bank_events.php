<?php
/**
 * فحص نهائي: حصر أحداث البنك في guarantee_history
 */

$db = new PDO('sqlite:storage/database/app.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== فحص نهائي: أحداث البنك في Timeline ===\n\n";

// 1. إجمالي السجلات
$stmt = $db->query("SELECT COUNT(*) FROM guarantee_history");
$totalHistory = $stmt->fetchColumn();
echo "📊 إجمالي سجلات guarantee_history: $totalHistory\n\n";

// 2. البحث عن أحداث البنك
$stmt = $db->query("
    SELECT COUNT(*) 
    FROM guarantee_history 
    WHERE event_details LIKE '%\"field\":\"bank\"%'
");
$bankEvents = $stmt->fetchColumn();
echo "🏦 أحداث البنك (field=bank): $bankEvents\n\n";

// 3. تحليل البيانات
echo "🔍 تحليل تفصيلي:\n";
$stmt = $db->query("
    SELECT id, guarantee_id, event_type, event_details, created_at
    FROM guarantee_history 
    WHERE event_details LIKE '%\"field\":\"bank\"%'
    ORDER BY created_at DESC
");

$manualCount = 0;
$autoCount = 0;
$unknownCount = 0;
$events = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $details = json_decode($row['event_details'], true);
    
    if ($details && isset($details['changes'])) {
        foreach ($details['changes'] as $change) {
            if (isset($change['field']) && $change['field'] === 'bank') {
                $trigger = $change['trigger'] ?? 'unknown';
                
                if ($trigger === 'manual') $manualCount++;
                elseif ($trigger === 'auto') $autoCount++;
                else $unknownCount++;
                
                $events[] = [
                    'id' => $row['id'],
                    'guarantee_id' => $row['guarantee_id'],
                    'trigger' => $trigger,
                    'old' => $change['old'] ?? 'N/A',
                    'new' => $change['new'] ?? 'N/A',
                    'created_at' => $row['created_at']
                ];
            }
        }
    }
}

echo "  - Manual (يدوي): $manualCount\n";
echo "  - Auto (تلقائي): $autoCount\n";
echo "  - Unknown (غير محدد): $unknownCount\n\n";

// 4. عرض عينة
echo "📋 عينة من الأحداث (آخر 10):\n";
foreach (array_slice($events, 0, 10) as $event) {
    echo sprintf(
        "  [ID:%d] GID:%d | %s → %s | trigger:%s | %s\n",
        $event['id'],
        $event['guarantee_id'],
        substr($event['old'], 0, 15),
        substr($event['new'], 0, 15),
        $event['trigger'],
        $event['created_at']
    );
}

echo "\n";
echo "=== ملخص التأثير ===\n";
echo "✅ سيتم تعديل: $manualCount + $unknownCount = " . ($manualCount + $unknownCount) . " حدث\n";
echo "✅ سيبقى كما هو: $autoCount حدث\n";
echo "✅ إجمالي أحداث البنك: " . count($events) . "\n\n";

echo "📌 ملاحظة: created_at لن يتغير - سيبقى التاريخ الأصلي\n";
