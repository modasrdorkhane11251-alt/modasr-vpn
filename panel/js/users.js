(function () {
    var searchInput = document.getElementById('searchInput');
    var filterStatus = document.getElementById('filterStatus');
    var filterRole = document.getElementById('filterRole');
    var usersBody = document.getElementById('usersBody');
    var tblLoading = document.getElementById('tblLoading');
    var tblFoot = document.getElementById('tblFoot');
    var clearAllBtn = document.getElementById('clearAllBtn');
    var searchClear = document.getElementById('searchClear');

    if (!searchInput || !usersBody) return;

    var debounce;
    var currentPage = parseInt(document.body.dataset.page || '1', 10);

    function showLoader() {
        if (tblLoading) tblLoading.style.display = 'flex';
        _lb.start();
    }

    function hideLoader() {
        if (tblLoading) tblLoading.style.display = 'none';
        _lb.done();
    }

    function getParams(page) {
        return new URLSearchParams({
            q: searchInput.value.trim(),
            status: filterStatus ? filterStatus.value : '',
            role: filterRole ? filterRole.value : '',
            page: page || 1,
        });
    }

    function fetchRows(page) {
        currentPage = page || 1;
        var params = getParams(currentPage);
        showLoader();

        fetch('users.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                usersBody.innerHTML = html;
                updateClearBtn();
                history.replaceState(null, '', location.pathname + '?' + params.toString());

                usersBody.querySelectorAll('[data-confirm]').forEach(function (el) {
                    el.addEventListener('click', function (e) {
                        e.preventDefault();
                        var href = el.href;
                        showConfirm(el.dataset.confirm, function () {
                            _lb.start();
                            window.location.href = href;
                        });
                    });
                });

                hideLoader();
            })
            .catch(function () { hideLoader(); });
    }

    function updateClearBtn() {
        var has = (searchInput.value || (filterStatus && filterStatus.value) || (filterRole && filterRole.value));
        if (clearAllBtn) clearAllBtn.style.display = has ? 'inline' : 'none';
        if (searchClear) searchClear.style.display = searchInput.value ? 'grid' : 'none';
    }

    searchInput.addEventListener('input', function () {
        updateClearBtn();
        clearTimeout(debounce);
        debounce = setTimeout(function () { fetchRows(1); }, 320);
    });

    if (filterStatus) filterStatus.addEventListener('change', function () { fetchRows(1); });
    if (filterRole) filterRole.addEventListener('change', function () { fetchRows(1); });

    if (searchClear) {
        searchClear.addEventListener('click', function () {
            searchInput.value = '';
            searchInput.focus();
            updateClearBtn();
            fetchRows(1);
        });
    }

    if (tblFoot) {
        tblFoot.addEventListener('click', function (e) {
            var a = e.target.closest('.pager a:not(.dis):not(.cur)');
            if (!a) return;
            e.preventDefault();
            var url = new URL(a.href, location.href);
            var page = parseInt(url.searchParams.get('page'), 10) || 1;
            fetchRows(page);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    window.setFilter = function (key, val) {
        if (key === 'status' && filterStatus) filterStatus.value = val;
        if (key === 'role' && filterRole) filterRole.value = val;
        fetchRows(1);
    };

    window.clearFilters = function () {
        searchInput.value = '';
        if (filterStatus) filterStatus.value = '';
        if (filterRole) filterRole.value = '';
        fetchRows(1);
    };
}());
