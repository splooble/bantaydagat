<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
require_once 'includes/requirements.php';
requireRole(MAO_ROLES);
$user = getCurrentUser();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));


$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;

$message  = '';
$msg_type = '';


$conn->query("UPDATE permits SET status='Expired' WHERE expiry_date < CURDATE() AND status='Active'");


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';




    $valid_date = function ($d) { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; };

    $scoped_ff = function ($fid) use ($conn, $mun_scope) {
        $st = $conn->prepare("SELECT id, municipality, boat_ownership FROM fisherfolk WHERE id = ?");
        $st->bind_param('i', $fid); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close();
        return ($row && (!$mun_scope || $row['municipality'] === $mun_scope)) ? $row : null;
    };




    if ($action === 'add_document') {
        $fid    = (int)($_POST['fisherfolk_id'] ?? 0);
        $dtype  = trim($_POST['permit_type'] ?? '');
        $dnum   = trim($_POST['permit_number'] ?? '');
        $issue  = trim($_POST['issue_date'] ?? '') ?: date('Y-m-d');
        $expiry = trim($_POST['expiry_date'] ?? '') ?: null;
        $msg_type = 'error';
        $rec   = $scoped_ff($fid);
        if (!$rec) {
            $message = 'Record not found, or it is outside your municipality.';
        } elseif (docKind($dtype) === null || $dnum === '') {
            $message = 'Choose the document and enter its number.';
        } elseif (!$valid_date($issue) || ($expiry !== null && !$valid_date($expiry))) {
            $message = 'Please enter valid dates.';
        } else {



            $st = $conn->prepare("SELECT 1 FROM registration_documents WHERE fisherfolk_id = ? AND doc_type = ? AND doc_number = ?
                                  UNION SELECT 1 FROM permits WHERE fisherfolk_id = ? AND permit_type = ? AND permit_number = ?");
            $st->bind_param('ississ', $fid, $dtype, $dnum, $fid, $dtype, $dnum); $st->execute();
            $repeat = (bool)$st->get_result()->fetch_row(); $st->close();
            if ($repeat) {
                $message = htmlspecialchars($dtype . ' No. ' . $dnum) . ' is already on this record.';
            } elseif (docKind($dtype) === 'support') {



                $ds = $conn->prepare("INSERT INTO registration_documents (fisherfolk_id,doc_type,doc_number,issue_date,expiry_date,issued_by) VALUES (?,?,?,?,?,?)
                                      ON DUPLICATE KEY UPDATE doc_number = VALUES(doc_number), issue_date = VALUES(issue_date), expiry_date = VALUES(expiry_date), issued_by = VALUES(issued_by)");
                $ds->bind_param('issssi', $fid, $dtype, $dnum, $issue, $expiry, $user['id']);
                $okDoc = $ds->execute(); $replaced = $ds->affected_rows === 2; $ds->close();
                $message  = $okDoc ? ($replaced ? 'Document saved. It replaced the older ' . htmlspecialchars($dtype) . ' on this record.' : 'Document saved.') : 'The document could not be saved.';
                $msg_type = $okDoc ? 'success' : 'error';
            } else {
                $st = $conn->prepare("SELECT id FROM permits WHERE permit_type = ? AND permit_number = ? LIMIT 1");
                $st->bind_param('ss', $dtype, $dnum); $st->execute();
                $dupe = $st->get_result()->fetch_assoc(); $st->close();
                if ($dupe) {
                    $message = htmlspecialchars($dtype . ' No. ' . $dnum) . ' is already registered to another record.';
                } else {
                    $boat_id = null;
                    if ($dtype === 'BoatR') {
                        $st = $conn->prepare("SELECT id FROM boats WHERE fisherfolk_id = ? ORDER BY id LIMIT 1");
                        $st->bind_param('i', $fid); $st->execute();
                        $b = $st->get_result()->fetch_assoc(); $st->close();
                        $boat_id = $b['id'] ?? null;
                    }
                    $pstatus = ($expiry === null || $expiry >= date('Y-m-d')) ? 'Active' : 'Expired';
                    $area = '';
                    $ps = $conn->prepare("INSERT INTO permits (fisherfolk_id,boat_id,permit_number,permit_type,fishing_area,issue_date,expiry_date,status,issued_by) VALUES (?,?,?,?,?,?,?,?,?)");
                    $ps->bind_param('iissssssi', $fid, $boat_id, $dnum, $dtype, $area, $issue, $expiry, $pstatus, $user['id']);
                    $ps->execute(); $ps->close();
                    $message  = 'Document saved.';
                    $msg_type = 'success';
                }
            }
        }
    }



    if ($action === 'renew_permit') {
        $pid = (int)($_POST['permit_id'] ?? 0);
        $msg_type = 'error';
        $st = $conn->prepare("SELECT p.id, p.status, p.expiry_date, p.fisherfolk_id, p.permit_type, f.municipality FROM permits p JOIN fisherfolk f ON f.id = p.fisherfolk_id WHERE p.id = ?");
        $st->bind_param('i', $pid); $st->execute();
        $perm = $st->get_result()->fetch_assoc(); $st->close();
        if (!$perm || !$scoped_ff((int)$perm['fisherfolk_id'])) {
            $message = 'Permit not found, or it is outside your municipality.';
        } else {
            $today  = date('Y-m-d');
            $months = permitValidityMonths((string)$perm['municipality'], (string)$perm['permit_type']);
            $newExp = (new DateTime($today))->modify('+' . $months . ' months')->format('Y-m-d');
            $newSt  = $perm['status'] === 'Suspended' ? 'Suspended' : 'Active';
            $old    = $perm['expiry_date'];
            try {
                $conn->begin_transaction();
                $u = $conn->prepare("UPDATE permits SET issue_date = ?, expiry_date = ?, status = ?, renewed_at = NOW(), renewed_by = ? WHERE id = ?");
                $u->bind_param('sssii', $today, $newExp, $newSt, $user['id'], $pid); $u->execute(); $u->close();
                $l = $conn->prepare("INSERT INTO permit_renewals (permit_id, fisherfolk_id, old_expiry, new_issue, new_expiry, renewed_by) VALUES (?,?,?,?,?,?)");
                $l->bind_param('iisssi', $pid, $perm['fisherfolk_id'], $old, $today, $newExp, $user['id']); $l->execute(); $l->close();
                $conn->commit();
                $message  = htmlspecialchars($perm['permit_type']) . ' renewed until ' . $newExp . ($newSt === 'Suspended' ? ' (still suspended).' : '.');
                $msg_type = 'success';
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Could not renew the permit. Has migration_v3.sql been imported?';
            }
        }
    }


    if ($action === 'set_permit_status') {
        $pid    = (int)($_POST['permit_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $inc    = (int)($_POST['incident_id'] ?? 0);
        $msg_type = 'error';
        $st = $conn->prepare("SELECT id, status, expiry_date, fisherfolk_id FROM permits WHERE id = ?");
        $st->bind_param('i', $pid); $st->execute();
        $perm = $st->get_result()->fetch_assoc(); $st->close();
        if (!$perm || !$scoped_ff((int)$perm['fisherfolk_id'])) {
            $message = 'Permit not found, or it is outside your municipality.';
        } elseif (!in_array($status, ['Active', 'Suspended'], true)) {
            $message = 'Invalid status.';
        } elseif ($reason === '') {
            $message = 'Please enter a reason.';
        } else {
            $inc_ok = true;
            if ($inc) {
                $st = $conn->prepare("SELECT id FROM incidents WHERE id = ? AND fisherfolk_id = ?");
                $st->bind_param('ii', $inc, $perm['fisherfolk_id']); $st->execute();
                $inc_ok = (bool)$st->get_result()->fetch_assoc(); $st->close();
            }
            if (!$inc_ok) {
                $message = 'That incident number is not linked to this fisherfolk.';
            } else {

                $new = $status;
                if ($status === 'Active' && $perm['expiry_date'] !== null && $perm['expiry_date'] < date('Y-m-d')) $new = 'Expired';
                $inc_param = $inc ?: null; $old = $perm['status'];
                try {
                    $conn->begin_transaction();
                    $u = $conn->prepare("UPDATE permits SET status = ? WHERE id = ?");
                    $u->bind_param('si', $new, $pid); $u->execute(); $u->close();
                    $l = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, incident_id, changed_by) VALUES (?,?,?,?,?,?)");
                    $l->bind_param('isssii', $pid, $old, $new, $reason, $inc_param, $user['id']); $l->execute(); $l->close();
                    $conn->commit();
                    $message  = "Permit status updated to $new.";
                    $msg_type = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Could not update the permit. Has migration_permit_log.sql been run?';
                }
            }
        }
    }
}




$mun_filter = $mun_scope ?? trim($_GET['mun'] ?? '');

pageHead('Fisherfolk Registry');
?>


<link rel="stylesheet" href="https://cdn.datatables.net/2.3.7/css/dataTables.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.7/js/dataTables.min.js"></script>


<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500&display=swap" rel="stylesheet">
<script src="id_card.js"></script>

<style>

    .dt-container {
        font-family: 'Instrument Sans', sans-serif;
        font-size: 13.5px;
    }

    .dt-search input {
        border: 1.5px solid #d5e6f1 !important;
        border-radius: 9px !important;
        padding: 7px 12px !important;
        font-family: 'Instrument Sans', sans-serif !important;
        font-size: 13px !important;
        outline: none !important;
        background: rgba(255, 255, 255, .85) !important;
        transition: border .2s;
    }

    .dt-search input:focus {
        border-color: #006bb7 !important;
    }

    .dt-search label {
        font-size: 12px;
        font-weight: 600;
        color: #5a7185;
    }

    .dt-length select {
        border: 1.5px solid #d5e6f1 !important;
        border-radius: 9px !important;
        padding: 6px 10px !important;
        font-family: 'Instrument Sans', sans-serif !important;
        font-size: 13px !important;
        outline: none !important;
        background: rgba(255, 255, 255, .85) !important;
    }

    .dt-length label {
        font-size: 12px;
        font-weight: 600;
        color: #5a7185;
    }

    .dt-info {
        font-size: 12px;
        color: #5a7185;
    }

    .dt-paging .dt-paging-button {
        border-radius: 7px !important;
        font-size: 13px !important;
        padding: 4px 10px !important;
        font-family: 'Instrument Sans', sans-serif !important;
        border: 1px solid transparent !important;
    }

    .dt-paging .dt-paging-button.current {
        background: linear-gradient(135deg, #006bb7, #0a2f4f) !important;
        color: #fff !important;
        border: none !important;
    }

    .dt-paging .dt-paging-button:hover:not(.current) {
        background: rgba(0,107,183, .08) !important;
        color: #006bb7 !important;
        border-color: rgba(0,107,183, .2) !important;
    }

    table.dataTable thead th {
        background: rgba(234,246,253, .9) !important;
        color: #5a7185 !important;
        font-size: 10.5px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: .08em !important;
        padding: 10px 14px !important;
        border-bottom: 1.5px solid #d5e6f1 !important;
    }

    table.dataTable tbody td {
        padding: 11px 14px !important;
        border-bottom: 1px solid rgba(213,230,241, .45) !important;
        color: #16324a !important;
        vertical-align: middle !important;
    }

    table.dataTable tbody tr:hover td {
        background: rgba(0,107,183, .03) !important;
    }

    table.dataTable tbody tr:last-child td {
        border-bottom: none !important;
    }

    table.dataTable {
        border-collapse: collapse !important;
        width: 100% !important;
    }


    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
</style>

<body>
    <script>window.TF_CSRF = <?= json_encode($_SESSION['csrf']) ?>; window.CAN_TRANSFER = <?= json_encode(canHandleTransfers()) ?>;</script>
    <?php sidebar('fisherfolk'); ?>
    <main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;">
            <div>
                <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Fisherfolk Registry</h1>
                <p style="font-size:13px;color:#5a7185;margin:0;">Search, view, and manage fisherfolk profiles, boat registrations, and consolidated permits</p>
            </div>
            <a href="register.php" target="_blank" class="btn-primary tooltip-container" data-tooltip="Opens the field registration app in a new tab" aria-label="Register new fisherfolk" style="white-space:nowrap;display:inline-flex;align-items:center;gap:6px;padding:8px 16px;text-decoration:none;">
                <svg style="width:16px;height:16px;flex-shrink:0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Register Fisherfolk
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert-<?= $msg_type ?>" style="margin-bottom:20px;"><?= $message ?></div>
        <?php endif; ?>


        <div class="card" style="overflow:hidden;">
            <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;">Registered Fisherfolk</h2>
            </div>
            <div style="padding:16px 20px;">
                <table id="fisherfolk-table" class="display" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Fisherfolk</th>
                            <th>Fisherfolk Registry No.</th>
                            <th>Status</th>
                            <th>Contact</th>
                            <th>Permits</th>
                            <th>Registered</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </main>


    <div id="viewModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:620px;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="view-title">Fisherfolk Record</h2>
                <button onclick="closeModal('viewModal')" style="background:none;border:none;cursor:pointer;color:#7d93a5;" aria-label="Close">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="view-body" style="padding:24px;max-height:75vh;overflow-y:auto;">
                <div style="text-align:center;padding:32px 0;">
                    <div style="width:32px;height:32px;border:3px solid rgba(0,107,183,.2);border-top-color:#006bb7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                    <p style="font-size:13px;color:#5a7185;margin:0;">Loading...</p>
                </div>
            </div>
        </div>
    </div>


    <div id="permitsModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:700px;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <div>
                    <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="permits-title">Permits</h2>
                    <p style="font-size:12px;color:#5a7185;margin:4px 0 0;" id="permits-subtitle"></p>
                </div>
                <button onclick="closeModal('permitsModal')" class="tooltip-container" data-tooltip="Close modal (ESC)" aria-label="Close permits modal" style="background:none;border:none;cursor:pointer;color:#7d93a5;padding:4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="tf-box" style="padding:0 24px;"></div>
            <div id="permits-body" style="padding:0 24px 16px;max-height:50vh;overflow-y:auto;"></div>


            <div style="padding:16px 24px;background:rgba(234,246,253,.4);border-top:1px solid rgba(213,230,241,.5);">
                <p style="font-size:11px;font-weight:700;color:#006bb7;text-transform:uppercase;letter-spacing:.07em;margin:0 0 12px;">Add Document</p>
                <form method="POST" action="fisherfolk.php" id="add-permit-form" data-confirm="Save this document to the fisherfolk record?">
                    <input type="hidden" name="action" value="add_document">
                    <input type="hidden" name="fisherfolk_id" id="add-permit-ff-id">
                    <p id="add-doc-hint" style="font-size:12px;color:#5a7185;margin:0 0 10px;"></p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div><label class="form-label">Document *</label><select name="permit_type" id="add-doc-type" class="form-input" required></select></div>
                        <div><label class="form-label">Document No. *</label><input type="text" name="permit_number" class="form-input" required maxlength="60" placeholder="As printed on the document"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div><label class="form-label">Issue Date *</label><input type="date" name="issue_date" class="form-input" required value="<?= date('Y-m-d') ?>"></div>
                        <div><label class="form-label">Expiry Date (optional)</label><input type="date" name="expiry_date" class="form-input"></div>
                    </div>
                    <button type="submit" id="add-doc-btn" class="btn-primary btn-sm" aria-label="Save document">Save Document</button>
                </form>
            </div>
        </div>
    </div>


    <div id="transferModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:520px;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <div>
                    <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="transfer-title">Transfer</h2>
                    <p style="font-size:12px;color:#5a7185;margin:4px 0 0;">Move a fisherfolk to another municipality.</p>
                </div>
                <button onclick="closeModal('transferModal')" aria-label="Close" style="background:none;border:none;cursor:pointer;color:#7d93a5;padding:4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <div id="transfer-body" style="padding:20px 24px;"></div>
        </div>
    </div>


    <div id="qrModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:400px;max-height:92vh;display:flex;flex-direction:column;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <div>
                    <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="qr-title">Print ID</h2>
                    <p style="font-size:11px;color:#5a7185;margin:4px 0 0;" id="qr-enc-status"></p>
                </div>
                <button onclick="closeModal('qrModal')" aria-label="Close" style="background:none;border:none;cursor:pointer;color:#7d93a5;padding:4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div style="padding:20px 14px;overflow:auto;" id="qr-body"></div>
            <div style="padding:12px 24px 20px;display:flex;gap:10px;align-items:center;justify-content:flex-end;border-top:1px solid rgba(213,230,241,.5);">
                <button onclick="closeModal('qrModal')" class="btn-secondary">Close</button>
                <button onclick="showQROnly()" class="btn-secondary" id="qr-only-btn" disabled>QR only</button>
                <button onclick="printIDCard()" class="btn-primary" id="qr-download-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M7 14h10v7H7z" />
                    </svg>
                    Print
                </button>
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


        if (typeof openModal === 'undefined') {
            function openModal(id) {
                const el = document.getElementById(id);
                if (el) el.style.display = 'flex';
            }
        }
        if (typeof closeModal === 'undefined') {
            function closeModal(id) {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(function(m) {
                    m.style.display = 'none';
                });
            }
        });

        document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal(overlay.id);
            });
        });


        $(document).ready(function() {

            var showMun = <?= json_encode($mun_scope === null) ?>;
            $('#fisherfolk-table').DataTable({
                pageLength: 25,
                serverSide: true,
                processing: true,
                searchDelay: 350,
                ajax: {
                    url: 'api_fisherfolk_dt.php',
                    data: function(d) { d.mun = <?= json_encode($mun_filter) ?>; }
                },
                columns: [
                    { data: 'name', render: function(d, t, r) {
                        return '<div style="font-weight:600;color:#16324a;">' + r.last_name + ', ' + r.first_name + '</div>' +
                               (r.middle_name ? '<div style="font-size:11px;color:#7d93a5;">' + r.middle_name + '</div>' : '') +
                               (showMun ? '<div style="font-size:11px;color:#7d93a5;">' + r.municipality + '</div>' : '');
                    } },
                    { data: 'boat_registry_number', render: function(d) {
                        return '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:#006bb7;font-weight:600;">' +
                               (d && d !== 'N/A' ? d : '—') + '</span>';
                    } },
                    { data: 'boat_ownership', render: function(d, t, r) {
                        var m = { 'Owner': ['Boat Owner', '#E0F0FB', '#054b7e'], 'Crew': ['Crew', '#DCFCE7', '#15803D'] };
                        var c = m[d] || ['No Boat', '#EEF2F6', '#5a7185'];
                        return '<span style="display:inline-block;background:' + c[1] + ';color:' + c[2] + ';font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;">' + c[0] + '</span>' +
                            (r.is_transferee ? '<div style="margin-top:3px;"><span style="display:inline-block;background:#E0F0FB;color:#054b7e;font-size:10.5px;font-weight:700;padding:1px 8px;border-radius:999px;">Transferee</span></div>' : '') +
                            (r.hold && r.hold !== 'Complete' ? '<div style="margin-top:3px;font-size:11px;font-weight:600;color:#92400E;">' + r.hold + '</div>' : '') +
                            (r.id_reprint_needed ? '<div style="margin-top:3px;font-size:11px;font-weight:600;color:#B91C1C;">ID reprint needed</div>' : '');
                    } },
                    { data: 'contact_number', render: function(d) { return d ? d : '—'; } },
                    { data: 'active_permits', render: function(d, t, r) {
                        var on = parseInt(d, 10) > 0;
                        return '<span style="display:inline-flex;align-items:center;gap:4px;background:' + (on ? '#DCFCE7' : '#FEE2E2') +
                               ';color:' + (on ? '#15803D' : '#B91C1C') + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">' +
                               d + ' active</span><span style="font-size:11px;color:#7d93a5;margin-left:4px;">' + r.permit_count + ' total</span>';
                    } },
                    { data: 'created_at', render: function(d, t, r) {
                        return '<span style="font-size:12px;color:#5a7185;">' + d + '</span>' +
                               (r.reg_by ? '<div style="font-size:11px;color:#7d93a5;">by ' + r.reg_by + '</div>' : '');
                    } },
                    { data: null, orderable: false, searchable: false, className: 'dt-center', render: function(d, t, r) {
                        var nm = r.first_name + ' ' + r.last_name;
                        var ic = function(path) { return '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' + path + '</svg>'; };
                        return '<div style="text-align:center;white-space:nowrap;">' +
                            '<button onclick="openViewModal(' + r.id + ')" class="btn-secondary btn-sm tooltip-container" data-tooltip="View full profile" aria-label="View profile of ' + nm + '" style="margin-right:4px;">' +
                                ic('<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>') + ' View</button>' +
                            '<button onclick="openPermitsModal(' + r.id + ', this.dataset.name)" data-name="' + nm + '" class="btn-secondary btn-sm tooltip-container" data-tooltip="Update permits" aria-label="Update permits for ' + nm + '" style="margin-right:4px;">' +
                                ic('<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>') + ' Update</button>' +
                            '<button onclick="openIDModal(' + r.id + ')" class="btn-secondary btn-sm tooltip-container" data-tooltip="Print fisherfolk ID card" aria-label="Print ID for ' + nm + '">' +
                                ic('<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 16c.5-1.4 1.6-2 3-2s2.5.6 3 2M14 10h4M14 14h3"/>') + ' Print ID</button>' +
                            (CAN_TRANSFER ? '<button onclick="openTransferModal(' + r.id + ', this.dataset.name)" data-name="' + nm + '" class="btn-secondary btn-sm tooltip-container" data-tooltip="Request a transfer to another municipality" aria-label="Transfer ' + nm + '" style="margin-left:4px;">' +
                                ic('<path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4-4m-4 4l4 4"/>') + ' Transfer</button>' : '') +
                            '</div>';
                    } }
                ],
                order: [
                    [5, 'desc']
                ],
                columnDefs: [{
                        orderable: false,
                        targets: 6
                    },
                    {
                        searchable: false,
                        targets: 6
                    }
                ],
                language: {
                    processing: 'Loading…',
                    search: 'Search:',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ fisherfolk',
                    infoEmpty: 'No fisherfolk found',
                    infoFiltered: '(filtered from _MAX_ total)',
                    zeroRecords: 'No matching records found',
                    paginate: {
                        first: '«',
                        last: '»',
                        next: '›',
                        previous: '‹'
                    }
                }
            });
        });


        function openViewModal(ffId) {
            document.getElementById('view-title').textContent = 'Fisherfolk Record';
            document.getElementById('view-body').innerHTML = `
            <div style="text-align:center;padding:32px 0;">
                <div style="width:32px;height:32px;border:3px solid rgba(0,107,183,.2);border-top-color:#006bb7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                <p style="font-size:13px;color:#5a7185;margin:0;">Loading...</p>
            </div>`;
            openModal('viewModal');

            fetch('api_ff.php?id=' + ffId)
                .then(r => r.json())
                .then(function(ff) {
                    if (!ff || !ff.id) {
                        document.getElementById('view-body').innerHTML = '<p style="color:#EF4444;padding:20px;">Could not load fisherfolk data.</p>';
                        return;
                    }
                    document.getElementById('view-title').textContent = ff.last_name + ', ' + ff.first_name;

                    const has = (v) => v !== null && v !== undefined && v !== '';
                    const fisherClass = has(ff.gross_tonnage) ? (parseFloat(ff.gross_tonnage) > 3 ? 'Commercial' : 'Municipal') : '—';
                    const hp = parseFloat(ff.horsepower);
                    const engine = hp > 0
                        ? [ff.engine_make, hp + ' HP'].filter(Boolean).join(' · ')
                        : (/^non/i.test(ff.boat_type || '') ? 'Non-motorized' : '—');
                    const dims = [ff.length_m, ff.breadth_m, ff.depth_m].some(has)
                        ? [ff.length_m, ff.breadth_m, ff.depth_m].map(v => has(v) ? v : '?').join(' × ') + ' m' : '—';
                    const gearList = Array.isArray(ff.gear) ? ff.gear : [];
                    const gearHtml = gearList.length
                        ? gearList.map(g => '<div>' + bdEsc(g.name) + ' × ' + bdEsc(g.units)
                            + (has(g.mesh_size_cm) ? ' <span style="color:#7d93a5;">(mesh ' + bdEsc(g.mesh_size_cm) + ' cm)</span>' : '') + '</div>').join('')
                        : '—';
                    const tfx = ff.transfer || {};
                    const transferRows = tfx.is_transferee ? [['Transferee', 'Transferred ' + (tfx.transferred_at || '').slice(0, 10) + (tfx.id_reprint_needed ? ' · ID reprint needed' : '')]] : [];
                    (tfx.old_numbers || []).forEach(function(o) { transferRows.push(['Previous registry no.', o.old_number]); });
                    const registered = ff.created_at ? ff.created_at + (ff.reg_by ? ' by ' + ff.reg_by : '') : '—';


                    const sections = [
                        ['Personal', [
                            ['Full Name', [ff.last_name, ff.first_name, ff.middle_name].filter(Boolean).join(', ')],
                            ['Birthdate', ff.birthdate || '—'],
                            ['Sex', ff.sex || '—'],
                            ['Civil Status', ff.civil_status || '—'],
                            ['Contact', ff.contact_number || '—'],
                            ['Address', ff.address || '—'],
                            ['Barangay', ff.barangay || '—'],
                            ['Municipality', ff.municipality || '—'],
                        ]],
                        ['Fishing', [
                            ['Environment', ff.fishing_environment || '—'],
                            ['Fishing Ground', ff.fishing_ground || '—'],
                            ['FMA No.', ff.fma_no || '—'],
                            ['Years Fishing', has(ff.years_fishing) ? ff.years_fishing : '—'],
                            ['Fisher Class', fisherClass],
                            ['Boat Role', ff.boat_ownership || '—'],
                        ]],
                        ['Vessel', [
                            ['Fisherfolk Registry No', ff.boat_registry_number || '—'],
                            ['Boat Name', ff.boat_name || '—'],
                            ['Homeport', ff.homeport || '—'],
                            ['Type / Material', (ff.boat_type || ff.boat_material) ? (ff.boat_type || '—') + ' / ' + (ff.boat_material || '—') : '—'],
                            ['Dimensions (L × B × D)', dims],
                            ['Gross Tonnage', has(ff.gross_tonnage) ? ff.gross_tonnage : '—'],
                            ['Engine', engine],
                            ['No. of Fishers', has(ff.no_fishers) ? ff.no_fishers : '—'],
                            ['Declared Gear', gearHtml, true],
                        ]],
                        ['Emergency Contact', [
                            ['Name', ff.emergency_name || '—'],
                            ['Relationship', ff.emergency_relationship || '—'],
                            ['Number', ff.emergency_contact || '—'],
                        ]],
                        ['Registry', [
                            ['RFID Card', ff.rfid_uid ? 'Linked · ' + ff.rfid_uid : 'No active card'],
                            ['Profile Status', ff.profile_status || '—'],
                            ['Hold', (ff.transfer && ff.transfer.hold) || '—'],
                            ['Registered', registered],
                        ].concat(transferRows)],
                    ];
                    const rows = sections.map(function([title, items]) {
                        return `<tr><td colspan="2" style="padding:14px 12px 6px;font-size:10.5px;font-weight:700;color:#006bb7;text-transform:uppercase;letter-spacing:.07em;">${bdEsc(title)}</td></tr>`
                            + items.map(function([label, val, raw]) {
                                return `<tr>
                        <td style="padding:6px 12px;font-size:12px;font-weight:600;color:#5a7185;white-space:nowrap;vertical-align:top;">${bdEsc(label)}</td>
                        <td style="padding:6px 12px;font-size:13px;color:#16324a;">${raw ? val : bdEsc(val)}</td>
                    </tr>`;
                            }).join('');
                    }).join('');
                    const photoHtml = ff.photo_path
                        ? `<div style="padding:4px 12px 0;"><img src="uploads/photos/${encodeURIComponent(ff.photo_path)}" alt="Photo of ${bdEsc(ff.first_name)} ${bdEsc(ff.last_name)}" style="width:110px;height:140px;object-fit:cover;border-radius:10px;border:1px solid #d5e6f1;"></div>`
                        : '';
                    document.getElementById('view-body').innerHTML = photoHtml + `
                    <table style="width:100%;border-collapse:collapse;">
                        <tbody>${rows}</tbody>
                    </table>
                    <div id="view-permits"></div>`;

                    fetch('api_ff.php?id=' + ffId + '&permits=1').then(r => r.json()).then(function(pd) {
                        const box = document.getElementById('view-permits'); if (!box || !pd) return;
                        const colors = {Active: ['#DCFCE7', '#15803D'], Expired: ['#FEE2E2', '#B91C1C'], Suspended: ['#FEF3C7', '#92400E']};
                        const head = '<p style="padding:14px 12px 6px;margin:0;font-size:10.5px;font-weight:700;color:#006bb7;text-transform:uppercase;letter-spacing:.07em;">Permits &amp; Documents</p>';
                        const th = 'style="text-align:left;padding:5px 12px;font-size:11px;color:#5a7185;font-weight:700;"';
                        const td = 'style="padding:6px 12px;font-size:12.5px;color:#16324a;vertical-align:top;"';
                        const prow = (p) => { const c = colors[p.status] || ['#F1F5F9', '#5a7185'];
                            return `<tr><td ${td}>${bdEsc(p.permit_type)}<div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#006bb7;">${bdEsc(p.permit_number)}</div></td><td ${td}>${bdEsc(p.issue_date)}</td><td ${td}>${bdEsc(p.expiry_date || 'No expiry')}</td><td ${td}><span style="background:${c[0]};color:${c[1]};font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">${bdEsc(p.status)}</span></td></tr>`; };
                        const drow = (d) => { const exp = d.expiry_date;
                            const st = exp && exp < new Date().toISOString().slice(0, 10) ? 'Expired' : 'On file'; const c = colors[st] || ['#E0F2FE', '#075985'];
                            return `<tr><td ${td}>${bdEsc(d.doc_type)}<div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#006bb7;">${bdEsc(d.doc_number)}</div></td><td ${td}>${bdEsc(d.issue_date)}</td><td ${td}>${bdEsc(exp || 'No expiry')}</td><td ${td}><span style="background:${c[0]};color:${c[1]};font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">${st}</span></td></tr>`; };
                        const all = (pd.permits || []).map(prow).concat((pd.documents || []).map(drow));
                        box.innerHTML = head + (all.length
                            ? `<table style="width:100%;border-collapse:collapse;"><thead><tr><th ${th}>Document</th><th ${th}>Registered / Issued</th><th ${th}>Expires</th><th ${th}>Status</th></tr></thead><tbody>${all.join('')}</tbody></table>`
                            : '<p style="padding:6px 12px 12px;margin:0;font-size:13px;color:#7d93a5;">No permits or documents on file. Use Update to add them.</p>');
                    }).catch(function() {});
                })
                .catch(function(e) {
                    document.getElementById('view-body').innerHTML = '<p style="color:#EF4444;padding:20px;">Error: ' + e.message + '</p>';
                });
        }


        function bdEsc(v) { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }


        function setNextDoc(permits, docs, catalog, required, tf) {
            const have = permits.map(p => p.permit_type).concat(docs.map(d => d.doc_type));
            const sel = document.getElementById('add-doc-type'), btn = document.getElementById('add-doc-btn');
            sel.innerHTML = '';
            (catalog || []).forEach(t => {
                const o = document.createElement('option'); o.value = t;
                o.textContent = t + (have.includes(t) ? ' (on file)' : '');
                sel.appendChild(o);
            });
            sel.disabled = btn.disabled = !(catalog || []).length;
            const missing = (required || []).filter(t => !have.includes(t));
            if (tf && tf.is_transferee) {
                document.getElementById('add-doc-hint').textContent = tf.hold === 'Complete'
                    ? 'Every item on the checklist has a fresh copy since the transfer.'
                    : tf.hold + '. After a transfer every item needs a fresh copy: renew FishR and BoatR, and add new copies of the rest.';
                return;
            }
            document.getElementById('add-doc-hint').textContent = missing.length
                ? 'Still missing: ' + missing.join(', ') + '. Other documents can be added too.'
                : 'All required documents are on file. You can still add extra documents.';
        }

        function renewalDate() {
            const d = new Date(); d.setFullYear(d.getFullYear() + 1);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }


        function renderTransferBox(data, ffId) {
            const box = document.getElementById('tf-box'); if (!box) return;
            const t = data.transfer; if (!t) { box.innerHTML = ''; return; }
            const chip = (txt, bg, fg) => `<span style="display:inline-block;background:${bg};color:${fg};font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;margin:0 6px 4px 0;">${bdEsc(txt)}</span>`;
            let h = '<div style="padding:14px 0 6px;">';
            if (t.is_transferee) h += chip('Transferee', '#E0F0FB', '#054b7e');
            h += t.hold === 'Complete' ? chip('Complete', '#DCFCE7', '#15803D') : chip(t.hold || '', '#FEF3C7', '#92400E');
            if (t.id_reprint_needed) h += chip('ID reprint needed', '#FEE2E2', '#B91C1C');
            if (t.is_transferee) {
                const olds = (t.old_numbers || []).map(o => bdEsc(o.old_number)).join(', ');
                h += `<div style="font-size:12px;color:#5a7185;">Transferred ${bdEsc((t.transferred_at || '').slice(0, 10))}${olds ? ' &middot; previous registry no. ' + olds : ''}. Documents issued before the transfer stay on file until a fresh copy replaces them, and they do not count toward the checklist.</div>`;
            }
            if (t.ready && t.pending) h += chip('Transfer pending', '#FEF9C3', '#854D0E');
            box.innerHTML = h + '</div>';
        }


        function openTransferModal(ffId, ffName) {
            document.getElementById('transfer-title').textContent = 'Transfer \u2014 ' + (ffName || '');
            document.getElementById('transfer-body').innerHTML = '<p style="font-size:13px;color:#5a7185;margin:0;">Loading...</p>';
            openModal('transferModal');
            Promise.all([
                fetch('api_ff.php?id=' + ffId).then(r => r.json()),
                fetch('api_ff.php?id=' + ffId + '&permits=1').then(r => r.json())
            ]).then(function([ff, data]) {
                const t = data.transfer || {};
                const chip = (txt, bg, fg) => `<span style="display:inline-block;background:${bg};color:${fg};font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;margin:0 6px 4px 0;">${bdEsc(txt)}</span>`;
                const row = (a, b) => `<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid #eef4f8;font-size:13px;"><span style="color:#5a7185;">${a}</span><span style="color:#16324a;font-weight:600;text-align:right;">${b}</span></div>`;
                let h = row('Municipality', bdEsc(ff.municipality)) + row('Registry No.', `<span style="font-family:'JetBrains Mono',monospace;color:#006bb7;">${bdEsc(ff.boat_registry_number || '--')}</span>`);
                h += '<div style="margin:12px 0 4px;">';
                if (t.is_transferee) h += chip('Transferee', '#E0F0FB', '#054b7e');
                h += t.hold === 'Complete' ? chip('Complete', '#DCFCE7', '#15803D') : chip(t.hold || '', '#FEF3C7', '#92400E');
                if (t.id_reprint_needed) h += chip('ID reprint needed', '#FEE2E2', '#B91C1C');
                h += '</div>';
                if (t.is_transferee) h += `<div style="font-size:12px;color:#5a7185;margin-bottom:8px;">Transferred ${bdEsc((t.transferred_at || '').slice(0, 10))}${(t.old_numbers || []).length ? ' \u00b7 previous registry no. ' + t.old_numbers.map(o => bdEsc(o.old_number)).join(', ') : ''}.</div>`;
                if (!t.ready) {
                    h += '<div style="font-size:13px;background:#FEF2F2;color:#B91C1C;border-radius:9px;padding:10px 12px;">Import migration_v4b.sql in phpMyAdmin first. Transfers need it.</div>';
                } else if (t.pending) {
                    h += `<div style="font-size:13px;background:#FEF9C3;color:#854D0E;border-radius:9px;padding:10px 12px;">A transfer request to <strong>${bdEsc(t.pending.to_municipality)}</strong> is waiting for an answer (${bdEsc(t.pending.left)} left). <a href="transfers.php?tab=sent" style="color:#054b7e;font-weight:700;">View in Transfer Requests</a></div>`;
                } else {
                    if (t.last && t.last.status === 'Rejected') h += `<div style="font-size:12.5px;color:#B91C1C;margin-bottom:10px;">The last request to ${bdEsc(t.last.to_municipality)} was rejected${t.last.response_note ? ': ' + bdEsc(t.last.response_note) : ''}.</div>`;
                    if (t.last && t.last.status === 'Expired') h += `<div style="font-size:12.5px;color:#92400E;margin-bottom:10px;">The last request to ${bdEsc(t.last.to_municipality)} expired without an answer. <a href="transfers.php?tab=sent" style="color:#054b7e;font-weight:700;">Send a follow-up</a></div>`;
                    if (data.can_transfer && (data.transfer_to || []).length) {
                        h += `<form method="POST" action="transfers.php" data-confirm="Send this transfer request?" style="margin-top:6px;">
                            <input type="hidden" name="csrf" value="${bdEsc(TF_CSRF)}"><input type="hidden" name="action" value="request"><input type="hidden" name="fisherfolk_id" value="${+ffId}">
                            <div style="margin-bottom:12px;"><label class="form-label">Moving to *</label><select name="to_municipality" class="form-input" required>${data.transfer_to.map(m => `<option>${bdEsc(m)}</option>`).join('')}</select></div>
                            <div style="margin-bottom:14px;"><label class="form-label">Note (optional)</label><input type="text" name="note" class="form-input" maxlength="255" placeholder="For the receiving MAO"></div>
                            <button type="submit" class="btn-primary btn-sm">Send transfer request</button>
                        </form>`;
                    } else {
                        h += '<div style="font-size:13px;color:#5a7185;">Only the municipality that holds this record can request a transfer.</div>';
                    }
                }
                document.getElementById('transfer-body').innerHTML = h;
                attachConfirmToForms();
            }).catch(function(e) { document.getElementById('transfer-body').innerHTML = '<p style="color:#EF4444;">Error: ' + e.message + '</p>'; });
        }

        function openPermitsModal(ffId, ffName) {
            document.getElementById('permits-title').textContent = 'Update — ' + (ffName || '');
            document.getElementById('permits-subtitle').textContent = 'Renew permits, add documents, or suspend and reactivate permits.';
            document.getElementById('add-permit-ff-id').value = ffId;
            document.getElementById('tf-box').innerHTML = '';
            document.getElementById('permits-body').innerHTML = `
            <div style="text-align:center;padding:32px 0;">
                <div style="width:32px;height:32px;border:3px solid rgba(0,107,183,.2);border-top-color:#006bb7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                <p style="font-size:13px;color:#5a7185;margin:0;">Loading permits...</p>
            </div>`;
            openModal('permitsModal');

            fetch('api_ff.php?id=' + ffId + '&permits=1')
                .then(r => r.json())
                .then(function(data) {
                    const permits = data.permits || [];
                    const docs = data.documents || [];
                    setNextDoc(permits, docs, data.catalog, data.required, data.transfer);
                    renderTransferBox(data, ffId);
                    const docsHtml = docs.length
                        ? '<p style="font-size:11px;font-weight:700;color:#5a7185;text-transform:uppercase;letter-spacing:.07em;margin:16px 0 4px;">Other documents on file</p>'
                          + docs.map(d => `<div style="padding:10px 0;border-bottom:1px solid rgba(213,230,241,.45);">
                                <div style="font-size:13px;font-weight:600;color:#16324a;margin-bottom:2px;">${bdEsc(d.doc_type)}</div>
                                <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#006bb7;margin-bottom:3px;">${bdEsc(d.doc_number)}</div>
                                <div style="font-size:11px;color:#5a7185;">Issued: ${bdEsc(d.issue_date)} &nbsp;·&nbsp; Expires: ${bdEsc(d.expiry_date || 'No expiry')}</div>
                            </div>`).join('')
                        : '';
                    if (!permits.length) {
                        document.getElementById('permits-body').innerHTML = '<p style="font-size:13px;color:#7d93a5;padding:20px 0;">No permits on file yet. Use Add Document below to record one.</p>' + docsHtml;
                        return;
                    }
                    const statusColors = {
                        'Active': {
                            bg: '#DCFCE7',
                            color: '#15803D'
                        },
                        'Expired': {
                            bg: '#FEE2E2',
                            color: '#B91C1C'
                        },
                        'Suspended': {
                            bg: '#FEF3C7',
                            color: '#92400E'
                        }
                    };
                    let html = permits.map(function(p) {
                        const sc = statusColors[p.status] || {
                            bg: '#F1F5F9',
                            color: '#5a7185'
                        };
                        return `<div style="padding:14px 0;border-bottom:1px solid rgba(213,230,241,.45);">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#16324a;margin-bottom:3px;">${bdEsc(p.permit_type)}</div>
                                <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#006bb7;margin-bottom:4px;">${bdEsc(p.permit_number)}</div>
                                <div style="font-size:11px;color:#5a7185;">${bdEsc(p.fishing_area || 'No area specified')} &nbsp;·&nbsp; Issued: ${bdEsc(p.issue_date)} &nbsp;·&nbsp; Expires: ${bdEsc(p.expiry_date || 'no expiry')}</div>
                                ${p.status === 'Suspended' && p.last_reason ? `<div style="font-size:11px;color:#B91C1C;margin-top:3px;">Suspended: ${bdEsc(p.last_reason)}${p.last_incident_id ? ' (Incident #' + bdEsc(p.last_incident_id) + ')' : ''}</div>` : ''}
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                                <span style="background:${sc.bg};color:${sc.color};font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">${p.status}</span>
                                <form method="POST" action="fisherfolk.php" style="display:inline;" data-confirm="Renew ${bdEsc(p.permit_type)} ${bdEsc(p.permit_number)}? It will be valid for 1 year from today (until ${renewalDate()}).">
                                    <input type="hidden" name="action" value="renew_permit">
                                    <input type="hidden" name="permit_id" value="${p.id}">
                                    <button type="submit" class="btn-secondary btn-sm" style="font-size:10px;color:#006bb7;" title="Renew for 1 year from today">Renew</button>
                                </form>
                                ${p.status !== 'Suspended' ? `<form method="POST" action="fisherfolk.php" style="display:inline;" data-confirm="Suspend permit ${bdEsc(p.permit_number)}? This will block it from being used until reactivated.">
                                    <input type="hidden" name="action" value="set_permit_status">
                                    <input type="hidden" name="permit_id" value="${p.id}">
                                    <input type="hidden" name="status" value="Suspended">
                                    <input type="text" name="reason" required maxlength="200" placeholder="Reason" style="width:120px;font-size:11px;padding:3px 6px;border:1px solid #d5e6f1;border-radius:6px;">
                                    <input type="number" name="incident_id" min="1" placeholder="Incident #" style="width:84px;font-size:11px;padding:3px 6px;border:1px solid #d5e6f1;border-radius:6px;">
                                    <button type="submit" class="btn-secondary btn-sm" style="font-size:10px;color:#92400E;" title="Suspend permit">Suspend</button>
                                </form>` : ''}
                                ${p.status === 'Suspended' ? `<form method="POST" action="fisherfolk.php" style="display:inline;" data-confirm="Reactivate permit ${bdEsc(p.permit_number)}?">
                                    <input type="hidden" name="action" value="set_permit_status">
                                    <input type="hidden" name="permit_id" value="${p.id}">
                                    <input type="hidden" name="status" value="Active">
                                    <input type="text" name="reason" required maxlength="200" placeholder="Reason" style="width:120px;font-size:11px;padding:3px 6px;border:1px solid #d5e6f1;border-radius:6px;">
                                    <button type="submit" class="btn-secondary btn-sm" style="font-size:10px;color:#15803D;" title="Reactivate permit">Reactivate</button>
                                </form>` : ''}
                            </div>
                        </div>
                    </div>`;
                    }).join('');
                    document.getElementById('permits-body').innerHTML = html + docsHtml;
                    attachConfirmToForms();
                })
                .catch(function(e) {
                    document.getElementById('permits-body').innerHTML = '<p style="color:#EF4444;padding:20px;">Error loading permits: ' + e.message + '</p>';
                });
        }

        let currentIDData = null;
        let currentIDBRN = null;
        let currentIDToken = null;

        function openIDModal(ffId) {
            currentIDData = null;
            currentIDBRN = null;
            currentIDToken = null;
            document.getElementById('qr-only-btn').disabled = true;

            document.getElementById('qr-title').textContent = 'Print ID';
            document.getElementById('qr-enc-status').textContent = 'Preparing ID card...';
            document.getElementById('qr-download-btn').disabled = true;
            document.getElementById('qr-body').innerHTML = `
            <div style="text-align:center;padding:32px 0;">
                <div style="width:36px;height:36px;border:3px solid rgba(0,107,183,.2);border-top-color:#006bb7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                <p style="font-size:13px;color:#5a7185;margin:0;">Encrypting QR and building the card...</p>
            </div>`;
            openModal('qrModal');

            Promise.all([
                fetch('api_ff.php?id=' + ffId).then(function(r) { return r.json(); }),
                fetch('api_qr_token.php?ff_id=' + ffId).then(function(r) { return r.json(); })
            ]).then(function([ff, tokenData]) {
                if (!ff || !ff.id) {
                    document.getElementById('qr-body').innerHTML = '<p style="color:#EF4444;padding:20px;">Could not load fisherfolk data.</p>';
                    document.getElementById('qr-enc-status').textContent = '';
                    return;
                }
                if (tokenData.error) {
                    document.getElementById('qr-body').innerHTML = '<p style="color:#EF4444;padding:20px;">' + tokenData.error + '</p>';
                    document.getElementById('qr-enc-status').textContent = '';
                    return;
                }
                if (!window.IDCard) {
                    document.getElementById('qr-body').innerHTML = '<p style="color:#EF4444;padding:20px;">id_card.js did not load. Make sure it was copied next to fisherfolk.php, then refresh.</p>';
                    return;
                }

                const brn = tokenData.brn || ff.boat_registry_number || '';
                return window.IDCard.render(ff, tokenData.token, brn, document.getElementById('qr-body')).then(function() {
                    currentIDData = ff;
                    currentIDBRN = brn;
                    currentIDToken = tokenData.token;
                    document.getElementById('qr-only-btn').disabled = false;
                    document.getElementById('qr-title').textContent = 'ID Card — ' + ff.last_name + ', ' + ff.first_name;
                    document.getElementById('qr-enc-status').innerHTML = (ff.transfer && ff.transfer.id_reprint_needed)
                        ? '<span style="color:#B91C1C;font-weight:700;">This person transferred. Print a new ID with the new registry number.</span> '
                        : '';
                    document.getElementById('qr-enc-status').innerHTML +=
                        '<span style="color:#15803D;font-weight:600;">🔒 AES-256-GCM Encrypted QR</span>';
                    document.getElementById('qr-download-btn').disabled = false;
                });
            }).catch(function(e) {
                document.getElementById('qr-body').innerHTML = '<p style="color:#EF4444;padding:20px;">Error: ' + e.message + '</p>';
            });
        }

        function showQROnly() {
            if (!currentIDToken) return;
            var old = document.getElementById('qr-only-overlay');
            if (old) old.remove();
            var ov = document.createElement('div');
            ov.id = 'qr-only-overlay';
            ov.style.cssText = 'position:fixed;inset:0;z-index:100;background:rgba(4,36,61,.75);display:flex;align-items:center;justify-content:center;padding:16px;';
            ov.innerHTML = '<div style="background:#fff;border-radius:18px;padding:22px;max-width:96vw;max-height:96vh;overflow:auto;text-align:center;">' +
                '<div id="qr-only-box" style="display:inline-block;background:#fff;padding:24px;"></div>' +
                '<p style="font:600 14px sans-serif;color:#16324a;margin:6px 0 14px;">' + (currentIDBRN || '') + '</p>' +
                '<div style="display:flex;gap:10px;justify-content:center;">' +
                '<button type="button" class="btn-secondary" id="qr-only-close">Close</button>' +
                '<button type="button" class="btn-primary" id="qr-only-dl">Download PNG</button></div></div>';
            document.body.appendChild(ov);
            var box = document.getElementById('qr-only-box');
            new QRCode(box, { text: currentIDToken, width: 440, height: 440, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.L });
            box.querySelectorAll('img, canvas').forEach(function (el) { el.style.maxWidth = '100%'; el.style.height = 'auto'; });
            document.getElementById('qr-only-close').onclick = function () { ov.remove(); };
            ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
            document.getElementById('qr-only-dl').onclick = function () {
                var c = box.querySelector('canvas'), img = box.querySelector('img');
                var url = c ? c.toDataURL('image/png') : (img ? img.src : null);
                if (!url) return;
                var a = document.createElement('a');
                a.href = url;
                a.download = 'QR-' + String(currentIDBRN || 'fisherfolk').replace(/[^A-Za-z0-9_-]/g, '') + '.png';
                document.body.appendChild(a); a.click(); a.remove();
            };
        }

        function printIDCard() {
            if (!currentIDData) return;
            const ff = currentIDData;
            window.IDCard.print(document.getElementById('qr-body')).then(function() {
                if (!(ff.transfer && ff.transfer.id_reprint_needed)) return;
                bdConfirm('Did the new ID print correctly? This clears the "ID reprint needed" flag.', { confirmText: 'Yes, it printed', cancelText: 'Not yet' }).then(function(ok) {
                    if (!ok) return;
                    const fd = new FormData(); fd.append('action', 'id_printed'); fd.append('ff_id', ff.id); fd.append('csrf', TF_CSRF);
                    fetch('api_transfer.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); }).then(function(d) {
                        if (d && d.success) { ff.transfer.id_reprint_needed = 0; document.getElementById('qr-enc-status').innerHTML = '<span style="color:#15803D;font-weight:600;">New ID printed</span>'; if (window.jQuery) $('#fisherfolk-table').DataTable().ajax.reload(null, false); }
                    });
                });
            }).catch(function(e) {
                alert('Could not open the print dialog: ' + e.message);
            });
        }
    </script>
</body>

</html>