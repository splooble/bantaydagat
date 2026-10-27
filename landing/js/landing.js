
(() => {
    const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.documentElement.classList.add('js');


    const toggle = $('[data-menu-toggle]'), mobile = $('#coastal-mobile-nav');
    toggle?.addEventListener('click', () => {
        const open = mobile.hidden;
        mobile.hidden = !open;
        toggle.setAttribute('aria-expanded', open);
    });
    $$('a', mobile).forEach(a => a.addEventListener('click', () => { mobile.hidden = true; toggle.setAttribute('aria-expanded', false); }));


    const onView = (el, fn, t = .25) => {
        if (!('IntersectionObserver' in window)) return fn();
        const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); fn(); } }, { threshold: t });
        io.observe(el);
    };
    $$('[data-reveal]').forEach(el => onView(el, () => el.classList.add('is-visible'), .12));


    $$('[data-count-group]').forEach(group => onView(group, () => {
        group.classList.add('is-counting');
        $$('[data-count-to]', group).forEach((n, i) => {
            const to = parseFloat(n.dataset.countTo), d = +n.dataset.decimals || 0;
            const fmt = v => v.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
            if (still) return (n.textContent = fmt(to));
            n.textContent = fmt(0);
            setTimeout(() => {
                const t0 = performance.now(), dur = 1800;
                const tick = now => {
                    const p = Math.min((now - t0) / dur, 1);
                    n.textContent = fmt(to * (1 - Math.pow(1 - p, 3)));
                    if (p < 1) requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            }, i * 250);
        });
    }, .3));


    const tabs = $$('[data-community-tab]');
    const select = tab => tabs.forEach(t => {
        const on = t === tab;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on);
        t.tabIndex = on ? 0 : -1;
        $('#' + t.getAttribute('aria-controls')).hidden = !on;
    });
    tabs.forEach((t, i) => {
        t.addEventListener('click', () => select(t));
        t.addEventListener('keydown', e => {
            const j = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
            if (j === undefined) return;
            e.preventDefault();
            const next = tabs[(j + tabs.length) % tabs.length];
            select(next); next.focus();
        });
    });


    const dlg = $('[data-guide-dialog]');
    $$('[data-guide-open]').forEach(b => b.addEventListener('click', () => dlg.showModal()));
    $('[data-guide-close]')?.addEventListener('click', () => dlg.close());
    dlg?.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });


    const loginCard = $('#login');
    const focusLogin = () => {
        const user = $('#login-username');
        if (user) (user.value ? $('#login-password') : user).focus({ preventScroll: true });
    };
    $$('[data-login-open]').forEach(a => a.addEventListener('click', e => {
        if (!loginCard) return;
        e.preventDefault();
        if (dlg?.open) dlg.close();
        loginCard.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'center' });
        setTimeout(focusLogin, still ? 0 : 500);
    }));
    $('[data-forgot-toggle]')?.addEventListener('click', e => {
        const note = $('#login-forgot-note'), show = note.hidden;
        note.hidden = !show;
        e.currentTarget.setAttribute('aria-expanded', show);
    });
    $('[data-toggle-password]')?.addEventListener('click', e => {
        const input = $('#login-password'), show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        e.currentTarget.setAttribute('aria-pressed', show);
        e.currentTarget.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    if (loginCard && (loginCard.hasAttribute('data-has-error') || /[?&]login=1/.test(location.search) || location.hash === '#login')) focusLogin();


    const waters = $('[data-waters-demo]'), boat = $('[data-intruder]', waters || document);
    if (waters && boat && !still) {
        const [x1, y1] = boat.dataset.from.split(',').map(Number), [x2, y2] = boat.dataset.to.split(',').map(Number);
        const cross = +boat.dataset.crossing, sail = 6000, hold = 3500;
        const place = p => boat.setAttribute('transform', `translate(${x1 + (x2 - x1) * p} ${y1 + (y2 - y1) * p})`);
        onView(waters, () => {
            let t0 = performance.now();
            const frame = now => {
                const el = now - t0, p = Math.min(el / sail, 1);
                place(p);
                waters.classList.toggle('is-inside', p >= cross);
                if (el > sail + hold) t0 = now;
                requestAnimationFrame(frame);
            };
            requestAnimationFrame(frame);
        }, .35);
    }


    const phone = $('[data-phone-demo]');
    if (phone) onView(phone, () => phone.classList.add('is-animated'), .4);
})();