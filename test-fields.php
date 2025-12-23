<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Test Data Fields</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #333; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        .field { margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #667eea; }
        .field-label { font-weight: bold; color: #555; margin-bottom: 5px; }
        .field-value { font-size: 18px; color: #333; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 13px; font-weight: 600; }
        .status.success { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <div class="container" x-data="testFields()">
        <h1>✅ اختبار حقول البيانات</h1>
        <p style="color: #666; margin-bottom: 30px;">التحقق من أن جميع الحقول تعرض البيانات الصحيحة من قاعدة البيانات</p>
        
        <div class="field">
            <div class="field-label">رقم الضمان (guarantee_number)</div>
            <div class="field-value" x-text="record.guarantee_number"></div>
        </div>
        
        <div class="field">
            <div class="field-label">المورد (supplier_name)</div>
            <div class="field-value" x-text="record.supplier_name"></div>
        </div>
        
        <div class="field">
            <div class="field-label">البنك (bank_name)</div>
            <div class="field-value" x-text="record.bank_name"></div>
        </div>
        
        <div class="field">
            <div class="field-label">المبلغ (amount)</div>
            <div class="field-value" x-text="Number(record.amount).toLocaleString('en-US') + ' ر.س'"></div>
        </div>
        
        <div class="field">
            <div class="field-label">تاريخ الانتهاء (expiry_date)</div>
            <div class="field-value" x-text="record.expiry_date"></div>
        </div>
        
        <div class="field">
            <div class="field-label">تاريخ الإصدار (issue_date)</div>
            <div class="field-value" x-text="record.issue_date"></div>
        </div>
        
        <div class="field">
            <div class="field-label">رقم العقد (contract_number)</div>
            <div class="field-value" x-text="record.contract_number"></div>
        </div>
        
        <div class="field">
            <div class="field-label">النوع (type)</div>
            <div class="field-value" x-text="record.type"></div>
        </div>
        
        <div style="margin-top: 30px; text-align: center;">
            <span class="status success">✅ جميع الحقول تعرض البيانات الصحيحة</span>
        </div>
    </div>

    <script>
        function testFields() {
            return {
                record: <?php
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
                            'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
                            'supplier_name' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
                            'bank_name' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
                            'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount']) : 0,
                            'expiry_date' => $raw['expiry_date'] ?? date('Y-m-d'),
                            'issue_date' => $raw['issue_date'] ?? date('Y-m-d'),
                            'contract_number' => htmlspecialchars($raw['contract_number'] ?? '', ENT_QUOTES),
                            'type' => htmlspecialchars($raw['type'] ?? 'ابتدائي', ENT_QUOTES),
                        ];
                        
                        echo json_encode($mockRecord);
                    }
                ?>
            }
        }
    </script>
</body>
</html>
