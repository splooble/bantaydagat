<?php

require_once __DIR__ . '/requirements.php';


require_once __DIR__ . '/tracking_settings.php';

const PERMIT_TYPES = ['FishR', 'BoatR', "Mayor's Permit", 'Sanitary Permit'];

function settingsConn(): ?mysqli {
    return (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
}

function settingDefaults(string $mun): array {
    $validity = [];
    foreach (PERMIT_TYPES as $t) $validity[$t] = 12;
    return [
        'requirements'         => MUN_REQUIREMENTS[$mun] ?? [],
        'access_requirements'  => [],
        'transfer_window_days' => 7,
        'expiring_soon_days'   => 30,
        'permit_validity'      => $validity,
        'office_name'          => $mun === 'Tobias Fornier' ? 'Municipal Agriculture and Services Office' : 'Municipal Agriculture Office',
        'encroach_seconds'     => defined('TRACKER_ENCROACH_SECONDS') ? (int)TRACKER_ENCROACH_SECONDS : 1800,
        'waters_access'        => in_array($mun, ['Hamtic', 'Tobias Fornier'], true) ? 'Closed' : 'Open',
    ];
}

const JSON_SETTING_KEYS = ['requirements', 'access_requirements', 'permit_validity'];
const INT_SETTING_KEYS  = ['transfer_window_days', 'expiring_soon_days', 'encroach_seconds'];


function settingRows(string $mun, bool $refresh = false): array {
    static $cache = [];
    if (!$refresh && isset($cache[$mun])) return $cache[$mun];
    $rows = [];
    $c = settingsConn();
    if ($c) {
        $st = @$c->prepare('SELECT setting_key, setting_value FROM settings WHERE municipality = ?');
        if ($st) {
            $st->bind_param('s', $mun);
            if ($st->execute()) {
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) $rows[$r['setting_key']] = $r['setting_value'];
            }
            $st->close();
        }
    }
    return $cache[$mun] = $rows;
}


function getSetting(string $mun, string $key) {
    $def  = settingDefaults($mun);
    $rows = settingRows($mun);
    if (!array_key_exists($key, $rows)) return $def[$key] ?? null;
    $v = $rows[$key];
    if (in_array($key, JSON_SETTING_KEYS, true)) {
        $d = json_decode($v, true);
        return is_array($d) ? $d : ($def[$key] ?? []);
    }
    if (in_array($key, INT_SETTING_KEYS, true)) return (int)$v;
    return $v;
}


function settingToText($v): string {
    return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
}


function saveSetting(mysqli $c, string $mun, string $key, $value, int $uid): bool {
    $new = settingToText($value);
    $old = settingToText(getSetting($mun, $key));
    if ($new === $old) return false;
    $st = $c->prepare('INSERT INTO settings (municipality, setting_key, setting_value, updated_by) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
    $st->bind_param('sssi', $mun, $key, $new, $uid);
    $ok = $st->execute(); $st->close();
    if (!$ok) return false;
    settingRows($mun, true);
    $lg = $c->prepare('INSERT INTO settings_log (municipality, setting_key, old_value, new_value, changed_by) VALUES (?,?,?,?,?)');
    $lg->bind_param('ssssi', $mun, $key, $old, $new, $uid);
    $lg->execute(); $lg->close();
    return true;
}


function permitValidityMonths(string $mun, string $type): int {
    $m = getSetting($mun, 'permit_validity');
    $n = (int)($m[$type] ?? 12);
    return ($n >= 1 && $n <= 60) ? $n : 12;
}


function allMunRequirements(): array {
    $out = [];
    foreach (array_keys(MUN_CODES) as $m) $out[$m] = effectiveRequirements($m);
    return $out;
}