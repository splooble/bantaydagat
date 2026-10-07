<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/requirements.php';
require_once 'includes/barangays.php';
requireRole(MAO_ROLES);

$user      = getCurrentUser();
$mun_scope = ($user['role'] !== 'superadmin' && !empty($user['mun'])) ? $user['mun'] : null;
$officer_name = $_SESSION['user_name'] ?? 'Officer';

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $act = $_GET['action'];
    $sql = "SELECT f.id,f.first_name,f.last_name,f.municipality,f.barangay,f.created_at,f.boat_ownership,
        CONCAT_WS('|',
            (SELECT GROUP_CONCAT(p.permit_type SEPARATOR '|') FROM permits p WHERE p.fisherfolk_id=f.id),
            (SELECT GROUP_CONCAT(d.doc_type SEPARATOR '|') FROM registration_documents d WHERE d.fisherfolk_id=f.id)) AS held
        FROM fisherfolk f
        WHERE CONCAT(f.first_name,' ',f.last_name) LIKE ?";
    $t = 's'; $a = ['%' . trim($_GET['q'] ?? '') . '%'];
    if ($mun_scope) { $sql .= " AND f.municipality=?"; $t .= 's'; $a[] = $mun_scope; }
    if ($act === 'history' && !empty($_GET['date'])) { $sql .= " AND DATE(f.created_at)=?"; $t .= 's'; $a[] = $_GET['date']; }
    $sql .= " ORDER BY f.created_at DESC LIMIT " . ($act === 'list' ? 200 : 50);
    $st = $conn->prepare($sql); $st->bind_param($t, ...$a); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $holds = holdTextFor($conn, $rows);
    foreach ($rows as &$r) { $r['hold'] = $holds[(int)$r['id']]; unset($r['held'], $r['boat_ownership']); }
    unset($r);
    echo json_encode(['rows' => $rows]);
    exit();
}


