window.switchTab = function (name) {
    var panes = { orders: 'paneOrders', pay: 'panePay', refs: 'paneRefs' };
    var tabs = { orders: 'tabOrders', pay: 'tabPay', refs: 'tabRefs' };

    Object.values(panes).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    Object.values(tabs).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.style.background = 'transparent';
            el.style.color = 'var(--mute)';
            el.style.border = 'none';
        }
    });

    var pane = document.getElementById(panes[name]);
    if (pane) pane.style.display = 'block';

    var tab = document.getElementById(tabs[name]);
    if (tab) {
        tab.style.background = 'var(--ac)';
        tab.style.color = 'var(--btn-ac-text, #fff)';
    }
};
