<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BGL3 | مركز تحكم الوكيل</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&family=Noto+Kufi+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="agentfrontend/theme_light.css">
</head>
<body>

    <header>
        <div class="brand">
            <h1>وكيل BGL3</h1>
            <p>لوحة التحكم المركزية</p>
        </div>
        <div class="badge-live <?= $systemStatusTone === 'warn' ? 'badge-warn' : ($systemStatusTone === 'unknown' ? 'badge-unknown' : '') ?>">
            <div class="pulse <?= $systemStatusTone === 'warn' ? 'warn' : ($systemStatusTone === 'unknown' ? 'unknown' : '') ?>"></div>
            حالة النظام: <?= htmlspecialchars($systemStatusText ?? 'غير متوفر') ?>
        </div>
    </header>

    <main class="container">
        
        <!-- Flash Messages -->
        <?php if ($feedback): ?>
            <div class="flash-alert flash-<?= $feedback['type'] ?>">
                <div style="font-size: 1.5rem;">
                    <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
                </div>
                <div>
                    <div style="font-weight:700"><?= htmlspecialchars($feedback['title']) ?></div>
                    <div><?= htmlspecialchars($feedback['message']) ?></div>
                </div>
            </div>
        <?php endif; ?>
        <!-- NEW WIDGET AREA (Self-Evolution) -->
        <?php if (file_exists(__DIR__ . '/partials/extra_widget.php')): ?>
            <div class="section-full">
                <?php include __DIR__ . '/partials/extra_widget.php'; ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            
            <!-- SECTION 1: HIGH LEVEL SUMMARY -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/summary.php'; ?>
            </div>

            <div class="section-full">
            </div>

            <div class="section-full">
                <?php include __DIR__ . '/partials/agent_controls.php'; ?>
            </div>

            <div class="section-full">
                <?php include __DIR__ . '/partials/agent_autonomy_state.php'; ?>
            </div>

            <!-- SECTION 2: ATTENTION REQUIRED -->
            <!-- Blockers are critical, show full width if they exist -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/blockers.php'; ?>
            </div>

            <div class="section-full">
                <?php include __DIR__ . '/partials/permission_queue.php'; ?>
            </div>

            <div class="section-half">
                <?php include __DIR__ . '/partials/proposed_playbooks_simple.php'; ?>
            </div>
            
            <div class="section-half">
                <?php include __DIR__ . '/partials/proposals_simple.php'; ?>
            </div>

            <!-- SECTION 3: SYSTEM HEALTH (Simplified) -->
            <div class="section-full" style="margin-top: 2rem;">
                <h3 style="color: var(--text-secondary); font-size: 0.9rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                    مؤشرات الأداء والحالة التقنية
                </h3>
            </div>

            <div class="section-third">
                <?php include __DIR__ . '/partials/health.php'; ?>
            </div>
            <div class="section-third">
                <?php include __DIR__ . '/partials/permissions.php'; ?>
            </div>
            <div class="section-third">
                <?php include __DIR__ . '/partials/kpis.php'; ?>
            </div>

            <div class="section-full">
                <?php include __DIR__ . '/partials/tool_evidence.php'; ?>
            </div>

            <div class="section-full">
                <?php include __DIR__ . '/partials/copilot_chat.php'; ?>
            </div>

            
            <!-- SECTION 4: ACTIVITY LOG -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/activity.php'; ?>
            </div>

            <!-- SECTION 5: EXPERIENCES -->
            <div class="section-full">
                <?php include __DIR__ . '/partials/experiences.php'; ?>
            </div>
            
            <!-- HIDDEN/SECONDARY SECTIONS (Not for non-specialists) -->
            <!-- 
                partials: domain_map, flows, js_inventory, gap_tests, external_checks, worst_routes, rules, intents, decisions
                These are hidden to keep the UI minimal.
            -->

        </div>
    </main>

    <footer style="margin-top: 50px; text-align: center; color: var(--text-secondary); font-size: 0.8rem;">
        لوحة عمليات وكيل BGL3 &bull; المرحلة 6 للنواة الموحدة &bull; 2026
    </footer>

    <div id="live-toast" class="live-toast" aria-live="polite"></div>
    <script>
      (function () {
        const toast = document.getElementById('live-toast');

        function showToast(message, type) {
          if (!toast) return;
          toast.textContent = message || '';
          toast.className = 'live-toast ' + (type || 'info');
          toast.style.display = message ? 'block' : 'none';
          if (message) {
            setTimeout(() => {
              toast.style.display = 'none';
            }, 2200);
          }
        }

        function updateHeaderStatus(text, tone) {
          const badge = document.querySelector('.badge-live');
          if (!badge) return;
          badge.classList.remove('badge-warn', 'badge-unknown');
          if (tone === 'warn') badge.classList.add('badge-warn');
          if (tone === 'unknown') badge.classList.add('badge-unknown');
          badge.innerHTML = `
            <div class="pulse ${tone || ''}"></div>
            حالة النظام: ${text || 'غير متوفر'}
          `;
        }

        const lastLive = {};
        function applyLiveValue(key, val) {
          if (val === null || val === undefined || val === '') return;
          if (lastLive[key] === val) return;
          lastLive[key] = val;
          const el = document.querySelector(`[data-live="${key}"]`);
          if (el) {
            let displayVal = val;
            if (key === 'llm-state') {
              const v = String(val).toUpperCase();
              displayVal = v === 'HOT' ? 'حار' : (v === 'COLD' ? 'بارد' : 'غير معروف');
            }
            if (key === 'tool-server-status') {
              const v = String(val).toUpperCase();
              displayVal = v === 'ONLINE' ? 'متصل' : (v === 'OFFLINE' ? 'غير متصل' : 'غير معروف');
            }
            if (key === 'system-state') {
              const v = String(val).toUpperCase();
              displayVal = v === 'PASS' ? 'مستقر' : (v === 'WARN' ? 'يحتاج مراجعة' : 'غير مؤكد');
            }
            el.textContent = displayVal;
          }
          if (key === 'llm-state' && el) {
            const v = String(val).toUpperCase();
            el.style.color = v === 'HOT' ? 'var(--success)' : (v === 'COLD' ? 'var(--accent-gold)' : 'var(--text-secondary)');
          }
          if (key === 'tool-server-status' && el) {
            const v = String(val).toUpperCase();
            el.style.color = v === 'ONLINE' ? 'var(--success)' : 'var(--danger)';
          }
        }

        function updateLiveValues(data) {
          if (!data) return;
          if (data.system_status_text) {
            updateHeaderStatus(data.system_status_text, data.system_status_tone);
          }
          const bindMap = {
            'system-state': data.system_state,
            'pending-playbooks': data.pending_playbooks,
            'proposal-count': data.proposal_count,
            'permission-issues-count': data.permission_issues_count,
            'experience-total': data.experience_total,
            'experience-recent': data.experience_recent,
            'experience-last': data.experience_last,
            'tool-server-status': data.tool_server_online ? 'ONLINE' : 'OFFLINE',
            'tool-server-port': data.tool_server_port !== undefined ? ('المنفذ: ' + data.tool_server_port) : null,
            'llm-state': data.llm_state,
            'llm-model': data.llm_model,
            'llm-base': data.llm_base_url,
            'health-score-text': data.health_score_display,
            'health-score-dash': data.health_score_dash,
            'snapshot-timestamp': data.snapshot_timestamp,
            'snapshot-runtime': data.snapshot_runtime_events,
            'snapshot-route-scan-limit': data.snapshot_route_scan_limit,
            'snapshot-readiness': data.snapshot_readiness
          };
          Object.keys(bindMap).forEach((key) => {
            const val = bindMap[key];
            if (key === 'health-score-dash') {
              const path = document.querySelector(`[data-live="${key}"]`);
              if (path && val !== null && val !== undefined) {
                path.setAttribute('stroke-dasharray', `${val}, 100`);
              }
              return;
            }
            if (key === 'snapshot-readiness') {
              const el = document.querySelector(`[data-live="${key}"]`);
              if (el && val) {
                const v = String(val).toUpperCase();
                const label = v === 'OK' ? 'سليم' : (v === 'WARN' ? 'يتطلب مراجعة' : 'غير متوفر');
                el.textContent = label;
                el.style.color = v === 'OK' ? 'var(--success)' : (v === 'WARN' ? 'var(--danger)' : 'var(--text-secondary)');
              }
              return;
            }
            applyLiveValue(key, val);
          });

          const offlineHint = document.getElementById('copilot-offline-hint');
          if (offlineHint) {
            offlineHint.style.display = data.tool_server_online ? 'none' : 'block';
          }

          if (data.health_score_value !== null && data.health_score_value !== undefined) {
            const val = parseFloat(data.health_score_value);
            const scoreColor = val >= 80 ? 'var(--success)' : 'var(--accent-gold)';
            const path = document.querySelector('[data-live="health-score-dash"]');
            if (path) path.setAttribute('stroke', scoreColor);
          }

          if (data.vitals) {
            Object.keys(data.vitals).forEach((k) => {
              const v = data.vitals[k];
              const el = document.querySelector(`[data-live="vital-${k}-status"]`);
              if (el) {
                el.textContent = v.status ?? '';
                el.classList.toggle('status-ok', !!v.ok);
                el.classList.toggle('status-warn', !v.ok);
              }
            });
          }

          if (data.kpi_current) {
            Object.keys(data.kpi_current).forEach((slug) => {
              const el = document.querySelector(`[data-live="kpi-${slug}-current"]`);
              if (el) {
                const val = data.kpi_current[slug];
                el.textContent = `الحالي: ${val}`;
              }
            });
          }
        }

        async function sendExperienceAction(btn) {
          const item = btn.closest('.exp-item');
          if (!item) return;
          const hash = item.getAttribute('data-exp-hash') || '';
          const action = btn.getAttribute('data-exp-action') || '';
          const scenario = btn.getAttribute('data-exp-scenario') || '';
          const summary = btn.getAttribute('data-exp-summary') || '';
          if (!hash || !action) return;
          const body = new URLSearchParams();
          body.set('action', 'experience_action');
          body.set('exp_hash', hash);
          body.set('exp_action', action);
          body.set('exp_scenario', scenario);
          body.set('exp_summary', summary);
          try {
            const res = await fetch('agent-dashboard.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body
            });
            const data = await res.json().catch(() => null);
            if (data && data.ok) {
              item.remove();
              showToast('تم تحديث الخبرة.', 'success');
            } else {
              showToast('تعذر تحديث الخبرة.', 'warn');
            }
          } catch (e) {
            showToast('تعذر تحديث الخبرة.', 'warn');
          }
        }

        document.addEventListener('click', (e) => {
          const btn = e.target && e.target.closest ? e.target.closest('[data-exp-action]') : null;
          if (!btn) return;
          e.preventDefault();
          sendExperienceAction(btn);
        });

        async function refreshLive() {
          try {
            const res = await fetch('agent-dashboard.php?live=1', {
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              }
            });
            const json = await res.json();
            if (json && json.ok) updateLiveValues(json);
          } catch (e) {
            // silent
          }
        }

        let liveStream = null;
        function startLiveStream() {
          try {
            if (liveStream) liveStream.close();
          } catch (e) {}
          liveStream = new EventSource('agent-dashboard.php');
          liveStream.addEventListener('live', (evt) => {
            try {
              const data = JSON.parse(evt.data);
              if (data && data.ok) updateLiveValues(data);
            } catch (e) {}
          });
          liveStream.onerror = () => {
            try { liveStream.close(); } catch (e) {}
            liveStream = null;
            // fallback to polling if stream fails
            startLivePolling();
          };
        }

        async function submitLiveForm(form) {
          const formData = new FormData(form);
          formData.append('ajax', '1');
          const actionUrl = form.getAttribute('action') || window.location.pathname;
          const res = await fetch(actionUrl, {
            method: (form.method || 'POST').toUpperCase(),
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            },
            body: formData
          });
          let json = null;
          try { json = await res.json(); } catch (e) {}
          if (!json) {
            showToast('تعذر تنفيذ العملية.', 'error');
            return;
          }
          showToast(json.message || 'تم التنفيذ.', json.ok ? 'success' : 'error');
          if (json.reload) {
            setTimeout(() => location.reload(), 800);
            return;
          }
          const removeKey = form.dataset.remove;
          if (removeKey) {
            const item = form.closest(`[data-item="${removeKey}"]`);
            if (item) item.remove();
            const empty = document.querySelector(`[data-empty="${removeKey}"]`);
            const anyLeft = document.querySelector(`[data-item="${removeKey}"]`);
            if (empty && !anyLeft) {
              empty.style.display = 'block';
            }
          }
          await refreshLive();
        }

        async function submitLiveLink(link) {
          const href = link.getAttribute('href');
          if (!href) return;
          const res = await fetch(href, {
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            }
          });
          let json = null;
          try { json = await res.json(); } catch (e) {}
          if (!json) {
            showToast('تعذر تنفيذ العملية.', 'error');
            return;
          }
          showToast(json.message || 'تم التنفيذ.', json.ok ? 'success' : 'error');
          if (json.reload) {
            setTimeout(() => location.reload(), 800);
            return;
          }
          const removeKey = link.dataset.remove;
          if (removeKey) {
            const item = link.closest(`[data-item="${removeKey}"]`);
            if (item) item.remove();
            const empty = document.querySelector(`[data-empty="${removeKey}"]`);
            const anyLeft = document.querySelector(`[data-item="${removeKey}"]`);
            if (empty && !anyLeft) {
              empty.style.display = 'block';
            }
          }
          await refreshLive();
        }

        document.querySelectorAll('form[data-live="1"]').forEach((form) => {
          form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitLiveForm(form);
          });
        });

        document.querySelectorAll('a[data-live-link="1"]').forEach((link) => {
          link.addEventListener('click', (e) => {
            e.preventDefault();
            submitLiveLink(link);
          });
        });

        // Auto-refresh live status (SSE primary, polling fallback).
        let liveTimer = null;
        function startLivePolling() {
          if (liveTimer) return;
          liveTimer = setInterval(() => {
            if (document.hidden) return;
            refreshLive();
          }, 8000);
        }
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) refreshLive();
        });
        setTimeout(() => {
          refreshLive();
          startLiveStream();
        }, 1500);
      })();
    </script>
</body>
</html>
