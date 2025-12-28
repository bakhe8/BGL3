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

<div id="preview-section" class="preview-section" style="display: none;">
    <header class="preview-header-bar">
        <span class="preview-title" data-preview-title>📄 معاينة الخطاب</span>
        <button class="preview-print" data-action="print" data-print-type="extension">&#x1F5A8; طباعة</button>
    </header>
    
    <div class="preview-body">
        <main class="letter-paper" id="letterPaper">
            <div class="sheet-content">
                <section class="writing-area" id="letterWritingArea">
                    <article class="preview-container" id="primaryLetter">
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
                        <div class="preview-recipient">
                            <div data-field="bankCenter">مركز خدمات التجارة</div>
                            <div>ص.ب. <span data-field="bankPoBox">3555</span></div>
                            <div>البريد الإلكتروني: <span data-field="bankEmail" lang="en">info@bank.com</span></div>
                        </div>
                        
                        <!-- السلام عليكم -->
                        <div class="preview-greeting">
                            <div>السَّلام عليكُم ورحمَة الله وبركاتِه</div>
                        </div>
                        
                        <!-- الموضوع -->
                        <div class="preview-subject">
                            <div class="preview-subject-label">الموضوع:</div>
                            <div class="preview-subject-text">
                                طلب تمديد الضمان البنكي رقم (<span data-preview-target="guarantee_number" lang="en"><?= htmlspecialchars($record['guarantee_number'] ?? '') ?></span>) والعائد للعقد رقم (<span data-preview-target="contract_number" lang="en"><?= htmlspecialchars($record['contract_number'] ?? '') ?></span>).
                            </div>
                        </div>
                        
                        <!-- المحتوى -->
                        <div class="preview-content">
                            <p class="letter-paragraph">
                                إشارة إلى الضمان البنكي <span data-preview-target="type"><?= htmlspecialchars($record['type'] ?? 'النهائي') ?></span> الموضح أعلاه، والصادر منكم لصالحنا على حساب شركة
                                <span data-preview-target="supplier_name"><?= htmlspecialchars($record['supplier_name'] ?? '') ?></span>
                                بمبلغ قدره (<span data-preview-target="amount"><?= number_format($record['amount'] ?? 0, 2, '.', ',') ?></span>) ريال، نأمل منكم تمديد فترة سريان الضمان حتى تاريخ
                                <span data-preview-target="expiry_date"><?= htmlspecialchars($record['expiry_date'] ?? '') ?></span>م مع بقاء
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
                    </article>
                </section>
            </div>
            
            <!-- التذييل -->
            <div class="sheet-footer">
                <span class="footer-left" lang="en">MBC: 9-2</span>
                <span class="footer-right" lang="en">BAMZ</span>
            </div>
        </main>
    </div>
</div>
