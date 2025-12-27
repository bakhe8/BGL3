<?php
/**
 * Partial: Add Bank Modal
 * UI for adding new banks when no match is found
 * Shows only when bank matching fails
 */
?>

<!-- Add New Bank Modal -->
<div id="add-bank-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>إضافة بنك جديد</h3>
            <span class="close" onclick="closeAddBankModal()">&times;</span>
        </div>
        
        <form id="add-bank-form">
            <div class="form-row">
                <div class="form-group">
                    <label>الاسم العربي الرسمي: *</label>
                    <input type="text" id="bank-arabic-name" placeholder="مثال: مصرف الراجحي" required>
                </div>
                
                <div class="form-group">
                    <label>الاسم الإنجليزي: *</label>
                    <input type="text" id="bank-english-name" placeholder="Example: Al Rajhi Bank" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>الرمز المختصر: *</label>
                <input type="text" id="bank-short-name" placeholder="مثال: ALRAJHI" required style="text-transform:uppercase; max-width: 300px;">
                <small>سيُستخدم للمطابقة السريعة</small>
            </div>
            
            <div class="form-group">
                <label>الصيغ البديلة (اختياري):</label>
                <div id="aliases-container">
                    <input type="text" class="alias-input" placeholder='مثال: "الراجحي"'>
                    <input type="text" class="alias-input" placeholder='مثال: "alrajhi"'>
                    <input type="text" class="alias-input" placeholder='مثال: "rajhi"'>
                </div>
                <button type="button" onclick="addAliasField()" class="btn-link" style="margin-top: 8px;">
                    <span>+</span> إضافة صيغة أخرى
                </button>
                <small>أضف الصيغ التي قد تظهر في ملفات Excel (عربي، إنجليزي، اختصارات)</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">حفظ البنك</button>
                <button type="button" onclick="closeAddBankModal()" class="btn-secondary">إلغاء</button>
            </div>
        </form>
        
        <div id="bank-message" style="display:none; margin-top: 15px;"></div>
    </div>
</div>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.6);
}

.modal-content {
    background-color: #fff;
    margin: 3% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 700px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 5px 25px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px 30px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 20px;
    color: #333;
}

.close {
    font-size: 32px;
    font-weight: 300;
    color: #999;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
}

.close:hover {
    color: #333;
}

#add-bank-form {
    padding: 30px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #333;
}

.form-group input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.form-group input[type="text"]:focus {
    outline: none;
    border-color: #4CAF50;
}

.alias-input {
    width: 100%;
    margin-bottom: 10px;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #666;
    font-size: 12px;
}

.btn-link {
    background: none;
    border: none;
    color: #4CAF50;
    cursor: pointer;
    padding: 5px 0;
    font-size: 14px;
}

.btn-link:hover {
    color: #45a049;
    text-decoration: underline;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.btn-primary, .btn-secondary {
    padding: 10px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-primary {
    background-color: #4CAF50;
    color: white;
}

.btn-primary:hover {
    background-color: #45a049;
}

.btn-secondary {
    background-color: #f5f5f5;
    color: #666;
}

.btn-secondary:hover {
    background-color: #e0e0e0;
}

.success-message {
    color: #155724;
    padding: 12px 15px;
    background: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 6px;
}

.error-message {
    color: #721c24;
    padding: 12px 15px;
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    border-radius: 6px;
}
</style>

<script>
function openAddBankModal(suggestedName = '') {
    document.getElementById('add-bank-modal').style.display = 'block';
    // Pre-fill with the failed bank name if provided
    if (suggestedName) {
        document.getElementById('bank-arabic-name').value = suggestedName;
    }
}

function closeAddBankModal() {
    document.getElementById('add-bank-modal').style.display = 'none';
    document.getElementById('add-bank-form').reset();
    document.getElementById('bank-message').style.display = 'none';
    // Clear extra alias fields
    const container = document.getElementById('aliases-container');
    const inputs = container.querySelectorAll('.alias-input');
    inputs.forEach((input, index) => {
        if (index >= 3) input.remove(); // Keep first 3
        else input.value = '';
    });
}

function addAliasField() {
    const container = document.getElementById('aliases-container');
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'alias-input';
    input.placeholder = 'صيغة بديلة أخرى';
    container.appendChild(input);
}

document.getElementById('add-bank-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const arabicName = document.getElementById('bank-arabic-name').value.trim();
    const englishName = document.getElementById('bank-english-name').value.trim();
    const shortName = document.getElementById('bank-short-name').value.trim().toUpperCase();
    
   // Collect aliases
    const aliasInputs = document.querySelectorAll('.alias-input');
    const aliases = Array.from(aliasInputs)
        .map(input => input.value.trim())
        .filter(val => val !== '');
    
    try {
        const response = await fetch('api/add-bank.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                arabic_name: arabicName,
                english_name: englishName,
                short_name: shortName,
                aliases: aliases
            })
        });
        
        const result = await response.json();
        
        const messageEl = document.getElementById('bank-message');
        messageEl.textContent = result.message || result.error;
        messageEl.className = result.success ? 'success-message' : 'error-message';
        messageEl.style.display = 'block';
        
        if (result.success) {
            setTimeout(() => {
                closeAddBankModal();
                location.reload(); // Refresh to show new bank
            }, 1500);
        }
    } catch (error) {
        console.error('Error:', error);
        const messageEl = document.getElementById('bank-message');
        messageEl.textContent = 'حدث خطأ في الإضافة';
        messageEl.className = 'error-message';
        messageEl.style.display = 'block';
    }
});

// Close modal on background click
document.getElementById('add-bank-modal').addEventListener('click', (e) => {
    if (e.target.id === 'add-bank-modal') {
        closeAddBankModal();
    }
});

// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.getElementById('add-bank-modal').style.display === 'block') {
        closeAddBankModal();
    }
});
</script>
