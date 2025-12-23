<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مقارنة البيانات - ID=1 vs ID=5</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; }
        .comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { color: #667eea; border-bottom: 2px solid #667eea; padding-bottom: 10px; margin-bottom: 15px; }
        .field { margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .field-label { font-weight: bold; color: #555; font-size: 13px; }
        .field-value { font-size: 16px; color: #333; margin-top: 5px; }
        .timeline-item { padding: 10px; margin: 8px 0; background: #f0f0f0; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 مقارنة البيانات: ID=1 vs ID=5</h1>
        <p style="color: #666;">للتحقق من أن البيانات تتغير حسب السجل</p>
        
        <div class="comparison">
            <!-- ID = 1 -->
            <div class="card" x-data="getData(1)">
                <h2>Guarantee ID = 1</h2>
                
                <div class="field">
                    <div class="field-label">رقم الضمان</div>
                    <div class="field-value" x-text="record.guarantee_number"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">المورد</div>
                    <div class="field-value" x-text="record.supplier_name"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">البنك</div>
                    <div class="field-value" x-text="record.bank_name"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">المبلغ</div>
                    <div class="field-value" x-text="Number(record.amount).toLocaleString('en-US') + ' ر.س'"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">تاريخ الانتهاء</div>
                    <div class="field-value" x-text="record.expiry_date"></div>
                </div>
                
                <h3 style="margin-top: 20px; color: #555;">Timeline (<span x-text="timeline.length"></span> events)</h3>
                <template x-for="event in timeline.slice(0, 3)" :key="event.id">
                    <div class="timeline-item">
                        <strong x-text="event.type"></strong> - <span x-text="event.date"></span>
                    </div>
                </template>
            </div>
            
            <!-- ID = 5 -->
            <div class="card" x-data="getData(5)">
                <h2>Guarantee ID = 5</h2>
                
                <div class="field">
                    <div class="field-label">رقم الضمان</div>
                    <div class="field-value" x-text="record.guarantee_number"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">المورد</div>
                    <div class="field-value" x-text="record.supplier_name"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">البنك</div>
                    <div class="field-value" x-text="record.bank_name"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">المبلغ</div>
                    <div class="field-value" x-text="Number(record.amount).toLocaleString('en-US') + ' ر.س'"></div>
                </div>
                
                <div class="field">
                    <div class="field-label">تاريخ الانتهاء</div>
                    <div class="field-value" x-text="record.expiry_date"></div>
                </div>
                
                <h3 style="margin-top: 20px; color: #555;">Timeline (<span x-text="timeline.length"></span> events)</h3>
                <template x-for="event in timeline.slice(0, 3)" :key="event.id">
                    <div class="timeline-item">
                        <strong x-text="event.type"></strong> - <span x-text="event.date"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        function getData(guaranteeId) {
            <?php
            require_once __DIR__ . '/app/Support/autoload.php';
            use App\Support\Database;
            use App\Repositories\GuaranteeRepository;
            
            $db = Database::connect();
            $guaranteeRepo = new GuaranteeRepository($db);
            $allGuarantees = $guaranteeRepo->getAll([], 100, 0);
            
            $dataById = [];
            
            foreach ($allGuarantees as $g) {
                $raw = $g->rawData;
                
                // Get timeline for this guarantee
                $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC LIMIT 5');
                $stmt->execute([$g->id]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $timeline = [];
                foreach ($history as $event) {
                    $timeline[] = [
                        'id' => $event['id'],
                        'type' => $event['action'],
                        'date' => $event['created_at']
                    ];
                }
                
                $dataById[$g->id] = [
                    'record' => [
                        'guarantee_number' => $g->guaranteeNumber,
                        'supplier_name' => $raw['supplier'] ?? 'N/A',
                        'bank_name' => $raw['bank'] ?? 'N/A',
                        'amount' => $raw['amount'] ?? 0,
                        'expiry_date' => $raw['expiry_date'] ?? 'N/A'
                    ],
                    'timeline' => $timeline
                ];
            }
            
            echo 'const allData = ' . json_encode($dataById, JSON_UNESCAPED_UNICODE) . ';';
            ?>
            
            return allData[guaranteeId] || { record: {}, timeline: [] };
        }
    </script>
</body>
</html>
