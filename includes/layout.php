<?php
function pageHead($title = 'BantayDagat: Connect') {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} | BantayDagat: Connect</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script>
tailwind.config = {
    theme: { extend: {
        colors: {
            navy:  { DEFAULT:'#0a2f4f', light:'#054b7e', pale:'#eaf6fd' },
            blue:  { DEFAULT:'#006bb7', light:'#0a86c8', pale:'#e3f6ff', dark:'#054b7e' },
            cream: { DEFAULT:'#f4f9fc', dark:'#eaf6fd', border:'#d5e6f1' },
            sky:   '#eaf6fd', line:'#d5e6f1', cyan:'#6fd3f5',
            ink:   '#16324a',
            muted: '#5a7185',
        },
        fontFamily: { sans:['Instrument Sans','sans-serif'], mono:['JetBrains Mono','monospace'] }
    }}
}
</script>
<style>
:root { --blue:#006bb7; --blue-d:#054b7e; --navy:#0a2f4f; --ink:#16324a; --muted:#5a7185; --sky:#eaf6fd; --line:#d5e6f1; --cyan:#6fd3f5; }
* { font-family:'Instrument Sans',system-ui,sans-serif; box-sizing:border-box; }
html { scroll-behavior:smooth; }
body { background:#f4f9fc; color:#16324a; min-height:100vh; line-height:1.55; margin:0; }
h1,h2,h3 { letter-spacing:-.02em; color:#0a2f4f; }



.sidebar { background:#fff; border-right:1px solid #d5e6f1; z-index:30; position:fixed; left:0; top:0; min-height:100vh; display:flex; flex-direction:column; }
.nav-link { display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;color:#5a7185;font-size:14px;font-weight:600;transition:all .2s;text-decoration:none; }
.nav-link:hover { background:#eaf6fd;color:#006bb7; }
.nav-link.active { background:#eaf6fd;color:#054b7e;box-shadow:inset 3px 0 0 #006bb7; }
.nav-link .icon { width:18px;height:18px;flex-shrink:0; }


.glass, .card { background:#fff;border:1px solid #d5e6f1;border-radius:16px;box-shadow:0 10px 30px rgba(10,47,79,.05); }
.glass-dark { background:#eaf6fd;border:1px solid #d5e6f1;border-radius:16px; }
.stat-card { background:#f8fcff;border:1px solid #d5e6f1;border-radius:16px;padding:20px 22px;box-shadow:0 10px 30px rgba(10,47,79,.04);border-left:4px solid; }


.btn-primary { background:#006bb7;color:#fff;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:700;border:1.5px solid transparent;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none; }
.btn-primary:hover { background:#054b7e;transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,107,183,.25); }
.btn-secondary { background:#eaf6fd;color:#054b7e;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:700;border:1.5px solid transparent;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none; }
.btn-secondary:hover { background:#d9eefa; }
.btn-danger { background:#FEF2F2;color:#B91C1C;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:700;border:1.5px solid #FECACA;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px; }
.btn-danger:hover { background:#FEE2E2; }
.btn-warn { background:#FFFBEB;color:#B45309;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:700;border:1.5px solid #FDE68A;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px; }
.btn-warn:hover { background:#FEF3C7; }
.btn-sm { padding:5px 12px !important;font-size:12.5px !important; }

.form-input { width:100%;border:1.5px solid #d5e6f1;border-radius:9px;padding:10px 12px;font-size:14px;color:#16324a;background:#fff;transition:all .2s;outline:none;font-family:'Instrument Sans',sans-serif; }
.form-input:focus { border-color:#006bb7;box-shadow:0 0 0 3px rgba(0,107,183,.12); }
.form-label { font-size:11px;font-weight:800;color:#5a7185;margin-bottom:5px;display:block;text-transform:uppercase;letter-spacing:.12em; }

.data-table { width:100%;border-collapse:collapse;font-size:14px; }
.data-table th { background:#eaf6fd;color:#054b7e;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:11px 14px;text-align:left;border-bottom:1px solid #d5e6f1; }
.data-table td { padding:12px 14px;border-bottom:1px solid #e6f0f6;color:#16324a;vertical-align:middle; }
.data-table tr:last-child td { border-bottom:none; }
.data-table tbody tr:hover td { background:#f8fcff; }

.badge { display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600; }
.badge-active    { background:#DCFCE7;color:#15803D; }
.badge-expired   { background:#FEE2E2;color:#B91C1C; }
.badge-suspended { background:#FEF3C7;color:#B45309; }
.badge-pending   { background:#FEF9C3;color:#A16207; }
.badge-investigating { background:#e3f6ff;color:#054b7e; }
.badge-resolved  { background:#DCFCE7;color:#15803D; }

.alert-error   { background:#FEF2F2;color:#B91C1C;border:1px solid #FCA5A5;border-radius:12px;padding:10px 14px;font-size:13.5px; }
.alert-success { background:#F0FDF4;color:#15803D;border:1px solid #86EFAC;border-radius:10px;padding:10px 14px;font-size:13.5px; }

.modal-overlay { position:fixed;inset:0;background:rgba(4,36,61,.6);backdrop-filter:blur(6px);z-index:50;display:flex;align-items:center;justify-content:center;padding:20px; }
.modal-box { background:#fff;border:1px solid #d5e6f1;border-radius:20px;width:100%;max-width:760px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 80px rgba(4,36,61,.25); }

::-webkit-scrollbar { width:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:#c3dceb;border-radius:3px; }

@keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
.fade-up { animation:fadeUp .38s cubic-bezier(.16,1,.3,1) forwards; }

@keyframes pulse { 0%,100% { opacity:1 } 50% { opacity:.6 } }
.pulse { animation:pulse 2s infinite; }

.section-label { font-size:11px;font-weight:800;color:#006bb7;text-transform:uppercase;letter-spacing:.16em;margin-bottom:12px;display:flex;align-items:center;gap:12px; }
.section-label::before { content:'';width:28px;height:2px;background:currentColor;opacity:.5; }

.highlight-unread { background:rgba(239,68,68,.08) !important; border-left:3px solid #EF4444; }


.tooltip-container { position:relative;display:inline-block; }
.tooltip { position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#0a2f4f;color:#fff;padding:7px 11px;border-radius:7px;font-size:11.5px;font-weight:500;white-space:nowrap;z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s;margin-bottom:6px;box-shadow:0 4px 12px rgba(4,36,61,.25); }
.tooltip::after { content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#0a2f4f; }
.tooltip-container:hover .tooltip { opacity:1;pointer-events:auto; }


:focus-visible { outline:2px solid #006bb7;outline-offset:2px;border-radius:4px; }
button:focus-visible, a:focus-visible, [role="button"]:focus-visible { outline:2px solid #006bb7;outline-offset:2px; }


.btn-priority { position:relative; }
.btn-priority::after { content:'⚡';position:absolute;top:-3px;right:-4px;background:#EF4444;color:#fff;width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700; }
.btn-primary.btn-priority { box-shadow:0 4px 16px rgba(0,107,183,.4),inset 0 0 0 1px rgba(10,134,200,.5); }


.action-buttons { display:flex;gap:6px;flex-wrap:wrap; }
.action-btn { padding:6px 11px;font-size:12px;border-radius:6px;border:none;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:4px;font-weight:500; }
.action-btn.view { background:#006bb7;color:#fff;box-shadow:0 2px 6px rgba(0,107,183,.25); }
.action-btn.view:hover { background:#054b7e;box-shadow:0 3px 10px rgba(0,107,183,.35); }
.action-btn.edit { background:rgba(0,107,183,.1);color:#006bb7;border:1px solid rgba(0,107,183,.2); }
.action-btn.edit:hover { background:rgba(0,107,183,.15); }
.action-btn.delete { background:rgba(220,38,38,.1);color:#DC2626;border:1px solid rgba(220,38,38,.2); }
.action-btn.delete:hover { background:rgba(220,38,38,.15); }
.action-btn.danger { background:rgba(220,38,38,.8);color:#fff; }
.action-btn.danger:hover { background:#B91C1C; }


.skip-to-main { position:absolute;top:-40px;left:0;background:#006bb7;color:#fff;padding:8px 16px;border-radius:0 0 8px 0;z-index:100; }
.skip-to-main:focus { top:0; }


.nav-toggle, .nav-backdrop { display:none; }
@media (max-width:900px) {
  .sidebar { transform:translateX(-100%); transition:transform .25s ease; z-index:60; overflow-y:auto; max-width:85vw; }
  body.nav-open .sidebar { transform:none; box-shadow:0 0 60px rgba(4,36,61,.35); }
  .nav-toggle { display:flex; position:fixed; top:12px; left:12px; z-index:50; width:44px; height:44px; align-items:center; justify-content:center; border:1px solid #d5e6f1; border-radius:12px; background:#fff; color:#0a2f4f; cursor:pointer; box-shadow:0 4px 14px rgba(10,47,79,.12); }
  body.nav-open .nav-backdrop { display:block; position:fixed; inset:0; z-index:55; background:rgba(4,36,61,.5); }
  main { margin-left:0 !important; padding:68px 16px 28px !important; max-width:100% !important; }
  main [style*="grid-template-columns"]:not([style*="auto-fit"]) { grid-template-columns:minmax(0,1fr) !important; }
  main .dt-container, main div:has(> table) { overflow-x:auto; max-width:100%; }
  main h1 { font-size:24px !important; }
  #live-map { height:320px !important; }
}
</style>

<script>



document.addEventListener('DOMContentLoaded', function() {

    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(el => {
        el.classList.add('tooltip-container');
        const tooltipText = el.getAttribute('data-tooltip');
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = tooltipText;
        tooltip.setAttribute('role', 'tooltip');
        el.appendChild(tooltip);
    });


    tooltips.forEach(el => {
        el.addEventListener('focus', function() { this.querySelector('.tooltip').style.opacity = '1'; });
        el.addEventListener('blur', function() { this.querySelector('.tooltip').style.opacity = '0'; });
    });
});




function updateIncidentBadge() {
    fetch('api_incident_reads.php?action=count')
        .then(r => r.json())
        .then(data => {
            const badge = document.getElementById('incident-badge');
            if (!badge) return;
            if (data.unread > 0) {
                badge.textContent = data.unread > 99 ? '99+' : data.unread;
                badge.style.display = 'inline-flex';
                badge.setAttribute('aria-label', data.unread + ' unread incident reports');
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(e => console.error('Error updating incident badge:', e));
}


document.addEventListener('DOMContentLoaded', updateIncidentBadge);


setInterval(updateIncidentBadge, 30000);


function updateTransferBadge() {
    var els = document.querySelectorAll('.transfer-badge');
    if (!els.length) return;
    fetch('api_transfer.php?action=count')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            els.forEach(function (b) {
                if (d.unseen > 0) { b.textContent = d.unseen > 99 ? '99+' : d.unseen; b.style.display = 'inline-flex'; }
                else b.style.display = 'none';
            });
        })
        .catch(function () {});
}
document.addEventListener('DOMContentLoaded', updateTransferBadge);
setInterval(updateTransferBadge, 30000);
</script>
</head>
HTML;
}

function sidebar($active = '') {
    $user = getCurrentUser();
    $pages = [
        ['href'=>'dashboard.php',  'label'=>'Dashboard',           'key'=>'dashboard',  'icon'=>'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['href'=>'fisherfolk.php', 'label'=>'Fisherfolk Registry', 'key'=>'fisherfolk', 'icon'=>'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['href'=>'boats.php',      'label'=>'Boat Registry',       'key'=>'boats',      'icon'=>'M3 15.75h18l-2.4 3.75H5.4L3 15.75ZM12 3.75v12M12 5.25l6 8.25h-6M12 7.5l-4.5 6H12'],
        ['href'=>'incidents.php',  'label'=>'Incident Reports',    'key'=>'incidents',  'icon'=>'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
        ['href'=>'reports.php',    'label'=>'Reports &amp; Analytics', 'key'=>'reports',    'icon'=>'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
    ];

    if (isOfficer()) $pages = array_values(array_filter($pages, fn($p) => $p['key'] === 'dashboard'));


    if (in_array($user['role'], ['superadmin','admin'], true) && !isOfficer()) {
        $pages[] = ['href'=>'users.php', 'label'=>($user['role'] === 'superadmin' ? 'MAO Accounts' : 'Field Staff Accounts'), 'key'=>'users', 'icon'=>'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'];
    }


    $showTransfers = in_array($user['role'], ['admin','mao_staff'], true) && !empty($user['mun']) && !isOfficer();
    if ($showTransfers) {
        $pages[] = ['href'=>'transfers.php', 'label'=>'Transfer Requests', 'key'=>'transfers', 'icon'=>'M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4-4m-4 4l4 4'];
    }


    if ($user['role'] === 'admin' && !isOfficer()) {
        $pages[] = ['href'=>'admin_settings.php', 'label'=>'Admin Settings', 'key'=>'admin_settings', 'icon'=>'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'];
    }

    echo '<button type="button" class="nav-toggle" aria-label="Open menu" aria-controls="app-sidebar" onclick="document.body.classList.toggle(\'nav-open\')">';
    echo '<svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg></button>';
    echo '<div class="nav-backdrop" onclick="document.body.classList.remove(\'nav-open\')"></div>';
    echo '<aside id="app-sidebar" class="sidebar min-h-screen flex flex-col fixed left-0 top-0" style="width:224px">';

    if ($showTransfers) echo '<div style="display:flex;align-items:center;border-bottom:1px solid #d5e6f1">';
    echo '<a href="index.php" style="display:flex;align-items:center;gap:10px;padding:16px 18px;' . ($showTransfers ? 'flex:1;min-width:0;' : 'border-bottom:1px solid #d5e6f1;') . 'text-decoration:none" aria-label="Bantay Dagat home">';
    echo '<img src="landing/images/antique-coastal-emblem.jpg" alt="" style="width:38px;height:38px;object-fit:contain;mix-blend-mode:multiply">';
    echo '<div><p style="color:#0a2f4f;font-weight:800;font-size:16px;line-height:1.1;letter-spacing:-.02em;margin:0">Bantay Dagat</p>';
    echo '<p style="color:#5a7185;font-size:11px;font-weight:600;margin:2px 0 0">Connect</p></div>';
    echo '</a>';
    if ($showTransfers) {
        echo '<a href="transfers.php" id="transfer-bell" title="Transfer requests" aria-label="Transfer requests" style="position:relative;display:flex;align-items:center;justify-content:center;width:34px;height:34px;margin-right:10px;border-radius:10px;color:#5a7185;text-decoration:none;flex-shrink:0">';
        echo '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
        echo '<span class="transfer-badge" style="display:none;position:absolute;top:-3px;right:-3px;background:#DC2626;color:#fff;font-size:10px;font-weight:700;min-width:17px;height:17px;padding:0 4px;border-radius:999px;align-items:center;justify-content:center"></span>';
        echo '</a></div>';
    }

    echo '<nav style="flex:1;padding:14px 10px;display:flex;flex-direction:column;gap:3px">';
    if ($user['role'] !== 'superadmin' && $user['mun']) {
        echo '<div style="margin:0 4px 12px;padding:9px 12px;border-radius:12px;background:#eaf6fd;border:1px solid #d5e6f1">';
        echo '<p style="font-size:10px;color:#5a7185;text-transform:uppercase;letter-spacing:.14em;font-weight:800;margin:0 0 3px">Access scope</p>';
        echo '<p style="font-size:13px;color:#054b7e;font-weight:700;margin:0">' . htmlspecialchars($user['mun']) . '</p>';
        echo '</div>';
    }
    foreach ($pages as $p) {
        $cls = ($active === $p['key']) ? 'nav-link active' : 'nav-link';
        echo "<a href='{$p['href']}' class='{$cls}' " . ($p['key'] === 'incidents' ? "id='nav-incidents'" : "") . ">";
        echo "<svg class='icon' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='{$p['icon']}'/></svg>";
        echo "<span>{$p['label']}</span>";
        if ($p['key'] === 'transfers') {
            echo "<span class='transfer-badge' style='display:none;margin-left:auto;background:#DC2626;color:#fff;font-weight:700;font-size:11px;padding:2px 7px;border-radius:999px;min-width:20px;text-align:center;flex-shrink:0'></span>";
        }
        if ($p['key'] === 'incidents') {
            echo "<span id='incident-badge' style='display:none;margin-left:auto;background:#DC2626;color:#fff;font-weight:700;font-size:11px;padding:2px 7px;border-radius:999px;min-width:20px;text-align:center;flex-shrink:0'></span>";
        }
        echo "</a>";
    }
    echo '</nav>';

    echo '<div style="padding:10px;border-top:1px solid #d5e6f1">';
    echo '<div style="display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:12px;background:#f4f9fc;border:1px solid #d5e6f1;margin-bottom:6px">';
    echo '<div style="width:32px;height:32px;border-radius:50%;background:#006bb7;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;flex-shrink:0">' . strtoupper(substr($user['name'],0,1)) . '</div>';
    echo '<div style="flex:1;min-width:0"><p style="color:#0a2f4f;font-size:13px;font-weight:700;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . htmlspecialchars($user['name']) . '</p>';
    $modeNote = $user['dual'] ? ' &middot; ' . (isOfficer() ? 'Field mode' : 'Office mode') : '';
    echo '<p style="color:#5a7185;font-size:11px;margin:0;text-transform:capitalize">' . str_replace('_',' ',$user['role']) . $modeNote . '</p></div>';
    echo '</div>';
    if ($user['dual']) {
        $toField = !isOfficer();
        echo '<a href="switch_mode.php?to=' . ($toField ? 'field' : 'office') . '" class="nav-link" style="color:#006bb7">';
        echo '<svg class="icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4-4m-4 4l4 4"/></svg>';
        echo '<span style="font-size:13.5px">' . ($toField ? 'Switch to Field Mode' : 'Switch to Office Mode') . '</span></a>';
    }
    echo '<a href="logout.php" class="nav-link" style="color:#B91C1C">';
    echo '<svg class="icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>';
    echo '<span style="font-size:13.5px">Sign Out</span></a>';
    echo '</div></aside>';
}
?>