$gearRows = [];
$gq = $conn->query("SELECT id, environment, method, gear_name, takes_mesh FROM gear_catalog ORDER BY sort_order, id");
if ($gq) $gearRows = $gq->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#0a2f4f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BD Register">
    <link rel="manifest" href="manifest-register.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <title>BantayDagat — Register Fisherfolk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        * {
            font-family: 'Instrument Sans', sans-serif;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            margin: 0;
            background: #f4f9fc;
            color: #16324a;
            min-height: 100vh;
            padding-bottom: 40px;
        }

        .app-header {
            background: linear-gradient(135deg, #0a2f4f, #06263f);
            padding: 44px 20px 16px;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .app-header h1 {
            font-size: 17px;
            font-weight: 700;
            margin: 0 0 2px;
        }

        .app-header p {
            font-size: 11.5px;
            color: rgba(255, 255, 255, .5);
            margin: 0;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            margin-top: 8px;
        }

        .status-online {
            background: rgba(34, 197, 94, .18);
            color: #86efac;
            border: 1px solid rgba(134, 239, 172, .3);
        }

        .status-offline {
            background: rgba(239, 68, 68, .18);
            color: #fca5a5;
            border: 1px solid rgba(252, 165, 165, .3);
        }

        .queue-banner {
            display: none;
            background: #FEF3C7;
            border: 1px solid #FCD34D;
            color: #92400E;
            font-size: 12px;
            font-weight: 600;
            padding: 10px 20px;
            align-items: center;
            gap: 8px;
        }

        .queue-banner.show {
            display: flex;
        }

        .form-wrap {
            padding: 18px 16px 0;
            max-width: 640px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border: 1px solid #d5e6f1;
            border-radius: 16px;
            padding: 18px 16px;
            margin-bottom: 16px;
        }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            color: #006bb7;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin: 0 0 12px;
        }

        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 600;
            color: #5a7185;
            margin-bottom: 4px;
        }

        .form-input {
            width: 100%;
            border: 1px solid #d5e6f1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #f8fcff;
            color: #16324a;
        }

        .form-input:focus {
            outline: none;
            border-color: #006bb7;
            background: #fff;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .grid-3 {
                grid-template-columns: 1fr 1fr;
            }
        }



        @media (min-width: 640px) and (max-width: 1023.98px) {
            .form-wrap {
                max-width: 720px;
            }
        }

        @media (min-width: 1024px) {
            .form-wrap {
                max-width: 1100px;
                padding-top: 32px;
            }
            #register-form {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                align-items: start;
            }
            #register-form .card {
                margin-bottom: 0;
            }

            #register-form > #submit-btn {
                grid-column: 1 / -1;
            }
        }

        .field {
            margin-bottom: 14px;
        }

        .permit-row {
            border: 1px solid #d5e6f1;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            position: relative;
            background: #f8fcff;
        }

        .permit-row .remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            color: #7d93a5;
            cursor: pointer;
        }

        .btn-secondary {
            background: #eaf6fd;
            color: #16324a;
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: #006bb7;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 13px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
        }

        .btn-primary:disabled {
            opacity: .6;
        }

        .toast {
            position: fixed;
            left: 16px;
            right: 16px;
            bottom: 20px;
            background: #16324a;
            color: #fff;
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            display: none;
            align-items: center;
            gap: 8px;
            z-index: 50;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
        }

        .toast.show {
            display: flex;
        }

        .toast.success {
            background: #15803D;
        }

        .toast.queued {
            background: #92400E;
        }

        .toast.error {
            background: #B91C1C;
        }


        #success-panel { display: none; }
        #success-panel.show { display: block; }
        .success-hero {
            text-align: center; padding: 28px 20px 20px;
        }
        .success-check {
            width: 56px; height: 56px; border-radius: 50%; background: #DCFCE7;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 14px;
        }
        .success-hero h2 { font-size: 17px; font-weight: 700; margin: 0 0 4px; color: #16324a; }
        .success-hero p { font-size: 12.5px; color: #5a7185; margin: 0; }
        .success-brn {
            font-family: 'JetBrains Mono', monospace; font-size: 15px; font-weight: 700;
            color: #006bb7; background: #eaf6fd; border: 1px solid #BFDBFE;
            border-radius: 10px; padding: 8px 14px; display: inline-block; margin-top: 12px;
        }
        .id-card {
            width: 100%; max-width: 380px; margin: 0 auto;
            background: linear-gradient(135deg, #0a2f4f, #06263f);
            border-radius: 16px; padding: 18px; color: #fff;
            box-shadow: 0 12px 32px rgba(13,22,40,.25);
        }
        .id-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .id-card-head img { width: 32px; height: 32px; object-fit: contain; }
        .id-card-head .t1 { font-size: 12px; font-weight: 700; }
        .id-card-head .t2 { font-size: 9.5px; color: rgba(255,255,255,.5); }
        .id-card-body { display: flex; gap: 14px; align-items: center; }
        #qr-preview { background: #fff; padding: 8px; border-radius: 10px; flex-shrink: 0; width: 108px; height: 108px; }
        #qr-preview img, #qr-preview canvas { width: 100% !important; height: 100% !important; }
        .id-card-info .name { font-size: 14px; font-weight: 700; margin: 0 0 2px; }
        .id-card-info .mun { font-size: 10.5px; color: rgba(255,255,255,.6); margin: 0 0 8px; }
        .id-card-info .brn-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .07em; color: rgba(255,255,255,.4); margin: 0; }
        .id-card-info .brn-val { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; font-weight: 600; margin: 1px 0 6px; }
        .id-card-info .rfid-tag {
            font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 999px;
            background: rgba(34,197,94,.18); color: #86EFAC; display: inline-block;
        }
        .success-actions { display: flex; flex-direction: column; gap: 10px; padding: 20px 4px 4px; }
        .success-actions button { font-family: 'Instrument Sans', sans-serif; }
        #qr-gen-status { text-align: center; font-size: 12px; color: #7d93a5; padding: 16px 0; }
    .hide-view{display:none!important}
        #sidebar{position:fixed;top:0;left:0;bottom:0;width:220px;background:#06263f;padding:76px 12px 12px;z-index:30;transform:translateX(-100%);transition:transform .2s}
        #sidebar.open{transform:none}
        .nav-btn{display:block;width:100%;text-align:left;background:none;border:0;color:rgba(255,255,255,.65);padding:11px 12px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:2px}
        .nav-btn.active{background:rgba(255,255,255,.13);color:#fff}
        #menu-btn{position:absolute;top:44px;right:16px;background:rgba(255,255,255,.12);border:0;color:#fff;border-radius:8px;padding:6px 10px;font-size:16px;z-index:40}
        @media(min-width:1024px){#sidebar{transform:none;padding-top:28px}body{padding-left:220px}#menu-btn{display:none}}
        .reg-row{background:#fff;border:1px solid #d5e6f1;border-radius:12px;padding:12px 14px;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center}
        .reg-row.new{border-color:#006bb7;background:#eaf6fd}
        .rn{font-size:14px;font-weight:600}.rm{font-size:11.5px;color:#5a7185;margin-top:2px}
        .bdg{font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:999px;white-space:nowrap}
        .bdg-ok{background:rgba(34,197,94,.15);color:#15803D}.bdg-hold{background:rgba(245,158,11,.15);color:#92400E}
        #sig{width:100%;height:150px;border:1px dashed #7d93a5;border-radius:10px;background:#fff;touch-action:none}
        .doc-row{border:1px solid #d5e6f1;border-radius:12px;padding:12px;margin-bottom:10px;background:#f8fcff}
        .doc-row.locked{opacity:.45}

        body{overflow-x:hidden}
        .form-input{min-width:0;max-width:100%}
        input[type=date].form-input{-webkit-appearance:none;appearance:none;height:42px}
        .grid-2>*,.grid-3>*{min-width:0}
        #sidebar{width:min(220px,82vw)}
        #menu-btn{width:40px;height:40px;padding:0;display:flex;align-items:center;justify-content:center}
        @media (max-width:1023.98px){.app-header{padding-right:68px}.app-header h1,.app-header p{overflow-wrap:anywhere}}
        @media (max-width:420px){.grid-2,.grid-3{grid-template-columns:1fr}.form-wrap{padding-left:12px;padding-right:12px}.card{padding:16px 12px}}
        .reg-row{flex-wrap:wrap}.reg-row>div:first-child{min-width:0;flex:1 1 180px}.bdg{white-space:normal}
        .id-card-body{flex-wrap:wrap;justify-content:center}
        .success-brn{max-width:100%;overflow-wrap:anywhere}

        @media (max-width:1023.98px){#register-form{display:flex;flex-direction:column}.form-col{display:contents}#submit-btn{order:6}}
        @media (min-width:1024px){.form-col{display:flex;flex-direction:column;gap:20px}}
            .gear-group { border: 1px solid #d5e6f1; border-radius: 10px; margin-bottom: 8px; background: #f8fcff; }
        .gear-group summary { cursor: pointer; padding: 9px 12px; font-size: 12.5px; font-weight: 600; }
        .gear-row { display: flex; align-items: center; gap: 8px; padding: 4px 12px 8px; font-size: 12.5px; }
</style>
</head>

<body>
    <aside id="sidebar">
        <button class="nav-btn active" data-v="new" onclick="showView('new')">New Registration</button>
        <button class="nav-btn" data-v="list" onclick="showView('list')">All Registrations</button>
        <button class="nav-btn" data-v="feed" onclick="showView('feed')">Live Feed</button>
        <button class="nav-btn" data-v="history" onclick="showView('history')">Registration History</button>
    </aside>
    <div class="app-header">
        <button id="menu-btn" aria-label="Open menu" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
        <h1>Register Fisherfolk</h1>
        <p>Signed in as <?= htmlspecialchars($officer_name) ?><?= $mun_scope ? ' · ' . htmlspecialchars($mun_scope) : '' ?></p>
        <span id="status-pill" class="status-pill status-online">● Online</span>
    </div>

    <div id="queue-banner" class="queue-banner">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        <span id="queue-banner-text">0 registrations waiting to sync</span>
    </div>


    <div id="success-panel" class="form-wrap">
        <div class="card">
            <div class="success-hero">
                <div class="success-check">
                    <svg width="26" height="26" fill="none" stroke="#15803D" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                </div>
                <h2>Fisherfolk Registered</h2>
                <p id="success-name"></p>
                <div class="success-brn" id="success-brn"></div>
                <div id="success-rfid-note" style="margin-top:8px;font-size:11px;color:#15803D;font-weight:600;"></div>
            </div>
        </div>

        <div class="card" style="margin-top:14px;padding:20px;">
            <div id="qr-gen-status">
                <button class="btn-primary" style="width:auto;padding:10px 20px;" onclick="generateIDCard()">Generate QR ID Card</button>
            </div>
            <div id="id-card-wrap" style="display:none;">
                <div class="id-card" id="id-card">
                    <div class="id-card-head">
                        <img src="includes/logos/bantaydagat_logo.png" alt="">
                        <div>
                            <div class="t1">BantayDagat: Connect</div>
                            <div class="t2">Fisherfolk Identification</div>
                        </div>
                    </div>
                    <div class="id-card-body">
                        <div id="qr-preview"></div>
                        <div class="id-card-info">
                            <p class="name" id="card-name"></p>
                            <p class="mun" id="card-mun"></p>
                            <p class="brn-label">Fisherfolk Registry No.</p>
                            <p class="brn-val" id="card-brn"></p>
                            <span class="rfid-tag" id="card-rfid-tag" style="display:none;">RFID Linked</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="success-actions">
            <button class="btn-primary" id="download-card-btn" style="display:none;" onclick="downloadIDCard()">Download ID Card (PDF)</button>
            <button class="btn-secondary" onclick="startAnotherRegistration()">Register Another Fisherfolk</button>
        </div>
    </div>

    <div class="form-wrap" id="form-wrap">
        <form id="register-form">
            <div class="form-col">
            <div class="card" style="order:1">
                <p class="section-label">Personal Information</p>
                <div class="grid-3 field">
                    <div><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-input" required></div>
                    <div><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-input" required></div>
                    <div><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-input"></div>
                </div>
                <div class="grid-3 field">
                    <div><label class="form-label">Birthdate</label><input type="date" name="birthdate" id="birthdate-field" class="form-input" max=""></div>
                    <div><label class="form-label">Sex</label>
                        <select name="sex" class="form-input">
                            <option>Male</option>
                            <option>Female</option>
                        </select>
                    </div>
                    <div><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-input" placeholder="09XX-XXX-XXXX"></div>
                </div>
                <div class="grid-3 field">
                    <div><label class="form-label">Civil Status</label>
                        <select name="civil_status" class="form-input"><option value="">Select...</option><option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option></select>
                    </div>
                    <div><label class="form-label">Years Fishing</label><input type="number" min="0" max="100" name="years_fishing" class="form-input"></div>
                    <div><label class="form-label">Fishing Environment</label>
                        <select name="fishing_environment" id="env-select" class="form-input"><option>Marine</option><option>Inland</option></select>
                    </div>
                </div>
                <div class="grid-2 field">
                    <div>
                        <label class="form-label">Municipality *</label>
                        <?php if ($mun_scope): ?>
                            <input type="text" class="form-input" value="<?= htmlspecialchars($mun_scope) ?>" disabled>
                            <input type="hidden" name="municipality" value="<?= htmlspecialchars($mun_scope) ?>">
                        <?php else: ?>
                            <select name="municipality" class="form-input" required>
                                <option value="">Select...</option>
                                <?php foreach (['San Jose de Buenavista', 'Hamtic', 'Tobias Fornier', 'Anini-y'] as $m): ?>
                                    <option value="<?= $m ?>"><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label" for="barangay">Barangay</label>
                        <select name="barangay" id="barangay" class="form-input" <?= $mun_scope ? '' : 'disabled' ?>>
                            <option value=""><?= $mun_scope ? 'Select barangay...' : 'Select a municipality first' ?></option>
                            <?php if ($mun_scope): foreach (barangaysFor($mun_scope) as $b): ?>
                                <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    </div>
                </div>
                <div class="field"><label class="form-label">Address</label><input type="text" name="address" class="form-input" placeholder="Street / Sitio / Purok"></div>
                <div class="grid-2 field">
                    <div><label class="form-label">Fishing Ground</label><input type="text" name="fishing_ground" class="form-input"></div>
                    <div><label class="form-label">FMA Number</label><input type="text" name="fma_no" class="form-input"></div>
                </div>
                <div class="grid-3 field" style="margin-bottom:0;">
                    <div><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_name" class="form-input"></div>
                    <div><label class="form-label">Relationship</label><input type="text" name="emergency_relationship" class="form-input" placeholder="e.g. Spouse"></div>
                    <div><label class="form-label">Emergency Number</label><input type="text" name="emergency_contact" class="form-input"></div>
                </div>
            </div>

            <div class="card" style="order:3">
                <p class="section-label">RFID Card <span style="text-transform:none;font-weight:400;color:#7d93a5;">(optional — can be assigned later)</span></p>
                <p style="font-size:12px;color:#5a7185;margin:0 0 12px;line-height:1.6;">
                    Cards are read-only, so we can't write the registry number onto one — instead, tap the fisherfolk's card on the reader once to link its existing ID to this record.
                </p>
                <div id="rfid-card-status" style="display:flex;align-items:center;gap:10px;border:1px dashed #d5e6f1;border-radius:10px;padding:12px;background:#f8fcff;">
                    <div id="rfid-card-icon" style="width:34px;height:34px;border-radius:50%;background:#eaf6fd;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="18" height="18" fill="none" stroke="#7d93a5" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5a7 7 0 010 11M4 4a10.5 10.5 0 010 16M17.5 6.5a7 7 0 010 11M20 4a10.5 10.5 0 010 16" /></svg>
                    </div>
                    <div style="flex:1;">
                        <div id="rfid-card-text" style="font-size:13px;font-weight:600;color:#5a7185;">No card linked yet</div>
                        <div id="rfid-card-sub" style="font-size:11px;color:#7d93a5;"></div>
                    </div>
                    <button type="button" id="rfid-scan-btn" class="btn-secondary" onclick="startRFIDCapture()">Scan Card</button>
                </div>
                <input type="text" id="rfid-capture-input" inputmode="none" autocomplete="off" style="position:absolute;opacity:0;width:1px;height:1px;border:none;pointer-events:none;">
                <input type="hidden" name="rfid_uid" id="rfid_uid_field">
            </div>

            <div class="card" style="order:5">
                <p class="section-label">Photo</p>
                <div style="display:flex;gap:14px;align-items:center;">
                    <div id="photo-box" style="width:96px;height:120px;border:1px dashed #d5e6f1;border-radius:10px;background:#f8fcff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;color:#7d93a5;font-size:11px;text-align:center;">No photo</div>
                    <div>
                        <input type="file" id="photo-input" accept="image/*" capture="user" style="display:none">
                        <input type="hidden" name="photo" id="photo-data">
                        <button type="button" class="btn-secondary" onclick="document.getElementById('photo-input').click()" id="photo-btn">Take photo</button>
                        <button type="button" class="btn-secondary" onclick="clearPhoto()" id="photo-clear" style="display:none;margin-left:6px">Remove</button>
                        <p style="font-size:11.5px;color:#7d93a5;margin:8px 0 0;line-height:1.5;">Face the camera in good light. The photo appears on the ID card and in Scan.</p>
                    </div>
                </div>
            </div>

            <div class="card" style="order:5">
                <p class="section-label">Certification</p>
                <canvas id="sig"></canvas>
                <div style="display:flex;gap:10px;align-items:center;margin:8px 0 12px">
                    <button type="button" class="btn-secondary" onclick="clearSig()">Clear</button>
                    <label style="font-size:12.5px"><input type="checkbox" id="cannot-sign" name="cannot_sign"> Cannot sign (thumbmark on hard copy)</label>
                </div>
                <div class="field"><label class="form-label">Witness name (if cannot sign)</label><input type="text" name="witness" class="form-input"></div>
                <label style="font-size:12.5px;line-height:1.5"><input type="checkbox" id="consent"> The applicant confirms the information is true and consents to its use for permit verification.</label>
            </div>
            </div>
            <div class="form-col">
            <div class="card" style="order:2">
                <p class="section-label">Boat &amp; Declared Gear</p>
                <div class="field"><label class="form-label">Boat Role</label>
                    <select name="boat_ownership" id="boat-role" class="form-input"><option value="Owner">Owner (fill in the boat below)</option><option value="Crew">Crew member (no boat of their own)</option><option value="No boat">No boat</option></select>
                </div>
                <div id="boat-fields">
                <div class="grid-2 field"><div><label class="form-label">Boat Name</label><input type="text" name="boat_name" class="form-input"></div>
                    <div><label class="form-label">Homeport</label><input type="text" name="homeport" class="form-input"></div></div>
                <div class="grid-3 field">
                    <div><label class="form-label">Boat Type</label><select name="boat_type" class="form-input"><option value="">Select...</option><option>Non-motorized banca</option><option>Motorized banca</option><option>Others</option></select></div>
                    <div><label class="form-label">Material</label><select name="boat_material" class="form-input"><option value="">Select...</option><option>Wood</option><option>Fiber glass</option><option>Composite</option></select></div>
                    <div><label class="form-label">No. of Fishers</label><input type="number" min="0" name="no_fishers" class="form-input"></div>
                </div>
                <div class="grid-3 field">
                    <div><label class="form-label">Length (m)</label><input type="number" step="0.01" name="length_m" class="form-input"></div>
                    <div><label class="form-label">Breadth (m)</label><input type="number" step="0.01" name="breadth_m" class="form-input"></div>
                    <div><label class="form-label">Depth (m)</label><input type="number" step="0.01" name="depth_m" class="form-input"></div>
                </div>
                <div class="grid-3 field">
                    <div><label class="form-label">Gross Tonnage</label><input type="number" step="0.01" name="gross_tonnage" class="form-input"></div>
                    <div><label class="form-label">Horsepower</label><input type="number" step="0.1" name="horsepower" class="form-input"></div>
                    <div><label class="form-label">Engine Make</label><input type="text" name="engine_make" class="form-input"></div>
                </div>
                <label class="form-label">Declared fishing gear (units)</label>
                <div id="gear-list"></div>
                </div>
            </div>

            <div class="card" style="order:4">
                <p class="section-label">Documents on hand <span style="text-transform:none;font-weight:400;color:#7d93a5;">(required for the selected municipality; FishR, BoatR and Mayor's Permit unlock in order, and BoatR is for boat owners only)</span></p>
                <div id="docs"></div>
                <div id="hold-preview" class="bdg bdg-hold" style="display:inline-block"></div>
            </div>
            </div>

            <button type="submit" id="submit-btn" class="btn-primary">Register Fisherfolk</button>
        </form>
    </div>

    <div id="view-list" class="form-wrap view hide-view"><input id="list-q" class="form-input" placeholder="Search by name" oninput="loadList()" style="margin-bottom:12px"><div id="list-body"></div></div>
    <div id="view-feed" class="form-wrap view hide-view"><p class="section-label">Live · updates every 8 seconds</p><div id="feed-body"></div></div>
    <div id="view-history" class="form-wrap view hide-view"><input type="date" id="hist-date" class="form-input" onchange="loadHistory()" style="margin-bottom:12px"><div id="hist-body"></div><p class="section-label" style="margin-top:18px">On this device</p><div id="hist-local"></div></div>
    <div id="toast" class="toast"></div>

    <script src="offline-db.js"></script>
    <script>

        document.getElementById('birthdate-field').max = new Date().toISOString().split('T')[0];


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


        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw-register.js').then((reg) => {
                navigator.serviceWorker.addEventListener('message', (e) => {
                    if (e.data && e.data.type === 'bd-queue-synced') refreshQueueBanner();
                });
            }).catch(() => {});
        }


        function updateStatusPill() {
            const pill = document.getElementById('status-pill');
            if (navigator.onLine) {
                pill.className = 'status-pill status-online';
                pill.textContent = '● Online';
            } else {
                pill.className = 'status-pill status-offline';
                pill.textContent = '● Offline — will queue';
            }
        }
        window.addEventListener('online', () => { updateStatusPill(); flushQueue(); });
        window.addEventListener('offline', updateStatusPill);
        updateStatusPill();





        const rfidCaptureInput = document.getElementById('rfid-capture-input');
        let rfidCapturing = false;

        function startRFIDCapture() {
            rfidCapturing = true;
            document.getElementById('rfid-scan-btn').textContent = 'Waiting...';
            document.getElementById('rfid-card-text').textContent = 'Tap the card on the reader now';
            document.getElementById('rfid-card-sub').textContent = '';
            document.getElementById('rfid-card-icon').style.background = 'rgba(0,107,183,.12)';
            rfidCaptureInput.value = '';
            rfidCaptureInput.focus();
        }

        rfidCaptureInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const uid = rfidCaptureInput.value.trim();
            rfidCaptureInput.value = '';
            rfidCapturing = false;
            document.getElementById('rfid-scan-btn').textContent = 'Rescan';
            document.getElementById('rfid-card-icon').style.background = '#DCFCE7';
            if (!uid) {
                document.getElementById('rfid-card-text').textContent = 'No card linked yet';
                document.getElementById('rfid-card-sub').textContent = 'Scan came back empty — try again.';
                document.getElementById('rfid-scan-btn').textContent = 'Scan Card';
                return;
            }
            document.getElementById('rfid_uid_field').value = uid;
            document.getElementById('rfid-card-text').textContent = 'Card linked';
            document.getElementById('rfid-card-sub').textContent = 'UID: ' + uid;
        });

        function resetRFIDCard() {
            rfidCapturing = false;
            document.getElementById('rfid_uid_field').value = '';
            document.getElementById('rfid-card-text').textContent = 'No card linked yet';
            document.getElementById('rfid-card-sub').textContent = '';
            document.getElementById('rfid-card-icon').style.background = '#eaf6fd';
            document.getElementById('rfid-scan-btn').textContent = 'Scan Card';
        }


        const REQS = <?= json_encode(allMunRequirements(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        const CHAIN_TYPES = <?= json_encode(CHAIN_TYPES, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        const OWNER_ONLY = <?= json_encode(OWNER_ONLY_TYPES, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        const GEAR = <?= json_encode($gearRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        const $ = (id) => document.getElementById(id);
        function currentMun() { const m = document.querySelector('[name="municipality"]'); return m ? m.value : ''; }
        function reqList() { return REQS[currentMun()] || []; }

        function skipped(t) { return OWNER_ONLY.includes(t) && $('boat-role').value !== 'Owner'; }
        function chainList() { const l = reqList(); return CHAIN_TYPES.filter(t => l.includes(t) && !skipped(t)); }
        function holdText(list, filled) { const i = list.findIndex((t, k) => !filled[k]); return i < 0 ? 'Complete' : 'On hold · awaiting ' + list[i]; }
        function initDocs() {
            const list = reqList();
            if (!list.length) {
                $('docs').innerHTML = '<p class="empty-note">Choose a municipality to see its required documents.</p>';
                $('hold-preview').textContent = '';
                return;
            }
            $('docs').innerHTML = list.map((t, i) => `<div class="doc-row" id="doc-${i}">
                <label class="form-label">${escapeHtml(t)} No. <span class="doc-note" id="doc-note-${i}" style="display:none;text-transform:none;font-weight:400;color:#7d93a5">(boat owners only)</span></label><input type="text" class="form-input doc-num" data-i="${i}" style="margin-bottom:8px">
                <div class="grid-2"><div><label class="form-label">Issue Date</label><input type="date" class="form-input doc-issue"></div>
                <div><label class="form-label">Expiry (optional)</label><input type="date" class="form-input doc-exp"></div></div></div>`).join('');
            $('docs').oninput = syncDocs; syncDocs();
        }
        function syncDocs() {
            const list = reqList(), chain = chainList();
            const nums = [...document.querySelectorAll('.doc-num')];
            const has = [];
            nums.forEach((n, i) => {
                const off = skipped(list[i]);
                const ci = chain.indexOf(list[i]);
                const prev = ci > 0 ? list.indexOf(chain[ci - 1]) : -1;
                const locked = off || (prev >= 0 && !has[prev]);
                if (locked) { n.value = ''; }
                $('doc-' + i).classList.toggle('locked', locked);
                $('doc-' + i).querySelectorAll('input').forEach(x => { x.disabled = locked; if (locked) x.value = ''; });
                const note = $('doc-note-' + i); if (note) note.style.display = off ? '' : 'none';
                has[i] = off ? true : !!n.value.trim();
            });
            $('hold-preview').textContent = holdText(list, has);
        }
        function docsFromForm() {
            const list = reqList();
            return [...document.querySelectorAll('.doc-num')].map((n, i) => n.value.trim() && ({
                type: list[i], number: n.value.trim(),
                issue: document.querySelectorAll('.doc-issue')[i].value, expiry: document.querySelectorAll('.doc-exp')[i].value
            })).filter(Boolean);
        }

        let sigStrokes = 0, drawing = false;
        function initSig() {
            const c = $('sig'), x = c.getContext('2d');
            c.width = 400; c.height = 150; x.lineWidth = 2.4; x.lineCap = 'round'; x.strokeStyle = '#16324a';
            const pt = (e) => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * 400 / r.width, (e.clientY - r.top) * 150 / r.height]; };
            c.onpointerdown = (e) => { drawing = true; c.setPointerCapture(e.pointerId); x.beginPath(); x.moveTo(...pt(e)); };
            c.onpointermove = (e) => { if (!drawing) return; x.lineTo(...pt(e)); x.stroke(); sigStrokes++; };
            c.onpointerup = c.onpointercancel = () => { drawing = false; };
        }

function clearPhoto() {
    document.getElementById('photo-data').value = '';
    document.getElementById('photo-input').value = '';
    document.getElementById('photo-box').innerHTML = 'No photo';
    document.getElementById('photo-clear').style.display = 'none';
    document.getElementById('photo-btn').textContent = 'Take photo';
}
document.getElementById('photo-input').addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = function () {
        const max = 480, k = Math.min(1, max / Math.max(img.width, img.height));
        const c = document.createElement('canvas');
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        const data = c.toDataURL('image/jpeg', 0.8);
        URL.revokeObjectURL(url);
        document.getElementById('photo-data').value = data;
        document.getElementById('photo-box').innerHTML = '<img src="' + data + '" alt="Applicant photo" style="width:100%;height:100%;object-fit:cover">';
        document.getElementById('photo-clear').style.display = '';
        document.getElementById('photo-btn').textContent = 'Retake';
    };
    img.onerror = function () { URL.revokeObjectURL(url); showToast('That file could not be read as a photo.', 'error'); };
    img.src = url;
});
function clearSig() { const c = $('sig'); c.getContext('2d').clearRect(0, 0, 400, 150); sigStrokes = 0; }

        const attrEsc = (v) => String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        let lastEnv = 'Marine';
        function renderGear() {
            const env = $('env-select').value || 'Marine';
            const groups = {};
            GEAR.filter(g => g.environment === env).forEach(g => (groups[g.method] = groups[g.method] || []).push(g));
            $('gear-list').innerHTML = Object.entries(groups).map(([method, items]) =>
                `<details class="gear-group"><summary>${escapeHtml(method)}</summary>` + items.map(g => `<div class="gear-row">
                    <input type="number" min="0" class="form-input gear-units" data-gid="${+g.id}" data-name="${attrEsc(g.gear_name)}" placeholder="0" style="width:70px">
                    <span>${escapeHtml(g.gear_name)}</span>
                    ${+g.takes_mesh ? '<input type="number" min="0" step="0.1" class="form-input gear-mesh" placeholder="Mesh cm" style="width:100px;display:none">' : ''}
                </div>`).join('') + '</details>').join('');
        }
        $('gear-list').addEventListener('input', (e) => {
            if (!e.target.classList.contains('gear-units')) return;
            const mesh = e.target.closest('.gear-row').querySelector('.gear-mesh');
            if (mesh) mesh.style.display = +e.target.value > 0 ? '' : 'none';
        });
        $('env-select').addEventListener('change', async () => {
            const hasGear = [...document.querySelectorAll('.gear-units')].some(g => +g.value > 0);
            if (hasGear && !(await bdConfirm('Changing the fishing environment clears the gear you entered. Continue?'))) { $('env-select').value = lastEnv; return; }
            lastEnv = $('env-select').value;
            renderGear();
        });

        function syncBoatRole() {
            const own = $('boat-role').value === 'Owner';
            $('boat-fields').style.display = own ? '' : 'none';
            $('boat-fields').querySelectorAll('input, select').forEach(el => { el.disabled = !own; });
        }
        $('boat-role').addEventListener('change', () => { syncBoatRole(); if (reqList().length) syncDocs(); });
        function resetExtras() { initDocs(); clearSig(); clearPhoto(); $('consent').checked = false; lastEnv = 'Marine'; renderGear(); syncBoatRole(); }

        let feedTimer, feedSeen = null;
        async function api(action, params = '') { return (await (await fetch('register.php?action=' + action + params)).json()).rows || []; }
        function rowHtml(r, isNew) {
            const st = r.hold;
            return `<div class="reg-row${isNew ? ' new' : ''}"><div><div class="rn">${escapeHtml(r.last_name + ', ' + r.first_name)}</div>
                <div class="rm">${escapeHtml(r.municipality + (r.barangay ? ' — ' + r.barangay : ''))} · ${escapeHtml(r.created_at)}</div></div>
                <span class="bdg ${st === 'Complete' ? 'bdg-ok' : 'bdg-hold'}">${st}</span></div>`;
        }
        function escapeHtml(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
        function showView(v) {
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.v === v));
            $('sidebar').classList.remove('open');
            $('form-wrap').classList.toggle('hide-view', v !== 'new');
            $('success-panel').classList.toggle('hide-view', v !== 'new');
            document.querySelectorAll('.view').forEach(e => e.classList.toggle('hide-view', e.id !== 'view-' + v));
            clearInterval(feedTimer);
            if (v === 'list') loadList();
            if (v === 'history') loadHistory();
            if (v === 'feed') { feedSeen = null; pollFeed(); feedTimer = setInterval(pollFeed, 8000); }
        }
        async function loadList() {
            try { const rs = await api('list', '&q=' + encodeURIComponent($('list-q').value));
                $('list-body').innerHTML = rs.map(r => rowHtml(r)).join('') || '<p class="empty-note">No registrations found.</p>';
            } catch (e) { $('list-body').innerHTML = '<p>Needs a connection.</p>'; }
        }
        async function pollFeed() {
            if (!navigator.onLine || document.hidden) return;
            try { const rs = await api('feed');
                const first = feedSeen === null; const ids = new Set(rs.map(r => r.id));
                $('feed-body').innerHTML = rs.map(r => rowHtml(r, !first && !feedSeen.has(r.id))).join('');
                feedSeen = ids;
            } catch (e) {}
        }
        async function loadHistory() {
            try { const rs = await api('history', '&date=' + $('hist-date').value);
                $('hist-body').innerHTML = rs.map(r => rowHtml(r)).join('') || '<p class="empty-note">Nothing for this date.</p>';
            } catch (e) { $('hist-body').innerHTML = '<p>Needs a connection.</p>'; }
            const q = await bdGetAll('registration_queue');
            $('hist-local').innerHTML = q.map(i => `<div class="reg-row"><div><div class="rn">${escapeHtml((i.payload.last_name || '') + ', ' + (i.payload.first_name || ''))}</div>
                <div class="rm">${escapeHtml(i.status === 'error' ? (i.error_message || 'Error') : 'Queued — waiting to sync')}</div></div></div>`).join('') || '<p class="empty-note">Nothing queued.</p>';
        }


        let toastTimer;
        function showToast(msg, kind) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast show ' + (kind || '');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.classList.remove('show'), 4000);
        }


        async function refreshQueueBanner() {
            const q = await bdGetAll('registration_queue');
            const banner = document.getElementById('queue-banner');
            const text = document.getElementById('queue-banner-text');
            if (q.length > 0) {
                text.textContent = q.length + ' registration' + (q.length > 1 ? 's' : '') + ' waiting to sync';
                banner.classList.add('show');
            } else {
                banner.classList.remove('show');
            }
        }

        async function flushQueue() {
            if (!navigator.onLine) return;
            const queued = await bdGetAll('registration_queue');
            for (const item of queued) {
                if (item.status === 'error') continue;
                try {
                    const res = await fetch('api_register.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(item.payload),
                    });
                    const data = await res.json();
                    if (data.success) {
                        await bdDelete('registration_queue', item.local_id);
                    } else {


                        item.status = 'error';
                        item.error_message = data.message || 'Server rejected this registration.';
                        await bdPut('registration_queue', item);
                    }
                } catch (e) {
                    break;
                }
            }
            refreshQueueBanner();

            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                navigator.serviceWorker.ready.then((reg) => reg.sync.register('bd-sync-registrations')).catch(() => {});
            }
        }

        function formToPayload(form) {
            const fd = new FormData(form), payload = {};
            for (const [k, v] of fd.entries()) { if (k === 'gear_units' || k === 'cannot_sign') continue; payload[k] = v; }
            payload.client_id = (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random());
            payload.documents = docsFromForm();
            payload.gear = [...document.querySelectorAll('.gear-units')].filter(g => !g.disabled && +g.value > 0).map(g => {
                const mesh = g.closest('.gear-row').querySelector('.gear-mesh');
                return { gear_id: +g.dataset.gid, name: g.dataset.name, units: +g.value, mesh_size_cm: mesh && mesh.value !== '' ? +mesh.value : null };
            });
            payload.cannot_sign = $('cannot-sign').checked ? 1 : 0;
            payload.signed_at = new Date().toISOString();
            payload.signature = sigStrokes >= 15 ? $('sig').toDataURL('image/png') : '';
            return payload;
        }

        document.getElementById('register-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            const form = e.target;
            const payload = formToPayload(form);

            if (!payload.first_name || !payload.last_name || !payload.municipality) {
                showToast('Please fill in all required fields.', 'error');
                return;
            }

            if (payload.birthdate && payload.birthdate > new Date().toISOString().split('T')[0]) {
                showToast('Birthdate cannot be in the future.', 'error');
                return;
            }

            if (!payload.cannot_sign && !payload.signature) { showToast('Signature required, or tick "Cannot sign".', 'error'); return; }
            if (payload.cannot_sign && !(payload.witness || '').trim()) { showToast('Enter the witness name.', 'error'); return; }
            if (!$('consent').checked) { showToast('Tick the applicant consent box.', 'error'); return; }

            const confirmed = await bdConfirm('Save this fisherfolk registration?');
            if (!confirmed) return;

            btn.disabled = true;
            btn.textContent = 'Submitting...';

            if (!navigator.onLine) {
                await bdPut('registration_queue', { payload, queued_at: new Date().toISOString(), status: 'queued' });
                showToast('Offline — registration queued, will sync automatically.', 'queued');
                form.reset();
                resetExtras();
                resetRFIDCard();
                refreshQueueBanner();
                btn.disabled = false;
                btn.textContent = 'Register Fisherfolk';
                return;
            }

            try {
                const res = await fetch('api_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (data.success) {
                    form.reset();
                    resetExtras();
                    resetRFIDCard();
                    showSuccessPanel(data);
                } else {
                    showToast(data.message || 'Registration failed.', 'error');
                }
            } catch (err) {

                await bdPut('registration_queue', { payload, queued_at: new Date().toISOString(), status: 'queued' });
                showToast('Connection lost — registration queued, will sync automatically.', 'queued');
                form.reset();
                resetExtras();
                resetRFIDCard();
                refreshQueueBanner();
            } finally {
                btn.disabled = false;
                btn.textContent = 'Register Fisherfolk';
            }
        });


        let lastRegistration = null;

        function showSuccessPanel(data) {
            lastRegistration = data;
            document.getElementById('form-wrap').style.display = 'none';
            document.getElementById('success-panel').classList.add('show');
            document.getElementById('success-name').textContent =
                data.last_name + ', ' + data.first_name + (data.middle_name ? ' ' + data.middle_name : '');
            document.getElementById('success-brn').textContent = data.boat_reg;
            document.getElementById('success-rfid-note').textContent = (data.rfid_uid ? '✓ RFID card linked  ' : '') + (data.hold !== 'Complete' ? data.hold : '');


            document.getElementById('qr-gen-status').style.display = data.hold === 'Complete' ? 'block' : 'none';
            document.getElementById('qr-gen-status').innerHTML = '<button class="btn-primary" style="width:auto;padding:10px 20px;" onclick="generateIDCard()">Generate QR ID Card</button>';
            document.getElementById('id-card-wrap').style.display = 'none';
            document.getElementById('download-card-btn').style.display = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function startAnotherRegistration() {
            document.getElementById('success-panel').classList.remove('show');
            document.getElementById('form-wrap').style.display = 'block';
            lastRegistration = null;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        async function generateIDCard() {
            if (!lastRegistration) return;
            const statusEl = document.getElementById('qr-gen-status');
            statusEl.innerHTML = 'Generating…';

            if (!navigator.onLine) {
                statusEl.innerHTML = 'Card generation needs a connection — try again once online. The registration itself is already saved.' +
                    '<br><br><button class="btn-primary" style="width:auto;padding:10px 20px;" onclick="generateIDCard()">Retry</button>';
                return;
            }

            try {
                const res = await fetch('api_qr_token.php?ff_id=' + lastRegistration.ff_id);
                const data = await res.json();
                if (data.error) {
                    statusEl.innerHTML = 'Error: ' + data.error +
                        '<br><br><button class="btn-primary" style="width:auto;padding:10px 20px;" onclick="generateIDCard()">Retry</button>';
                    return;
                }
                statusEl.style.display = 'none';
                document.getElementById('id-card-wrap').style.display = 'block';
                document.getElementById('card-name').textContent =
                    lastRegistration.last_name + ', ' + lastRegistration.first_name;
                document.getElementById('card-mun').textContent = lastRegistration.municipality +
                    (lastRegistration.barangay ? ' · ' + lastRegistration.barangay : '');
                document.getElementById('card-brn').textContent = data.brn;
                document.getElementById('card-rfid-tag').style.display = lastRegistration.rfid_uid ? 'inline-block' : 'none';

                const qrEl = document.getElementById('qr-preview');
                qrEl.innerHTML = '';
                new QRCode(qrEl, {
                    text: data.token,
                    width: 200, height: 200,
                    colorDark: '#000000', colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                });

                document.getElementById('download-card-btn').style.display = 'block';
            } catch (e) {
                statusEl.innerHTML = 'Connection error generating the card.' +
                    '<br><br><button class="btn-primary" style="width:auto;padding:10px 20px;" onclick="generateIDCard()">Retry</button>';
            }
        }

        function downloadIDCard() {
            if (!lastRegistration) return;

            setTimeout(() => {
                const qrCanvas = document.querySelector('#qr-preview canvas');
                const qrImg = document.querySelector('#qr-preview img');
                const qrDataUrl = qrCanvas ? qrCanvas.toDataURL('image/png') : (qrImg ? qrImg.src : null);
                if (!qrDataUrl) { showToast('QR not ready yet — try again in a moment.', 'error'); return; }

                const { jsPDF } = window.jspdf;

                const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: [85.6, 54] });
                pdf.setFillColor(27, 47, 91);
                pdf.rect(0, 0, 85.6, 54, 'F');
                pdf.setTextColor(255, 255, 255);
                pdf.setFontSize(9);
                pdf.setFont('helvetica', 'bold');
                pdf.text('BantayDagat: Connect', 6, 9);
                pdf.setFontSize(6.5);
                pdf.setFont('helvetica', 'normal');
                pdf.setTextColor(200, 210, 230);
                pdf.text('Fisherfolk Identification', 6, 13);

                pdf.addImage(qrDataUrl, 'PNG', 5, 17, 26, 26);

                pdf.setTextColor(255, 255, 255);
                pdf.setFontSize(9);
                pdf.setFont('helvetica', 'bold');
                pdf.text(lastRegistration.last_name + ', ' + lastRegistration.first_name, 35, 23, { maxWidth: 46 });
                pdf.setFontSize(6.5);
                pdf.setFont('helvetica', 'normal');
                pdf.setTextColor(190, 200, 220);
                pdf.text(lastRegistration.municipality, 35, 28, { maxWidth: 46 });
                pdf.setFontSize(5.5);
                pdf.setTextColor(150, 165, 195);
                pdf.text('BOAT REGISTRY NO.', 35, 36);
                pdf.setFontSize(7.5);
                pdf.setFont('courier', 'bold');
                pdf.setTextColor(255, 255, 255);
                pdf.text(lastRegistration.boat_reg, 35, 41);

                if (lastRegistration.rfid_uid) {
                    pdf.setFillColor(34, 197, 94);
                    pdf.roundedRect(35, 44, 22, 6, 1.5, 1.5, 'F');
                    pdf.setFontSize(5.5);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setTextColor(255, 255, 255);
                    pdf.text('RFID LINKED', 37, 48);
                }

                pdf.save('BantayDagat_ID_' + lastRegistration.boat_reg + '.pdf');
            }, 150);
        }

        document.addEventListener('click', (e) => { const sb = $('sidebar'); if (sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('#menu-btn')) sb.classList.remove('open'); });


        const BARANGAYS = <?= json_encode(allBarangays(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        function fillBarangays() {
            const sel = $('barangay'); if (!sel) return;
            const mun = currentMun(), keep = sel.value, list = BARANGAYS[mun] || [];
            sel.innerHTML = '';
            const first = document.createElement('option');
            first.value = ''; first.textContent = mun ? 'Select barangay...' : 'Select a municipality first';
            sel.appendChild(first);
            list.forEach(b => { const o = document.createElement('option'); o.value = b; o.textContent = b; sel.appendChild(o); });
            sel.disabled = !list.length;
            if (list.includes(keep)) sel.value = keep;
        }
        const munSelect = document.querySelector('select[name="municipality"]');
        if (munSelect) munSelect.addEventListener('change', fillBarangays);
        document.getElementById('register-form').addEventListener('reset', () => setTimeout(fillBarangays, 0));
        fillBarangays();

        if (munSelect) munSelect.addEventListener('change', initDocs);
        initDocs();
        renderGear();
        syncBoatRole();
        initSig();
        refreshQueueBanner();
    </script>
</body>

</html>