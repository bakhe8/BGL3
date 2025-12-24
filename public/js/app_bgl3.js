

document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('import-file-input');
    if(fileInput) {
        fileInput.addEventListener('change', async (e) => {
            if(e.target.files.length > 0) {
                const formData = new FormData();
                formData.append('file', e.target.files[0]);
                
                try {
                    const res = await fetch('api/import.php', { method: 'POST', body: formData });
                    if(res.ok) {
                         const txt = await res.text();
                         console.log(txt);
                         try {
                            const json = JSON.parse(txt);
                             if(json.success || json.status === 'success') {
                                alert('Import successful!');
                                // alert('Import successful!');
                                window.location.reload();
                             } else {
                                // alert('Import failed: ' + (json.message || txt));
                             }
                         } catch(e) {
                             window.location.reload();
                         }
                    } else {
                        // alert('Upload failed: ' + res.status);
                    }
                } catch(e) {
                    // alert('Network error during upload');
                }
            }
        });
    }

});
