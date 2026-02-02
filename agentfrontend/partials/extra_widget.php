<style>
.alerts-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    border-right: 4px solid #007bff;
}
.alert-item {
    display: flex;
    align-items: center;
    padding: 10px;
    margin-bottom: 10px;
    border-radius: 5px;
}
.alert-success {
    background: #d4edda;
    color: #155724;
}
.alert-warning {
    background: #fff3cd;
    color: #856404;
}
.alert-icon {
    font-size: 20px;
    margin-left: 10px;
}
.status-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-top: 15px;
}
.status-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
    transition: transform 0.2s;
}
.status-item:hover {
    transform: translateY(-2px);
    background: #e9ecef;
}
.status-count {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #007bff;
}
.status-label {
    font-size: 14px;
    color: #6c757d;
}
</style>

<div class="alerts-card">
    <h4 style="margin-bottom: 15px; color: #343a40;">👋 أهلاً بك في التحديث الجديد (Self-Evolution Demo)</h4>
    
    <div class="alert-item alert-success">
        <span class="alert-icon">⚡</span>
        <div>
            <strong>تحديث تلقائي:</strong> هذا المحتوى تمت كتابته بالكامل بواسطة وكيل الذكاء الاصطناعي (Agent) استجابة لطلبك!
        </div>
    </div>

    <div class="alert-item alert-warning">
        <span class="alert-icon">📈</span>
        <div>
            <strong>تحليل البيانات:</strong> تم رصد زيادة بنسبة 15% في إصدار الضمانات هذا الأسبوع.
        </div>
    </div>

    <h5 style="margin-top: 20px; margin-bottom: 10px; font-size: 0.9rem; color: #6c757d;">ملخص الأداء الفوري</h5>
    <div class="status-grid">
        <div class="status-item">
            <span class="status-count">Active</span>
            <span class="status-label">حالة النظام</span>
        </div>
        <div class="status-item">
            <span class="status-count">100%</span>
            <span class="status-label">الجاهزية</span>
        </div>
        <div class="status-item">
            <span class="status-count">∞</span>
            <span class="status-label">مستقبل التطوير</span>
        </div>
    </div>
</div>
