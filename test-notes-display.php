<!DOCTYPE html>
<html>
<head>
    <title>Notes Display Test</title>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body>
    <h1>Testing Notes Injection</h1>
    
    <div x-data="testNotes()">
        <h2>Notes Count: <span x-text="notes.length"></span></h2>
        
        <h3>First 5 Notes:</h3>
        <template x-for="(note, index) in notes.slice(0, 5)" :key="note.id">
            <div style="border: 1px solid #ccc; padding: 10px; margin: 5px;">
                <strong x-text="'#' + (index + 1) + ' - ID: ' + note.id"></strong><br>
                <span x-text="note.content"></span><br>
                <small x-text="'By: ' + note.created_by + ' at ' + note.created_at"></small>
            </div>
        </template>
    </div>
    
    <script>
        function testNotes() {
            return {
                notes: <?php
                    require_once __DIR__ . '/app/Support/autoload.php';
                    use App\Support\Database;
                    $db = Database::connect();
                    $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
                    $stmt->execute([1]);
                    $mockNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode($mockNotes ?? []);
                ?>
            }
        }
    </script>
</body>
</html>
