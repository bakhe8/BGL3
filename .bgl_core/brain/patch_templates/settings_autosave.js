// Template: Settings auto-save with toast feedback
// Placeholders: {{endpoint}}, {{success_toast}}

async function autoSaveSetting(key, value) {
  const res = await fetch('{{endpoint}}', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ key, value }),
  });
  if (res.ok) {
    showToast('{{success_toast}}'); // implement showToast in your UI
  } else {
    console.error('Auto-save failed', await res.text());
  }
}
