<?php
/**
 * V3 Batch Print View
 * Prints Multiple Guarantee Letters
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\BankRepository;
use App\Repositories\SupplierRepository;

// 1. Inputs
$idsParam = $_GET['ids'] ?? '';
$actionType = $_GET['action'] ?? 'extension'; // extension, release

if (!$idsParam) {
    die("معرفات السجلات مفقودة.");
}

$guaranteeIds = explode(',', $idsParam);
$guaranteeIds = array_filter(array_map('intval', $guaranteeIds));

if (empty($guaranteeIds)) {
    die("لا توجد سجلات صالحة للطباعة.");
}

// 2. Data Fetching
$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$bankRepo = new BankRepository();
$supplierRepo = new SupplierRepository();

// Helpers
$hindiDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
$toHindi = fn($str) => preg_replace_callback('/[0-9]/', fn($m) => $hindiDigits[$m[0]], strval($str));
$months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

$formatDateHindi = function($dateStr) use ($hindiDigits, $months, $toHindi) {
    if (!$dateStr) return '-';
    try {
        $d = new DateTime($dateStr);
        $day = $toHindi($d->format('j'));
        $month = $months[(int)$d->format('n') - 1];
        $year = $toHindi($d->format('Y'));
        return $day . ' ' . $month . ' ' . $year;
    } catch (Exception $e) { return $dateStr; }
};

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة مجمعة - <?= count($guaranteeIds) ?> خطابات</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; background: #525659; font-family: 'Tajawal', sans-serif; }
        .print-wrapper { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            padding: 40px 0; 
            min-height: 100vh;
        }
        .letter-preview {
             background: transparent; 
             padding: 0; 
             width: auto; 
             margin-bottom: 30px;
        }
        .letter-paper { 
            width: 210mm !important;
            height: 297mm !important;
            margin: 0;
            background: white;
            padding: 20mm;
            position: relative;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .fw-800-sharp { font-weight: 800; }
        .header-line { margin-bottom: 20px; }
        .greeting { margin-top: 5px; }
        .subject { margin: 20px 0; font-weight: bold; display: flex; text-decoration: underline; }
        .first-paragraph { text-align: justify; line-height: 1.8; margin-bottom: 15px; }
        
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .print-wrapper { display: block; padding: 0; }
            .no-print { display: none !important; }
            .letter-preview { margin: 0; width: 100% !important; page-break-after: always; }
            .letter-preview:last-child { page-break-after: auto; }
            .letter-paper { box-shadow: none; border: none; margin: 0; padding: 20mm; width: 100% !important; height: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="no-print fixed top-5 right-5 z-40 flex flex-col gap-2">
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 flex items-center gap-2">
            <span>🖨️</span> طباعة الكل (<?= count($guaranteeIds) ?>)
        </button>
        <button onclick="window.close()" class="bg-gray-600 text-white px-4 py-2 rounded shadow hover:bg-gray-700 flex items-center gap-2">
            إغلاق
        </button>
    </div>

    <div class="print-wrapper">
        <?php foreach ($guaranteeIds as $index => $guaranteeId): ?>
            <?php
            // Fetch Record Logic (Duplicated from print.php mostly)
            $guarantee = $guaranteeRepo->find((int)$guaranteeId);
            if (!$guarantee) continue; // Skip missing

            $raw = $guarantee->rawData;
            $data = (object) [
                'guaranteeNumber' => $guarantee->guaranteeNumber,
                'contractNumber' => $raw['contract_number'] ?? '',
                'amount' => $raw['amount'] ?? 0.0,
                'expiryDate' => $raw['expiry_date'] ?? null,
                'type' => $raw['type'] ?? '',
                'supplierName' => $raw['supplier'] ?? 'غير محدد',
                'bankName' => $raw['bank'] ?? 'غير محدد',
                'bankDept' => 'إدارة الضمانات',
                'bankAddress' => ['المقر الرئيسي'],
                'bankEmail' => null,
                'isRelease' => ($actionType === 'release')
            ];

            // Decision Logic
            $decisionStmt = $db->prepare("SELECT supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1");
            $decisionStmt->execute([$guaranteeId]);
            $decision = $decisionStmt->fetch(\PDO::FETCH_ASSOC);

            if ($decision && $decision['supplier_id']) {
                $supplier = $supplierRepo->find((int)$decision['supplier_id']);
                if ($supplier) $data->supplierName = $supplier->officialName;
            }

            if ($decision && $decision['bank_id']) {
                $bank = $bankRepo->find((int)$decision['bank_id']);
                if ($bank) {
                    $data->bankName = $bank->officialName;
                    $data->bankDept = $bank->department ?? $data->bankDept;
                    $data->bankAddress = array_filter([$bank->addressLine1, $bank->addressLine2]) ?: $data->bankAddress;
                    $data->bankEmail = $bank->contactEmail;
                }
            }

            // Calculations
            $amountVal = number_format($data->amount, 2);
            $amountHindi = $toHindi($amountVal);

            $guaranteeDesc = 'خطاب ضمان';
            if ($data->type) {
                $t = mb_strtoupper($data->type);
                if (str_contains($t, 'FINAL') || str_contains($t, 'نهائي')) $guaranteeDesc = 'الضمان البنكي النهائي';
                elseif (str_contains($t, 'ADVANCED') || str_contains($t, 'دفعة') || str_contains($t, 'مقدمة')) $guaranteeDesc = 'ضمان الدفعة المقدمة البنكي';
                elseif (str_contains($t, 'INITIAL') || str_contains($t, 'ابتدائي') || str_contains($t, 'أولي')) $guaranteeDesc = 'الضمان البنكي الابتدائي';
            }

            $hasArabic = preg_match('/\p{Arabic}/u', $data->supplierName ?? '');
            $supplierStyle = ($hasArabic === 0) ? "font-family: 'Arial', sans-serif !important; direction: ltr; display: inline-block;" : "";

            $renewalDate = '-';
            if ($data->expiryDate) {
                try {
                    $d = new DateTime($data->expiryDate);
                    $d->modify('+1 year');
                    $renewalDate = $formatDateHindi($d->format('Y-m-d')) . 'م';
                } catch(Exception $e) {}
            }
            ?>
            
            <div class="letter-preview">
                <div class="letter-paper">
                    <!-- Header -->
                    <div class="header-line">
                        <div class="fw-800-sharp text-lg">السادة / <span id="letterBank"><?= htmlspecialchars($data->bankName) ?></span></div>
                        <div class="greeting">المحترمين</div>
                    </div>
                    
                    <!-- Bank Details -->
                    <div class="mb-4">
                        <div class="fw-800-sharp"><?= htmlspecialchars($data->bankDept) ?></div>
                        <?php foreach($data->bankAddress as $line): ?>
                        <div><?= $toHindi($line) ?></div>
                        <?php endforeach; ?>
                        <?php if($data->bankEmail): ?>
                        <div><span>البريد الالكتروني:</span> <?= htmlspecialchars($data->bankEmail) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="text-left mb-6 font-bold">السَّلام عليكُم ورحمَة الله وبركاتِه</div>

                    <!-- Subject -->
                    <div class="subject">
                        <span class="ml-2 w-20">الموضوع:</span>
                        <span>
                            <?php if ($data->isRelease): ?>
                            إفراج الضمان البنكي رقم (<?= htmlspecialchars($data->guaranteeNumber) ?>)
                            <?php else: ?>
                            طلب تمديد الضمان البنكي رقم (<?= htmlspecialchars($data->guaranteeNumber) ?>)
                            <?php endif; ?>
                            <?php if ($data->contractNumber): ?>
                            والعائد للعقد رقم (<?= htmlspecialchars($data->contractNumber) ?>)
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Body -->
                    <?php if ($data->isRelease): ?>
                    <div class="first-paragraph">
                        إشارة الى <?= $guaranteeDesc ?> الموضح أعلاه، والصادر منكم لصالحنا على حساب 
                        <span style="<?= $supplierStyle ?> font-weight:bold;"><?= htmlspecialchars($data->supplierName) ?></span> 
                        بمبلغ قدره (<strong><?= $amountHindi ?></strong>) ريال، 
                        نود إفادتكم بأنه قد تم الانتهاء من العقد المذكور أعلاه وفق الأصول والشروط المتفق عليها، 
                        لذا نأمل منكم <span class="fw-800-sharp">إلغاء الضمان البنكي</span> 
                        وإعادته إلى المقاول المذكور أعلاه.
                    </div>
                    <?php else: ?>
                    <div class="first-paragraph">
                        إشارة الى <?= $guaranteeDesc ?> الموضح أعلاه، والصادر منكم لصالحنا على حساب 
                        <span style="<?= $supplierStyle ?> font-weight:bold;"><?= htmlspecialchars($data->supplierName) ?></span> 
                        بمبلغ قدره (<strong><?= $amountHindi ?></strong>) ريال، 
                        نأمل منكم <span class="fw-800-sharp">تمديد فترة سريان الضمان حتى تاريخ <?= $renewalDate ?></span>، 
                        مع بقاء الشروط الأخرى دون تغيير، وإفادتنا بذلك من خلال البريد الالكتروني المخصص للضمانات البنكية لدى مستشفى الملك فيصل التخصصي ومركز الأبحاث بالرياض (bgfinance@kfshrc.edu.sa)، كما نأمل منكم إرسال أصل تمديد الضمان الى:
                    </div>

                    <div class="mr-12 mb-6">
                        <div>مستشفى الملك فيصل التخصصي ومركز الأبحاث – الرياض</div>
                        <div>ص.ب ٣٣٥٤ الرياض ١١٢١١</div>
                        <div>مكتب الخدمات الإدارية</div>
                    </div>

                    <div class="first-paragraph">
                        علمًا بأنه في حال عدم تمكن البنك من تمديد الضمان المذكور قبل انتهاء مدة سريانه، فيجب على البنك دفع قيمة الضمان إلينا حسب النظام.
                    </div>
                    <?php endif; ?>

                    <div class="mt-8 ml-12 text-left font-bold">وَتفضَّلوا بِقبُول خَالِص تحيَّاتِي</div>

                    <div class="mt-12 text-center mr-64">
                        <div class="mb-16 font-extrabold">مُدير الإدارة العامَّة للعمليَّات المحاسبيَّة</div>
                        <div class="font-bold">سَامِي بن عبَّاس الفايز</div>
                    </div>

                    <!-- Footer Codes -->
                    <div class="absolute bottom-16 left-20 right-20 flex justify-between text-xs font-mono">
                      <span>MBC:09-2</span>
                      <span>BAMZ</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
