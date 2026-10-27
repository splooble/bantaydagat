
<section id="why-it-matters" class="coastal-impact coastal-section" aria-labelledby="impact-title">
    <div class="coastal-section-heading" data-reveal>
        <div>
            <p class="coastal-eyebrow">Why it matters <span class="coastal-eyebrow__line" aria-hidden="true"></span></p>
            <h2 id="impact-title">Small boats.<br><span>Big responsibilities.</span></h2>
        </div>
        <p class="coastal-section-heading__intro">Municipal waters feed coastal families, yet illegal, unreported, and unregulated (IUU) fishing still costs them. Reliable records and monitoring help local offices protect what they have.</p>
    </div>


    <div class="coastal-impact__grid" data-count-group>
        <?php foreach ([

            ['', 2413426, 0, '', 'Registered fisherfolk', 'in the Philippines, as of March 2024'],
            ['', 388098, 0, '', 'Municipal fishing boats', 'below three gross tons, nationwide'],
            ['₱', 4.94, 2, 'B', 'Lost every year', 'to IUU fishing in municipal waters'],
            ['1 in ', 11, 0, '', 'People worldwide', 'depend partly on small-scale fishing'],
        ] as $__k => [$prefix, $number, $decimals, $suffix, $label, $detail]): ?>
            <article class="coastal-impact__stat" data-reveal style="--reveal-delay: <?= e($__k * 120) ?>ms">
                <p class="coastal-impact__value"><?= e($prefix) ?><span data-count-to="<?= e($number) ?>" data-decimals="<?= e($decimals) ?>"><?= e(number_format($number, $decimals)) ?></span><?= e($suffix) ?></p>
                <h3><?= e($label) ?></h3>
                <p><?= e($detail) ?></p>
            </article>
        <?php endforeach; ?>
    </div>

    <figure class="coastal-impact__local" data-reveal>
        <p class="coastal-impact__local-label">Closer to home</p>
        <p class="coastal-impact__local-text">Registered fisherfolk in Hamtic, Tibiao, and San Jose report declining fish populations, along with concerns about commercial "kubkuban" operations near municipal waters and weak enforcement of fisheries laws.</p>
        <figcaption>Cabigunda-Gordon (2025), study of coastal communities in Antique</figcaption>
    </figure>

    <p class="coastal-impact__source">Figures as cited in the project study: Bureau of Fisheries and Aquatic Resources (2026); Aguión et al. (2025), <cite>Nature Food</cite>.</p>
</section>
