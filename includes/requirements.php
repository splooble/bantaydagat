<?php


const MUN_CODES = [
    'San Jose de Buenavista' => 'SJB',
    'Hamtic'                 => 'HMT',
    'Tobias Fornier'         => 'TBF',
    'Anini-y'                => 'ANY',
];

const CHAIN_TYPES = ['FishR', 'BoatR', "Mayor's Permit"];


const OWNER_ONLY_TYPES = ['BoatR'];

const DOC_KIND = [
    'BoatR'             => 'permit',
    'FishR'             => 'permit',
    "Mayor's Permit"    => 'permit',
    'Sanitary Permit'   => 'permit',
    'Cedula'            => 'support',
    'Brgy Clearance'    => 'support',
    'Receipt'           => 'support',
    'MAO Certification' => 'support',
];


const MUN_REQUIREMENTS = [
    'San Jose de Buenavista' => ['FishR', 'BoatR', "Mayor's Permit", 'Cedula', 'Brgy Clearance', 'Receipt'],
    'Hamtic'                 => ['BoatR', "Mayor's Permit", 'MAO Certification', 'Brgy Clearance', 'Cedula', 'Receipt'],
    'Tobias Fornier'         => ['FishR', 'BoatR', "Mayor's Permit", 'Cedula', 'Brgy Clearance', 'Receipt'],
    'Anini-y'                => ['FishR', 'BoatR', "Mayor's Permit", 'Sanitary Permit', 'Brgy Clearance', 'Cedula', 'Receipt'],
];


function skippedForRole($type, $role) {
    return $role !== 'Owner' && in_array($type, OWNER_ONLY_TYPES, true);
}


function effectiveRequirements($mun) {
    $list = MUN_REQUIREMENTS[$mun] ?? [];
    if (function_exists('getSetting')) {
        $saved = getSetting((string)$mun, 'requirements');
        if (is_array($saved) && $saved) $list = $saved;
    }
    return array_values(array_filter($list, fn($t) => isset(DOC_KIND[$t])));
}


function munRequirements($mun, $role = 'Owner') {
    $list = effectiveRequirements($mun);
    return array_values(array_filter($list, fn($t) => !skippedForRole($t, $role)));
}


function munChain($mun, $role = 'Owner') {
    $req = munRequirements($mun, $role);
    return array_values(array_filter(CHAIN_TYPES, fn($t) => in_array($t, $req, true)));
}

function docKind($type) { return DOC_KIND[$type] ?? null; }


function holdText(array $heldTypes, $mun, $role = 'Owner', $transferee = false) {
    $req = munRequirements($mun, $role);
    if ($transferee) {


        $extra = array_values(array_filter(['FishR', $role === 'Owner' ? 'BoatR' : null], fn($t) => $t && !in_array($t, $req, true)));
        $req = array_merge($extra, $req);
    }
    foreach ($req as $t) {
        if (!in_array($t, $heldTypes, true)) return 'On hold · awaiting ' . $t;
    }
    return 'Complete';
}


require_once __DIR__ . '/settings.php';

require_once __DIR__ . '/transfers.php';
