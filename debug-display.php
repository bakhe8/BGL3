<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Debug - Extract Displayed Values</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #e74c3c; }
        .section { margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; }
        .field { margin: 10px 0; font-family: monospace; }
        .label { font-weight: bold; color: #555; }
        .value { color: #e74c3c; font-size: 16px; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug: ما الذي يُعرض فعلياً في الواجهة؟</h1>
        
        <div class="section">
            <h2>البيانات المتوقعة من قاعدة البيانات (ID=1):</h2>
            <div class="code">
                <?php
                require_once __DIR__ . '/app/Support/autoload.php';
                use App\Support\Database;
                use App\Repositories\GuaranteeRepository;
                use App\Repositories\GuaranteeDecisionRepository;
                
                $db = Database::connect();
                $guaranteeRepo = new GuaranteeRepository($db);
                $decisionRepo = new GuaranteeDecisionRepository($db);
                
                $allGuarantees = $guaranteeRepo->getAll([], 100, 0);
                $currentRecord = null;
                
                foreach ($allGuarantees as $g) {
                    if ($g->id === 1) {
                        $currentRecord = $g;
                        break;
                    }
                }
                
                if ($currentRecord) {
                    $raw = $currentRecord->rawData;
                    
                    $mockRecord = [
                        'id' => $currentRecord->id,
                        'session_id' => $raw['session_id'] ?? 0,
                        'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
                        'supplier_name' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
                        'bank_name' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
                        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount']) : 0,
                        'expiry_date' => $raw['expiry_date'] ?? date('Y-m-d'),
                        'issue_date' => $raw['issue_date'] ?? date('Y-m-d'),
                        'contract_number' => htmlspecialchars($raw['contract_number'] ?? '', ENT_QUOTES),
                        'type' => htmlspecialchars($raw['type'] ?? 'ابتدائي', ENT_QUOTES),
                        'status' => 'pending'
                    ];
                    
                    // Load timeline
                    $mockTimeline = [];
                    $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([$currentRecord->id]);
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($history as $event) {
                        $mockTimeline[] = [
                            'id' => $event['id'],
                            'type' => $event['action'],
                            'date' => $event['created_at'],
                            'description' => $event['change_reason'] ?? '',
                            'user' => $event['created_by'] ?? 'النظام'
                        ];
                    }
                    
                    echo "<pre>";
                    foreach ($mockRecord as $key => $value) {
                        printf("%-20s : %s\n", $key, $value);
                    }
                    echo "</pre>";
                }
                ?>
            </div>
        </div>
        
        <div class="section" x-data="testData()">
            <h2>البيانات الفعلية المعروضة في Alpine.js:</h2>
            
            <div class="field">
                <span class="label">id:</span>
                <span class="value" x-text="record.id"></span>
            </div>
            
            <div class="field">
                <span class="label">guarantee_number:</span>
                <span class="value" x-text="record.guarantee_number"></span>
            </div>
            
            <div class="field">
                <span class="label">supplier_name:</span>
                <span class="value" x-text="record.supplier_name"></span>
            </div>
            
            <div class="field">
                <span class="label">bank_name:</span>
                <span class="value" x-text="record.bank_name"></span>
            </div>
            
            <div class="field">
                <span class="label">amount:</span>
                <span class="value" x-text="record.amount"></span>
            </div>
            
            <div class="field">
                <span class="label">expiry_date:</span>
                <span class="value" x-text="record.expiry_date"></span>
            </div>
            
            <div class="field">
                <span class="label">issue_date:</span>
                <span class="value" x-text="record.issue_date"></span>
            </div>
            
            <div class="field">
                <span class="label">contract_number:</span>
                <span class="value" x-text="record.contract_number"></span>
            </div>
            
            <div class="field">
                <span class="label">type:</span>
                <span class="value" x-text="record.type"></span>
            </div>
            
            <h3 style="margin-top: 20px;">Timeline Events:</h3>
            <div class="code">
                <div><strong>Count:</strong> <span x-text="timeline.length"></span></div>
                <template x-for="(event, index) in timeline" :key="event.id">
                    <div x-text="`${index + 1}. ${event.type} - ${event.date}`"></div>
                </template>
            </div>
            
            <h3 style="margin-top: 20px;">Raw JSON:</h3>
            <div class="code">
                <pre x-text="JSON.stringify({record, timeline}, null, 2)"></pre>
            </div>
        </div>
    </div>

    <script>
        function testData() {
            return {
                record: {
                    id: <?= $mockRecord['id'] ?? 0 ?>,
                    guarantee_number: '<?= $mockRecord['guarantee_number'] ?>',
                    issue_date: '<?= $mockRecord['issue_date'] ?>',
                    amount: <?= $mockRecord['amount'] ?>,
                    supplier_name: '<?= $mockRecord['supplier_name'] ?>',
                    excel_supplier: '<?= $mockRecord['supplier_name'] ?>',
                    bank_name: '<?= $mockRecord['bank_name'] ?>',
                    contract_number: '<?= $mockRecord['contract_number'] ?? '' ?>',
                    expiry_date: '<?= $mockRecord['expiry_date'] ?>',
                    type: '<?= $mockRecord['type'] ?>',
                    supplier_id: null
                },
                timeline: <?= json_encode($mockTimeline ?? []) ?>
            }
        }
    </script>
</body>
</html>
