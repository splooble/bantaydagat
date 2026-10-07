<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
requireRole(MAO_ROLES);

$user = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;




$sql = "SELECT id, first_name, last_name, municipality, barangay, created_at
        FROM fisherfolk";
if ($mun_scope) {
    $sql .= " WHERE municipality = '" . $conn->real_escape_string($mun_scope) . "'";
}
$sql .= " ORDER BY created_at DESC LIMIT 50";
$result = $conn->query($sql);
$syncedRegistrations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BantayDagat — Recent Registrations</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Instrument Sans', sans-serif; box-sizing: border-box; }
        body { margin: 0; background: #f4f9fc; color: #16324a; min-height: 100vh; padding-bottom: 40px; }

        .app-header {
            background: linear-gradient(135deg, #0a2f4f, #06263f);
            padding: 28px 20px 16px; color: #fff;
        }
        .app-header h1 { font-size: 18px; font-weight: 700; margin: 0 0 2px; }
        .app-header p { font-size: 12px; color: rgba(255,255,255,.55); margin: 0; }

        .content-wrap { padding: 18px 16px 0; max-width: 640px; margin: 0 auto; }
        @media (min-width: 640px) and (max-width: 1023.98px) { .content-wrap { max-width: 760px; } }
        @media (min-width: 1024px) { .content-wrap { max-width: 1100px; padding-top: 28px; } }

        .reg-row {
            background: #fff; border: 1px solid #d5e6f1; border-radius: 12px;
            padding: 14px 16px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            flex-wrap: wrap;
        }
        .reg-name { font-size: 14px; font-weight: 600; color: #16324a; }
        .reg-meta { font-size: 11.5px; color: #5a7185; margin-top: 2px; }
        .reg-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .badge {
            font-size: 10.5px; font-weight: 700; padding: 3px 10px;
            border-radius: 999px; white-space: nowrap;
        }
        .badge-synced { background: rgba(34,197,94,.15); color: #15803D; }
        .badge-queued { background: rgba(245,158,11,.15); color: #92400E; }
        .badge-error  { background: rgba(239,68,68,.15); color: #B91C1C; }

        .error-msg { font-size: 11px; color: #B91C1C; margin-top: 4px; }

        .btn-sm {
            font-size: 11.5px; font-weight: 600; padding: 5px 10px; border-radius: 7px;
            border: 1px solid #d5e6f1; background: #f8fcff; cursor: pointer; color: #16324a;
        }
        .btn-sm:hover { background: #eaf6fd; }
        .btn-danger-sm { color: #B91C1C; border-color: rgba(239,68,68,.3); }

        .section-title {
            font-size: 11px; font-weight: 700; color: #006bb7; text-transform: uppercase;
            letter-spacing: .07em; margin: 20px 0 10px;
        }
        .empty-note { font-size: 12.5px; color: #7d93a5; padding: 16px 0; }
    </style>
</head>
<body>
    <div class="app-header">
        <h1>Recent Registrations</h1>
        <p>Synced, queued, and errored fisherfolk registrations</p>
    </div>

    <div class="content-wrap">
        <p class="section-title">On this device — not yet synced</p>
        <div id="local-queue-list"><p class="empty-note">Loading...</p></div>

        <p class="section-title">Recently saved (server)</p>
        <div id="synced-list">
            <?php if (empty($syncedRegistrations)): ?>
                <p class="empty-note">No registrations yet.</p>
            <?php else: ?>
                <?php foreach ($syncedRegistrations as $r): ?>
                    <div class="reg-row">
                        <div>
                            <div class="reg-name"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></div>
                            <div class="reg-meta"><?= htmlspecialchars($r['municipality'] . ($r['barangay'] ? ' — ' . $r['barangay'] : '')) ?> · <?= htmlspecialchars($r['created_at']) ?></div>
                        </div>
                        <span class="badge badge-synced">Synced</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="offline-db.js"></script>
    <script>

        function bdConfirm(message, options = {}) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'bd-confirm-overlay';
                overlay.innerHTML = `
                    <div class="bd-confirm-box">
                        <p class="bd-confirm-message"></p>
                        <div class="bd-confirm-actions">
                            <button type="button" class="bd-confirm-cancel">${options.cancelText || 'Cancel'}</button>
                            <button type="button" class="bd-confirm-ok">${options.confirmText || 'Confirm'}</button>
                        </div>
                    </div>
                `;
                overlay.querySelector('.bd-confirm-message').textContent = message;
                document.body.appendChild(overlay);
                requestAnimationFrame(() => overlay.classList.add('bd-confirm-visible'));
                function cleanup(result) {
                    overlay.classList.remove('bd-confirm-visible');
                    setTimeout(() => overlay.remove(), 150);
                    resolve(result);
                }
                overlay.querySelector('.bd-confirm-ok').addEventListener('click', () => cleanup(true));
                overlay.querySelector('.bd-confirm-cancel').addEventListener('click', () => cleanup(false));
                overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(false); });
                document.addEventListener('keydown', function escHandler(e) {
                    if (e.key === 'Escape') { cleanup(false); document.removeEventListener('keydown', escHandler); }
                });
            });
        }
        (function injectConfirmStyles() {
            const style = document.createElement('style');
            style.textContent = `
                .bd-confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999; opacity: 0; transition: opacity 0.15s ease; }
                .bd-confirm-overlay.bd-confirm-visible { opacity: 1; }
                .bd-confirm-box { background: #fff; border-radius: 10px; padding: 1.5rem; max-width: 340px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.15s ease; }
                .bd-confirm-overlay.bd-confirm-visible .bd-confirm-box { transform: scale(1); }
                .bd-confirm-message { margin: 0 0 1.25rem; font-size: 15px; color: #1a2b22; line-height: 1.4; }
                .bd-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }
                .bd-confirm-actions button { border: none; border-radius: 6px; padding: 8px 16px; font-size: 14px; cursor: pointer; font-weight: 500; }
                .bd-confirm-cancel { background: #eee; color: #333; }
                .bd-confirm-ok { background: #0f6e56; color: #fff; }
            `;
            document.head.appendChild(style);
        })();

        async function loadLocalQueue() {
            const container = document.getElementById('local-queue-list');
            let items;
            try {
                items = await bdGetAll('registration_queue');
            } catch (e) {
                container.innerHTML = '<p class="empty-note">Could not read local queue.</p>';
                return;
            }

            if (!items || items.length === 0) {
                container.innerHTML = '<p class="empty-note">Nothing queued on this device.</p>';
                return;
            }


            items.sort((a, b) => new Date(b.queued_at) - new Date(a.queued_at));

            container.innerHTML = items.map(item => {
                const p = item.payload || {};
                const name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim() || 'Unnamed registration';
                const isError = item.status === 'error';
                const badge = isError
                    ? '<span class="badge badge-error">Error</span>'
                    : '<span class="badge badge-queued">Queued — waiting to sync</span>';
                const errorLine = isError
                    ? `<div class="error-msg">${escapeHtml(item.error_message || 'Unknown error')}</div>`
                    : '';
                const actions = isError
                    ? `<button class="btn-sm" onclick="retryItem(${item.local_id})">Retry</button>
                       <button class="btn-sm btn-danger-sm" onclick="discardItem(${item.local_id})">Discard</button>`
                    : '';

                return `<div class="reg-row">
                    <div>
                        <div class="reg-name">${escapeHtml(name)}</div>
                        <div class="reg-meta">${escapeHtml(p.municipality || '')} · queued ${new Date(item.queued_at).toLocaleString()}</div>
                        ${errorLine}
                    </div>
                    <div class="reg-actions">${badge}${actions}</div>
                </div>`;
            }).join('');
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        async function retryItem(localId) {
            const items = await bdGetAll('registration_queue');
            const item = items.find(i => i.local_id === localId);
            if (!item) return;

            try {
                const res = await fetch('api_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(item.payload),
                });
                const data = await res.json();
                if (data.success) {
                    await bdDelete('registration_queue', localId);
                } else {
                    item.status = 'error';
                    item.error_message = data.message || 'Server rejected this registration.';
                    await bdPut('registration_queue', item);
                }
            } catch (e) {
                alert('Still offline — try again once you have a connection.');
                return;
            }
            loadLocalQueue();
        }

        async function discardItem(localId) {
            const confirmed = await bdConfirm('Discard this registration permanently? This cannot be undone.', { confirmText: 'Discard', cancelText: 'Keep it' });
            if (!confirmed) return;
            await bdDelete('registration_queue', localId);
            loadLocalQueue();
        }

        loadLocalQueue();
    </script>
</body>
</html>