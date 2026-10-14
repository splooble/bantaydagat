<?php

require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/layout.php';
require_once 'includes/requirements.php';
requireRole(MAO_ROLES);
$user = getCurrentUser();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$isSuper   = $user['role'] === 'superadmin';
$mun_scope = (!$isSuper && !empty($user['mun'])) ? $user['mun'] : null;
$municipalities = array_keys(MUN_CODES);

pageHead('Boat Registry');
?>


<link rel="stylesheet" href="https://cdn.datatables.net/2.3.7/css/dataTables.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.3.7/js/dataTables.min.js"></script>

<style>
    .dt-container { font-family: 'Instrument Sans', sans-serif; }
    table.dataTable thead th { font-size: 11px; font-weight: 800; color: #5a7185; text-transform: uppercase; letter-spacing: .12em; border-bottom: 1px solid #d5e6f1 !important; }
    table.dataTable tbody td { font-size: 13.5px; color: #16324a; border-bottom: 1px solid rgba(213,230,241,.5) !important; vertical-align: middle; }
    table.dataTable tbody tr:hover { background: #f4f9fc; }
    .dt-container .dt-search input, .dt-container .dt-length select { border: 1.5px solid #d5e6f1; border-radius: 9px; padding: 6px 10px; font-family: inherit; outline: none; }
    .dt-container .dt-paging .dt-paging-button.current { background: #eaf6fd !important; border-color: #006bb7 !important; color: #054b7e !important; }
    .pill { display:inline-block; font-size:11px; font-weight:700; padding:2px 10px; border-radius:999px; }
    .sec-title { font-size:11px; font-weight:800; color:#5a7185; text-transform:uppercase; letter-spacing:.12em; margin:0 0 10px; }
    .kv { display:grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px 18px; }
    .kv .k { font-size:11px; font-weight:700; color:#7d93a5; text-transform:uppercase; letter-spacing:.08em; margin:0 0 2px; }
    .kv .v { font-size:14px; font-weight:600; color:#16324a; margin:0; word-break:break-word; }
    .sec { padding:18px 0; border-top:1px solid rgba(213,230,241,.7); }
    .sec:first-child { border-top:none; padding-top:0; }
    .row-line { display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid rgba(213,230,241,.5); }
    .row-line:last-child { border-bottom:none; }
    .notice { padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:14px; }
    .notice.ok { background:#DCFCE7; color:#15803D; } .notice.err { background:#FEE2E2; color:#B91C1C; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 900px) { main { margin-left: 0 !important; padding: 72px 16px 24px !important; } }
</style>

<body>
    <?php sidebar('boats'); ?>
    <main class="fade-up" style="margin-left:224px;min-height:100vh;padding:32px 36px;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;gap:16px;flex-wrap:wrap;">
            <div>
                <h1 style="font-size:22px;font-weight:700;color:#16324a;margin:0 0 4px;">Boat Registry</h1>
                <p style="font-size:13px;color:#5a7185;margin:0;">View and update boat details, owners and crew</p>
            </div>
            <?php if ($isSuper): ?>
            <div style="min-width:220px;">
                <label class="form-label" for="mun-filter">Municipality</label>
                <select id="mun-filter" class="form-input">
                    <option value="">All municipalities</option>
                    <?php foreach ($municipalities as $m): ?><option><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="card" style="overflow:hidden;">
            <div style="padding:14px 20px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-weight:700;font-size:14px;color:#16324a;margin:0;">Registered Boats</h2>
            </div>
            <div style="padding:16px 20px;overflow-x:auto;">
                <table id="boats-table" class="display" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Boat</th>
                            <th>Owner</th>
                            <th>Fisherfolk Registry No.</th>
                            <th>Homeport</th>
                            <th>Type</th>
                            <th>Class</th>
                            <th>Crew</th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </main>


    <div id="boatModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:760px;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid rgba(213,230,241,.5);">
                <h2 style="font-size:17px;font-weight:700;color:#16324a;margin:0;" id="boat-title">Boat</h2>
                <button type="button" onclick="closeBoat()" style="background:none;border:none;cursor:pointer;color:#7d93a5;" aria-label="Close">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="boat-body" style="padding:24px;max-height:75vh;overflow-y:auto;"></div>
        </div>
    </div>

    <script>
    var CSRF = <?= json_encode($_SESSION['csrf']) ?>;
    var IS_SUPER = <?= json_encode($isSuper) ?>;
    var cur = null, editing = false;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function dash(v) { return (v === null || v === undefined || v === '') ? '—' : esc(v); }
    function num(v, unit) { return (v === null || v === undefined) ? '—' : esc(v) + (unit ? ' ' + unit : ''); }
    function classPill(c) {
        if (!c) return '<span style="color:#7d93a5;">—</span>';
        var com = c === 'Commercial';
        return '<span class="pill" style="background:' + (com ? '#FEF3C7' : '#E0F0FB') + ';color:' + (com ? '#B45309' : '#054b7e') + ';">' + c + '</span>';
    }


    var table;
    $(document).ready(function () {
        table = $('#boats-table').DataTable({
            pageLength: 25, serverSide: true, processing: true, searchDelay: 350,
            ajax: { url: 'api_boats_dt.php', data: function (d) { var f = document.getElementById('mun-filter'); d.mun = f ? f.value : ''; } },
            order: [[0, 'asc']],
            columns: [
                { data: 'name', render: function (d, t, r) {
                    return '<div style="font-weight:600;color:#16324a;">' + esc(d) + '</div>' +
                           (r.gross_tonnage !== null ? '<div style="font-size:11px;color:#7d93a5;">' + esc(r.gross_tonnage) + ' GT</div>' : '');
                } },
                { data: 'owner', render: function (d, t, r) {
                    return '<div style="font-weight:600;">' + esc(d) + '</div>' + (IS_SUPER ? '<div style="font-size:11px;color:#7d93a5;">' + esc(r.municipality) + '</div>' : '');
                } },
                { data: 'registry_no', render: function (d) {
                    return '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:#006bb7;font-weight:600;">' + (d ? esc(d) : '—') + '</span>';
                } },
                { data: 'homeport', render: function (d) { return dash(d); } },
                { data: 'boat_type', render: function (d) { return dash(d); } },
                { data: 'fisher_class', render: function (d) { return classPill(d); } },
                { data: 'crew_count', render: function (d, t, r) {
                    return '<span class="pill" style="background:' + (d > 0 ? '#DCFCE7' : '#EEF2F6') + ';color:' + (d > 0 ? '#15803D' : '#5a7185') + ';">' + d + '</span>';
                } },
                { data: null, orderable: false, searchable: false, className: 'dt-center', render: function (d, t, r) {
                    return '<button type="button" class="btn-secondary btn-sm" onclick="openBoat(' + r.id + ')" aria-label="View ' + esc(r.name) + '">View / Update</button>';
                } }
            ],
            columnDefs: [{ orderable: false, searchable: false, targets: 7 }],
            language: { processing: 'Loading…', info: 'Showing _START_ to _END_ of _TOTAL_ boats', infoEmpty: 'No boats found', emptyTable: 'No boats registered yet', zeroRecords: 'No matching boats' }
        });
        var f = document.getElementById('mun-filter');
        if (f) f.addEventListener('change', function () { table.ajax.reload(); });
        document.getElementById('boatModal').addEventListener('click', function (e) { if (e.target === this) closeBoat(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeBoat(); });
    });


    function closeBoat() { document.getElementById('boatModal').style.display = 'none'; cur = null; editing = false; }
    function spinner() {
        return '<div style="text-align:center;padding:32px 0;"><div style="width:32px;height:32px;border:3px solid rgba(0,107,183,.2);border-top-color:#006bb7;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div><p style="font-size:13px;color:#5a7185;margin:0;">Loading...</p></div>';
    }
    function notice(kind, msg) {
        var el = document.getElementById('boat-notice');
        if (!el) return;
        el.className = 'notice ' + (kind === 'ok' ? 'ok' : 'err');
        el.textContent = msg; el.style.display = 'block';
        if (kind === 'ok') setTimeout(function () { if (el.textContent === msg) el.style.display = 'none'; }, 3500);
    }
    function api(url, opts) {
        return fetch(url, opts).then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected response from the server.' }; }); });
    }
    function post(payload) {
        payload.csrf = CSRF; payload.boat_id = cur.boat.id;
        return api('api_boat.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    }

    function openBoat(id) {
        editing = false;
        document.getElementById('boat-title').textContent = 'Boat';
        document.getElementById('boat-body').innerHTML = spinner();
        document.getElementById('boatModal').style.display = 'flex';
        loadBoat(id);
    }
    function loadBoat(id) {
        return api('api_boat.php?id=' + id).then(function (d) {
            if (!d || !d.success) {
                document.getElementById('boat-body').innerHTML = '<div class="notice err">' + esc((d && d.error) || 'Could not load this boat.') + '</div>';
                return;
            }
            cur = d; render();
        }).catch(function () { document.getElementById('boat-body').innerHTML = '<div class="notice err">Could not reach the server.</div>'; });
    }

    function render() {
        var b = cur.boat;
        document.getElementById('boat-title').textContent = b.name || 'Boat';
        document.getElementById('boat-body').innerHTML =
            '<div id="boat-notice" class="notice" style="display:none;"></div>' +
            '<div class="sec" id="sec-details"></div>' +
            '<div class="sec" id="sec-owner"></div>' +
            '<div class="sec" id="sec-crew"></div>';
        editing ? renderEdit() : renderDetails();
        renderOwner();
        renderCrew();
    }

    function kv(k, v) { return '<div><p class="k">' + k + '</p><p class="v">' + v + '</p></div>'; }

    function renderDetails() {
        var b = cur.boat, br = cur.boatr;
        var gear = cur.gear.length ? cur.gear.map(function (g) {
            return '<div class="row-line"><span style="flex:1;font-weight:600;">' + esc(g.name) + '</span><span style="color:#5a7185;font-size:13px;">' +
                esc(g.units) + ' unit' + (g.units === 1 ? '' : 's') + (g.mesh_size_cm !== null ? ' · ' + esc(g.mesh_size_cm) + ' cm mesh' : '') + '</span></div>';
        }).join('') : '<p style="font-size:13px;color:#7d93a5;margin:0;">No gear declared.</p>';
        document.getElementById('sec-details').innerHTML =
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;"><p class="sec-title" style="margin:0;">Boat details</p>' +
            '<button type="button" class="btn-secondary btn-sm" onclick="startEdit()">Edit details</button></div>' +
            '<div class="kv">' +
                kv('Boat name', dash(b.name)) + kv('Homeport', dash(b.homeport)) + kv('Type', dash(b.boat_type)) + kv('Material', dash(b.material)) +
                kv('Class', classPill(b.fisher_class)) + kv('Gross tonnage', num(b.gross_tonnage, 'GT')) +
                kv('Length', num(b.length_m, 'm')) + kv('Breadth', num(b.breadth_m, 'm')) + kv('Depth', num(b.depth_m, 'm')) +
                kv('Horsepower', num(b.horsepower, 'HP')) + kv('Engine make', dash(b.engine_make)) + kv('Declared fishers', num(b.no_fishers)) +
                kv('BoatR', br ? esc(br.permit_number) + ' <span style="font-weight:500;color:#7d93a5;">(' + esc(br.status) + (br.expiry_date ? ', until ' + esc(br.expiry_date) : '') + ')</span>' : '—') +
            '</div>' +
            '<p class="sec-title" style="margin:18px 0 6px;">Gear</p>' + gear;
    }

    function field(label, name, val, type, extra) {
        return '<div><label class="form-label" for="f-' + name + '">' + label + '</label><input id="f-' + name + '" type="' + (type || 'text') + '" class="form-input" value="' + esc(val) + '" ' + (extra || '') + '></div>';
    }
    function selectField(label, name, options, val) {
        var opts = ['<option value="">Select...</option>'];
        var list = options.slice();
        if (val && list.indexOf(val) === -1) list.push(val);
        list.forEach(function (o) { opts.push('<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>'); });
        return '<div><label class="form-label" for="f-' + name + '">' + label + '</label><select id="f-' + name + '" class="form-input">' + opts.join('') + '</select></div>';
    }
    function gearRow(g) {
        var sel = ['<option value="">Select gear...</option>'];
        var seen = {};
        cur.gear_options.forEach(function (o) { seen[o.id] = 1; sel.push('<option value="' + o.id + '" data-mesh="' + o.takes_mesh + '"' + (g && o.id === g.gear_id ? ' selected' : '') + '>' + esc(o.gear_name) + '</option>'); });
        if (g && !seen[g.gear_id]) sel.push('<option value="' + g.gear_id + '" data-mesh="' + g.takes_mesh + '" selected>' + esc(g.name) + '</option>');
        var showMesh = g && g.takes_mesh;
        return '<div class="row-line gear-edit-row">' +
            '<select class="form-input g-id" style="flex:1;min-width:0;" onchange="gearChanged(this)">' + sel.join('') + '</select>' +
            '<input type="number" min="1" step="1" class="form-input g-units" placeholder="Units" style="width:90px;" value="' + (g ? esc(g.units) : '') + '">' +
            '<input type="number" min="0" step="0.1" class="form-input g-mesh" placeholder="Mesh cm" style="width:100px;' + (showMesh ? '' : 'display:none;') + '" value="' + (g && g.mesh_size_cm !== null ? esc(g.mesh_size_cm) : '') + '">' +
            '<button type="button" class="btn-danger btn-sm" onclick="this.closest(\'.gear-edit-row\').remove()" aria-label="Remove gear">Remove</button></div>';
    }
    function gearChanged(sel) {
        var mesh = sel.closest('.gear-edit-row').querySelector('.g-mesh');
        var takes = sel.selectedOptions[0] && sel.selectedOptions[0].dataset.mesh === '1';
        mesh.style.display = takes ? '' : 'none';
        if (!takes) mesh.value = '';
    }
    function addGearRow() { document.getElementById('gear-rows').insertAdjacentHTML('beforeend', gearRow(null)); }

    function startEdit() { editing = true; renderEdit(); }
    function cancelEdit() { editing = false; renderDetails(); }
    function renderEdit() {
        var b = cur.boat;
        document.getElementById('sec-details').innerHTML =
            '<p class="sec-title">Edit boat details</p>' +
            '<div class="kv" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));">' +
                field('Boat name', 'name', b.name, 'text', 'maxlength="100"') + field('Homeport', 'homeport', b.homeport, 'text', 'maxlength="100"') +
                selectField('Type', 'boat_type', cur.types, b.boat_type) + selectField('Material', 'material', cur.materials, b.material) +
                field('Gross tonnage (GT)', 'gross_tonnage', b.gross_tonnage, 'number', 'min="0" step="0.01"') +
                field('Length (m)', 'length_m', b.length_m, 'number', 'min="0" step="0.01"') + field('Breadth (m)', 'breadth_m', b.breadth_m, 'number', 'min="0" step="0.01"') +
                field('Depth (m)', 'depth_m', b.depth_m, 'number', 'min="0" step="0.01"') + field('Horsepower', 'horsepower', b.horsepower, 'number', 'min="0" step="0.1"') +
                field('Engine make', 'engine_make', b.engine_make, 'text', 'maxlength="100"') + field('Declared fishers', 'no_fishers', b.no_fishers, 'number', 'min="0" step="1"') +
            '</div>' +
            '<p style="font-size:12px;color:#7d93a5;margin:10px 0 0;">Class follows the gross tonnage: over 3 GT is Commercial, otherwise Municipal.</p>' +
            '<p class="sec-title" style="margin:18px 0 6px;">Gear (' + esc(cur.owner.fishing_environment) + ')</p>' +
            '<div id="gear-rows">' + cur.gear.map(gearRow).join('') + '</div>' +
            '<button type="button" class="btn-secondary btn-sm" style="margin-top:10px;" onclick="addGearRow()">+ Add gear</button>' +
            '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">' +
                '<button type="button" class="btn-secondary" onclick="cancelEdit()">Cancel</button>' +
                '<button type="button" class="btn-primary" id="save-btn" onclick="saveBoat()">Save changes</button></div>';
    }
    function saveBoat() {
        var v = function (id) { return document.getElementById('f-' + id).value; };
        var gear = [];
        document.querySelectorAll('#gear-rows .gear-edit-row').forEach(function (row) {
            var gid = row.querySelector('.g-id').value;
            if (!gid) return;
            gear.push({ gear_id: +gid, units: +row.querySelector('.g-units').value || 0, mesh_size_cm: row.querySelector('.g-mesh').value });
        });
        var btn = document.getElementById('save-btn'); btn.disabled = true; btn.textContent = 'Saving…';
        post({ action: 'save_boat', name: v('name'), homeport: v('homeport'), boat_type: v('boat_type'), material: v('material'),
               gross_tonnage: v('gross_tonnage'), length_m: v('length_m'), breadth_m: v('breadth_m'), depth_m: v('depth_m'),
               horsepower: v('horsepower'), engine_make: v('engine_make'), no_fishers: v('no_fishers'), gear: gear })
        .then(function (d) {
            if (d.success) {
                editing = false;
                loadBoat(cur.boat.id).then(function () { notice('ok', d.message); if (table) table.ajax.reload(null, false); });
            } else { btn.disabled = false; btn.textContent = 'Save changes'; notice('err', d.error || 'Could not save.'); }
        }).catch(function () { btn.disabled = false; btn.textContent = 'Save changes'; notice('err', 'Could not reach the server.'); });
    }

    function renderOwner() {
        var o = cur.owner;
        document.getElementById('sec-owner').innerHTML =
            '<p class="sec-title">Owner</p><div class="kv">' +
            kv('Name', dash(o.name)) + kv('Fisherfolk Registry No.', o.registry_no ? '<span style="font-family:\'JetBrains Mono\',monospace;color:#006bb7;">' + esc(o.registry_no) + '</span>' : '—') +
            kv('Contact', dash(o.contact)) + kv('Barangay', dash(o.barangay)) + kv('Municipality', dash(o.municipality)) + '</div>' +
            '<p style="font-size:12px;color:#7d93a5;margin:10px 0 0;">The owner is managed on the Fisherfolk Registry and through transfers.</p>';
    }


    function renderCrew() {
        var rows = cur.crew.length ? cur.crew.map(function (c) {
            return '<div class="row-line"><div style="flex:1;min-width:0;"><div style="font-weight:600;">' + esc(c.name) + '</div>' +
                '<div style="font-size:12px;color:#7d93a5;">' + (c.registry_no ? esc(c.registry_no) : 'No registry no.') + (c.contact ? ' · ' + esc(c.contact) : '') + (c.barangay ? ' · ' + esc(c.barangay) : '') +
                (IS_SUPER || !c.can_remove ? ' · ' + esc(c.municipality) : '') + '</div></div>' +
                (c.can_remove ? '<button type="button" class="btn-danger btn-sm" onclick="removeCrew(' + c.id + ')">Remove</button>'
                              : '<span style="font-size:11px;color:#7d93a5;">Other municipality</span>') + '</div>';
        }).join('') : '<p style="font-size:13px;color:#7d93a5;margin:0;">No crew assigned to this boat.</p>';
        var declared = cur.boat.no_fishers;
        document.getElementById('sec-crew').innerHTML =
            '<div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;"><p class="sec-title" style="margin:0;">Crew (' + cur.crew.length + ')</p>' +
            (declared !== null ? '<span style="font-size:12px;color:#7d93a5;">' + esc(declared) + ' fishers declared on the boat</span>' : '') + '</div>' +
            '<div id="crew-list">' + rows + '</div>' +
            '<div style="margin-top:16px;"><label class="form-label" for="crew-q">Add crew</label>' +
            '<input id="crew-q" type="search" class="form-input" placeholder="Search by name or registry number" autocomplete="off" oninput="crewSearchDebounced()">' +
            '<div id="crew-results" style="margin-top:6px;"></div>' +
            '<p style="font-size:12px;color:#7d93a5;margin:8px 0 0;">Only fisherfolk in your municipality who are registered as Crew or No boat and are not on another boat appear here.</p></div>';
    }
    var crewTimer = null;
    function crewSearchDebounced() { clearTimeout(crewTimer); crewTimer = setTimeout(crewSearch, 300); }
    function crewSearch() {
        var q = document.getElementById('crew-q').value.trim();
        var box = document.getElementById('crew-results');
        if (q.length < 2) { box.innerHTML = ''; return; }
        var boatId = cur.boat.id;
        api('api_boat.php?id=' + boatId + '&crew_search=' + encodeURIComponent(q)).then(function (d) {
            if (!cur || cur.boat.id !== boatId || document.getElementById('crew-q').value.trim() !== q) return;
            if (!d.success) { box.innerHTML = '<div class="notice err">' + esc(d.error || 'Search failed.') + '</div>'; return; }
            box.innerHTML = d.results.length ? d.results.map(function (r) {
                return '<div class="row-line"><div style="flex:1;min-width:0;"><div style="font-weight:600;">' + esc(r.name) + '</div>' +
                    '<div style="font-size:12px;color:#7d93a5;">' + (r.registry_no ? esc(r.registry_no) : 'No registry no.') + (r.barangay ? ' · ' + esc(r.barangay) : '') + ' · ' + esc(r.role) + '</div></div>' +
                    '<button type="button" class="btn-primary btn-sm" onclick="addCrew(' + r.id + ')">Add</button></div>';
            }).join('') : '<p style="font-size:13px;color:#7d93a5;margin:6px 0 0;">No matching fisherfolk can be added.</p>';
        });
    }
    function refreshCrew(msg) {

        return api('api_boat.php?id=' + cur.boat.id).then(function (d) {
            if (d && d.success) { cur.crew = d.crew; renderCrew(); if (table) table.ajax.reload(null, false); }
            if (msg) notice('ok', msg);
        });
    }
    function addCrew(ffid) {
        post({ action: 'add_crew', fisherfolk_id: ffid }).then(function (d) {
            if (d.success) refreshCrew(d.message); else notice('err', d.error || 'Could not add crew.');
        }).catch(function () { notice('err', 'Could not reach the server.'); });
    }
    function removeCrew(ffid) {
        if (!confirm('Remove this person from the crew of this boat?')) return;
        post({ action: 'remove_crew', fisherfolk_id: ffid }).then(function (d) {
            if (d.success) refreshCrew(d.message); else notice('err', d.error || 'Could not remove crew.');
        }).catch(function () { notice('err', 'Could not reach the server.'); });
    }
    </script>
</body>
</html>
