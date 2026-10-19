<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
requireRole(MAO_ROLES);
$user = getCurrentUser();

$message  = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'report') {
        $violation_id     = (int)($_POST['violation_id'] ?? 0);
        $description      = trim($_POST['description'] ?? '');
        $location_name    = trim($_POST['location_name'] ?? '');

        $municipality     = $mun_scope ?? trim($_POST['municipality'] ?? '');
        $reported_by_name = trim($_POST['reported_by_name'] ?? $user['name']);

        $latitude         = ($_POST['latitude']  ?? '') !== '' ? floatval($_POST['latitude'])  : null;
        $longitude        = ($_POST['longitude'] ?? '') !== '' ? floatval($_POST['longitude']) : null;


        $violation_ok = false;
        if ($violation_id > 0) {
            $vc = $conn->prepare("SELECT id FROM violation_catalog WHERE id = ? AND active = 1 AND detection = 'officer'");
            $vc->bind_param('i', $violation_id);
            $vc->execute();
            $violation_ok = (bool)$vc->get_result()->fetch_assoc();
            $vc->close();
        }

        $photo_path = null;
        if (!empty($_FILES['photo']['name'])) {
            $upload_dir = 'uploads/incidents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext   = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $fname = uniqid('inc_') . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $fname)) {
                $photo_path = $upload_dir . $fname;
            }
        }

        if ($violation_ok && $municipality) {
            $stmt = $conn->prepare("INSERT INTO incidents (violation_id, description, location_name, municipality, reported_by_name, reported_by_user, photo_path, latitude, longitude) VALUES (?,?,?,?,?,?,?,?,?)");
            $uid = (int)$user['id'];
            $stmt->bind_param(
                'issssisdd',
                $violation_id, $description, $location_name, $municipality,
                $reported_by_name, $uid, $photo_path, $latitude, $longitude
            );
            if ($stmt->execute()) {
                $message  = "Incident report submitted successfully.";
                $msg_type = 'success';
            } else {
                $message  = "Error: " . $conn->error;
                $msg_type = 'error';
            }
        } else {
            $message  = "Please fill in all required fields.";
            $msg_type = 'error';
        }
    }

    if ($_POST['action'] === 'update_status') {
        $iid     = (int)$_POST['incident_id'];
        $status  = $_POST['status'];
        $allowed = ['Pending', 'Under Investigation', 'Resolved'];
        if (in_array($status, $allowed)) {
            $conn->query("UPDATE incidents SET status='$status' WHERE id=$iid");
            $message  = "Incident status updated.";
            $msg_type = 'success';
        }
    }
}

$mun_scope     = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

