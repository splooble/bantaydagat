<?php


const BARANGAY_LISTS = [
    'San Jose de Buenavista' => [
        'Atabay', 'Badiang',
        'Barangay 1 (Poblacion)', 'Barangay 2 (Poblacion)', 'Barangay 3 (Poblacion)', 'Barangay 4 (Poblacion)',
        'Barangay 5 (Poblacion)', 'Barangay 6 (Poblacion)', 'Barangay 7 (Poblacion)', 'Barangay 8 (Poblacion)',
        'Bariri', 'Bugarot (Catungan-Bugarot)', 'Cansadan (Cansadan-Tubudan)', 'Durog', 'Funda-Dalipe', 'Igbonglo',
        'Inabasan', 'Madrangca', 'Magcalon', 'Malaiba', 'Maybato Norte', 'Maybato Sur', 'Mojon', 'Pantao',
        'San Angel', 'San Fernando', 'San Pedro', 'Supa',
    ],
    'Hamtic' => [
        'Apdo', 'Asluman', 'Banawon', 'Bia-an', 'Bongbongan I-II', 'Bongbongan III', 'Botbot', 'Budbudan', 'Buhang',
        'Calacja I', 'Calacja II', 'Calala', 'Cantulan', 'Caridad', 'Caromangay', 'Casalngan', 'Dangcalan', 'Del Pilar',
        'Fabrica', 'Funda', 'General Fullon (Tina)', 'Gov. Evelio B. Javier (Lanag)', 'Guintas', 'Igbical', 'Igbucagay',
        'Inabasan', 'Ingwan-Batangan', 'La Paz', 'Linaban', 'Malandog', 'Mapatag', 'Masanag', 'Nalihawan',
        'Pamandayan (Botbot)', 'Pasu-Jungao', 'Piape I', 'Piape II', 'Piape III', 'Pili 1, 2, 3',
        'Poblacion 1', 'Poblacion 2', 'Poblacion 3', 'Poblacion 4', 'Poblacion 5', 'Pu-ao', 'Suloc', 'Villavert-Jimenez',
    ],
    'Tobias Fornier' => [
        'Abaca', 'Aras-Asan', 'Arobo', 'Atabay', 'Atiotes', 'Bagumbayan', 'Ballescas', 'Balud', 'Barasanan A',
        'Barasanan B', 'Barasanan C', 'Bariri', 'Camandagan', 'Cato-ogan', 'Danawan', 'Diclum', 'Fatima',
        'Gamad (Igdamacio)', 'Igbalogo', 'Igbangcal-A', 'Igbangcal-B', 'Igbangcal-C', 'Igcabuad', 'Igcadac', 'Igcado',
        'Igcalawagan', 'Igcapuyas', 'Igcasicad (San Pedro)', 'Igdalaguit', 'Igdanlog', 'Igdurarog', 'Igtugas', 'Lawigan',
        'Lindero', 'Manaling (Cata-an)', 'Masayo', 'Nagsubuan', 'Nasuli-A (San Ramon)', 'Opsan (San Jose)', 'Paciencia',
        'Poblacion Norte', 'Poblacion Sur', 'Portillo', 'Quezon', 'Samalague (Santa Maria)', 'Santo Tomas', 'Tacbuyan',
        'Tene', 'Villaflor', 'Ysulat',
    ],
    'Anini-y' => [
        'Bayo Grande', 'Bayo Pequeño', 'Butuan', 'Casay', 'Casay Viejo', 'Iba', 'Milagrosa (Igbarabatuan)', 'Igpalge',
        'Igtumarom', 'Lisub A', 'Lisub B', 'Mabuyong', 'Magdalena', 'Nasuli C', 'Nato', 'Poblacion', 'Sagua',
        'Salvacion', 'San Francisco (Dapdap)', 'San Ramon', 'San Roque', 'Tagaytay', 'Talisayan',
    ],
];


function barangaysFor(string $municipality): array {
    $list = BARANGAY_LISTS[$municipality] ?? [];
    natcasesort($list);
    return array_values($list);
}


function allBarangays(): array {
    $out = [];
    foreach (array_keys(BARANGAY_LISTS) as $m) $out[$m] = barangaysFor($m);
    return $out;
}


function canonicalBarangay(string $municipality, string $name): ?string {

    $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    $needle = $lower(preg_replace('/\s+/u', ' ', trim($name)));
    if ($needle === '') return null;
    foreach (BARANGAY_LISTS[$municipality] ?? [] as $b) {
        if ($lower($b) === $needle) return $b;
    }
    return null;
}
