window.pickTheme = function (t) {
    applyTheme(t);
    document.querySelectorAll('.theme-card').forEach(function (c) {
        c.classList.toggle('active', c.dataset.tk === t);
    });
    var nameEl = document.querySelector('[data-tk="' + t + '"] .theme-name');
    toast(window.t('jsThemeActivated', { name: (nameEl ? nameEl.textContent : t) }), 'info', 2200);
};

window.setSidebarMode = function (collapsed) {
    localStorage.setItem('panel-sb-collapsed', collapsed ? '1' : '0');
    var sb = document.getElementById('sidebar');
    if (sb) {
        if (collapsed) sb.classList.add('collapsed');
        else sb.classList.remove('collapsed');
    }
    document.querySelectorAll('[id^="mode"]').forEach(function (b) {
        b.style.borderColor = '';
        b.style.color = '';
    });
    var btn = document.getElementById(collapsed ? 'modeCollapsed' : 'modeExpanded');
    if (btn) { btn.style.borderColor = 'var(--ac)'; btn.style.color = 'var(--ac)'; }
    toast(collapsed ? window.t('jsSidebarCollapsed') : window.t('jsSidebarExpanded'), 'info', 1800);
};

window.togglePw = function (id, btn) {
    var inp = document.getElementById(id);
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.style.color = 'var(--ac)';
    } else {
        inp.type = 'password';
        btn.style.color = 'var(--dim)';
    }
};

window.checkPwStr = function (val) {
    var bar = document.getElementById('pwBar');
    var hint = document.getElementById('pwHint');
    if (!bar || !hint) return;

    var score = 0;
    if (val.length >= 6) score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    var levels = [
        { w: '0%', c: 'var(--no)', t: window.t('jsPwVeryWeak') },
        { w: '25%', c: 'var(--no)', t: window.t('jsPwWeak') },
        { w: '50%', c: 'var(--warn)', t: window.t('jsPwMedium') },
        { w: '75%', c: 'var(--ok)', t: window.t('jsPwGood') },
        { w: '100%', c: 'var(--ok)', t: window.t('jsPwExcellent') },
    ];
    var lv = levels[Math.min(score, 4)];
    bar.style.width = lv.w;
    bar.style.background = lv.c;
    hint.textContent = val.length ? lv.t : window.t('jsPwMinHint');
    hint.style.color = lv.c;
};

(function () {
    var cur = localStorage.getItem('panel-theme') || 'navy';
    var card = document.querySelector('[data-tk="' + cur + '"]');
    if (card) card.classList.add('active');

    var collapsed = localStorage.getItem('panel-sb-collapsed') === '1';
    var btn = document.getElementById(collapsed ? 'modeCollapsed' : 'modeExpanded');
    if (btn) { btn.style.borderColor = 'var(--ac)'; btn.style.color = 'var(--ac)'; }
}());