$violation_groups = [];
$vq = $conn->query("SELECT id, category_code, category_name, name FROM violation_catalog
                    WHERE active = 1 AND detection = 'officer' ORDER BY sort_order, id");
while ($vr = $vq->fetch_assoc()) {
    $violation_groups[$vr['category_name']][] = $vr;
}

$counts = [];
foreach (['Pending', 'Under Investigation', 'Resolved'] as $st) {
    $sw          = $mun_scope ? " AND municipality='" . $conn->real_escape_string($mun_scope) . "'" : '';
    $r           = $conn->query("SELECT COUNT(*) as c FROM incidents WHERE status='$st'$sw");
    $counts[$st] = $r->fetch_assoc()['c'];
}


$incWaters = json_decode((string)@file_get_contents(__DIR__ . '/includes/waters.json'), true) ?: ['features' => []];
pageHead('Incident Reports');
?>

<link rel="stylesheet" href="https://cdn.datatables.net/2.3.7/css/dataTables.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.7/js/dataTables.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    .dt-container,.dt-container table,.dt-container input,.dt-container select{font-family:inherit !important;font-size:inherit !important;}
    .dt-search input,.dt-length select{border:1.5px solid #d5e6f1 !important;border-radius:9px !important;padding:6px 11px !important;outline:none !important;background:rgba(255,255,255,.85) !important;transition:border .2s;}
    .dt-search input:focus{border-color:#006bb7 !important;}
    .dt-search label,.dt-length label,.dt-info{font-size:12px;font-weight:600;color:#5a7185;}
    .dt-paging .dt-paging-button{border-radius:7px !important;padding:4px 10px !important;border:1px solid transparent !important;}
    .dt-paging .dt-paging-button.current{background:linear-gradient(135deg,#006bb7,#0a2f4f) !important;color:#fff !important;border:none !important;}
    .dt-paging .dt-paging-button:hover:not(.current){background:rgba(0,107,183,.08) !important;color:#006bb7 !important;border-color:rgba(0,107,183,.2) !important;}
    table.dataTable thead th{background:rgba(234,246,253,.9) !important;color:#5a7185 !important;font-size:10.5px !important;font-weight:700 !important;text-transform:uppercase !important;letter-spacing:.08em !important;padding:10px 14px !important;border-bottom:1.5px solid #d5e6f1 !important;}
    table.dataTable tbody td{padding:11px 14px !important;border-bottom:1px solid rgba(213,230,241,.45) !important;color:#16324a !important;vertical-align:middle !important;}
    table.dataTable tbody tr:hover td{background:rgba(0,107,183,.03) !important;}
    table.dataTable tbody tr:last-child td{border-bottom:none !important;}
    table.dataTable{border-collapse:collapse !important;width:100% !important;}
    tr.highlight-unread td{background:rgba(0,107,183,.06) !important;}
    @keyframes slideUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
    @keyframes slideDown{from{opacity:1;transform:translateY(0);}to{opacity:0;transform:translateY(8px);}}


    @keyframes riseIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    .rise-in { opacity:0; animation: riseIn .5s cubic-bezier(.16,1,.3,1) forwards; }
    .rise-d1 { animation-delay: .03s; } .rise-d2 { animation-delay: .08s; }
    .rise-d3 { animation-delay: .13s; } .rise-d4 { animation-delay: .18s; }


    .stat-card {
        background: rgba(255,255,255,.94); border: 1px solid rgba(213,230,241,.55);
        border-radius: 16px; padding: 18px 20px; position: relative; overflow: hidden;
        transition: transform .2s cubic-bezier(.16,1,.3,1), box-shadow .2s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(10,47,79,.1); }
    .stat-card .accent-bar { position:absolute; top:0; left:0; right:0; height:3px; border-radius:16px 16px 0 0; }
    .stat-card .stat-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center; margin-bottom: 12px;
    }
    .stat-card .stat-value { font-size: 27px; font-weight: 800; color: #16324a; line-height: 1; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: 11px; font-weight: 700; color: #5a7185; text-transform: uppercase; letter-spacing: .08em; margin-top: 3px; }
    .card { transition: box-shadow .2s; }
    .card:hover { box-shadow: 0 8px 24px rgba(10,47,79,.06); }

    #inc-map { height:250px; width:100%; border-radius:10px; border:1.5px solid #d5e6f1; position:relative; z-index:0; background:#eaf6fd; }
    .inc-pin { position:relative; width:30px; height:40px; }
    .inc-pin svg { position:absolute; left:0; top:0; filter:drop-shadow(0 2px 3px rgba(0,0,0,.35)); }
    .inc-pin-ring { position:absolute; left:3px; top:25px; width:24px; height:24px; border-radius:50%; border:2px solid #dc2626; opacity:0; animation:incPing 1.8s ease-out infinite; }
    @keyframes incPing { 0% { transform:scale(.4); opacity:.9; } 100% { transform:scale(1.8); opacity:0; } }
    .inc-map-btn { background:#eaf6fd; color:#054b7e; border:1.5px solid transparent; border-radius:8px; padding:5px 11px; font-size:12px; font-weight:700; cursor:pointer; }
    .inc-map-btn:hover { background:#d9eefa; }
</style>

<body>
    <?php sidebar('incidents'); ?>
    <main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;" class="rise-in">
            <div>
                <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Incident Reports</h1>
                <p style="font-size:13px;color:#5a7185;margin:0;">Log and manage coastal violation and incident records</p>
            </div>
            <button onclick="openModal('reportModal')" class="btn-primary" style="display:flex;align-items:center;gap:8px;">
                <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                File Incident Report
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert-<?= $msg_type ?>" style="margin-bottom:20px;"><?= $message ?></div>
        <?php endif; ?>


        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:28px;">
            <div class="stat-card rise-in rise-d1">
                <div class="accent-bar" style="background:linear-gradient(90deg,#EAB308,#CA8A04);"></div>
                <div class="stat-icon" style="background:rgba(234,179,8,.1);">
                    <svg width="18" height="18" fill="none" stroke="#EAB308" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="stat-value" id="count-pending" data-count="<?= (int)$counts['Pending'] ?>">0</p>
                <p class="stat-label">Pending</p>
            </div>
            <div class="stat-card rise-in rise-d2">
                <div class="accent-bar" style="background:linear-gradient(90deg,#0a86c8,#006bb7);"></div>
                <div class="stat-icon" style="background:rgba(10,134,200,.1);">
                    <svg width="18" height="18" fill="none" stroke="#0a86c8" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <p class="stat-value" id="count-investigating" data-count="<?= (int)$counts['Under Investigation'] ?>">0</p>
                <p class="stat-label">Under Investigation</p>
            </div>
            <div class="stat-card rise-in rise-d3">
                <div class="accent-bar" style="background:linear-gradient(90deg,#10B981,#059669);"></div>
                <div class="stat-icon" style="background:rgba(16,185,129,.1);">
                    <svg width="18" height="18" fill="none" stroke="#10B981" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="stat-value" id="count-resolved" data-count="<?= (int)$counts['Resolved'] ?>">0</p>
                <p class="stat-label">Resolved</p>
            </div>
        </div>


        <div class="card rise-in rise-d4" style="overflow:hidden;">
            <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;">Incident Records</h2>
                    <span style="font-size:12px;color:#5a7185;" id="record-count">0 records found</span>
                </div>
            </div>
            <div style="padding:16px 20px;overflow-x:auto;">
                <table id="incidents-table" class="display" style="width:100%;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Violation Type</th>
                            <th>Location</th>
                            <th>Municipality</th>
                            <th>Reported By</th>
                            <th>Date Reported</th>
                            <th>Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </main>


    <div id="reportModal" class="modal-overlay" style="display:none;">
        <div class="modal-box">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;">File Incident Report</h2>
                <button onclick="closeModal('reportModal')" class="tooltip-container" data-tooltip="Close modal (ESC)" aria-label="Close report form" style="background:none;border:none;cursor:pointer;color:#7d93a5;padding:4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <form method="POST" action="incidents.php" enctype="multipart/form-data" style="padding:24px;" data-confirm="Submit this incident report?">
                <input type="hidden" name="action" value="report">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="form-label">Violation Type *</label>
                        <select name="violation_id" class="form-input" required>
                            <option value="">Select violation...</option>
                            <?php foreach ($violation_groups as $gname => $items): ?>
                                <optgroup label="<?= htmlspecialchars($gname) ?>">
                                    <?php foreach ($items as $v): ?>
                                        <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Municipality *</label>
                        <select name="municipality" class="form-input" required <?= $mun_scope ? 'disabled' : '' ?>>
                            <?php if ($mun_scope): ?>
                                <option value="<?= htmlspecialchars($mun_scope) ?>" selected><?= htmlspecialchars($mun_scope) ?></option>
                            <?php else: ?>
                                <option value="">Select...</option>
                                <?php foreach (['San Jose de Buenavista', 'Hamtic', 'Tobias Fornier', 'Anini-y'] as $m): ?>
                                    <option value="<?= $m ?>"><?= $m ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($mun_scope): ?><input type="hidden" name="municipality" value="<?= htmlspecialchars($mun_scope) ?>"><?php endif; ?>
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Location Name</label>
                    <input type="text" name="location_name" class="form-input" placeholder="Barangay, sitio, or landmark">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:8px;">
                    <div>
                        <label class="form-label">Latitude</label>
                        <input type="number" step="0.0000001" name="latitude" id="lat" class="form-input" placeholder="e.g. 10.7566">
                    </div>
                    <div>
                        <label class="form-label">Longitude</label>
                        <input type="number" step="0.0000001" name="longitude" id="lng" class="form-input" placeholder="e.g. 121.9345">
                    </div>
                </div>
                <button type="button" onclick="getLocation()" class="btn-secondary btn-sm" style="margin-bottom:14px;display:flex;align-items:center;gap:6px;">
                    <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Use My Current Location
                </button>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-input" rows="3" placeholder="Describe what was observed..."></textarea>
                </div>
                <div style="margin-bottom:14px;">
                    <label class="form-label">Reported By</label>
                    <input type="text" name="reported_by_name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>">
                </div>
                <div style="margin-bottom:20px;">
                    <label class="form-label">Photo Evidence (optional)</label>
                    <input type="file" name="photo" class="form-input" accept="image/*">
                </div>
                <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:12px;border-top:1px solid rgba(213,230,241,.5);">
                    <button type="button" onclick="closeModal('reportModal')" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-primary">Submit Report</button>
                </div>
            </form>
        </div>
    </div>


    <div id="viewIncidentModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:600px;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="inc-title">Incident Report</h2>
                <button onclick="closeModal('viewIncidentModal')" class="tooltip-container" data-tooltip="Close modal (ESC)" aria-label="Close view modal" style="background:none;border:none;cursor:pointer;color:#7d93a5;padding:4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div style="padding:24px;" id="inc-body"></div>
            <div style="padding:24px;border-top:1px solid rgba(213,230,241,.5);">
                <form method="POST" action="incidents.php" style="display:flex;gap:12px;align-items:flex-end;" data-confirm="Update this incident's status?">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="incident_id" id="inc-id-input">
                    <div style="flex:1;">
                        <label class="form-label">Update Status</label>
                        <select name="status" id="inc-status-select" class="form-input">
                            <option value="Pending">Pending</option>
                            <option value="Under Investigation">Under Investigation</option>
                            <option value="Resolved">Resolved</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary" style="height:38px;">Update</button>
                </form>
            </div>
        </div>
    </div>

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

        function attachConfirmToForms(selector = 'form[data-confirm]') {
            document.querySelectorAll(selector).forEach((form) => {
                if (form.dataset.bdConfirmAttached) return;
                form.dataset.bdConfirmAttached = 'true';
                form.addEventListener('submit', async (e) => {
                    if (form.dataset.bdConfirmed === 'true') return;
                    e.preventDefault();
                    const message = form.dataset.confirm || 'Are you sure you want to save this?';
                    const confirmed = await bdConfirm(message);
                    if (confirmed) {
                        form.dataset.bdConfirmed = 'true';
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    }
                });
            });
        }

        (function injectConfirmStyles() {
            if (document.getElementById('bd-confirm-styles')) return;
            const style = document.createElement('style');
            style.id = 'bd-confirm-styles';
            style.textContent = `
                .bd-confirm-overlay {
                    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
                    display: flex; align-items: center; justify-content: center;
                    z-index: 9999; opacity: 0; transition: opacity 0.15s ease;
                }
                .bd-confirm-overlay.bd-confirm-visible { opacity: 1; }
                .bd-confirm-box {
                    background: #fff; border-radius: 10px; padding: 1.5rem;
                    max-width: 340px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    transform: scale(0.95); transition: transform 0.15s ease;
                }
                .bd-confirm-overlay.bd-confirm-visible .bd-confirm-box { transform: scale(1); }
                .bd-confirm-message { margin: 0 0 1.25rem; font-size: 15px; color: #1a2b22; line-height: 1.4; }
                .bd-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }
                .bd-confirm-actions button {
                    border: none; border-radius: 6px; padding: 8px 16px;
                    font-size: 14px; cursor: pointer; font-weight: 500;
                }
                .bd-confirm-cancel { background: #eee; color: #333; }
                .bd-confirm-ok { background: #0f6e56; color: #fff; }
            `;
            document.head.appendChild(style);
        })();

        attachConfirmToForms();

        let incidentsTable;
        let unreadIncidentIds = [];
        let lastIncidentTotal = null;
        let pollInterval      = null;


        document.querySelectorAll('.stat-value[data-count]').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const dur = 600, start = performance.now();
            function tick(now) {
                const p = Math.min(1, (now - start) / dur);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.round(eased * target).toLocaleString();
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        });


        function loadUnreadIds() {
            return fetch('api_incident_reads.php?action=unread_ids')
                .then(r => r.json())
                .then(data => { unreadIncidentIds = data.unread_ids || []; return unreadIncidentIds; });
        }

        function markIncidentAsRead(incidentId) {
            if (!unreadIncidentIds.includes(incidentId)) return;
            fetch('api_incident_reads.php?action=mark_read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'incident_id=' + incidentId
            }).then(r => r.json()).then(() => {
                unreadIncidentIds = unreadIncidentIds.filter(id => id !== incidentId);
                refreshUnreadHighlights();
                if (typeof updateIncidentBadge === 'function') updateIncidentBadge();
            });
        }

        function refreshUnreadHighlights() {
            if (!incidentsTable) return;
            incidentsTable.rows().nodes().to$().removeClass('highlight-unread');
            incidentsTable.rows().every(function () {
                const d = this.data();
                if (d && unreadIncidentIds.includes(d.id)) $(this.node()).addClass('highlight-unread');
            });
        }


        function showToast(msg, type = 'warning') {
            const palettes = {
                success: { bg: '#DCFCE7', color: '#15803D', border: '#86EFAC' },
                warning: { bg: '#FEF3C7', color: '#92400E', border: '#FCD34D' },
                error:   { bg: '#FEE2E2', color: '#B91C1C', border: '#FCA5A5' }
            };
            const c     = palettes[type] || palettes.warning;
            const toast = document.createElement('div');
            toast.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;background:${c.bg};color:${c.color};border:1.5px solid ${c.border};padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.12);display:flex;align-items:center;gap:8px;animation:slideUp .3s ease forwards;`;
            toast.innerHTML = `<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>${msg}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'slideDown .3s ease forwards';
                setTimeout(() => toast.remove(), 320);
            }, 4500);
        }


        function flashTabTitle(flashMsg, original) {
            let on = true;
            const iv = setInterval(() => { document.title = on ? flashMsg : original; on = !on; }, 800);
            setTimeout(() => { clearInterval(iv); document.title = original; }, 12000);
            window.addEventListener('focus', () => { clearInterval(iv); document.title = original; }, { once: true });
        }


        function updateStatCards(data) {
            if (data.count_pending       != null) document.getElementById('count-pending').textContent       = data.count_pending;
            if (data.count_investigating != null) document.getElementById('count-investigating').textContent = data.count_investigating;
            if (data.count_resolved      != null) document.getElementById('count-resolved').textContent      = data.count_resolved;
        }


        async function poll() {
            try {
                const res  = await fetch('api_incidents_dt.php?poll=1');
                const data = await res.json();

                updateStatCards(data);

                if (typeof updateIncidentBadge === 'function') updateIncidentBadge();

                const newTotal = data.total ?? 0;

                if (lastIncidentTotal === null) {
                    lastIncidentTotal = newTotal;
                    return;
                }

                if (newTotal > lastIncidentTotal) {
                    lastIncidentTotal = newTotal;
                    if (incidentsTable) incidentsTable.ajax.reload(null, false);
                    await loadUnreadIds();
                    flashTabTitle('🔴 New Incident!', 'Incident Reports');
                    showToast('New incident report filed.', 'warning');
                }
            } catch (e) {
                console.warn('Poll error:', e);
            }
        }

        function startPolling() {
            clearInterval(pollInterval);
            pollInterval = setInterval(poll, 5000);
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(pollInterval);
            } else {
                poll();
                startPolling();
            }
        });


        $(document).ready(function () {
            loadUnreadIds().then(() => {
                incidentsTable = $('#incidents-table').DataTable({
                    serverSide: true,
                    processing: true,
                    ajax: { url: 'api_incidents_dt.php', type: 'GET' },
                    columns: [
                        {
                            data: null,
                            render: function (data, type, row, meta) {
                                return `<span style="color:#5a7185;font-family:'JetBrains Mono',monospace;font-size:11px;">${meta.settings._iDisplayStart + meta.row + 1}</span>`;
                            },
                            searchable: false, orderable: false, width: '40px'
                        },
                        {
                            data: 'violation_type',
                            render: function (data, type, row) {
                                const preview = row.description
                                    ? (row.description.length > 60 ? row.description.substring(0, 60) + '…' : row.description)
                                    : null;
                                const desc = preview ? `<p style="font-size:11px;color:#7d93a5;margin:2px 0 0;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${preview}</p>` : '';
                                return `<p style="font-weight:600;color:#16324a;margin:0;font-size:12px;">${data}</p>${desc}`;
                            }
                        },
                        { data: 'location_name',    render: d => `<span style="color:#5a7185;font-size:12px;">${d ?? ''}</span>` },
                        { data: 'municipality',     render: d => `<span style="color:#5a7185;font-size:12px;">${d ?? ''}</span>` },
                        { data: 'reported_by_name', render: d => `<span style="color:#5a7185;font-size:12px;">${d ?? ''}</span>` },
                        { data: 'reported_at',      render: d => `<span style="color:#7d93a5;font-family:'JetBrains Mono',monospace;font-size:11px;">${d ?? ''}</span>` },
                        { data: 'status',           render: (d, t, row) => `<span class="badge ${row.status_badge}">${d}</span>` },
                        {
                            data: null,
                            render: function (data, type, row) {
                                const safe = JSON.stringify(row).replace(/"/g, '&quot;');
                                return `<button class="btn-secondary btn-sm" data-row="${safe}" onclick="markAndViewIncident(this)">View</button>`;
                            },
                            orderable: false, searchable: false
                        }
                    ],
                    pageLength: 15,
                    lengthMenu: [10, 15, 25, 50, 100],
                    order: [[5, 'desc']],
                    language: {
                        search: 'Search:', lengthMenu: 'Show _MENU_ entries',
                        info: 'Showing _START_ to _END_ of _TOTAL_ incidents',
                        infoEmpty: 'No records found', infoFiltered: '(filtered from _MAX_ total)',
                        processing: 'Loading...',
                        paginate: { first: '«', last: '»', next: '›', previous: '‹' }
                    },
                    columnDefs: [
                        { targets: 0, width: '30px' },
                        { targets: 1, width: '200px' },
                        { targets: 7, width: '80px', orderable: false }
                    ],
                    drawCallback: function (settings) {
                        const total = settings.fnRecordsDisplay();
                        document.getElementById('record-count').textContent =
                            total === 0 ? '0 records found' : total + ' record' + (total !== 1 ? 's' : '') + ' found';
                        refreshUnreadHighlights();
                    }
                });

                startPolling();
            });
        });


        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
        });
        function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape')
                document.querySelectorAll('.modal-overlay[style*="flex"]').forEach(m => m.style.display = 'none');
        });

        function getLocation() {
            if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
            navigator.geolocation.getCurrentPosition(
                pos => {
                    document.getElementById('lat').value = pos.coords.latitude.toFixed(7);
                    document.getElementById('lng').value = pos.coords.longitude.toFixed(7);
                },
                () => alert('Could not get location. Please enter coordinates manually.')
            );
        }

        function markAndViewIncident(btn) {
            const row = JSON.parse(btn.getAttribute('data-row'));
            markIncidentAsRead(row.id);
            viewIncident(row);
        }

        function viewIncident(r) {
            document.getElementById('inc-title').textContent   = r.violation_type;
            document.getElementById('inc-id-input').value      = r.id;
            document.getElementById('inc-status-select').value = r.status;

            const badgeClass = { 'Pending': 'badge-pending', 'Under Investigation': 'badge-investigating', 'Resolved': 'badge-resolved' }[r.status] || 'badge-pending';

            const photoHtml = r.photo_path
                ? `<div style="grid-column:1/3;margin-top:16px;"><p class="form-label">Photo Evidence</p><img src="${r.photo_path}" style="border-radius:8px;max-height:192px;object-fit:cover;width:100%;" alt="Incident photo"></div>`
                : '';

            const hasPin = incValidCoords(r.latitude, r.longitude);
            const coordHtml = hasPin
                ? `${parseFloat(r.latitude).toFixed(6)}, ${parseFloat(r.longitude).toFixed(6)}`
                : 'Not recorded';
            const mapHtml = hasPin
                ? `<div style="grid-column:1/3;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                            <p class="form-label" style="margin:0;">Where it was reported</p>
                            <span style="display:flex;gap:6px;">
                                <button type="button" class="inc-map-btn" onclick="incZoomPin()">Zoom to pin</button>
                                <button type="button" class="inc-map-btn" onclick="incZoomBoundary()">Show boundary</button>
                            </span>
                        </div>
                        <div id="inc-map"></div>
                   </div>`
                : '';

            document.getElementById('inc-body').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px 32px;font-size:13px;">
                    <div><p class="form-label">Status</p><span class="badge ${badgeClass}">${r.status}</span></div>
                    <div><p class="form-label">Municipality</p><p style="font-weight:500;margin:0;">${r.municipality}</p></div>
                    <div><p class="form-label">Location</p><p style="margin:0;">${r.location_name || 'N/A'}</p></div>
                    <div><p class="form-label">GPS Coordinates</p><p style="margin:0;">${coordHtml}</p></div>
                    <div><p class="form-label">Reported By</p><p style="margin:0;">${r.reported_by_name || 'N/A'}</p></div>
                    <div><p class="form-label">Date Reported</p><p style="margin:0;">${r.reported_at || 'N/A'}</p></div>
                    ${mapHtml}
                    <div style="grid-column:1/3;"><p class="form-label">Description</p><p style="color:#16324a;margin:0;">${r.description || 'No description provided.'}</p></div>
                    ${photoHtml}
                </div>`;
            openModal('viewIncidentModal');
            if (hasPin) renderIncidentMap(r);
        }




        const INC_WATERS = <?= json_encode($incWaters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        let incMap = null, incHome = null, incPinLatLng = null;

        function incValidCoords(lat, lng) {
            const a = parseFloat(lat), b = parseFloat(lng);
            return isFinite(a) && isFinite(b) && Math.abs(a) <= 90 && Math.abs(b) <= 180 && !(a === 0 && b === 0);
        }

        function renderIncidentMap(r) {
            if (incMap) { incMap.remove(); incMap = null; }
            incHome = null;
            const el = document.getElementById('inc-map');
            if (!el) return;
            if (typeof L === 'undefined') {
                el.style.cssText = 'display:flex;align-items:center;justify-content:center;font-size:12px;color:#5a7185;padding:12px;text-align:center;';
                el.textContent = 'The map could not load. Check the internet connection and open this report again.';
                return;
            }
            const lat = parseFloat(r.latitude), lng = parseFloat(r.longitude);
            incPinLatLng = [lat, lng];
            incMap = L.map(el, { zoomControl: true, attributionControl: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, subdomains: 'abc' }).addTo(incMap);
            L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap contributors').addTo(incMap);


            (INC_WATERS.features || []).forEach(function (f) {
                const mine = f.properties.name === r.municipality;
                const layer = L.geoJSON(f, {
                    style: mine
                        ? { color: '#006bb7', weight: 2, dashArray: '6 5', fillColor: '#006bb7', fillOpacity: 0.08 }
                        : { color: '#94a3b8', weight: 1, dashArray: '4 4', fillOpacity: 0 },
                    interactive: mine
                }).addTo(incMap);
                if (mine) { layer.bindTooltip(f.properties.name + ' — municipal waters', { sticky: true }); incHome = layer; }
            });


            const pinIcon = L.divIcon({
                className: '',
                iconSize: [30, 40], iconAnchor: [15, 40],
                html: '<div class="inc-pin"><div class="inc-pin-ring"></div>' +
                      '<svg width="30" height="40" viewBox="0 0 30 40"><path d="M15 0C6.7 0 0 6.5 0 14.6 0 25 15 40 15 40s15-15 15-25.4C30 6.5 23.3 0 15 0z" fill="#dc2626"/>' +
                      '<circle cx="15" cy="14.5" r="5.5" fill="#fff"/></svg></div>'
            });
            L.marker(incPinLatLng, { icon: pinIcon, keyboard: false })
                .addTo(incMap)
                .bindTooltip((document.getElementById('inc-title').textContent || 'Incident') + '<br>' + lat.toFixed(5) + ', ' + lng.toFixed(5), { direction: 'top', offset: [0, -38] });


            setTimeout(function () { if (!incMap) return; incMap.invalidateSize(); incZoomBoundary(); }, 60);
        }

        function incZoomBoundary() {
            if (!incMap) return;
            if (incHome) {
                const b = incHome.getBounds();
                incMap.fitBounds(L.latLngBounds(b.getSouthWest(), b.getNorthEast()).extend(incPinLatLng).pad(0.08));
            } else {
                incMap.setView(incPinLatLng, 14);
            }
        }
        function incZoomPin() { if (incMap) incMap.setView(incPinLatLng, 16); }
    </script>
</body>
</html>