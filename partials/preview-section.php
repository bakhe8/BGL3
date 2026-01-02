<?php
/**
 * Partial: Preview Section
 * Letter preview - Server-rendered, togglable with JS
 * Required variables: $record (guarantee data)
 * Updated to match test/index.html design
 */

if (!isset($record)) {
    return;
}
?>

<div id="preview-section" class="preview-section letter-preview">

    <?php 
    $hasAction = !empty($record['active_action']); 
    
    // ✨ Arabic Numeral Conversion (Define BEFORE template usage)
    /**
     * Convert Western numerals (0-9) to Arabic-Indic numerals (٠-٩)
     */
    function toArabicNumerals($text) {
        if (empty($text)) return $text;
        $arabicNumerals = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        return preg_replace_callback('/\d/', function($matches) use ($arabicNumerals) {
            return $arabicNumerals[(int)$matches[0]];
        }, $text);
    }
    
    // Pre-convert all numeric fields
    $arabicAmount = toArabicNumerals(number_format($record['amount'] ?? 0, 2));
    $arabicGuaranteeNumber = $record['guarantee_number'] ?? '';  // alphanumeric - keep mixed
    $arabicContractNumber = toArabicNumerals($record['contract_number'] ?? '');
    $arabicPoBox = toArabicNumerals($record['bank_po_box'] ?? '3555');
    ?>
    
    <?php if ($hasAction): ?>
    <main class="letter-paper" id="letterPaper">
        <!-- Print Icon (Floating) -->
        <button 
            class="print-icon-btn no-print" 
            onclick="window.print()"
            title="طباعة الخطاب">
            &#x1F5A8;
        </button>
        <!-- رأس الخطاب: اسم البنك + المحترمين -->
        <div class="preview-header">
            <div class="preview-recipient-name">
                <div>السـادة / <span data-preview-target="bank_name"><?= htmlspecialchars($record['bank_name'] ?? '') ?></span></div>
            </div>
            <div class="preview-salutation">
                <div>المحترمين</div>
            </div>
        </div>
        
        <!-- معلومات البنك -->
        <!-- معلومات البنك -->
        <div class="preview-recipient">
            <div data-field="bankCenter"><?= htmlspecialchars($record['bank_center'] ?? 'مركز خدمات التجارة') ?></div>
            <div><span data-field="bankPoBox"><?= htmlspecialchars($arabicPoBox ?? 'ص.ب. ٣٥٥٥') ?></span></div>
            <div>البريد الإلكتروني: <span data-field="bankEmail" lang="en"><?= htmlspecialchars($record['bank_email'] ?? 'info@bank.com') ?></span></div>
        </div>
        
        <!-- السلام عليكم -->
        <div class="preview-greeting">
            <div>السَّلام عليكُم ورحمَة الله وبركاتِه</div>
        </div>
        
        <!-- الموضوع -->
        <div class="preview-subject">
            <div class="preview-subject-label">الموضوع:</div>
            <div class="preview-subject-text">
                <span data-preview-target="subject_action_type">
                    <?php
                    // ADR-007: No default. Subject determined by action only.
                    if (!empty($record['active_action'])) {
                        echo $record['active_action'] === 'extension' ? 'طلب تمديد' : 
                            ($record['active_action'] === 'reduction' ? 'طلب تخفيض' : 
                            ($record['active_action'] === 'release' ? 'طلب الإفراج عن' : ''));
                    }
                    ?>
                </span> الضمان البنكي رقم (<span data-preview-target="guarantee_number" lang="en"><?= htmlspecialchars($arabicGuaranteeNumber ?? '') ?></span>) والعائد للعقد رقم (<span data-preview-target="contract_number"><?= htmlspecialchars($arabicContractNumber ?? '') ?></span>).
            </div>
        </div>
        
        <!-- المحتوى -->
        <?php
        // ترجمة نوع الضمان من الإنجليزية إلى العربية
        $typeTranslations = [
            'Final' => 'النهائي',
            'Preliminary' => 'الابتدائي',
            'Performance' => 'الأداء',
            'Advance Payment' => 'الدفعة المقدمة',
        ];
        $guaranteeType = $record['type'] ?? 'النهائي';
        $guaranteeTypeArabic = $typeTranslations[$guaranteeType] ?? $guaranteeType;
        
        // تنسيق التاريخ بالصيغة العربية (1 يناير 2025)
        $arabicMonths = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
        ];
        
        function formatArabicDate($dateStr, $months) {
            if (empty($dateStr)) return '';
            $timestamp = strtotime($dateStr);
            if ($timestamp === false) return $dateStr;
            $day = date('j', $timestamp);
            $month = (int)date('n', $timestamp);
            $year = date('Y', $timestamp);
            $monthName = $months[$month] ?? '';
            
            // ✨ Convert day and year to Arabic numerals
            $arabicDay = toArabicNumerals($day);
            $arabicYear = toArabicNumerals($year);
            
            return $arabicDay . ' ' . $monthName . ' ' . $arabicYear;
        }
        
        $formattedExpiryDate = formatArabicDate($record['expiry_date'] ?? '', $arabicMonths);
        ?>
        <div class="preview-content">
        <p class="letter-paragraph">
            <?php
            // Logic for the full introductory phrase
            $guaranteeTypeRaw = trim($record['type'] ?? '');
            
            if (stripos($guaranteeTypeRaw, 'Final') !== false) {
                // Type is Final
                $introPhrase = 'إشارة إلى الضمان البنكي النهائي الموضح أعلاه';
            } elseif (stripos($guaranteeTypeRaw, 'Advance') !== false) {
                // Type is Advance (or Advance Payment)
                $introPhrase = 'إشارة إلى ضمان الدفعة المقدمة البنكي الموضح أعلاه';
            } else {
                // Default (Preliminary, Performance, or empty)
                $introPhrase = 'إشارة إلى الضمان البنكي الموضح أعلاه';
            }
            ?>
            <span data-preview-target="full_intro_phrase"><?= $introPhrase ?></span>، والصادر منكم لصالحنا على حساب شركة
                <span data-preview-target="supplier_name"><?= htmlspecialchars($record['supplier_name'] ?? '') ?></span>
                بمبلغ قدره (<span data-preview-target="amount"><?= $arabicAmount ?? '٠.٠٠' ?></span>)، نأمل منكم تمديد فترة سريان الضمان حتى تاريخ
                <span data-preview-target="expiry_date"><?= htmlspecialchars($formattedExpiryDate) ?></span>م مع بقاء
                الشروط الأخرى دون تغيير، وإفادتنا بذلك من خلال البريد الإلكتروني المخصص للضمانات البنكية لدى
                مستشفى الملك فيصل التخصصي ومركز الأبحاث بالرياض (<span lang="en">bgfinance@kfshrc.edu.sa</span>)، كما نأمل منكم إرسال أصل
                تمديد الضمان إلى العنوان التالي:
            </p>
            
            <div class="preview-address-box">
                <div class="preview-recipient">
                    <div>مستشفى الملك فيصل التخصصي ومركز الأبحاث - الرياض</div>
                    <div>ص.ب 3354 الرياض 11211</div>
                    <div>مكتب الخدمات الإدارية</div>
                </div>
            </div>
            
            <p class="letter-paragraph">
                علمًا بأنه في حال عدم تمكن البنك من تمديد الضمان المذكور قبل انتهاء مدة سريانه فيجب على البنك دفع
                قيمة الضمان إلينا حسب النظام.
            </p>
        </div>
        
        <!-- التوقيع -->
        <div class="preview-clearfix">
            <div class="letter-line preview-note">وَتفضَّلوا بِقبُول خَالِص تحيَّاتي</div>
            <div class="preview-signature">
                <div>مُدير الإدارة العامَّة للعمليَّات المحاسبيَّة</div>
                <div class="signature-seal">سَامي بن عبَّاس الفايز</div>
            </div>
        </div>
        
        <!-- التذييل - في أسفل الورقة -->
        <div class="sheet-footer">
            <span class="footer-left" lang="en">MBC: 9-2</span>
            <span class="footer-right" lang="en">BAMZ</span>
        </div>
    </main>
    <?php else: ?>
    <!-- ADR-007: No Action State -->
    <div class="preview-no-action-state" style="
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        text-align: center;
        padding: 40px;
        color: #666;
    ">
        <div style="font-size: 64px; margin-bottom: 24px; opacity: 0.6;">📋</div>
        <h3 style="color: #333; margin-bottom: 12px; font-size: 20px; font-weight: 600;">ضمان بنكي جاهز</h3>
        <p style="margin-bottom: 8px; font-size: 14px; color: #555;">
            لم يتم اتخاذ أي إجراء على هذا الضمان حتى الآن.
        </p>
        <p style="font-size: 13px; color: #999;">
            يمكنك تنفيذ إجراء (تمديد، تخفيض، إفراج) عند الحاجة.
        </p>
    </div>
    <?php endif; ?>
</div>
