<section class="coastal-hero" style="--hero-photo: url('../images/antique-coast-hero.jpg')" aria-labelledby="hero-title">
        <div class="coastal-hero__content">
            <p class="coastal-eyebrow coastal-eyebrow--cyan">Rooted in southern Antique</p>
            <h1 id="hero-title">Connected records.<br><span>Protected coasts.</span></h1>
            <p class="coastal-hero__intro">A shared workspace for fisherfolk registration, permit verification, and coastal monitoring—built around the people who know this shoreline best.</p>
            <div class="coastal-hero__actions">
                <a class="coastal-button coastal-button--blue" href="#platform">Explore the platform <span aria-hidden="true">↗</span></a>
                <a class="coastal-button coastal-button--outline" href="#how-it-works">See how it works</a>
            </div>
            <div class="coastal-stats" aria-label="Platform highlights">
                <div class="coastal-stat"><svg aria-hidden="true"><use href="#coastal-icon-users" /></svg><p><strong>04</strong><span>municipalities</span></p></div>
                <div class="coastal-stat"><svg aria-hidden="true"><use href="#coastal-icon-qr" /></svg><p><strong>QR + RFID</strong><span>identification</span></p></div>
                <div class="coastal-stat"><svg aria-hidden="true"><use href="#coastal-icon-pin" /></svg><p><strong>IoT + GPS</strong><span>monitoring</span></p></div>
            </div>
        </div>
        <?php include __DIR__."/login-form.php"; ?>
        <a class="coastal-hero__scroll" href="#why-it-matters">Scroll to discover <span aria-hidden="true">↓</span></a>
        <div class="coastal-waves" aria-hidden="true">
            <svg class="coastal-waves__layer coastal-waves__layer--back" viewBox="0 0 2880 64" preserveAspectRatio="none"><path class="coastal-waves__back" d="M0 26C240 44 480 44 720 26S1200 8 1440 26S1920 44 2160 26S2640 8 2880 26V64H0Z" /></svg>
            <svg class="coastal-waves__layer coastal-waves__layer--front" viewBox="0 0 2880 64" preserveAspectRatio="none"><path class="coastal-waves__front" d="M0 34C240 16 480 16 720 34S1200 52 1440 34S1920 16 2160 34S2640 52 2880 34V64H0Z" /></svg>
        </div>
    </section>

    <div class="coastal-municipalities" aria-label="Participating municipalities">
        <span class="coastal-municipalities__label">One shared coastline</span>
        <div class="coastal-municipalities__grid">
            <?php foreach ([['San Jose de Buenavista', 'town'], ['Hamtic', 'mountain'], ['Tobias Fornier', 'mountain'], ['Anini-y', 'palm']] as $__k => [$municipality, $icon]): ?>
                <div class="coastal-municipalities__item"><svg aria-hidden="true"><use href="#coastal-icon-<?= e($icon) ?>" /></svg><span><?= e($municipality) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php include __DIR__."/impact.php"; ?>

    <section id="platform" class="coastal-platform coastal-section" aria-labelledby="platform-title">
        <div class="coastal-section-heading" data-reveal>
            <div><p class="coastal-eyebrow">The platform <span class="coastal-eyebrow__line" aria-hidden="true"></span></p><h2 id="platform-title">Local knowledge.<br><span>Connected tools.</span></h2></div>
            <p class="coastal-section-heading__intro">Records become more useful when the people at the municipal office and on the water can work from the same picture.</p>
        </div>
        <div class="coastal-features">
            <?php foreach ([
                ['01', 'Fisherfolk registration', 'Keep fisherfolk profiles and local records organized in one place, so the details needed for day-to-day work are easier to find.', 'users', 'register-step'],
                ['02', 'Permit verification', 'Connect credentials to field checks and make it easier for authorized personnel to verify records when they need them.', 'document', 'verify-step'],
                ['03', 'Coastal monitoring', 'Bring vessel information, positions, and incident reports into a clearer view of activity along the coast.', 'waves', 'monitor-step'],
            ] as $__k => [$number, $title, $description, $icon, $target]): ?>
                <article class="coastal-feature" data-reveal>
                    <div class="coastal-feature__top"><div class="coastal-feature__icon"><svg aria-hidden="true"><use href="#coastal-icon-<?= e($icon) ?>" /></svg></div><span><?= e($number) ?> / 03</span></div>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($description) ?></p>
                    <a href="#<?= e($target) ?>" class="coastal-feature__link" aria-label="Learn more about <?= e(strtolower($title)) ?>">Explore this step <span aria-hidden="true">↗</span></a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="how-it-works" class="coastal-process coastal-section" style="--process-photo: url('../images/antique-coast-hero.jpg')" aria-labelledby="process-title">
        <div class="coastal-process__heading" data-reveal>
            <div class="coastal-section-heading"><p class="coastal-eyebrow">How it works</p><h2 id="process-title">From the office<br>to the shoreline.</h2></div>
            <p>One connected workflow helps local teams keep records current, check credentials, and respond with better context in the field.</p>
        </div>
        <div class="coastal-process__steps">
            <?php foreach ([
                ['register-step', '01', 'Register', 'Municipal personnel create and maintain fisherfolk, vessel, and permit records.'],
                ['verify-step', '02', 'Verify', 'Authorized teams check credentials and permits during field activities.'],
                ['monitor-step', '03', 'Monitor', 'Vessel information and incident reports support a clearer coastal picture.'],
            ] as $__k => [$id, $number, $title, $description]): ?>
                <article id="<?= e($id) ?>" class="coastal-process__step" data-reveal><span class="coastal-process__number"><?= e($number) ?></span><div><h3><?= e($title) ?></h3><p><?= e($description) ?></p></div></article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php include __DIR__."/in-action.php"; ?>
    <?php include __DIR__."/capabilities.php"; ?>

    <section class="coastal-people coastal-section" aria-labelledby="people-title">
        <div class="coastal-people__image" data-reveal>
            <img src="landing/images/antique-coast-town.jpg" alt="A coastal community with fishing boats along the shoreline" loading="lazy">
            <span>Built around the communities it serves.</span>
        </div>
        <div class="coastal-people__content" data-reveal>
            <p class="coastal-eyebrow">Made for local work <span class="coastal-eyebrow__line" aria-hidden="true"></span></p>
            <h2 id="people-title">Different roles.<br><span>One shared purpose.</span></h2>
            <p class="coastal-people__lead">The platform supports the handoff between the people who maintain local records and the teams who use them along the coast.</p>
            <div class="coastal-people__role"><span class="coastal-people__role-icon"><svg aria-hidden="true"><use href="#coastal-icon-document" /></svg></span><div><h3>Municipal offices</h3><p>MAO and MASO personnel manage local fisherfolk, vessel, and permit information.</p></div></div>
            <div class="coastal-people__role"><span class="coastal-people__role-icon"><svg aria-hidden="true"><use href="#coastal-icon-pin" /></svg></span><div><h3>Coastal field teams</h3><p>Coast Guard and Bantay Dagat personnel use shared information for verification and monitoring.</p></div></div>
        </div>
    </section>

    <?php
        $communities = [
            ['id' => 'san-jose', 'name' => 'San Jose de Buenavista', 'photo' => 'san-jose.jpg',
                'intro' => 'The capital of Antique and the largest of the four municipalities, where local government and the Sulu Sea shoreline meet.'],
            ['id' => 'hamtic', 'name' => 'Hamtic', 'photo' => 'hamtic.jpg',
                'intro' => 'A coastal town just southeast of the capital, with a long shoreline and a community closely tied to the sea.'],
            ['id' => 'tobias-fornier', 'name' => 'Tobias Fornier', 'photo' => 'tobias-fornier.jpg',
                'intro' => 'Formerly known as Dao, a coastal municipality spread across many barangays along the Sulu Sea.'],
            ['id' => 'anini-y', 'name' => 'Anini-y', 'photo' => 'anini-y.jpg',
                'intro' => 'The southernmost municipality of Antique, a compact coastal town at the province\'s southern edge.'],
        ];
    ?>
    <section id="communities" class="coastal-communities coastal-section" aria-labelledby="communities-title">
        <div class="coastal-section-heading" data-reveal>
            <div><p class="coastal-eyebrow">Our communities <span class="coastal-eyebrow__line" aria-hidden="true"></span></p><h2 id="communities-title">Four municipalities.<br><span>One shared coast.</span></h2></div>
            <p class="coastal-section-heading__intro">Each municipality brings its own local knowledge. The platform gives those teams a common place to connect their work.</p>
        </div>
        <div class="coastal-community-card" data-reveal>
            <div class="coastal-tabs" role="tablist" aria-label="Select a municipality">
                <?php foreach ($communities as $index => $community): ?>
                    <button type="button" id="tab-<?= e($community['id']) ?>" class="coastal-tabs__tab <?= e($index === 0 ? 'is-active' : '') ?>" role="tab" aria-controls="panel-<?= e($community['id']) ?>" aria-selected="<?= e($index === 0 ? 'true' : 'false') ?>" tabindex="<?= e($index === 0 ? '0' : '-1') ?>" data-community-tab><?= e($community['name']) ?></button>
                <?php endforeach; ?>
            </div>
            <?php foreach ($communities as $index => $community): ?>
                <div id="panel-<?= e($community['id']) ?>" class="coastal-community-panel" role="tabpanel" aria-labelledby="tab-<?= e($community['id']) ?>" tabindex="0" <?php if ($index !== 0): ?> hidden <?php endif; ?>>
                    <div class="coastal-community-panel__art">
                        <?php if (!empty($community['photo']) && is_file(__DIR__.'/images/communities/'.$community['photo'])): ?>
                            <img class="coastal-community-panel__photo" src="landing/images/communities/<?= e($community['photo']) ?>" alt="<?= e($community['name']) ?>, Antique" loading="lazy">
                        <?php else: $id = $community['id']; include __DIR__."/coastal-map.php"; endif; ?>
                    </div>
                    <div class="coastal-community-panel__copy">
                        <p class="coastal-community-panel__eyebrow">Southern Antique · <?= e(str_pad($index + 1, 2, '0', STR_PAD_LEFT)) ?> / 04</p>
                        <h3><?= e($community['name']) ?></h3>
                        <p class="coastal-community-panel__intro"><?= e($community['intro']) ?></p>
                        <button type="button" class="coastal-community-panel__guide" data-guide-open>See the personnel guide <span aria-hidden="true">↗</span></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="about" class="coastal-about coastal-section" aria-labelledby="about-title">
        <div class="coastal-about__intro" data-reveal><p class="coastal-eyebrow">About the project <span class="coastal-eyebrow__line" aria-hidden="true"></span></p><h2 id="about-title">A thoughtful tool for a working coastline.</h2><p>Bantay Dagat is a St. Anthony's College capstone project focused on connecting municipal records, field verification, and coastal monitoring in southern Antique.</p></div>
        <div class="coastal-about__questions" data-reveal>
            <h3>Good to know</h3>
            <details><summary>Who is the platform for?</summary><p>Municipal agriculture offices, Coast Guard personnel, and Bantay Dagat teams use role-based workspaces for their respective tasks.</p></details>
            <details><summary>Do fisherfolk need an account?</summary><p>No. Municipal personnel maintain fisherfolk records on their behalf.</p></details>
            <details><summary>How do staff get access?</summary><p>Accounts for authorized staff are issued by the system administrator.</p></details>
        </div>
    </section>

    <section class="coastal-cta" aria-labelledby="cta-title" data-reveal>
        <div><p class="coastal-eyebrow">Ready to connect?</p><h2 id="cta-title">Support starts with<br>better information.</h2><p>Explore the platform with your team, or sign in to your municipal or coastal workspace.</p></div>
        <div class="coastal-cta__actions">
            <?php if (!empty($_SESSION['user_id'])): ?>
                <a class="coastal-button coastal-button--white" href="dashboard.php">Go to dashboard <span aria-hidden="true">↗</span></a>
            <?php else: ?>
                <a class="coastal-button coastal-button--white" href="#login" data-login-open>Personnel log in <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </div>
    </section>