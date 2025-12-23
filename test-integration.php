<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>V3 Integration Test</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 20px; }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section-title { font-size: 20px; font-weight: bold; margin-bottom: 15px; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 13px; font-weight: 600; }
        .status.success { background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        .item { padding: 12px; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 8px; }
        .item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .item-title { font-weight: 600; color: #333; }
        .item-meta { font-size: 12px; color: #666; }
        .item-content { font-size: 14px; color: #555; line-height: 1.5; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 36px; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 14px; opacity: 0.9; }
    </style>
</head>
<body>
    <div class="container" x-data="testData()">
        <div class="header">
            <h1>✅ اختبار تكامل واجهة V3</h1>
            <p>التحقق من تحميل جميع المكونات من قاعدة البيانات</p>
        </div>

        <!-- Statistics -->
        <div class="grid">
            <div class="stat-card">
                <div class="stat-number" x-text="notes.length"></div>
                <div class="stat-label">ملاحظة</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" x-text="attachments.length"></div>
                <div class="stat-label">مرفق</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" x-text="timeline.length"></div>
                <div class="stat-label">حدث في التايم لاين</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">✅</div>
                <div class="stat-label">التكامل مكتمل</div>
            </div>
        </div>

        <!-- Notes Section -->
        <div class="section">
            <div class="section-title">
                📝 الملاحظات
                <span class="status success" x-text="notes.length + ' ملاحظة'"></span>
            </div>
            <template x-for="(note, index) in notes.slice(0, 5)" :key="note.id">
                <div class="item">
                    <div class="item-header">
                        <span class="item-title" x-text="'#' + (index + 1) + ' - ID: ' + note.id"></span>
                        <span class="item-meta" x-text="note.created_at"></span>
                    </div>
                    <div class="item-content" x-text="note.content"></div>
                    <div class="item-meta" x-text="'بواسطة: ' + note.created_by"></div>
                </div>
            </template>
            <div x-show="notes.length > 5" style="text-align: center; color: #666; font-size: 14px; margin-top: 10px;">
                ... و <span x-text="notes.length - 5"></span> ملاحظة أخرى
            </div>
        </div>

        <!-- Attachments Section -->
        <div class="section">
            <div class="section-title">
                📎 المرفقات
                <span class="status success" x-text="attachments.length + ' مرفق'"></span>
            </div>
            <template x-for="(file, index) in attachments" :key="file.id">
                <div class="item">
                    <div class="item-header">
                        <span class="item-title">
                            📄 <span x-text="file.file_name"></span>
                        </span>
                        <span class="item-meta" x-text="file.created_at?.substring(0,10)"></span>
                    </div>
                    <div class="item-meta">
                        <span x-text="'الحجم: ' + file.file_size + ' بايت'"></span> | 
                        <span x-text="'النوع: ' + file.file_type"></span> | 
                        <span x-text="'بواسطة: ' + file.uploaded_by"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Timeline Section -->
        <div class="section">
            <div class="section-title">
                📅 التايم لاين
                <span class="status success" x-text="timeline.length + ' حدث'"></span>
            </div>
            <template x-for="(event, index) in timeline" :key="event.id">
                <div class="item">
                    <div class="item-header">
                        <span class="item-title" x-text="'#' + (index + 1) + ' - ' + event.type"></span>
                        <span class="item-meta" x-text="event.date"></span>
                    </div>
                    <div class="item-content" x-text="event.description || 'لا يوجد وصف'"></div>
                    <div class="item-meta" x-text="'بواسطة: ' + event.user"></div>
                </div>
            </template>
        </div>
    </div>

    <script>
        function testData() {
            return {
                notes: <?php
                    require_once __DIR__ . '/app/Support/autoload.php';
                    use App\Support\Database;
                    $db = Database::connect();
                    $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([1]);
                    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
                ?>,
                attachments: <?php
                    $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([1]);
                    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC) ?? []);
                ?>,
                timeline: <?php
                    $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([1]);
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $timeline = [];
                    foreach ($history as $event) {
                        $timeline[] = [
                            'id' => $event['id'],
                            'type' => $event['action'],
                            'date' => $event['created_at'],
                            'description' => $event['change_reason'] ?? '',
                            'user' => $event['created_by'] ?? 'النظام'
                        ];
                    }
                    echo json_encode($timeline);
                ?>
            }
        }
    </script>
</body>
</html>
