
<?php
    $map = json_decode(file_get_contents(__DIR__.'/data/landing-map.json'), true);
    [, , $mapWidth, $mapHeight] = array_map('floatval', explode(' ', $map['viewBox']));


    $qrModules = '';
    $isFinder = fn ($x, $y) => ($x < 8 && $y < 8) || ($x > 12 && $y < 8) || ($x < 8 && $y > 12);
    for ($y = 0; $y < 21; $y++) {
        for ($x = 0; $x < 21; $x++) {
            if (! $isFinder($x, $y) && crc32("bantay-dagat-{$x}-{$y}") % 2 === 0) {
                $qrModules .= "M{$x} {$y}h1v1h-1z";
            }
        }
    }
?>

<section id="in-action" class="coastal-action coastal-section" aria-labelledby="action-title">
    <div class="coastal-section-heading" data-reveal>
        <div>
            <p class="coastal-eyebrow">See it in action <span class="coastal-eyebrow__line" aria-hidden="true"></span></p>
            <h2 id="action-title">Built for the water.<br><span>Not just the office.</span></h2>
        </div>
        <p class="coastal-section-heading__intro">Two moments the system is designed around: a commercial vessel crossing into municipal waters, and a permit check at the shoreline.</p>
    </div>

    <div class="coastal-action__row">
        <figure class="coastal-waters is-inside" data-reveal data-waters-demo>
            <div class="coastal-waters__map">
                <svg viewBox="<?= e($map['viewBox']) ?>" role="img" aria-labelledby="waters-map-title waters-map-desc">
                    <title id="waters-map-title">Municipal waters of southern Antique</title>
                    <desc id="waters-map-desc">Map of San Jose de Buenavista, Hamtic, Tobias Fornier, and Anini-y with their 15-kilometer municipal waters. Municipal boats fish inside the zones while a commercial vessel crosses into <?= e($map['intruder']['waters']) ?> waters and is flagged.</desc>

                    <rect class="coastal-waters__sea" width="<?= e($mapWidth) ?>" height="<?= e($mapHeight) ?>" />
                    <?php foreach ($map['zones'] as $__k => $zone): ?>
                        <path class="coastal-waters__zone" d="<?= e($zone['path']) ?>"><title><?= e($zone['name']) ?>: 15-km municipal waters</title></path>
                    <?php endforeach; ?>
                    <path class="coastal-waters__land" d="<?= e($map['land']) ?>" />
                    <path class="coastal-waters__shore" d="<?= e($map['shoreline']) ?>" />

                    <?php foreach ($map['towns'] as $__k => $town): ?>
                        <g class="coastal-waters__town" transform="translate(<?= e($town['at'][0]) ?> <?= e($town['at'][1]) ?>)">
                            <circle r="3.5" />
                            <text x="9" y="4"><?= e($town['name']) ?></text>
                        </g>
                    <?php endforeach; ?>

                    <?php foreach ($map['boats'] as $__k => $boat): ?>
                        <g class="coastal-waters__boat" transform="translate(<?= e($boat['at'][0]) ?> <?= e($boat['at'][1]) ?>)" style="--pulse-delay: <?= e($__k * 0.6) ?>s">
                            <circle class="coastal-waters__halo" r="10" />
                            <circle r="4.5" />
                        </g>
                    <?php endforeach; ?>

                    <g class="coastal-waters__vessel" transform="translate(<?= e($map['passing'][0]) ?> <?= e($map['passing'][1]) ?>)"><circle r="5" /></g>

                    <line class="coastal-waters__track" x1="<?= e($map['intruder']['from'][0]) ?>" y1="<?= e($map['intruder']['from'][1]) ?>" x2="<?= e($map['intruder']['to'][0]) ?>" y2="<?= e($map['intruder']['to'][1]) ?>" />
                    <g class="coastal-waters__intruder" transform="translate(<?= e($map['intruder']['to'][0]) ?> <?= e($map['intruder']['to'][1]) ?>)"
                        data-intruder data-from="<?= e(implode(',', $map['intruder']['from'])) ?>" data-to="<?= e(implode(',', $map['intruder']['to'])) ?>" data-crossing="<?= e($map['intruder']['crossing']) ?>">
                        <circle class="coastal-waters__pulse" r="11" />
                        <circle r="6" />
                    </g>

                    <g class="coastal-waters__scale" transform="translate(22 <?= e($mapHeight - 28) ?>)">
                        <path d="M0 -5V0H<?= e($map['scale15km']) ?>V-5" />
                        <text x="0" y="16">15 km</text>
                    </g>
                </svg>

                <div class="coastal-waters__alert" aria-hidden="true">
                    <span class="coastal-waters__alert-icon">!</span>
                    <span><strong>Commercial vessel in municipal waters</strong><span><?= e($map['intruder']['waters']) ?> &middot; flagged for Coast Guard and Bantay Dagat</span></span>
                </div>
            </div>

            <figcaption>
                <ul class="coastal-waters__legend">
                    <li><span class="coastal-waters__key coastal-waters__key--boat"></span>Municipal boat</li>
                    <li><span class="coastal-waters__key coastal-waters__key--vessel"></span>Commercial vessel</li>
                    <li><span class="coastal-waters__key coastal-waters__key--flagged"></span>Flagged inside municipal waters</li>
                    <li><span class="coastal-waters__key coastal-waters__key--zone"></span>15-km reference zone</li>
                </ul>
                <p>Illustration. Shoreline &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors.</p>
            </figcaption>
        </figure>

        <div class="coastal-action__copy" data-reveal>
            <p class="coastal-action__kicker">Coastal monitoring</p>
            <h3>Watch the 15-kilometer line.</h3>
            <p>GPS trackers report vessel positions to the system. When a commercial fishing vessel enters a municipality's 15-kilometer waters, it is flagged on the map for the teams who can respond.</p>
            <ul class="coastal-action__points">
                <li>IoT GPS tracking for municipal boats and commercial vessels</li>
                <li>Automatic flag when a commercial vessel enters municipal waters</li>
                <li>Coast Guard and Bantay Dagat work from the same map</li>
            </ul>
            <p class="coastal-action__note">The 15-km line is a monitoring reference built from the shoreline. It is not an official legal boundary.</p>
        </div>
    </div>

    <div class="coastal-action__row coastal-action__row--reverse">
        <div class="coastal-action__copy" data-reveal>
            <p class="coastal-action__kicker">Field verification</p>
            <h3>Scan. Verify. Act.</h3>
            <p>Bantay Dagat and Coast Guard personnel scan a fisherfolk's QR card or RFID tag and see the matching registration, boat, and permit in seconds, right on their phone.</p>
            <ul class="coastal-action__points">
                <li>QR codes work with any phone camera; RFID adds a faster tap</li>
                <li>Permit status shows as valid or expired at a glance</li>
                <li>Unregistered or expired? Record an incident on the spot</li>
            </ul>
        </div>

        <div class="coastal-phone" data-reveal data-phone-demo>
            <div class="coastal-phone__screen" role="img" aria-label="Phone showing a scanned fisherfolk record with a valid fishing permit">
                <div class="coastal-phone__notch" aria-hidden="true"></div>
                <p class="coastal-phone__heading">Verify credential</p>
                <div class="coastal-phone__scanner">
                    <svg class="coastal-phone__qr" viewBox="-1 -1 23 23" aria-hidden="true">
                        <?php foreach ([[0, 0], [14, 0], [0, 14]] as $__k => [$fx, $fy]): ?>
                            <path d="M<?= e($fx) ?> <?= e($fy) ?>h7v7h-7zM<?= e($fx + 1) ?> <?= e($fy + 1) ?>v5h5v-5zM<?= e($fx + 2) ?> <?= e($fy + 2) ?>h3v3h-3z" fill-rule="evenodd" />
                        <?php endforeach; ?>
                        <path d="<?= e($qrModules) ?>" />
                    </svg>
                    <span class="coastal-phone__laser" aria-hidden="true"></span>
                </div>
                <p class="coastal-phone__status">
                    <span class="coastal-phone__status-scanning">Scanning QR code&hellip;</span>
                    <span class="coastal-phone__status-found">Record found</span>
                </p>
                <div class="coastal-phone__result">
                    <div class="coastal-phone__person">
                        <span class="coastal-phone__avatar" aria-hidden="true">RV</span>
                        <span><strong>Ramon D. Villanueva</strong><span>San Jose de Buenavista</span></span>
                    </div>
                    <p class="coastal-phone__badge">&#10003; Valid fishing permit</p>
                    <dl>
                        <div><dt>FishR no.</dt><dd>FR-SJB-000101</dd></div>
                        <div><dt>Boat</dt><dd>Bangka Maria</dd></div>
                        <div><dt>Offenses</dt><dd>None on record</dd></div>
                    </dl>
                    <p class="coastal-phone__sample">Sample record</p>
                </div>
            </div>
        </div>
    </div>
</section>
