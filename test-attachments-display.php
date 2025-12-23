<!DOCTYPE html>
<html>
<head>
    <title>Attachments Test</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body>
    <h1>Testing Attachments Injection</h1>
    
    <div x-data="testAttachments()">
        <h2>Attachments Count: <span x-text="attachments.length"></span></h2>
        
        <h3>All Attachments:</h3>
        <template x-for="(file, index) in attachments" :key="file.id">
            <div style="border: 1px solid #ccc; padding: 10px; margin: 5px;">
                <strong x-text="'#' + (index + 1) + ' - ID: ' + file.id"></strong><br>
                <span x-text="'File: ' + file.file_name"></span><br>
                <span x-text="'Path: ' + file.file_path"></span><br>
                <span x-text="'Size: ' + file.file_size + ' bytes'"></span><br>
                <small x-text="'Uploaded by: ' + file.uploaded_by + ' at ' + file.created_at"></small>
            </div>
        </template>
    </div>
    
    <script>
        function testAttachments() {
            return {
                attachments: <?php
                    require_once __DIR__ . '/app/Support/autoload.php';
                    use App\Support\Database;
                    $db = Database::connect();
                    $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([1]);
                    $mockAttachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode($mockAttachments ?? []);
                ?>
            }
        }
    </script>
</body>
</html>
