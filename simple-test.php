<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$_GET['id'] = 1;
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$guarantee = $guaranteeRepo->find(1);
$raw = $guarantee->rawData;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Simple Test - No Cache</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        body { font-family: Arial; padding: 40px; background: #f5f5f5; }
        .card { background: white; padding: 30px; border-radius: 8px; max-width: 600px; margin: 0 auto; }
        .field { margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px; }
        .label { font-weight: bold; color: #555; margin-bottom: 8px; }
        .value { font-size: 20px; color: #333; }
        h1 { color: #e74c3c; text-align: center; }
    </style>
</head>
<body>
    <div class="card" x-data="{
        supplier: '<?= htmlspecialchars($raw['supplier'], ENT_QUOTES) ?>',
        bank: '<?= htmlspecialchars($raw['bank'], ENT_QUOTES) ?>',
        amount: <?= $raw['amount'] ?>,
        guarantee_number: '<?= $guarantee->guaranteeNumber ?>'
    }">
        <h1>🧪 اختبار بسيط - بدون Cache</h1>
        
        <div class="field">
            <div class="label">رقم الضمان:</div>
            <div class="value" x-text="guarantee_number"></div>
        </div>
        
        <div class="field">
            <div class="label">المورد:</div>
            <div class="value" x-text="supplier"></div>
        </div>
        
        <div class="field">
            <div class="label">البنك:</div>
            <div class="value" x-text="bank"></div>
        </div>
        
        <div class="field">
            <div class="label">المبلغ:</div>
            <div class="value" x-text="Number(amount).toLocaleString('en-US') + ' ر.س'"></div>
        </div>
        
        <div style="margin-top: 30px; padding: 15px; background: #d4edda; border-radius: 6px; text-align: center;">
            <strong>إذا رأيت البيانات الصحيحة هنا، فالمشكلة في cache المتصفح فقط!</strong>
        </div>
    </div>
</body>
</html>
