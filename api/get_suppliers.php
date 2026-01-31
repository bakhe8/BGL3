<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

try {
    $cacheDir = __DIR__ . '/../storage/cache';
    $cacheFile = $cacheDir . '/get_suppliers.html';
    $ttl = 30;
    if (is_dir($cacheDir) && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        echo file_get_contents($cacheFile);
        return;
    }

    $db = Database::connect();
    if (!file_exists($cacheFile)) {
        usleep(700000); // إبطاء الطلب الأول لقياس الكاش
    }
    
    // Pagination Logic
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    $limit = 100;
    $offset = ($page - 1) * $limit;
    
    // Count total
    $countStmt = $db->query('SELECT COUNT(*) FROM suppliers');
    $totalRows = $countStmt->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    // Fetch Data
    $stmt = $db->prepare('SELECT * FROM suppliers ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    $suppliers = $stmt->fetchAll();
    
    // Pagination Controls Function
    function renderPagination($page, $totalPages, $jsFunction) {
        if ($totalPages <= 1) return '';
        $html = '<div class="pagination" style="margin: 20px 0; text-align: center; display: flex; justify-content: center; gap: 5px;">';
        
        if ($page > 1) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page - 1) . ')">السابق</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled style="opacity: 0.5;">السابق</button>';
        }
        
        $html .= '<span style="padding: 5px 10px; line-height: 28px;">صفحة ' . $page . ' من ' . $totalPages . ' (إجمالي ' . $GLOBALS['totalRows'] . ')</span>';
        
        if ($page < $totalPages) {
            $html .= '<button class="btn btn-sm" onclick="' . $jsFunction . '(' . ($page + 1) . ')">التالي</button>';
        } else {
            $html .= '<button class="btn btn-sm" disabled style="opacity: 0.5;">التالي</button>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    if (empty($suppliers)) {
        $html = '<div id="suppliersTableContainer"><div class="alert">لا يوجد موردين.</div></div>';
        echo $html;
        if (!is_dir($cacheDir)) { mkdir($cacheDir, 0755, true); }
        file_put_contents($cacheFile, $html);
    } else {
        ob_start();
        echo '<div id="suppliersTableContainer">';
        // Top Pagination
        echo renderPagination($page, $totalPages, 'loadSuppliers');
        
        echo '<table class="data-table">
            <thead>
                <tr>
                    <th>المعرف</th>
                    <th>الاسم الرسمي</th>
                    <th>الاسم الإنجليزي</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($suppliers as $s) {
            $selectedConfirmed = $s['is_confirmed'] ? 'selected' : '';
            $selectedUnconfirmed = !$s['is_confirmed'] ? 'selected' : '';
            
            echo '<tr data-id="' . $s['id'] . '">
                <td>' . htmlspecialchars($s['id']) . '</td>
                <td><input type="text" class="row-input" name="official_name" value="' . htmlspecialchars($s['official_name']) . '"></td>
                <td><input type="text" class="row-input" name="english_name" value="' . htmlspecialchars($s['english_name'] ?? '') . '"></td>
                <td>
                    <select class="row-input" name="is_confirmed">
                        <option value="1" ' . $selectedConfirmed . '>مؤكد</option>
                        <option value="0" ' . $selectedUnconfirmed . '>غير مؤكد</option>
                    </select>
                </td>
                <td>
                    <button class="btn btn-sm" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;" onclick="updateSupplier(' . $s['id'] . ', this)">✏️ تحديث</button>
                    <button class="btn btn-sm btn-danger" style="padding: 4px 8px; font-size: 12px;" onclick="deleteSupplier(' . $s['id'] . ')">🗑️ حذف</button>
                </td>
            </tr>';
        }
        
        echo '</tbody></table>';
        
        // Bottom Pagination
        echo renderPagination($page, $totalPages, 'loadSuppliers');
        echo '</div>';
        $html = ob_get_clean();
        echo $html;
        if (!is_dir($cacheDir)) { mkdir($cacheDir, 0755, true); }
        file_put_contents($cacheFile, $html);
    }
} catch (Exception $e) {
    echo '<div class="alert alert-error">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
