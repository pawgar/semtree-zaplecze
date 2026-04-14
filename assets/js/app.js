// ── Helper ────────────────────────────────────────────────────
function api(method, url, body = null) {
    const opts = {method, headers: {'Content-Type': 'application/json'}};
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(r => r.json());
}

// ── Sidebar Toggle ───────────────────────────────────────────
function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;

    if (window.innerWidth < 992) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('show');
    } else {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');
    if (sidebar && localStorage.getItem('sidebarCollapsed') === '1' && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
    }
    loadSettingsPage();
});

// ── Toast Notifications ──────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) { alert(message); return; }

    const icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-custom toast-${type}`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="toast-body">
            <i class="bi ${icons[type] || icons.info} toast-icon"></i>
            <span>${message}</span>
            <button type="button" class="btn-close btn-close-sm ms-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    container.appendChild(toast);

    const bsToast = new bootstrap.Toast(toast, { delay: type === 'error' ? 6000 : 3500 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

// ── Settings Page ────────────────────────────────────────────
function loadSettingsPage() {
    if (!document.getElementById('anthropicApiKeyInput')) return;
    // Only load on settings page
    const isSettings = window.location.search.includes('page=settings');
    if (!isSettings) return;
    loadAnthropicKey();
    loadGeminiKey();
    loadSpeedLinksKey();
    loadCronToken();
    loadContentSettings();
    loadGscSettings();
}

async function loadContentSettings() {
    try {
        const r1 = await api('GET', 'api/settings.php?key=ai_model');
        if (r1.value) {
            const el = document.getElementById('settingsAiModel');
            if (el) el.value = r1.value;
        }
        const r2 = await api('GET', 'api/settings.php?key=default_lang');
        if (r2.value) {
            const el = document.getElementById('settingsDefaultLang');
            if (el) el.value = r2.value;
        }
    } catch (e) {}
}

async function saveContentSettings() {
    const model = document.getElementById('settingsAiModel')?.value;
    const lang = document.getElementById('settingsDefaultLang')?.value;
    try {
        if (model) await api('POST', 'api/settings.php', { key: 'ai_model', value: model });
        if (lang) await api('POST', 'api/settings.php', { key: 'default_lang', value: lang });
        showToast('Ustawienia zapisane', 'success');
    } catch (e) {
        showToast('Blad zapisu: ' + e.message, 'error');
    }
}

// ── Sites (Dashboard) ────────────────────────────────────────
let sitesData = [];

let sitesSortField = 'name';
let sitesSortAsc = true;

let gscDashboardData = null;

function loadSites() {
    api('GET', 'api/sites.php').then(sites => {
        sitesData = sites;
        buildCategoryFilter();
        buildClientFilter();
        updateDashboardSummary();
        updateDashboardGscFromSites();
        filterSites();
        checkGscConnected();
    });
}

async function checkGscConnected() {
    try {
        const data = await api('GET', 'api/gsc-auth.php?action=status');
        if (data.connected) {
            const btn = document.getElementById('refreshGscBtn');
            if (btn) btn.style.display = '';
        }
    } catch(e) {}
}

function updateDashboardSummary() {
    const el = document.getElementById('dashboardSummary');
    if (!el) return;
    el.style.display = '';

    const totalSites = sitesData.length;
    const totalPosts = sitesData.reduce((sum, s) => sum + (parseInt(s.post_count) || 0), 0);
    const totalLinks = sitesData.reduce((sum, s) => sum + (parseInt(s.link_count) || 0), 0);
    const errors = sitesData.filter(s => {
        if (!s.last_status_check) return false;
        const httpBad = !(s.http_status >= 200 && s.http_status < 400);
        return httpBad || !s.api_ok;
    }).length;

    document.getElementById('sumSites').textContent = totalSites;
    document.getElementById('sumPosts').textContent = totalPosts;
    document.getElementById('sumLinks').textContent = totalLinks;
    document.getElementById('sumErrors').textContent = errors;

    const errCard = document.getElementById('sumErrorsCard');
    if (errCard) {
        const statCard = errCard.querySelector('.stat-card');
        if (statCard) {
            statCard.style.boxShadow = errors > 0 ? '0 0 0 2px rgba(239,68,68,0.5)' : '';
        }
    }
}

function updateDashboardGscFromSites() {
    // Check if any site has GSC data (stored in sites table by CRON/refresh)
    const hasGsc = sitesData.some(s => s.gsc_clicks !== null && s.gsc_clicks !== undefined);
    if (!hasGsc) return;

    gscDashboardData = true; // Flag to show GSC columns

    // Show GSC cards and refresh button
    const clicksCard = document.getElementById('gscClicksCard');
    const impCard = document.getElementById('gscImpressionsCard');
    const kwCard = document.getElementById('gscKeywordsCard');
    const refreshBtn = document.getElementById('refreshGscBtn');
    if (clicksCard) clicksCard.style.display = '';
    if (impCard) impCard.style.display = '';
    if (kwCard) kwCard.style.display = '';
    if (refreshBtn) refreshBtn.style.display = '';

    // Calculate totals from sitesData (already loaded from sites.php)
    let totalClicks = 0, totalImpressions = 0, totalKeywords = 0;
    sitesData.forEach(s => {
        totalClicks += parseInt(s.gsc_clicks) || 0;
        totalImpressions += parseInt(s.gsc_impressions) || 0;
        totalKeywords += parseInt(s.gsc_keywords_count) || 0;
    });

    document.getElementById('sumGscClicks').textContent = formatNumber(totalClicks);
    document.getElementById('sumGscImpressions').textContent = formatNumber(totalImpressions);
    document.getElementById('sumGscKeywords').textContent = formatNumber(totalKeywords);

    // Calculate weighted average changes
    let clicksChange = 0, impChange = 0;
    if (totalClicks > 0) {
        sitesData.forEach(s => {
            const c = parseInt(s.gsc_clicks) || 0;
            clicksChange += (parseFloat(s.gsc_clicks_change) || 0) * c;
        });
        clicksChange /= totalClicks;
    }
    if (totalImpressions > 0) {
        sitesData.forEach(s => {
            const i = parseInt(s.gsc_impressions) || 0;
            impChange += (parseFloat(s.gsc_impressions_change) || 0) * i;
        });
        impChange /= totalImpressions;
    }

    const clicksChg = document.getElementById('sumGscClicksChange');
    const impChg = document.getElementById('sumGscImpressionsChange');
    if (clicksChg) clicksChg.innerHTML = formatChange(clicksChange);
    if (impChg) impChg.innerHTML = formatChange(impChange);

    // Show GSC columns in table
    document.querySelectorAll('.gsc-col').forEach(el => el.style.display = '');
}

async function refreshDashboardGsc() {
    const btn = document.getElementById('refreshGscBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Odświeżam...'; }
    try {
        await api('GET', 'api/gsc-data.php?action=refresh');
        // Reload sites data (now includes updated GSC columns)
        const sites = await api('GET', 'api/sites.php');
        sitesData = sites;
        updateDashboardSummary();
        updateDashboardGscFromSites();
        filterSites();
        showToast('Dane GSC odświeżone', 'success');
    } catch (e) {
        showToast('Błąd odświeżania GSC: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-graph-up"></i> Odśwież GSC'; }
    }
}

function formatNumber(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return n.toString();
}

function formatChange(pct) {
    if (pct === null || pct === undefined) return '';
    const cls = pct > 0 ? 'text-success' : pct < 0 ? 'text-danger' : 'text-muted';
    const icon = pct > 0 ? 'arrow-up' : pct < 0 ? 'arrow-down' : 'dash';
    return `<span class="${cls} change-inline"><i class="bi bi-${icon}"></i>${Math.round(Math.abs(pct))}%</span>`;
}

function sortSites(field) {
    if (sitesSortField === field) {
        sitesSortAsc = !sitesSortAsc;
    } else {
        sitesSortField = field;
        sitesSortAsc = true;
    }
    // Update sort indicators
    document.querySelectorAll('#sitesTable th.sortable i').forEach(icon => {
        icon.className = 'bi bi-chevron-expand small';
    });
    const th = document.querySelector(`#sitesTable th[data-sort="${field}"]`);
    if (th) {
        th.querySelector('i').className = sitesSortAsc ? 'bi bi-chevron-up small' : 'bi bi-chevron-down small';
    }
    filterSites();
}

function renderSites(sites) {
    const tbody = document.getElementById('sitesBody');
    if (!tbody) return;

    const colSpan = gscDashboardData ? 10 : 8;
    if (sites.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted">Brak stron. Dodaj pierwsza strone.</td></tr>`;
        return;
    }

    // Show last status check timestamp (take the most recent across all sites)
    let latestCheck = '';
    sites.forEach(s => {
        if (s.last_status_check && s.last_status_check > latestCheck) latestCheck = s.last_status_check;
    });
    const checkEl = document.getElementById('lastStatusCheck');
    if (checkEl) {
        checkEl.textContent = latestCheck ? `Statusy z: ${formatDateLocal(latestCheck)}` : 'Statusy: nigdy nie odswiezane';
    }

    tbody.innerHTML = sites.map((s, i) => {
        const cats = (s.categories || '').split(',').map(c => c.trim()).filter(c => c);
        const badges = cats.map(c => `<span class="badge bg-secondary category-badge">${esc(c)}</span>`).join(' ');

        // Post count from DB
        const postCount = s.post_count !== null && s.post_count !== undefined ? s.post_count : '-';

        // HTTP status LED
        const httpOk = s.http_status >= 200 && s.http_status < 400;
        const httpLed = s.last_status_check
            ? `<span class="status-led status-led-${httpOk ? 'ok' : 'error'}" title="HTTP ${s.http_status || '?'}"></span>`
            : '<span class="status-led status-led-unknown" title="Nie sprawdzono"></span>';

        // API status LED
        const apiLed = s.last_status_check
            ? `<span class="status-led status-led-${s.api_ok ? 'ok' : 'error'}" title="${s.api_ok ? 'API OK' : 'API Failed'}"></span>`
            : '<span class="status-led status-led-unknown" title="Nie sprawdzono"></span>';

        // Row highlighting for errors
        const hasError = s.last_status_check && (!httpOk || !s.api_ok);
        const rowClass = hasError ? 'class="table-danger"' : '';

        return `
        <tr data-id="${s.id}" ${rowClass}>
            <td>${i + 1}</td>
            <td><a href="index.php?page=site-card&id=${s.id}" title="Karta strony">${esc(s.name)}</a></td>
            <td>${badges}</td>
            <td id="posts-${s.id}">${postCount}</td>
            <td><a href="#" onclick="goToLinks(${s.id}); return false;" title="Pokaz linki">${s.link_count || 0}</a></td>
            ${gscDashboardData ? `
            <td class="gsc-col text-end text-nowrap">${s.gsc_clicks != null ? formatNumber(s.gsc_clicks) : '-'} ${s.gsc_clicks_change != null ? formatChange(s.gsc_clicks_change) : ''}</td>
            <td class="gsc-col text-end text-nowrap">${s.gsc_impressions != null ? formatNumber(s.gsc_impressions) : '-'} ${s.gsc_impressions_change != null ? formatChange(s.gsc_impressions_change) : ''}</td>
            ` : ''}
            <td class="text-center" id="status-${s.id}">${httpLed}</td>
            <td class="text-center" id="api-${s.id}">${apiLed}</td>
            <td class="text-nowrap">
                <a href="${esc(s.url)}" target="_blank" class="btn btn-sm btn-outline-info me-1" title="Otwórz stronę">
                    <i class="bi bi-eye"></i>
                </a>
                <button class="btn btn-sm btn-outline-success me-1" onclick="goToPublish(${s.id})" title="Publikuj">
                    <i class="bi bi-send"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary me-1" id="refresh-btn-${s.id}" onclick="refreshSiteStatus(${s.id})" title="Odswiez status">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="editSite(${s.id})" title="Edytuj">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteSite(${s.id}, '${esc(s.name)}')" title="Usun">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

function saveSite() {
    const editId = document.getElementById('siteEditId').value;
    const data = {
        name: document.getElementById('siteName').value.trim(),
        url: document.getElementById('siteUrl').value.trim(),
        username: document.getElementById('siteUsername').value.trim(),
        app_password: document.getElementById('siteAppPassword').value.trim(),
        categories: document.getElementById('siteCategories').value.trim(),
    };

    if (!data.name || !data.url || !data.username || !data.app_password) {
        showToast('Wypelnij wszystkie pola');
        return;
    }

    if (editId) {
        data.id = parseInt(editId);
        api('PUT', 'api/sites.php', data).then(r => {
            if (r.error) return showToast(r.error, 'error');
            bootstrap.Modal.getInstance(document.getElementById('addSiteModal')).hide();
            loadSites();
        });
    } else {
        api('POST', 'api/sites.php', data).then(r => {
            if (r.error) return showToast(r.error, 'error');
            bootstrap.Modal.getInstance(document.getElementById('addSiteModal')).hide();
            loadSites();
        });
    }
}

function editSite(id) {
    const site = sitesData.find(s => s.id === id);
    if (!site) return;

    document.getElementById('siteModalTitle').textContent = 'Edytuj strone';
    document.getElementById('siteEditId').value = id;
    document.getElementById('siteName').value = site.name;
    document.getElementById('siteUrl').value = site.url;
    document.getElementById('siteUsername').value = site.username;
    document.getElementById('siteAppPassword').value = site.app_password;
    document.getElementById('siteCategories').value = site.categories || '';

    new bootstrap.Modal(document.getElementById('addSiteModal')).show();
}

function deleteSite(id, name) {
    if (!confirm(`Usunac strone "${name}"?`)) return;
    api('DELETE', 'api/sites.php', {id}).then(r => {
        if (r.error) return showToast(r.error, 'error');
        loadSites();
    });
}

function goToOrderWithSite() {
    const siteId = document.getElementById('publishSiteSelect')?.value;
    if (!siteId) { showToast('Najpierw wybierz stronę'); return; }
    sessionStorage.setItem('orderSiteId', siteId);
    window.location.href = 'index.php?page=order';
}

function goToPublish(siteId) {
    sessionStorage.setItem('publishSiteId', siteId);
    window.location.href = 'index.php?page=publish';
}

function goToLinks(siteId) {
    sessionStorage.setItem('linksSiteId', siteId);
    window.location.href = 'index.php?page=links';
}

// ── Category Filter ──────────────────────────────────────────
function buildCategoryFilter() {
    const sel = document.getElementById('categoryFilter');
    if (!sel) return;
    const all = new Set();
    sitesData.forEach(s => {
        (s.categories || '').split(',').map(c => c.trim()).filter(c => c).forEach(c => all.add(c));
    });
    const current = sel.value;
    sel.innerHTML = '<option value="">Wszystkie kategorie</option>' +
        [...all].sort().map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    sel.value = current;
}

function buildClientFilter() {
    const sel = document.getElementById('clientFilter');
    if (!sel) return;
    api('GET', 'api/clients.php').then(clients => {
        const current = sel.value;
        sel.innerHTML = '<option value="">Bez linku do...</option>' +
            clients.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
        sel.value = current;
        initSearchableSelects();
    });
}

function filterSites() {
    const catSel = document.getElementById('categoryFilter');
    const clientSel = document.getElementById('clientFilter');
    const cat = catSel ? catSel.value : '';
    const clientId = clientSel ? clientSel.value : '';
    let filtered = [...sitesData];
    if (cat) {
        filtered = filtered.filter(s => {
            const cats = (s.categories || '').split(',').map(c => c.trim());
            return cats.includes(cat);
        });
    }
    if (clientId) {
        filtered = filtered.filter(s => {
            const linked = (s.linked_client_ids || '').split(',').filter(id => id);
            return !linked.includes(clientId);
        });
    }
    // Sort
    filtered.sort((a, b) => {
        let va = a[sitesSortField], vb = b[sitesSortField];
        if (sitesSortField === 'post_count' || sitesSortField === 'link_count' || sitesSortField === 'gsc_clicks' || sitesSortField === 'gsc_impressions') {
            va = parseInt(va) || 0;
            vb = parseInt(vb) || 0;
        } else {
            va = (va || '').toString().toLowerCase();
            vb = (vb || '').toString().toLowerCase();
        }
        if (va < vb) return sitesSortAsc ? -1 : 1;
        if (va > vb) return sitesSortAsc ? 1 : -1;
        return 0;
    });
    renderSites(filtered);
}

// Reset modal on close
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('addSiteModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', () => {
            document.getElementById('siteModalTitle').textContent = 'Dodaj strone';
            document.getElementById('siteEditId').value = '';
            document.getElementById('siteName').value = '';
            document.getElementById('siteUrl').value = '';
            document.getElementById('siteUsername').value = '';
            document.getElementById('siteAppPassword').value = '';
            document.getElementById('siteCategories').value = '';
        });
    }

    // Auto-load data based on page
    if (document.getElementById('siteCardContainer')) { loadSiteCard(); }
    // GSC report: do NOT auto-load, wait for user click
    if (document.getElementById('sitesBody')) { loadSites(); loadCronToken(); }
    if (document.getElementById('usersBody')) loadUsers();
    if (document.getElementById('profileUserId')) loadProfileStats();
    if (document.getElementById('linksOverviewBody')) {
        initLinksPage();
    } else if (document.getElementById('orderSiteSearch')) {
        initOrderPage();
    } else if (document.getElementById('xlsxFile')) {
        initImportPage();
    } else if (document.getElementById('publishSiteSelect')) {
        initPublishPage();
    }

    // Init searchable selects after a short delay to let options populate
    setTimeout(initSearchableSelects, 300);

    // Claude API status check
    checkClaudeApiStatus();
    setInterval(checkClaudeApiStatus, 120000); // refresh every 2 min
});

function checkClaudeApiStatus() {
    const led = document.getElementById('claudeStatusLed');
    const label = document.getElementById('claudeStatusLabel');
    const indicator = document.getElementById('claudeStatusIndicator');
    if (!led) return;

    fetch('api/claude-status.php')
        .then(r => r.json())
        .then(data => {
            led.className = 'status-led status-led-' + (data.status || 'unknown');
            if (label) label.textContent = data.status === 'ok' ? 'API OK' : (data.description || 'API');
            if (indicator) indicator.title = data.description || 'Status nieznany';
        })
        .catch(() => {
            led.className = 'status-led status-led-unknown';
            if (label) label.textContent = 'API ?';
            if (indicator) indicator.title = 'Nie udało się sprawdzić statusu';
        });
}

// ── Status Refresh ───────────────────────────────────────────
function refreshSiteStatus(siteId) {
    const postsCell = document.getElementById('posts-' + siteId);
    const statusCell = document.getElementById('status-' + siteId);
    const apiCell = document.getElementById('api-' + siteId);
    const btn = document.getElementById('refresh-btn-' + siteId);

    if (postsCell) postsCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
    if (statusCell) statusCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
    if (apiCell) apiCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
    if (btn) { btn.disabled = true; btn.querySelector('i').classList.add('spin'); }

    api('POST', 'api/status.php', {id: siteId}).then(r => {
        applyStatusResult(r);
    }).catch(() => {
        applyStatusResult({ id: siteId, http_status: 0, api_ok: false, post_count: null });
    });
}

async function refreshAllStatuses() {
    const BATCH = 10;

    // Show spinners on all sites
    sitesData.forEach(s => {
        const postsCell = document.getElementById('posts-' + s.id);
        const statusCell = document.getElementById('status-' + s.id);
        const apiCell = document.getElementById('api-' + s.id);
        const btn = document.getElementById('refresh-btn-' + s.id);
        if (postsCell) postsCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (statusCell) statusCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (apiCell) apiCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (btn) { btn.disabled = true; btn.querySelector('i').classList.add('spin'); }
    });

    // Process in batches of BATCH concurrent single-site requests
    for (let i = 0; i < sitesData.length; i += BATCH) {
        const batch = sitesData.slice(i, i + BATCH);
        const promises = batch.map(s =>
            api('POST', 'api/status.php', { id: s.id })
                .then(r => applyStatusResult(r))
                .catch(() => applyStatusResult({ id: s.id, http_status: 0, api_ok: false, post_count: null }))
        );
        await Promise.all(promises);
    }

    // Refresh summary counts (incl. links total)
    updateDashboardSummary();
}

function applyStatusResult(r) {
    const postsCell = document.getElementById('posts-' + r.id);
    const statusCell = document.getElementById('status-' + r.id);
    const apiCell = document.getElementById('api-' + r.id);
    const btn = document.getElementById('refresh-btn-' + r.id);

    if (postsCell) {
        postsCell.textContent = r.post_count !== null ? r.post_count : '?';
    }
    if (statusCell) {
        const httpOk = r.http_status >= 200 && r.http_status < 400;
        statusCell.innerHTML = `<span class="status-led status-led-${httpOk ? 'ok' : 'error'}" title="HTTP ${r.http_status || '?'}"></span>`;
    }
    if (apiCell) {
        apiCell.innerHTML = `<span class="status-led status-led-${r.api_ok ? 'ok' : 'error'}" title="${r.api_ok ? 'API OK' : 'API Failed'}"></span>`;
    }
    if (btn) { btn.disabled = false; btn.querySelector('i')?.classList.remove('spin'); }

    // Update timestamp display
    const checkEl = document.getElementById('lastStatusCheck');
    if (checkEl) {
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        checkEl.textContent = `Statusy z: ${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }

    // Update link count cell if returned
    if (r.link_count !== undefined) {
        const linkCell = document.querySelector(`tr[data-id="${r.id}"] td:nth-child(6) a`);
        if (linkCell) linkCell.textContent = r.link_count;
    }

    // Update sitesData cache
    const site = sitesData.find(s => s.id === r.id);
    if (site) {
        site.post_count = r.post_count;
        site.http_status = r.http_status;
        site.api_ok = r.api_ok;
        site.last_status_check = new Date().toISOString();
        if (r.link_count !== undefined) site.link_count = r.link_count;
    }
}

// ── Cron Token ──────────────────────────────────────────────
function loadCronToken() {
    api('GET', 'api/settings.php?key=cron_token').then(r => {
        const input = document.getElementById('cronTokenInput');
        if (input && r.value) {
            input.value = r.value;
            updateCronPreview(r.value);
        }
    }).catch(() => {});
}

function saveCronToken() {
    const token = document.getElementById('cronTokenInput').value.trim();
    if (!token) { showToast('Wpisz lub wygeneruj token'); return; }
    api('POST', 'api/settings.php', { key: 'cron_token', value: token }).then(() => {
        updateCronPreview(token);
        showToast('Token zapisany');
    });
}

function generateCronToken() {
    const arr = new Uint8Array(24);
    crypto.getRandomValues(arr);
    const token = Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('cronTokenInput').value = token;
    updateCronPreview(token);
}

function updateCronPreview(token) {
    const el = document.getElementById('cronCommandPreview');
    if (!el) return;
    const base = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '');
    el.textContent = `0 23 * * * curl -s "${base}/api/cron-status.php?token=${token}"`;
    const elGsc = document.getElementById('cronGscCommandPreview');
    if (elGsc) {
        elGsc.textContent = `0 6 * * * curl -s "${base}/api/cron-gsc.php?token=${token}"`;
    }
}

// ── GSC Settings ────────────────────────────────────────────
async function loadGscSettings() {
    try {
        const [r1, r2, status] = await Promise.all([
            api('GET', 'api/settings.php?key=gsc_client_id'),
            api('GET', 'api/settings.php?key=gsc_client_secret'),
            api('GET', 'api/gsc-auth.php?action=status'),
        ]);
        const idEl = document.getElementById('gscClientId');
        const secretEl = document.getElementById('gscClientSecret');
        if (idEl) idEl.value = r1.value || '';
        if (secretEl && r2.value) secretEl.value = r2.value;

        updateGscStatus(status);
    } catch (e) {}
}

function updateGscStatus(status) {
    const statusEl = document.getElementById('gscStatus');
    const connectBtn = document.getElementById('gscConnectBtn');
    const disconnectBtn = document.getElementById('gscDisconnectBtn');
    if (!statusEl) return;

    if (status.connected) {
        statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Połączono z Google Search Console</span>';
        if (connectBtn) connectBtn.classList.add('d-none');
        if (disconnectBtn) disconnectBtn.classList.remove('d-none');
    } else if (status.configured) {
        statusEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-circle"></i> Skonfigurowano, ale nie połączono. Kliknij "Połącz z Google".</span>';
        if (connectBtn) connectBtn.classList.remove('d-none');
        if (disconnectBtn) disconnectBtn.classList.add('d-none');
    } else {
        statusEl.innerHTML = '<span class="text-muted"><i class="bi bi-info-circle"></i> Wpisz Client ID i Client Secret, aby rozpocząć.</span>';
        if (connectBtn) connectBtn.classList.remove('d-none');
        if (disconnectBtn) disconnectBtn.classList.add('d-none');
    }

    // Show success/error from redirect
    const params = new URLSearchParams(window.location.search);
    if (params.get('gsc_connected') === '1') {
        showToast('Połączono z Google Search Console!', 'success');
        window.history.replaceState({}, '', window.location.pathname + '?page=settings');
    }
    if (params.get('gsc_error')) {
        showToast('Błąd GSC: ' + params.get('gsc_error'), 'error');
        window.history.replaceState({}, '', window.location.pathname + '?page=settings');
    }
}

async function saveGscCredentials() {
    const clientId = document.getElementById('gscClientId')?.value.trim();
    const clientSecret = document.getElementById('gscClientSecret')?.value.trim();
    try {
        await api('POST', 'api/settings.php', { key: 'gsc_client_id', value: clientId || '' });
        await api('POST', 'api/settings.php', { key: 'gsc_client_secret', value: clientSecret || '' });
        showToast('Dane GSC zapisane', 'success');
        loadGscSettings();
    } catch (e) {
        showToast('Błąd zapisu: ' + e.message, 'error');
    }
}

async function connectGsc() {
    try {
        // Save credentials first
        const clientId = document.getElementById('gscClientId')?.value.trim();
        const clientSecret = document.getElementById('gscClientSecret')?.value.trim();
        if (!clientId || !clientSecret) {
            showToast('Wpisz Client ID i Client Secret', 'error');
            return;
        }
        await api('POST', 'api/settings.php', { key: 'gsc_client_id', value: clientId });
        await api('POST', 'api/settings.php', { key: 'gsc_client_secret', value: clientSecret });

        const r = await api('GET', 'api/gsc-auth.php?action=connect');
        if (r.auth_url) {
            window.location.href = r.auth_url;
        } else if (r.error) {
            showToast(r.error, 'error');
        }
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

async function disconnectGsc() {
    if (!confirm('Rozłączyć Google Search Console?')) return;
    try {
        await api('GET', 'api/gsc-auth.php?action=disconnect');
        showToast('Rozłączono GSC', 'success');
        loadGscSettings();
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

// ── Change Password (all sites) ──────────────────────────────
function changeAllPasswords() {
    const password = document.getElementById('newGlobalPassword').value;
    if (!password) return showToast('Wpisz nowe haslo');
    if (password.length < 6) return showToast('Haslo musi miec co najmniej 6 znakow');
    if (!confirm(`Zmieniac haslo na ${sitesData.length} stronach?`)) return;

    const log = document.getElementById('passwordChangeLog');
    const results = document.getElementById('passwordChangeResults');
    const btn = document.getElementById('btnChangeAllPasswords');

    log.innerHTML = '';
    results.classList.remove('d-none');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Zmieniam...';

    let done = 0;
    const total = sitesData.length;

    sitesData.forEach(site => {
        api('POST', 'api/password.php', {site_id: site.id, password})
            .then(r => {
                const cls = r.success ? 'pw-log-ok' : 'pw-log-fail';
                const icon = r.success ? 'check-circle' : 'x-circle';
                const msg = r.success ? 'OK' : r.error;
                log.innerHTML += `<div class="${cls}"><i class="bi bi-${icon}"></i> <strong>${esc(r.site_name)}</strong> - ${esc(msg)}</div>`;
            })
            .catch(e => {
                log.innerHTML += `<div class="pw-log-fail"><i class="bi bi-x-circle"></i> <strong>${esc(site.name)}</strong> - Blad: ${esc(e.message)}</div>`;
            })
            .finally(() => {
                done++;
                if (done === total) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-key"></i> Zmien haslo';
                }
            });
    });
}

function togglePasswordField(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

function toggleTablePwd(spanId) {
    const span = document.getElementById(spanId);
    if (!span) return;
    const btn = span.nextElementSibling;
    if (span.dataset.visible === '0') {
        span.textContent = span.dataset.pw;
        span.dataset.visible = '1';
        btn.innerHTML = '<i class="bi bi-eye-slash small"></i>';
    } else {
        span.textContent = '••••••••';
        span.dataset.visible = '0';
        btn.innerHTML = '<i class="bi bi-eye small"></i>';
    }
}

// ── CSV Import / Export ──────────────────────────────────────
function importCsv(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').map(l => l.trim()).filter(l => l);
        if (lines.length < 2) return showToast('Plik CSV jest pusty');

        const header = lines[0].split(';');
        const required = ['name', 'url', 'username', 'app_password'];
        const optional = ['categories'];
        const indices = {};
        for (const col of required) {
            const idx = header.indexOf(col);
            if (idx === -1) return showToast(`Brak kolumny: ${col}\nWymagane: ${required.join(';')}`);
            indices[col] = idx;
        }
        for (const col of optional) {
            const idx = header.indexOf(col);
            if (idx !== -1) indices[col] = idx;
        }

        let imported = 0, skipped = 0;
        const promises = [];

        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(';');
            const data = {
                name: (cols[indices.name] || '').trim(),
                url: (cols[indices.url] || '').trim(),
                username: (cols[indices.username] || '').trim(),
                app_password: (cols[indices.app_password] || '').trim(),
            };
            if (indices.categories !== undefined) {
                data.categories = (cols[indices.categories] || '').trim();
            }

            if (!data.name || !data.url || !data.username || !data.app_password) {
                skipped++;
                continue;
            }

            promises.push(
                api('POST', 'api/sites.php', data).then(r => {
                    if (r.error) skipped++; else imported++;
                })
            );
        }

        Promise.all(promises).then(() => {
            showToast(`Zaimportowano: ${imported}\nPominieto: ${skipped}`);
            loadSites();
        });
    };
    reader.readAsText(file, 'UTF-8');
}

function exportCsv() {
    if (sitesData.length === 0) return showToast('Brak stron do eksportu');

    let csv = 'name;url;username;app_password;categories\n';
    sitesData.forEach(s => {
        csv += `${s.name};${s.url};${s.username};${s.app_password};${s.categories || ''}\n`;
    });

    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'wordpress_sites.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ── Users Management ─────────────────────────────────────────
function loadUsers() {
    api('GET', 'api/users.php').then(users => {
        const tbody = document.getElementById('usersBody');
        if (!tbody) return;

        tbody.innerHTML = users.map((u, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${esc(u.username)}</td>
                <td>
                    <select class="form-select form-select-sm d-inline-block" style="width:110px"
                        onchange="changeRole(${u.id}, this.value)" ${users.filter(x => x.role === 'admin').length <= 1 && u.role === 'admin' ? 'disabled' : ''}>
                        <option value="worker" ${u.role === 'worker' ? 'selected' : ''}>worker</option>
                        <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>admin</option>
                    </select>
                </td>
                <td>${u.created_at}</td>
                <td>
                    <button class="btn btn-sm btn-outline-info me-1" onclick="viewUserStats(${u.id})" title="Statystyki">
                        <i class="bi bi-bar-chart"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-warning me-1" onclick="showResetPassword(${u.id}, '${esc(u.username)}')" title="Ustaw haslo">
                        <i class="bi bi-key"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id}, '${esc(u.username)}')" title="Usun">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    });
}

function addUser() {
    const username = document.getElementById('newUserLogin').value.trim();
    const password = document.getElementById('newUserPassword').value;

    if (!username || !password) return showToast('Wypelnij login i haslo');

    api('POST', 'api/users.php', {username, password}).then(r => {
        if (r.error) return showToast(r.error, 'error');
        bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
        document.getElementById('newUserLogin').value = '';
        document.getElementById('newUserPassword').value = '';
        loadUsers();
    });
}

function deleteUser(id, name) {
    if (!confirm(`Usunac uzytkownika "${name}"?`)) return;
    api('DELETE', 'api/users.php', {id}).then(r => {
        if (r.error) return showToast(r.error, 'error');
        loadUsers();
    });
}

function changeRole(id, role) {
    api('PATCH', 'api/users.php', {id, action: 'change_role', role}).then(r => {
        if (r.error) { showToast(r.error, 'error'); loadUsers(); return; }
        loadUsers();
    });
}

function showResetPassword(id, username) {
    document.getElementById('resetPasswordUserId').value = id;
    document.getElementById('resetPasswordUser').textContent = username;
    document.getElementById('resetPasswordInput').value = '';
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function confirmResetPassword() {
    const id = parseInt(document.getElementById('resetPasswordUserId').value);
    const password = document.getElementById('resetPasswordInput').value;
    if (!password) return showToast('Wpisz nowe haslo');
    api('PATCH', 'api/users.php', {id, action: 'reset_password', password}).then(r => {
        if (r.error) return showToast(r.error, 'error');
        bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal')).hide();
        showToast('Haslo zostalo zmienione');
    });
}

function viewUserStats(userId) {
    window.location.href = 'index.php?page=profile&user_id=' + userId;
}

// ── Profile ─────────────────────────────────────────────────

function changeOwnPassword() {
    const current = document.getElementById('currentPassword').value;
    const newPw = document.getElementById('newPassword').value;
    const confirm = document.getElementById('confirmPassword').value;
    const msg = document.getElementById('passwordMsg');

    if (!current || !newPw) { msg.innerHTML = '<span class="text-danger">Wypelnij wszystkie pola</span>'; return; }
    if (newPw !== confirm) { msg.innerHTML = '<span class="text-danger">Hasla nie sa identyczne</span>'; return; }
    if (newPw.length < 4) { msg.innerHTML = '<span class="text-danger">Haslo musi miec co najmniej 4 znaki</span>'; return; }

    api('POST', 'api/profile.php', {action: 'change_password', current_password: current, new_password: newPw}).then(r => {
        if (r.error) { msg.innerHTML = `<span class="text-danger">${esc(r.error)}</span>`; return; }
        msg.innerHTML = '<span class="text-success">Haslo zmienione pomyslnie</span>';
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
    });
}

let allProfilePublications = [];

function loadProfileStats() {
    const userId = document.getElementById('profileUserId')?.value;
    if (!userId) return;

    api('GET', `api/profile.php?user_id=${userId}`).then(data => {
        if (data.error) return;

        // Summary cards
        const summary = document.getElementById('profileSummary');
        if (summary && data.summary) {
            summary.style.display = '';
            document.getElementById('profileTotalPubs').textContent = data.summary.total_pubs;
            document.getElementById('profileTotalLinks').textContent = data.summary.total_linked;
            document.getElementById('profileTotalSites').textContent = data.summary.unique_sites;
            document.getElementById('profileTotalClients').textContent = data.summary.unique_clients;
        }

        // Activity chart (last 12 months bar chart)
        renderProfileActivityChart(data.monthly || []);

        // Top clients
        renderProfileTopClients(data.top_clients || []);

        // Top sites
        renderProfileTopSites(data.top_sites || []);

        // Monthly stats table
        const monthlyBody = document.getElementById('monthlyStatsBody');
        if (data.monthly.length === 0) {
            monthlyBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Brak danych</td></tr>';
        } else {
            monthlyBody.innerHTML = data.monthly.map(m => `
                <tr>
                    <td class="small">${esc(m.month)}</td>
                    <td class="text-center">${m.total_articles}</td>
                    <td class="text-center">${m.articles_with_links}</td>
                </tr>
            `).join('');
        }

        // Publications list
        allProfilePublications = data.publications || [];
        renderProfilePublications(allProfilePublications);
    });
}

function renderProfileActivityChart(monthly) {
    const container = document.getElementById('profileActivityChart');
    if (!container) return;

    // Get last 12 months (fill gaps)
    const months = [];
    const now = new Date();
    for (let i = 11; i >= 0; i--) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
        const key = d.toISOString().substring(0, 7);
        const label = d.toLocaleDateString('pl', { month: 'short' });
        const found = monthly.find(m => m.month === key);
        months.push({ key, label, count: found ? parseInt(found.total_articles) : 0 });
    }

    const max = Math.max(...months.map(m => m.count), 1);

    // Build Y-axis ticks (0, 25%, 50%, 75%, max)
    const ticks = [0, Math.round(max * 0.25), Math.round(max * 0.5), Math.round(max * 0.75), max];
    const uniqueTicks = [...new Set(ticks)].sort((a, b) => b - a);

    const yAxis = uniqueTicks.map(t => {
        const bottom = (t / max) * 100;
        return `<div style="position:absolute;right:4px;bottom:${bottom}%;transform:translateY(50%);font-size:0.65rem;color:#999;line-height:1">${t}</div>`;
    }).join('');

    const yLines = uniqueTicks.map(t => {
        const bottom = (t / max) * 100;
        return `<div style="position:absolute;left:0;right:0;bottom:${bottom}%;border-bottom:1px solid #eee;pointer-events:none"></div>`;
    }).join('');

    const bars = months.map(m => {
        const pct = max > 0 ? (m.count / max) * 100 : 0;
        return `<div style="flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;height:100%">
            <div style="flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center">
                <div class="profile-chart-bar" style="height:${Math.max(pct, m.count > 0 ? 3 : 0)}%;width:70%;max-width:40px" title="${m.key}: ${m.count} artykułów"></div>
            </div>
            <span style="font-size:0.65rem;color:#999;margin-top:4px;white-space:nowrap">${m.label}</span>
        </div>`;
    }).join('');

    container.innerHTML = `
        <div style="position:relative;flex:1;display:flex;height:100%;margin-left:30px">
            <div style="position:absolute;left:-30px;top:0;bottom:20px;width:28px">${yAxis}</div>
            <div style="position:absolute;left:0;right:0;top:0;bottom:20px">${yLines}</div>
            <div style="display:flex;width:100%;gap:2px;align-items:stretch">${bars}</div>
        </div>`;
}

function renderProfileTopClients(clients) {
    const container = document.getElementById('profileTopClients');
    if (!container) return;

    if (!clients.length) {
        container.innerHTML = '<div class="text-muted small text-center">Brak danych</div>';
        return;
    }

    const max = clients[0]?.link_count || 1;
    container.innerHTML = clients.map(c => `
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge" style="background:${c.color || '#6c757d'};min-width:8px;height:8px;padding:0;border-radius:50%"></span>
            <span class="small flex-fill text-truncate" title="${esc(c.domain)}">${esc(c.name)}</span>
            <div class="progress flex-fill" style="height:6px;max-width:100px">
                <div class="progress-bar" style="width:${(c.link_count / max) * 100}%;background:${c.color || 'var(--primary)'}"></div>
            </div>
            <span class="badge bg-secondary">${c.link_count}</span>
        </div>
    `).join('');
}

function renderProfileTopSites(sites) {
    const container = document.getElementById('profileTopSites');
    if (!container) return;

    if (!sites.length) {
        container.innerHTML = '<div class="text-muted small text-center">Brak danych</div>';
        return;
    }

    const max = sites[0]?.pub_count || 1;
    container.innerHTML = sites.map(s => `
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-globe2 text-muted small"></i>
            <span class="small flex-fill text-truncate" title="${esc(s.url)}">${esc(s.name)}</span>
            <div class="progress flex-fill" style="height:6px;max-width:100px">
                <div class="progress-bar bg-info" style="width:${(s.pub_count / max) * 100}%"></div>
            </div>
            <span class="badge bg-secondary">${s.pub_count}</span>
        </div>
    `).join('');
}

function renderProfilePublications(pubs) {
    const pubBody = document.getElementById('publicationsBody');
    if (pubs.length === 0) {
        pubBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Brak publikacji</td></tr>';
    } else {
        pubBody.innerHTML = pubs.map(p => `
            <tr>
                <td>${esc(p.site_name)}</td>
                <td>${p.client_domain ? esc(p.client_domain) : '<span class="text-muted">-</span>'}</td>
                <td>
                    ${p.post_url ? `<a href="${esc(p.post_url)}" target="_blank" title="${esc(p.post_title)}">${esc(truncate(p.post_title || p.post_url, 60))}</a>` : esc(p.post_title)}
                </td>
                <td>${formatDate(p.created_at)}</td>
            </tr>
        `).join('');
    }
}

function filterProfilePublications() {
    const from = document.getElementById('profileDateFrom').value;
    const to = document.getElementById('profileDateTo').value;
    if (!from && !to) { renderProfilePublications(allProfilePublications); return; }

    const filtered = allProfilePublications.filter(p => {
        const date = (p.created_at || '').substring(0, 10);
        if (from && date < from) return false;
        if (to && date > to) return false;
        return true;
    });
    renderProfilePublications(filtered);
}

function clearProfileDateFilter() {
    document.getElementById('profileDateFrom').value = '';
    document.getElementById('profileDateTo').value = '';
    renderProfilePublications(allProfilePublications);
}

// ══════════════════════════════════════════════════════════════
// ── Publish / Import Articles ────────────────────────────────
// ══════════════════════════════════════════════════════════════

let articles = [];
let wpCategories = [];
let wpAuthors = [];
let publishedUrls = [];
let importPlan = [];
let categoryMap = {};

function initPublishPage() {
    // Load sites into dropdown
    api('GET', 'api/sites.php').then(sites => {
        sitesData = sites;
        const sel = document.getElementById('publishSiteSelect');
        sites.forEach(s => {
            sel.innerHTML += `<option value="${s.id}">${esc(s.name)} (${esc(s.url)})</option>`;
        });
        // Auto-select site if navigated from dashboard
        const preselected = sessionStorage.getItem('publishSiteId');
        if (preselected) {
            sel.value = preselected;
            sessionStorage.removeItem('publishSiteId');
            sel.dispatchEvent(new Event('change'));
        }
    });

    // Auto-load WP data when site changes, reset selections
    document.getElementById('publishSiteSelect').addEventListener('change', function() {
        wpCategories = [];
        wpAuthors = [];
        fillCategorySelect('bulkCategory', []);
        fillAuthorSelect('bulkAuthor', []);
        articles.forEach(a => { a.category_id = ''; a.author_id = ''; });
        renderArticles();
        document.getElementById('wpDataStatus').textContent = '';
        const orderBtn = document.getElementById('btnOrderSingleArticle');
        if (orderBtn) orderBtn.style.display = this.value ? '' : 'none';
        if (this.value) {
            loadWpData();
        }
    });

    // Reset manual article modal on close
    const modal = document.getElementById('manualArticleModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', () => {
            document.getElementById('manualTitle').value = '';
            document.getElementById('manualContent').value = '';
            document.getElementById('manualImage').value = '';
            document.getElementById('manualImagePreview').innerHTML = '';
            manualImageData = '';
            manualImageFilename = '';
        });
    }
}

// ── Load WP categories & authors ─────────────────────────────
function loadWpData() {
    const siteId = document.getElementById('publishSiteSelect').value;
    if (!siteId) return showToast('Wybierz strone');

    const status = document.getElementById('wpDataStatus');
    status.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Laduje...';

    const catPromise = api('GET', `api/wp-data.php?site_id=${siteId}&type=categories`).catch(() => ({ error: 'Nie udalo sie pobrac kategorii' }));
    const authPromise = api('GET', `api/wp-data.php?site_id=${siteId}&type=authors`).catch(() => ({ error: 'Nie udalo sie pobrac autorow' }));

    Promise.all([catPromise, authPromise]).then(([cats, auths]) => {
        const warnings = [];

        if (cats.error) {
            wpCategories = [];
            warnings.push('Kategorie: ' + cats.error);
        } else {
            wpCategories = cats;
        }

        if (auths.error) {
            wpAuthors = [];
            warnings.push('Autorzy: ' + auths.error);
        } else {
            wpAuthors = auths;
        }

        fillCategorySelect('bulkCategory', wpCategories);
        fillAuthorSelect('bulkAuthor', wpAuthors);
        renderArticles();

        if (warnings.length === 2) {
            status.innerHTML = `<i class="bi bi-x-circle text-danger"></i> ${warnings.join('; ')}`;
        } else if (warnings.length === 1) {
            status.innerHTML = `<i class="bi bi-exclamation-triangle text-warning"></i> Czesciowo zaladowano. ${warnings[0]}`;
        } else {
            status.innerHTML = `<i class="bi bi-check-circle text-success"></i> Zaladowano ${cats.length} kategorii, ${auths.length} autorow`;
        }

        // If on import page, update category mapping
        if (document.getElementById('categoryMapping') && importPlan.length > 0) {
            matchCategories();
        }
    });
}

function fillCategorySelect(id, cats) {
    const sel = document.getElementById(id);
    if (!sel) return;
    sel.innerHTML = '<option value="">--</option>' + cats.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
}

function fillAuthorSelect(id, auths) {
    const sel = document.getElementById(id);
    if (!sel) return;
    sel.innerHTML = '<option value="">--</option>' + auths.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
}

// ── Add articles ─────────────────────────────────────────────
let manualImageData = '';
let manualImageFilename = '';

async function generateManualImage() {
    const title = document.getElementById('manualTitle').value.trim();
    if (!title) return showToast('Wpisz najpierw tytul');
    const preview = document.getElementById('manualImagePreview');
    preview.innerHTML = '<i class="bi bi-arrow-clockwise spin text-primary"></i> <span class="small">Generuje obrazek...</span>';
    try {
        const r = await api('POST', 'api/gemini-generate.php', { title });
        if (r.error) { preview.innerHTML = `<span class="text-danger small">${esc(r.error)}</span>`; return; }
        manualImageData = r.image_data;
        manualImageFilename = r.image_filename;
        preview.innerHTML = `<img src="data:image/jpeg;base64,${r.image_data}" class="img-thumbnail" style="max-height:120px"> <button class="btn btn-sm btn-outline-danger ms-2" onclick="manualImageData='';manualImageFilename='';this.parentElement.innerHTML=''"><i class="bi bi-x"></i></button>`;
    } catch (e) {
        preview.innerHTML = `<span class="text-danger small">Blad: ${esc(e.message)}</span>`;
    }
}

function addManualArticle() {
    const title = document.getElementById('manualTitle').value.trim();
    const content = document.getElementById('manualContent').value;
    const imageInput = document.getElementById('manualImage');

    if (!title) return showToast('Wpisz tytul');

    const article = {
        id: Date.now(),
        title,
        content,
        slug: titleToSlug(title),
        category_id: '',
        author_id: '',
        image_data: '',
        image_filename: '',
        publish_date: '',
        status: 'draft',
    };

    if (manualImageData) {
        article.image_data = manualImageData;
        article.image_filename = manualImageFilename;
        articles.push(article);
        renderArticles();
        bootstrap.Modal.getInstance(document.getElementById('manualArticleModal')).hide();
    } else if (imageInput.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            article.image_data = e.target.result.split(',')[1]; // strip data:... prefix
            article.image_filename = imageInput.files[0].name;
            articles.push(article);
            renderArticles();
            bootstrap.Modal.getInstance(document.getElementById('manualArticleModal')).hide();
        };
        reader.readAsDataURL(imageInput.files[0]);
    } else {
        articles.push(article);
        renderArticles();
        bootstrap.Modal.getInstance(document.getElementById('manualArticleModal')).hide();
    }
}

function uploadDocxFiles(input) {
    const files = Array.from(input.files);
    if (!files.length) return;
    input.value = '';

    const status = document.getElementById('articleCount');
    status.innerHTML = `<i class="bi bi-arrow-clockwise spin"></i> Przetwarzam ${files.length} plikow...`;

    let done = 0;
    files.forEach(file => {
        const formData = new FormData();
        formData.append('file', file);

        fetch('api/upload-docx.php', {method: 'POST', body: formData})
            .then(r => r.json())
            .then(r => {
                if (r.error) {
                    showToast(`Blad parsowania ${file.name}: ${r.error}`, 'error');
                } else {
                    articles.push({
                        id: Date.now() + Math.random(),
                        title: r.title,
                        content: r.html_body,
                        slug: titleToSlug(r.title),
                        category_id: '',
                        author_id: '',
                        image_data: '',
                        image_filename: '',
                        publish_date: '',
                        status: 'draft',
                    });
                }
            })
            .catch(e => showToast(`Blad uploadu ${file.name}: ${e.message}`, 'error'))
            .finally(() => {
                done++;
                if (done === files.length) {
                    renderArticles();
                    status.textContent = '';
                }
            });
    });
}

// ── Render articles table ────────────────────────────────────
function renderArticles() {
    const tbody = document.getElementById('articlesBody');
    if (!tbody) return;

    const count = document.getElementById('articleCount');
    if (count) count.textContent = articles.length > 0 ? `${articles.length} artykulow` : '';

    if (articles.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Brak artykulow. Wgraj DOCX lub dodaj recznie.</td></tr>';
        return;
    }

    const catOpts = wpCategories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    const authOpts = wpAuthors.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');

    tbody.innerHTML = articles.map((a, i) => `
        <tr>
            <td>${i + 1}</td>
            <td><input type="text" class="form-control form-control-sm" value="${esc(a.title)}" onchange="articles[${i}].title=this.value; articles[${i}].slug=titleToSlug(this.value)"></td>
            <td>
                <select class="form-select form-select-sm" onchange="articles[${i}].category_id=this.value">
                    <option value="">--</option>
                    ${catOpts}
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" onchange="articles[${i}].author_id=this.value">
                    <option value="">--</option>
                    ${authOpts}
                </select>
            </td>
            <td>
                ${a.image_filename
                    ? `<span class="text-success small"><i class="bi bi-image"></i> ${esc(a.image_filename)}</span>
                       <button class="btn btn-sm btn-outline-danger p-0 ms-1" onclick="removeArticleImage(${i})" title="Usun"><i class="bi bi-x"></i></button>`
                    : `<div class="d-flex gap-1"><input type="file" class="form-control form-control-sm" accept="image/*" onchange="setArticleImage(${i}, this)" style="max-width:110px">
                       <button class="btn btn-sm btn-outline-info" onclick="generateGeminiImage(${i})" title="Generuj AI"><i class="bi bi-stars"></i></button></div>`
                }
            </td>
            <td><input type="datetime-local" class="form-control form-control-sm" value="${a.publish_date}" onchange="articles[${i}].publish_date=this.value"></td>
            <td>
                <select class="form-select form-select-sm" onchange="articles[${i}].status=this.value">
                    <option value="draft" ${a.status==='draft'?'selected':''}>Szkic</option>
                    <option value="publish" ${a.status==='publish'?'selected':''}>Publikuj</option>
                </select>
            </td>
            <td>
                <button class="btn btn-sm btn-outline-danger" onclick="removeArticle(${i})" title="Usun">
                    <i class="bi bi-x"></i>
                </button>
            </td>
        </tr>
    `).join('');

    // Set selected values for category/author dropdowns
    articles.forEach((a, i) => {
        const row = tbody.children[i];
        if (a.category_id) row.querySelector('td:nth-child(3) select').value = a.category_id;
        if (a.author_id) row.querySelector('td:nth-child(4) select').value = a.author_id;
    });
}

function setArticleImage(index, input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        articles[index].image_data = e.target.result.split(',')[1];
        articles[index].image_filename = file.name;
        renderArticles();
    };
    reader.readAsDataURL(file);
}

function removeArticle(index) {
    articles.splice(index, 1);
    renderArticles();
}

function removeArticleImage(index) {
    articles[index].image_data = '';
    articles[index].image_filename = '';
    delete articles[index]._media_id;
    renderArticles();
}

function clearArticles() {
    if (articles.length && !confirm('Wyczysc cala liste artykulow?')) return;
    articles = [];
    publishedUrls = [];
    renderArticles();
    document.getElementById('publishReport').classList.add('d-none');
}

// ── Bulk operations ──────────────────────────────────────────
function setBulkCategory(val) {
    if (!val) return;
    articles.forEach(a => a.category_id = val);
    renderArticles();
}

function setBulkAuthor(val) {
    if (!val) return;
    articles.forEach(a => a.author_id = val);
    renderArticles();
}

function setBulkStatus(val) {
    articles.forEach(a => a.status = val);
    renderArticles();
}

function setRandomDates() {
    const fromStr = document.getElementById('randomDateFrom').value;
    const toStr = document.getElementById('randomDateTo').value;
    if (!fromStr || !toStr) return showToast('Ustaw zakres dat (od - do)');

    const from = new Date(fromStr).getTime();
    const to = new Date(toStr).getTime();
    if (from >= to) return showToast('Data "od" musi byc wczesniejsza niz "do"');
    if (!articles.length) return showToast('Brak artykulow');

    articles.forEach(a => {
        const randomTime = from + Math.random() * (to - from);
        const d = new Date(randomTime);
        const pad = n => String(n).padStart(2, '0');
        a.publish_date = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    });
    renderArticles();
}

// ── Save/Load articles state ──────────────────────────────────
function exportArticlesJson() {
    if (!articles.length) return showToast('Brak artykulow do zapisania');
    const data = JSON.stringify(articles, null, 0);
    const blob = new Blob([data], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    a.download = `artykuly-${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function importArticlesJson(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = JSON.parse(e.target.result);
            if (!Array.isArray(data)) throw new Error('Plik nie zawiera tablicy artykulow');
            articles = data;
            renderArticles();

            // Show articles card + publish card if hidden
            const artCard = document.getElementById('importArticlesCard');
            const pubCard = document.getElementById('importPublishCard');
            if (artCard) artCard.classList.remove('d-none');
            if (pubCard) pubCard.classList.remove('d-none');

            const imgCount = articles.filter(a => a.image_data).length;
            const mediaCount = articles.filter(a => a._media_id).length;
            showToast(`Wczytano ${data.length} artykulow (${imgCount} z obrazkami, ${mediaCount} juz uploadowanych).`);
        } catch (err) {
            showToast('Blad wczytywania: ' + err.message);
        }
    };
    reader.readAsText(file);
    input.value = '';
}

// ── Publish all (batched image upload + sequential post creation) ──
async function publishAllArticles() {
    const siteId = document.getElementById('publishSiteSelect').value;
    if (!siteId) return showToast('Wybierz strone');
    if (!articles.length) return showToast('Dodaj artykuly');
    if (!confirm(`Opublikowac ${articles.length} artykulow?`)) return;

    const btn = document.getElementById('btnPublishAll');
    const progressWrap = document.getElementById('publishProgress');
    const progressBar = document.getElementById('publishProgressBar');
    const report = document.getElementById('publishReport');
    const log = document.getElementById('publishReportLog');

    btn.disabled = true;
    progressWrap.classList.remove('d-none');
    report.classList.remove('d-none');
    log.innerHTML = '';
    publishedUrls = [];

    const total = articles.length;

    // Phase 1: Upload images in batches of 3 (not all at once!)
    const articlesWithImages = articles.filter(a => a.image_data && !a._media_id);
    if (articlesWithImages.length > 0) {
        btn.innerHTML = `<i class="bi bi-arrow-clockwise spin"></i> Obrazki (0/${articlesWithImages.length})...`;
        progressBar.style.width = '0%';
        progressBar.textContent = `Obrazki: 0 / ${articlesWithImages.length}`;

        let uploaded = 0;
        const IMG_BATCH = 3;

        for (let i = 0; i < articlesWithImages.length; i += IMG_BATCH) {
            const batch = articlesWithImages.slice(i, i + IMG_BATCH);

            await Promise.all(batch.map(a => {
                return api('POST', 'api/upload-image.php', {
                    site_id: parseInt(siteId),
                    image_data: a.image_data,
                    image_filename: a.image_filename,
                }).then(r => {
                    uploaded++;
                    progressBar.style.width = (uploaded / articlesWithImages.length * 50) + '%';
                    progressBar.textContent = `Obrazki: ${uploaded} / ${articlesWithImages.length}`;
                    if (r.success) {
                        a._media_id = r.media_id;
                        log.innerHTML += `<div class="text-muted small"><i class="bi bi-image"></i> Obrazek "${esc(a.title)}" przeslany (media_id: ${r.media_id})</div>`;
                    } else {
                        log.innerHTML += `<div class="text-warning small"><i class="bi bi-exclamation-triangle"></i> Obrazek "${esc(a.title)}": ${esc(r.error)}</div>`;
                    }
                }).catch(e => {
                    uploaded++;
                    log.innerHTML += `<div class="text-warning small"><i class="bi bi-exclamation-triangle"></i> Obrazek "${esc(a.title)}": ${esc(e.message)}</div>`;
                });
            }));
        }

        log.innerHTML += '<hr>';
    }

    // Phase 2: Create posts sequentially
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Posty...';

    for (let i = 0; i < total; i++) {
        const a = articles[i];
        progressBar.style.width = (50 + (i + 1) / total * 50) + '%';
        progressBar.textContent = `Posty: ${i + 1} / ${total}`;

        try {
            const postData = {
                site_id: parseInt(siteId),
                title: a.title,
                content: a.content,
                status: a.status,
                category_id: a.category_id ? parseInt(a.category_id) : 0,
                author_id: a.author_id ? parseInt(a.author_id) : 0,
                publish_date: a.publish_date || '',
            };

            // Use pre-uploaded media_id if available
            if (a._media_id) {
                postData.media_id = a._media_id;
            } else if (a.image_data) {
                postData.image_data = a.image_data;
                postData.image_filename = a.image_filename;
            }

            const r = await api('POST', 'api/publish.php', postData);

            if (r.success) {
                publishedUrls.push(r.post_url);
                log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> <strong>${esc(r.title)}</strong> → <a href="${esc(r.post_url)}" target="_blank">${esc(r.post_url)}</a></div>`;
                // Fire-and-forget: extract and save links
                extractAndSaveLinks(parseInt(siteId), r.post_url, r.title, a.content);
            } else {
                log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> <strong>${esc(r.title)}</strong> → ${esc(r.error)}</div>`;
            }
        } catch (e) {
            log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> <strong>${esc(a.title)}</strong> → ${esc(e.message)}</div>`;
        }

        if (i < total - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }

    // Speed-Links indexing
    const speedLinksCheck = document.getElementById('speedLinksCheck');
    if (speedLinksCheck && speedLinksCheck.checked && publishedUrls.length > 0) {
        log.innerHTML += '<hr><div class="text-info"><i class="bi bi-arrow-clockwise spin"></i> Wysylam do indeksacji Speed-Links...</div>';
        try {
            const slResult = await submitToSpeedLinks(publishedUrls);
            if (slResult.error) {
                log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Speed-Links: ${esc(slResult.error)}</div>`;
            } else {
                let msg = `Speed-Links: wyslano ${slResult.submitted} URLi do indeksacji`;
                if (slResult.report_url) msg += ` — <a href="${esc(slResult.report_url)}" target="_blank">raport</a>`;
                log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> ${msg}</div>`;
            }
        } catch (e) {
            log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Speed-Links: ${esc(e.message)}</div>`;
        }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send"></i> Publikuj wszystkie';
}

function copyPublishedUrls() {
    if (!publishedUrls.length) return showToast('Brak linkow do skopiowania');
    navigator.clipboard.writeText(publishedUrls.join('\n')).then(() => {
        showToast('Skopiowano ' + publishedUrls.length + ' linkow');
    });
}

// ── Gemini Image Generation ─────────────────────────────────
async function generateGeminiImage(index) {
    const a = articles[index];
    if (!a) return;

    const tbody = document.getElementById('articlesBody');
    const row = tbody.children[index];
    const imgCell = row.querySelector('td:nth-child(5)');
    imgCell.innerHTML = '<i class="bi bi-arrow-clockwise spin text-primary"></i> <span class="small">Generuje...</span>';

    try {
        const r = await api('POST', 'api/gemini-generate.php', { title: a.title });
        if (r.error) {
            imgCell.innerHTML = `<span class="text-danger small">${esc(r.error)}</span>`;
            return;
        }
        articles[index].image_data = r.image_data;
        articles[index].image_filename = r.image_filename;
        renderArticles();
    } catch (e) {
        imgCell.innerHTML = `<span class="text-danger small">Blad: ${esc(e.message)}</span>`;
    }
}

async function bulkGenerateImages() {
    const indices = articles.map((a, i) => !a.image_data ? i : -1).filter(i => i >= 0);
    if (!indices.length) return showToast('Wszystkie artykuly maja juz obrazki');
    if (!confirm(`Wygenerowac obrazki AI dla ${indices.length} artykulow?`)) return;

    const status = document.getElementById('geminiStatus');
    let success = 0;

    for (let j = 0; j < indices.length; j++) {
        status.innerHTML = `<i class="bi bi-arrow-clockwise spin"></i> Generuje ${j + 1}/${indices.length}...`;
        await generateGeminiImage(indices[j]);
        if (articles[indices[j]].image_data) success++;
        if (j < indices.length - 1) {
            await new Promise(r => setTimeout(r, 1000));
        }
    }

    status.innerHTML = `<i class="bi bi-check-circle text-success"></i> Wygenerowano ${success}/${indices.length} obrazkow`;
}

async function loadGeminiKey() {
    try {
        const r = await api('GET', 'api/settings.php?key=gemini_api_key');
        document.getElementById('geminiApiKey').value = r.value || '';
    } catch (e) {
        // ignore
    }
}

async function saveGeminiKey() {
    const key = document.getElementById('geminiApiKey').value.trim();
    try {
        await api('POST', 'api/settings.php', { key: 'gemini_api_key', value: key });
        showToast('Klucz Gemini API zapisany', 'success');
    } catch (e) {
        showToast('Blad zapisu: ' + e.message, 'error');
    }
}

// ── Speed-Links Indexing ─────────────────────────────────────
async function loadSpeedLinksKey() {
    try {
        const r = await api('GET', 'api/settings.php?key=speedlinks_api_key');
        document.getElementById('speedLinksApiKey').value = r.value || '';
    } catch (e) {
        // ignore
    }
}

async function saveSpeedLinksKey() {
    const key = document.getElementById('speedLinksApiKey').value.trim();
    try {
        await api('POST', 'api/settings.php', { key: 'speedlinks_api_key', value: key });
        showToast('Klucz Speed-Links API zapisany', 'success');
    } catch (e) {
        showToast('Blad zapisu: ' + e.message, 'error');
    }
}

async function submitToSpeedLinks(urls) {
    if (!urls || urls.length === 0) return;
    try {
        const r = await api('POST', 'api/speed-links.php', { urls });
        return r;
    } catch (e) {
        return { error: e.message };
    }
}

// ── Slug generation ──────────────────────────────────────────
function titleToSlug(title) {
    const polish = {'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z',
                    'Ą':'a','Ć':'c','Ę':'e','Ł':'l','Ń':'n','Ó':'o','Ś':'s','Ź':'z','Ż':'z'};
    return title
        .toLowerCase()
        .replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, c => polish[c] || c)
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

// ══════════════════════════════════════════════════════════════
// ── Import Masowy ────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════

function initImportPage() {
    // Load sites into dropdown (reuses same publishSiteSelect ID)
    api('GET', 'api/sites.php').then(sites => {
        sitesData = sites;
        const sel = document.getElementById('publishSiteSelect');
        sites.forEach(s => {
            sel.innerHTML += `<option value="${s.id}">${esc(s.name)} (${esc(s.url)})</option>`;
        });
    });

    // Auto-load WP data when site changes, reset selections
    document.getElementById('publishSiteSelect').addEventListener('change', function() {
        wpCategories = [];
        wpAuthors = [];
        fillCategorySelect('bulkCategory', []);
        fillAuthorSelect('bulkAuthor', []);
        articles.forEach(a => { a.category_id = ''; a.author_id = ''; });
        renderArticles();
        document.getElementById('wpDataStatus').textContent = '';
        if (this.value) {
            loadWpData();
        }
    });
}

// ── Parse XLSX and auto-load DOCX files ─────────────────────
async function parseXlsxFile(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    const status = document.getElementById('xlsxStatus');
    status.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Parsuje XLSX...';

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await fetch('api/parse-xlsx.php', { method: 'POST', body: formData });
        const r = await response.json();

        if (r.error) {
            status.innerHTML = `<i class="bi bi-x-circle text-danger"></i> ${esc(r.error)}`;
            return;
        }

        importPlan = r.rows;

        // Count categories
        const catCounts = {};
        importPlan.forEach(row => {
            const cat = row.category_name || '(brak)';
            catCounts[cat] = (catCounts[cat] || 0) + 1;
        });
        const catCount = Object.keys(catCounts).filter(c => c !== '(brak)').length;

        status.innerHTML = `<i class="bi bi-check-circle text-success"></i> Znaleziono ${importPlan.length} artykulow w ${catCount} kategoriach`;

        // Show category mapping
        matchCategories();

        // Show DOCX upload card
        document.getElementById('docxLoadCard').classList.remove('d-none');
    } catch (e) {
        status.innerHTML = `<i class="bi bi-x-circle text-danger"></i> Blad: ${esc(e.message)}`;
    }
}

// ── Match XLSX categories to WP categories ──────────────────
function matchCategories() {
    const container = document.getElementById('categoryMapping');
    if (!container) return;

    // Count categories from import plan
    const catCounts = {};
    importPlan.forEach(row => {
        const cat = row.category_name || '(brak)';
        catCounts[cat] = (catCounts[cat] || 0) + 1;
    });

    // Build category map (case-insensitive match)
    categoryMap = {};
    const wpCatLower = {};
    wpCategories.forEach(c => {
        wpCatLower[c.name.toLowerCase().trim()] = c;
    });

    let html = '<h6 class="mb-2"><i class="bi bi-diagram-3"></i> Mapowanie kategorii</h6>';
    html += '<table class="table table-sm table-bordered" style="max-width:700px"><thead class="table-light"><tr><th>Kategoria XLSX</th><th>Artykuly</th><th>Kategoria WP</th></tr></thead><tbody>';

    const sortedCats = Object.keys(catCounts).sort();
    let matchedCount = 0;

    sortedCats.forEach(catName => {
        if (catName === '(brak)') {
            html += `<tr class="table-warning"><td>${esc(catName)}</td><td>${catCounts[catName]}</td><td><span class="text-muted">-</span></td></tr>`;
            return;
        }

        const wpMatch = wpCatLower[catName.toLowerCase().trim()];
        if (wpMatch) {
            categoryMap[catName] = wpMatch.id;
            matchedCount++;
            html += `<tr class="table-success"><td>${esc(catName)}</td><td>${catCounts[catName]}</td><td><i class="bi bi-check-circle text-success"></i> ${esc(wpMatch.name)} (ID: ${wpMatch.id})</td></tr>`;
        } else {
            // Show dropdown to manually map
            const opts = wpCategories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
            html += `<tr class="table-warning"><td>${esc(catName)}</td><td>${catCounts[catName]}</td><td>
                <div class="d-flex gap-1 align-items-center">
                    <i class="bi bi-exclamation-triangle text-warning"></i>
                    <select class="form-select form-select-sm" style="width:200px" onchange="setCategoryMapping('${esc(catName).replace(/'/g, "\\'")}', this.value)">
                        <option value="">-- wybierz --</option>
                        ${opts}
                    </select>
                </div>
            </td></tr>`;
        }
    });

    html += '</tbody></table>';

    if (wpCategories.length === 0) {
        html += '<div class="text-muted small"><i class="bi bi-info-circle"></i> Zaladuj dane WP (krok 1), aby dopasowac kategorie automatycznie.</div>';
    } else {
        html += `<div class="small text-muted">Dopasowano automatycznie: ${matchedCount}/${sortedCats.filter(c => c !== '(brak)').length} kategorii</div>`;
    }

    container.innerHTML = html;
    container.classList.remove('d-none');
}

function setCategoryMapping(xlsxCat, wpCatId) {
    if (wpCatId) {
        categoryMap[xlsxCat] = parseInt(wpCatId);
    } else {
        delete categoryMap[xlsxCat];
    }
    // Update existing articles that use this category
    articles.forEach(a => {
        if (a._xlsx_category === xlsxCat) {
            a.category_id = wpCatId || '';
        }
    });
    renderArticles();
}

// ── Load DOCX files from local disk paths ───────────────────
function addArticleFromDocx(plan, htmlBody) {
    const catId = categoryMap[plan.category_name] || '';
    articles.push({
        id: Date.now() + Math.random(),
        title: plan.title,
        content: htmlBody,
        slug: titleToSlug(plan.title),
        category_id: catId ? String(catId) : '',
        author_id: '',
        image_data: '',
        image_filename: '',
        publish_date: '',
        status: 'draft',
        _xlsx_category: plan.category_name,
        _docx_filename: plan.docx_filename,
    });
}

async function uploadImportDocxFiles(input) {
    const files = Array.from(input.files);
    if (!files.length) return;

    const status = document.getElementById('docxMatchStatus');
    const progressWrap = document.getElementById('docxProgress');
    const progressBar = document.getElementById('docxProgressBar');

    progressWrap.classList.remove('d-none');
    status.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Wgrywam i przetwarzam pliki DOCX...';

    const total = files.length;
    let done = 0;
    let matched = 0;
    let unmatched = [];
    const BATCH_SIZE = 5;

    // Build lookup: lowercase filename -> importPlan row(s)
    const planByFilename = {};
    for (const plan of importPlan) {
        const key = (plan.docx_filename || '').toLowerCase();
        if (key) {
            if (!planByFilename[key]) planByFilename[key] = [];
            planByFilename[key].push(plan);
        }
    }

    // Build lookup: normalized title -> importPlan row (for fuzzy matching)
    const normalizeTitle = s => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]/g, ' ').replace(/\s+/g, ' ').trim();
    const planTitles = [];
    for (const plan of importPlan) {
        const norm = normalizeTitle(plan.title || '');
        if (norm) planTitles.push({ norm, words: new Set(norm.split(' ')), plan });
    }
    const planByTitle = {};
    for (const pt of planTitles) planByTitle[pt.norm] = pt.plan;

    // Fuzzy match: Jaccard + subset check
    const fuzzyMatchPlan = (docxTitleNorm) => {
        if (!docxTitleNorm) return null;
        const docxWords = new Set(docxTitleNorm.split(' '));
        let bestPlan = null, bestScore = 0;
        for (const pt of planTitles) {
            let common = 0;
            for (const w of pt.words) { if (docxWords.has(w)) common++; }
            const union = new Set([...pt.words, ...docxWords]).size;
            // 1. Jaccard similarity
            const jaccard = union > 0 ? common / union : 0;
            // 2. Subset: shorter title words contained in longer (AI expands titles)
            const smaller = Math.min(pt.words.size, docxWords.size);
            const subsetRatio = smaller > 0 ? common / smaller : 0;
            const score = Math.max(jaccard, subsetRatio);
            if (score > bestScore) { bestScore = score; bestPlan = pt.plan; }
        }
        return bestScore >= 0.5 ? bestPlan : null;
    };

    for (let i = 0; i < files.length; i += BATCH_SIZE) {
        const batch = files.slice(i, i + BATCH_SIZE);

        await Promise.all(batch.map(async (file) => {
            try {
                const fd = new FormData();
                fd.append('file', file);
                const r = await fetch('api/upload-docx.php', {
                    method: 'POST',
                    headers: { 'X-Auth': localStorage.getItem('authToken') || '' },
                    body: fd,
                }).then(res => res.json());

                if (r.error) {
                    unmatched.push(file.name + ': ' + r.error);
                } else {
                    // 1. Match by filename (case-insensitive)
                    const key = file.name.toLowerCase();
                    const plans = planByFilename[key];
                    if (plans && plans.length > 0) {
                        for (const plan of plans) {
                            addArticleFromDocx(plan, r.html_body);
                            matched++;
                        }
                    }
                    // 2. Fallback: match by title from DOCX H1 vs XLSX title
                    else {
                        const docxTitle = r.title || '';
                        const titleKey = normalizeTitle(docxTitle);
                        // Try exact match first, then fuzzy (Jaccard + subset)
                        const titlePlan = (titleKey ? planByTitle[titleKey] : null)
                            || fuzzyMatchPlan(titleKey);

                        if (titlePlan) {
                            addArticleFromDocx(titlePlan, r.html_body);
                            matched++;
                        } else {
                            // No match — add without category
                            articles.push({
                                id: Date.now() + Math.random(),
                                title: docxTitle || file.name.replace(/\.docx$/i, ''),
                                content: r.html_body,
                                slug: titleToSlug(docxTitle || file.name.replace(/\.docx$/i, '')),
                                category_id: '',
                                author_id: '',
                                image_data: '',
                                image_filename: '',
                                publish_date: '',
                                status: 'draft',
                                _xlsx_category: '',
                                _docx_filename: file.name,
                            });
                            unmatched.push(file.name + ': brak w planie XLSX (dodano bez kategorii)');
                        }
                    }
                }
            } catch (e) {
                unmatched.push(file.name + ': ' + e.message);
            } finally {
                done++;
                const pct = Math.round(done / total * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = `${done} / ${total}`;
            }
        }));
    }

    // Results
    let statusText = `<i class="bi bi-check-circle text-success"></i> Wgrano ${done} plikow, dopasowano ${matched} do planu XLSX.`;
    if (unmatched.length > 0) {
        statusText += ` <span class="text-warning">${unmatched.length} uwag:</span> <span class="text-muted small">${unmatched.slice(0, 5).map(e => esc(e)).join('; ')}${unmatched.length > 5 ? '...' : ''}</span>`;
    }
    status.innerHTML = statusText;

    // Show articles table
    document.getElementById('importArticlesCard').classList.remove('d-none');
    document.getElementById('importPublishCard').classList.remove('d-none');

    renderArticles();

    // Reset file input
    input.value = '';
}

// ══════════════════════════════════════════════════════════════
// ── Links Tracking ───────────────────────────────────────────
// ══════════════════════════════════════════════════════════════

let linksClients = [];
let linksSites = [];
let reportLinks = []; // for CSV export of current report

function initLinksPage() {
    const preselectedSiteId = sessionStorage.getItem('linksSiteId');
    if (preselectedSiteId) sessionStorage.removeItem('linksSiteId');

    // Load sites for filter dropdowns
    api('GET', 'api/sites.php').then(sites => {
        linksSites = sites;
        // History site filter
        const hSel = document.getElementById('historySiteFilter');
        if (hSel) {
            sites.forEach(s => { hSel.innerHTML += `<option value="${s.id}">${esc(s.name)}</option>`; });

            // Auto-navigate to History tab filtered by site
            if (preselectedSiteId) {
                hSel.value = preselectedSiteId;
                const historyTab = document.getElementById('tab-history');
                if (historyTab) new bootstrap.Tab(historyTab).show();
                loadLinksHistory();
            }
        }
        initSearchableSelects();
    });

    // Load clients
    loadClients();

    // Load overview
    loadLinksOverview();

    // Tab change listeners
    document.querySelectorAll('#linksTabs button').forEach(btn => {
        btn.addEventListener('shown.bs.tab', e => {
            const target = e.target.getAttribute('data-bs-target');
            if (target === '#pane-overview') loadLinksOverview();
            if (target === '#pane-clients') loadClients();
            if (target === '#pane-history') loadLinksHistory();
            if (target === '#pane-removelinks') initSearchableSelects();
        });
    });
}

// ── Clients ──────────────────────────────────────────────────
function filterClientsTable() {
    const query = (document.getElementById('clientsSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#clientsBody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = (!query || text.includes(query)) ? '' : 'none';
    });
}

function loadClients() {
    api('GET', 'api/clients.php').then(clients => {
        linksClients = clients;

        // Render clients table
        const tbody = document.getElementById('clientsBody');
        if (!tbody) return;

        if (clients.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Brak klientow.</td></tr>';
        } else {
            tbody.innerHTML = clients.map(c => `
                <tr>
                    <td><span class="badge" style="background:${esc(c.color)}">${esc(c.name)}</span></td>
                    <td class="small">${esc(c.domain)}</td>
                    <td>${c.link_count}</td>
                    <td>${c.site_count}</td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-info me-1" onclick="loadLinksForClient(${c.id}, '${esc(c.name).replace(/'/g, "\\'")}')" title="Pokaz linki"><i class="bi bi-eye"></i></button>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editClient(${c.id})" title="Edytuj"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteClient(${c.id}, '${esc(c.name).replace(/'/g, "\\'")}')" title="Usun"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            `).join('');
        }

        // Fill filter dropdowns
        const hcSel = document.getElementById('historyClientFilter');
        if (hcSel) {
            const val = hcSel.value;
            hcSel.innerHTML = '<option value="">Wszyscy klienci</option>' +
                clients.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
            hcSel.value = val;
        }
        const rSel = document.getElementById('reportClientSelect');
        if (rSel) {
            const val = rSel.value;
            rSel.innerHTML = '<option value="">-- wybierz klienta --</option>' +
                clients.map(c => `<option value="${c.id}">${esc(c.name)} (${esc(c.domain)})</option>`).join('');
            rSel.value = val;
        }
        const rlSel = document.getElementById('removeLinkClientSelect');
        if (rlSel) {
            const val = rlSel.value;
            rlSel.innerHTML = '<option value="">-- wybierz klienta --</option>' +
                clients.map(c => `<option value="${c.id}">${esc(c.name)} (${esc(c.domain)})</option>`).join('');
            rlSel.value = val;
        }
    });
}

function resetClientModal() {
    document.getElementById('clientModalTitle').textContent = 'Dodaj klienta';
    document.getElementById('clientEditId').value = '';
    document.getElementById('clientName').value = '';
    document.getElementById('clientDomain').value = '';
    document.getElementById('clientColor').value = '#6c757d';
}

function saveClient() {
    const editId = document.getElementById('clientEditId').value;
    const data = {
        name: document.getElementById('clientName').value.trim(),
        domain: document.getElementById('clientDomain').value.trim(),
        color: document.getElementById('clientColor').value,
    };

    if (!data.name || !data.domain) {
        showToast('Wypelnij nazwe i domene');
        return;
    }

    const method = editId ? 'PUT' : 'POST';
    if (editId) data.id = parseInt(editId);

    api(method, 'api/clients.php', data).then(r => {
        if (r.error) return showToast(r.error, 'error');
        bootstrap.Modal.getInstance(document.getElementById('clientModal')).hide();
        loadClients();
    });
}

function editClient(id) {
    const c = linksClients.find(x => x.id === id);
    if (!c) return;
    document.getElementById('clientModalTitle').textContent = 'Edytuj klienta';
    document.getElementById('clientEditId').value = id;
    document.getElementById('clientName').value = c.name;
    document.getElementById('clientDomain').value = c.domain;
    document.getElementById('clientColor').value = c.color || '#6c757d';
    new bootstrap.Modal(document.getElementById('clientModal')).show();
}

function deleteClient(id, name) {
    if (!confirm(`Usunac klienta "${name}"? Linki pozostana ale bez przypisania.`)) return;
    api('DELETE', 'api/clients.php', { id }).then(r => {
        if (r.error) return showToast(r.error, 'error');
        loadClients();
    });
}

function exportClientsCsv() {
    if (!linksClients || linksClients.length === 0) return showToast('Brak klientow do eksportu');
    let csv = 'name;domain;color\n';
    linksClients.forEach(c => {
        csv += `${c.name};${c.domain};${c.color || '#6c757d'}\n`;
    });
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'klienci.csv';
    a.click();
    URL.revokeObjectURL(url);
}

function importClientsCsv(input) {
    const file = input.files[0];
    if (!file) return;
    input.value = '';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').map(l => l.trim()).filter(l => l);
        if (lines.length < 2) return showToast('Plik CSV jest pusty');

        const header = lines[0].split(';').map(h => h.trim().toLowerCase());
        const required = ['name', 'domain'];
        const optional = ['color'];
        const indices = {};

        for (const col of required) {
            const idx = header.indexOf(col);
            if (idx === -1) return showToast(`Brak kolumny: ${col}\nWymagane: ${required.join(';')}`);
            indices[col] = idx;
        }
        for (const col of optional) {
            const idx = header.indexOf(col);
            if (idx !== -1) indices[col] = idx;
        }

        let imported = 0, skipped = 0;
        const promises = [];

        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(';');
            const data = {
                name: (cols[indices.name] || '').trim(),
                domain: (cols[indices.domain] || '').trim(),
            };
            if (indices.color !== undefined) {
                data.color = (cols[indices.color] || '').trim() || '#6c757d';
            }
            if (!data.name || !data.domain) { skipped++; continue; }

            promises.push(
                api('POST', 'api/clients.php', data).then(r => {
                    if (r.error) skipped++; else imported++;
                })
            );
        }

        Promise.all(promises).then(() => {
            showToast(`Zaimportowano: ${imported}\nPominieto: ${skipped}`);
            loadClients();
        });
    };
    reader.readAsText(file, 'UTF-8');
}

function loadLinksForClient(clientId, clientName) {
    const panel = document.getElementById('clientLinksPanel');
    const title = document.getElementById('clientLinksTitle');
    const tbody = document.getElementById('clientLinksBody');

    panel.style.display = 'block';
    title.innerHTML = `<i class="bi bi-link-45deg"></i> Linki: <strong>${esc(clientName)}</strong>`;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><i class="bi bi-arrow-clockwise spin"></i></td></tr>';

    api('GET', `api/links.php?client_id=${clientId}&limit=500`).then(r => {
        const links = r.links || [];
        if (links.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Brak linkow.</td></tr>';
            return;
        }
        tbody.innerHTML = links.map(l => `
            <tr>
                <td class="small">${esc(l.site_name)}</td>
                <td class="small"><a href="${esc(l.post_url)}" target="_blank" title="${esc(l.post_title)}">${esc(truncate(l.post_title || l.post_url, 40))}</a></td>
                <td class="small">${esc(l.anchor_text)}</td>
                <td class="small"><a href="${esc(l.target_url)}" target="_blank">${esc(truncate(l.target_url, 40))}</a></td>
                <td><span class="badge bg-${l.link_type === 'dofollow' ? 'success' : 'secondary'}">${l.link_type}</span></td>
                <td class="small text-muted">${formatDate(l.created_at)}</td>
            </tr>
        `).join('');
    });
}

// ── Overview ─────────────────────────────────────────────────
function loadLinksOverview() {
    const tbody = document.getElementById('linksOverviewBody');
    if (!tbody) return;

    api('GET', 'api/sites.php').then(sites => {
        if (sites.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Brak stron.</td></tr>';
            return;
        }

        // For each site, get link summary
        const promises = sites.map(s =>
            api('GET', `api/links.php?site_id=${s.id}&limit=1`).then(r => ({
                site: s,
                total: r.total || 0,
                lastLink: (r.links && r.links[0]) ? r.links[0] : null,
            }))
        );

        Promise.all(promises).then(results => {
            // Get unique client counts per site
            const clientPromises = results.map(r => {
                if (r.total === 0) return Promise.resolve({ ...r, clientCount: 0 });
                return api('GET', `api/links.php?site_id=${r.site.id}&limit=2000`).then(lr => {
                    const clientIds = new Set((lr.links || []).filter(l => l.client_id).map(l => l.client_id));
                    return { ...r, clientCount: clientIds.size };
                });
            });

            Promise.all(clientPromises).then(data => {
                tbody.innerHTML = data.map((d, i) => `
                    <tr>
                        <td>${i + 1}</td>
                        <td><a href="${esc(d.site.url)}" target="_blank">${esc(d.site.name)}</a></td>
                        <td><strong>${d.total}</strong></td>
                        <td>${d.clientCount}</td>
                        <td class="small text-muted">${d.lastLink ? formatDate(d.lastLink.created_at) : '-'}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="scanSiteLinks(${d.site.id}, this)" title="Skanuj">
                                <i class="bi bi-search"></i> Skanuj
                            </button>
                        </td>
                    </tr>
                `).join('');
            });
        });
    });
}

function scanSiteLinks(siteId, btn) {
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
    }

    api('POST', 'api/scan-links.php', { site_id: siteId }).then(r => {
        if (r.error) {
            showToast('Blad skanowania: ' + r.error, 'error');
        } else {
            if (btn) btn.innerHTML = `<i class="bi bi-check text-success"></i> +${r.links_inserted}`;
        }
    }).catch(e => {
        showToast('Blad: ' + e.message, 'error');
        if (btn) btn.innerHTML = '<i class="bi bi-x text-danger"></i>';
    }).finally(() => {
        if (btn) btn.disabled = false;
    });
}

function scanAllSitesLinks() {
    const status = document.getElementById('scanAllStatus');
    status.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Skanuje...';

    api('GET', 'api/sites.php').then(async sites => {
        let totalInserted = 0;
        let errors = 0;
        for (let i = 0; i < sites.length; i++) {
            status.innerHTML = `<i class="bi bi-arrow-clockwise spin"></i> ${i + 1}/${sites.length}: ${esc(sites[i].name)}...`;
            try {
                const r = await api('POST', 'api/scan-links.php', { site_id: sites[i].id });
                if (r.error) { errors++; }
                else { totalInserted += r.links_inserted; }
            } catch (e) { errors++; }
        }
        status.innerHTML = `<i class="bi bi-check-circle text-success"></i> Gotowe! Nowych linkow: ${totalInserted}${errors ? `, bledy: ${errors}` : ''}`;
        loadLinksOverview();
    });
}

// ── History ──────────────────────────────────────────────────
function loadLinksHistory() {
    const tbody = document.getElementById('linksHistoryBody');
    if (!tbody) return;

    const clientId = document.getElementById('historyClientFilter').value;
    const siteId = document.getElementById('historySiteFilter').value;
    const dateFrom = document.getElementById('historyDateFrom').value;
    const dateTo = document.getElementById('historyDateTo').value;

    let qs = 'limit=500';
    if (clientId) qs += `&client_id=${clientId}`;
    if (siteId) qs += `&site_id=${siteId}`;
    if (dateFrom) qs += `&date_from=${dateFrom}`;
    if (dateTo) qs += `&date_to=${dateTo}`;

    tbody.innerHTML = '<tr><td colspan="9" class="text-center"><i class="bi bi-arrow-clockwise spin"></i></td></tr>';

    api('GET', `api/links.php?${qs}`).then(r => {
        const links = r.links || [];
        const count = document.getElementById('historyCount');
        if (count) count.textContent = `${links.length} z ${r.total} linkow`;

        if (links.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Brak linkow.</td></tr>';
            return;
        }

        tbody.innerHTML = links.map((l, i) => `
            <tr>
                <td>${i + 1}</td>
                <td class="small text-muted">${formatDate(l.created_at)}</td>
                <td class="small">${esc(l.site_name)}</td>
                <td class="small"><a href="${esc(l.post_url)}" target="_blank" title="${esc(l.post_title)}">${esc(truncate(l.post_title || l.post_url, 30))}</a></td>
                <td>${l.client_name ? `<span class="badge" style="background:${esc(l.client_color || '#6c757d')}">${esc(l.client_name)}</span>` : '<span class="text-muted small">-</span>'}</td>
                <td class="small">${esc(l.anchor_text)}</td>
                <td class="small"><a href="${esc(l.target_url)}" target="_blank">${esc(truncate(l.target_url, 35))}</a></td>
                <td><span class="badge bg-${l.link_type === 'dofollow' ? 'success' : 'secondary'} small">${l.link_type}</span></td>
                <td>
                    <button class="btn btn-sm btn-outline-danger p-0 px-1" onclick="deleteLink(${l.id})" title="Usun"><i class="bi bi-x small"></i></button>
                </td>
            </tr>
        `).join('');
    });
}

function deleteLink(id) {
    if (!confirm('Usunac ten link?')) return;
    api('DELETE', 'api/links.php', { id }).then(r => {
        if (r.error) return showToast(r.error, 'error');
        loadLinksHistory();
    });
}

function clearAllLinks() {
    if (!confirm('UWAGA: Usunac WSZYSTKIE linki z bazy? Tej operacji nie mozna cofnac!')) return;
    if (!confirm('Na pewno? To usunie cala historie linkow.')) return;
    api('DELETE', 'api/links.php', { all: true }).then(r => {
        if (r.error) return showToast(r.error, 'error');
        showToast(`Usunieto ${r.deleted} linkow.`);
        loadLinksHistory();
        loadLinksOverview();
    });
}

function exportLinksCsv() {
    const clientId = document.getElementById('historyClientFilter').value;
    const siteId = document.getElementById('historySiteFilter').value;
    const dateFrom = document.getElementById('historyDateFrom').value;
    const dateTo = document.getElementById('historyDateTo').value;

    let qs = 'limit=2000';
    if (clientId) qs += `&client_id=${clientId}`;
    if (siteId) qs += `&site_id=${siteId}`;
    if (dateFrom) qs += `&date_from=${dateFrom}`;
    if (dateTo) qs += `&date_to=${dateTo}`;

    api('GET', `api/links.php?${qs}`).then(r => {
        const links = r.links || [];
        if (!links.length) return showToast('Brak linkow do eksportu');

        let csv = 'Data;Strona;Post URL;Post Tytul;Klient;Anchor;URL docelowy;Typ\n';
        links.forEach(l => {
            csv += `${l.created_at};${l.site_name};${l.post_url};${l.post_title};${l.client_name || ''};${l.anchor_text};${l.target_url};${l.link_type}\n`;
        });

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'linki-historia.csv';
        a.click();
        URL.revokeObjectURL(url);
    });
}

// ── Report ───────────────────────────────────────────────────
function generateReport() {
    const clientId = document.getElementById('reportClientSelect').value;
    const dateFrom = document.getElementById('reportDateFrom').value;
    const dateTo = document.getElementById('reportDateTo').value;
    const container = document.getElementById('reportContent');

    if (!clientId) return showToast('Wybierz klienta');

    let qs = `client_id=${clientId}&limit=2000`;
    if (dateFrom) qs += `&date_from=${dateFrom}`;
    if (dateTo) qs += `&date_to=${dateTo}`;

    container.innerHTML = '<div class="text-center"><i class="bi bi-arrow-clockwise spin"></i> Generuje raport...</div>';

    api('GET', `api/links.php?${qs}`).then(r => {
        const links = r.links || [];
        reportLinks = links;
        const client = linksClients.find(c => c.id === parseInt(clientId));
        const clientName = client ? client.name : '?';
        const clientDomain = client ? client.domain : '?';

        document.getElementById('btnCopyReport').classList.remove('d-none');
        document.getElementById('btnExportReportCsv').classList.remove('d-none');

        if (links.length === 0) {
            container.innerHTML = `<div class="alert alert-info">Brak linkow dla klienta <strong>${esc(clientName)}</strong> w wybranym okresie.</div>`;
            return;
        }

        // Summary
        const uniqueSites = new Set(links.map(l => l.site_id));
        const uniquePosts = new Set(links.map(l => l.post_url));
        const dofollowCount = links.filter(l => l.link_type === 'dofollow').length;
        const nofollowCount = links.filter(l => l.link_type === 'nofollow').length;

        // By site
        const bySite = {};
        links.forEach(l => {
            if (!bySite[l.site_name]) bySite[l.site_name] = [];
            bySite[l.site_name].push(l);
        });

        // By month
        const byMonth = {};
        links.forEach(l => {
            const m = (l.created_at || '').substring(0, 7); // YYYY-MM
            if (!byMonth[m]) byMonth[m] = 0;
            byMonth[m]++;
        });

        // Anchor distribution
        const anchors = {};
        links.forEach(l => {
            const a = l.anchor_text || '(pusty)';
            if (!anchors[a]) anchors[a] = 0;
            anchors[a]++;
        });

        let html = '';

        // Summary card
        const periodStr = dateFrom || dateTo
            ? `${dateFrom || '...'} - ${dateTo || '...'}`
            : 'caly okres';

        html += `<div class="card mb-3"><div class="card-body">
            <h5><span class="badge" style="background:${esc(client?.color || '#6c757d')}">${esc(clientName)}</span> — ${esc(clientDomain)}</h5>
            <p class="text-muted mb-2">Okres: ${esc(periodStr)}</p>
            <div class="row text-center">
                <div class="col"><h3>${links.length}</h3><small class="text-muted">Linkow</small></div>
                <div class="col"><h3>${uniqueSites.size}</h3><small class="text-muted">Stron</small></div>
                <div class="col"><h3>${uniquePosts.size}</h3><small class="text-muted">Postow</small></div>
                <div class="col"><h3>${dofollowCount}</h3><small class="text-muted">Dofollow</small></div>
                <div class="col"><h3>${nofollowCount}</h3><small class="text-muted">Nofollow</small></div>
            </div>
        </div></div>`;

        // By site
        html += `<div class="card mb-3"><div class="card-body">
            <h6>Linki per strona</h6>
            <table class="table table-sm table-striped">
                <thead><tr><th>Strona</th><th>Linki</th><th>Posty</th></tr></thead>
                <tbody>${Object.entries(bySite).sort((a, b) => b[1].length - a[1].length).map(([name, ls]) => {
                    const posts = new Set(ls.map(l => l.post_url));
                    return `<tr><td>${esc(name)}</td><td>${ls.length}</td><td>${posts.size}</td></tr>`;
                }).join('')}</tbody>
            </table>
        </div></div>`;

        // Anchors
        html += `<div class="card mb-3"><div class="card-body">
            <h6>Anchory</h6>
            <table class="table table-sm table-striped">
                <thead><tr><th>Anchor text</th><th>Ilosc</th></tr></thead>
                <tbody>${Object.entries(anchors).sort((a, b) => b[1] - a[1]).map(([text, cnt]) =>
                    `<tr><td>${esc(text)}</td><td>${cnt}</td></tr>`
                ).join('')}</tbody>
            </table>
        </div></div>`;

        // Monthly distribution
        const sortedMonths = Object.keys(byMonth).sort();
        if (sortedMonths.length > 1) {
            html += `<div class="card mb-3"><div class="card-body">
                <h6>Rozklad miesięczny</h6>
                <table class="table table-sm table-striped">
                    <thead><tr><th>Miesiac</th><th>Linki</th></tr></thead>
                    <tbody>${sortedMonths.map(m => `<tr><td>${m}</td><td>${byMonth[m]}</td></tr>`).join('')}</tbody>
                </table>
            </div></div>`;
        }

        // Full link list
        html += `<div class="card mb-3"><div class="card-body">
            <h6>Wszystkie linki (${links.length})</h6>
            <div class="table-responsive" style="max-height:400px; overflow-y:auto">
            <table class="table table-sm table-striped">
                <thead class="table-light"><tr><th>Data</th><th>Strona</th><th>Post</th><th>Anchor</th><th>URL</th><th>Typ</th></tr></thead>
                <tbody>${links.map(l => `
                    <tr>
                        <td class="small">${formatDate(l.created_at)}</td>
                        <td class="small">${esc(l.site_name)}</td>
                        <td class="small"><a href="${esc(l.post_url)}" target="_blank">${esc(truncate(l.post_title || l.post_url, 30))}</a></td>
                        <td class="small">${esc(l.anchor_text)}</td>
                        <td class="small"><a href="${esc(l.target_url)}" target="_blank">${esc(truncate(l.target_url, 35))}</a></td>
                        <td><span class="badge bg-${l.link_type === 'dofollow' ? 'success' : 'secondary'} small">${l.link_type}</span></td>
                    </tr>
                `).join('')}</tbody>
            </table>
            </div>
        </div></div>`;

        container.innerHTML = html;
    });
}

function copyReportToClipboard() {
    const container = document.getElementById('reportContent');
    if (!container || !container.textContent.trim()) return showToast('Brak raportu');

    // Build plain text version
    const client = linksClients.find(c => c.id === parseInt(document.getElementById('reportClientSelect').value));
    let text = `RAPORT LINKOW: ${client?.name || '?'} (${client?.domain || '?'})\n`;
    text += `Linkow: ${reportLinks.length}\n\n`;

    reportLinks.forEach(l => {
        text += `${l.created_at} | ${l.site_name} | ${l.post_url} | ${l.anchor_text} | ${l.target_url} | ${l.link_type}\n`;
    });

    navigator.clipboard.writeText(text).then(() => showToast('Raport skopiowany do schowka'));
}

function exportReportCsv() {
    if (!reportLinks.length) return showToast('Brak danych raportu');
    let csv = 'Data;Strona;Post URL;Post Tytul;Anchor;URL docelowy;Typ\n';
    reportLinks.forEach(l => {
        csv += `${l.created_at};${l.site_name};${l.post_url};${l.post_title};${l.anchor_text};${l.target_url};${l.link_type}\n`;
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'raport-linki.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ── Remove Links ─────────────────────────────────────────────
let removeLinksData = [];

function loadRemoveLinks() {
    const clientId = document.getElementById('removeLinkClientSelect')?.value;
    const tbody = document.getElementById('removeLinksBody');
    const btn = document.getElementById('btnRemoveSelected');
    if (!tbody) return;

    if (!clientId) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Wybierz klienta</td></tr>';
        if (btn) btn.disabled = true;
        removeLinksData = [];
        return;
    }

    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><i class="bi bi-arrow-clockwise spin"></i></td></tr>';

    api('GET', `api/links.php?client_id=${clientId}&limit=2000`).then(r => {
        removeLinksData = r.links || [];
        if (removeLinksData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Brak linkow dla tego klienta</td></tr>';
            if (btn) btn.disabled = true;
            return;
        }

        if (btn) btn.disabled = false;
        document.getElementById('removeLinksCheckAll').checked = false;

        tbody.innerHTML = removeLinksData.map(l => `
            <tr id="remove-row-${l.id}">
                <td><input type="checkbox" class="remove-link-cb" value="${l.id}"></td>
                <td class="small">${esc(l.site_name)}</td>
                <td class="small"><a href="${esc(l.post_url)}" target="_blank" title="${esc(l.post_title)}">${esc(truncate(l.post_title || l.post_url, 40))}</a></td>
                <td class="small">${esc(l.anchor_text)}</td>
                <td class="small"><a href="${esc(l.target_url)}" target="_blank">${esc(truncate(l.target_url, 35))}</a></td>
                <td><span class="badge bg-${l.link_type === 'dofollow' ? 'success' : 'secondary'} small">${l.link_type}</span></td>
                <td class="small text-muted">${formatDate(l.created_at)}</td>
            </tr>
        `).join('');
    });
}

function toggleRemoveCheckAll(el) {
    document.querySelectorAll('.remove-link-cb').forEach(cb => { cb.checked = el.checked; });
}

async function removeSelectedLinks() {
    const checked = [...document.querySelectorAll('.remove-link-cb:checked')].map(cb => parseInt(cb.value));
    if (checked.length === 0) return showToast('Zaznacz linki do usuniecia');
    if (!confirm(`Usunac ${checked.length} linkow z wpisow blogowych? Wpisy pozostana, tylko linki zostana usuniete.`)) return;

    const status = document.getElementById('removeLinksStatus');
    const btn = document.getElementById('btnRemoveSelected');
    btn.disabled = true;
    let done = 0, errors = 0;

    for (const linkId of checked) {
        status.textContent = `Usuwam ${++done}/${checked.length}...`;
        try {
            const r = await api('POST', 'api/remove-links.php', { link_id: linkId });
            if (r.error) {
                errors++;
                markRow(linkId, 'danger', r.error);
            } else {
                markRow(linkId, 'success', r.warning || 'OK');
            }
        } catch (e) {
            errors++;
            markRow(linkId, 'danger', e.message);
        }
    }

    status.textContent = `Gotowe: ${done - errors} usunieto, ${errors} bledow`;
    btn.disabled = false;

    // Reload after 1s
    setTimeout(() => loadRemoveLinks(), 1000);
}

function markRow(linkId, type, msg) {
    const row = document.getElementById('remove-row-' + linkId);
    if (!row) return;
    if (type === 'success') {
        row.classList.add('table-success');
        row.querySelector('.remove-link-cb').disabled = true;
    } else {
        row.classList.add('table-danger');
        row.title = msg;
    }
}

// ── Publish hook: extract links client-side ──────────────────
function extractAndSaveLinks(siteId, postUrl, postTitle, html) {
    if (!html) return;

    // Get site domain from sitesData
    const site = sitesData.find(s => s.id === siteId);
    if (!site) return;

    let siteDomain = '';
    try {
        siteDomain = new URL(site.url).hostname.replace(/^www\./, '').toLowerCase();
    } catch (e) { return; }

    // Parse HTML with DOMParser
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const anchors = doc.querySelectorAll('a[href]');
    const links = [];

    anchors.forEach(a => {
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('javascript:') || href.startsWith('tel:')) return;

        let linkDomain;
        try {
            const u = new URL(href, 'https://placeholder.com');
            linkDomain = u.hostname.replace(/^www\./, '').toLowerCase();
        } catch (e) { return; }

        if (linkDomain === siteDomain) return;
        if (/\/(wp-admin|wp-content|wp-includes|wp-login)\//i.test(href)) return;

        const rel = (a.getAttribute('rel') || '').toLowerCase();
        links.push({
            post_url: postUrl,
            post_title: postTitle,
            target_url: href,
            anchor_text: a.textContent.trim(),
            link_type: rel.includes('nofollow') ? 'nofollow' : 'dofollow',
        });
    });

    if (links.length === 0) return;

    // Fire and forget
    api('POST', 'api/links.php', { site_id: siteId, links }).catch(() => {});
}

// ── Searchable Select ────────────────────────────────────────
function makeSearchable(selectEl) {
    if (!selectEl || selectEl.dataset.ssInit) return;
    selectEl.dataset.ssInit = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select';
    wrapper.style.width = selectEl.style.width || selectEl.offsetWidth + 'px';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = selectEl.className.replace('form-select', 'form-control');
    input.placeholder = selectEl.options[0]?.text || 'Wybierz...';
    input.style.width = '100%';
    input.classList.add('ss-input', 'input-dropdown');
    input.setAttribute('autocomplete', 'new-password');
    input.setAttribute('data-lpignore', 'true');
    input.setAttribute('readonly', '');

    const dropdown = document.createElement('div');
    dropdown.className = 'ss-dropdown';

    selectEl.style.display = 'none';
    selectEl.parentNode.insertBefore(wrapper, selectEl);
    wrapper.appendChild(input);
    wrapper.appendChild(dropdown);
    wrapper.appendChild(selectEl);

    // Sync display from select value
    function syncDisplay() {
        const opt = selectEl.options[selectEl.selectedIndex];
        input.value = (opt && selectEl.value) ? opt.text : '';
        input.placeholder = selectEl.options[0]?.text || 'Wybierz...';
    }

    function buildOptions(filter) {
        const f = (filter || '').toLowerCase();
        dropdown.innerHTML = '';
        for (let i = 0; i < selectEl.options.length; i++) {
            const opt = selectEl.options[i];
            if (f && !opt.text.toLowerCase().includes(f)) continue;
            const div = document.createElement('div');
            div.className = 'ss-option' + (opt.value === selectEl.value ? ' active' : '');
            div.textContent = opt.text;
            div.dataset.value = opt.value;
            div.addEventListener('mousedown', e => {
                e.preventDefault();
                selectEl.value = opt.value;
                selectEl.dispatchEvent(new Event('change'));
                syncDisplay();
                close();
            });
            dropdown.appendChild(div);
        }
    }

    function open() {
        wrapper.classList.add('open');
        input.value = '';
        buildOptions('');
        input.focus();
    }

    function close() {
        wrapper.classList.remove('open');
        syncDisplay();
    }

    input.addEventListener('focus', () => { input.removeAttribute('readonly'); open(); });
    input.addEventListener('input', () => buildOptions(input.value));
    input.addEventListener('blur', () => { input.setAttribute('readonly', ''); setTimeout(close, 150); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { close(); input.blur(); }
    });

    // Watch for external option changes (JS-rebuilt options)
    const observer = new MutationObserver(() => syncDisplay());
    observer.observe(selectEl, { childList: true, subtree: true });

    syncDisplay();
}

function initSearchableSelects() {
    document.querySelectorAll('select.form-select, select.form-select-sm').forEach(sel => {
        // Skip tiny selects (status, link_type etc.) with ≤5 static options
        if (sel.options.length <= 5 && !sel.id?.includes('Filter') && !sel.id?.includes('filter') && !sel.id?.includes('Select') && !sel.id?.includes('select')) return;
        makeSearchable(sel);
    });
}

// ── Utility ──────────────────────────────────────────────────
function truncate(str, max) {
    if (!str) return '';
    return str.length > max ? str.substring(0, max) + '...' : str;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return dateStr.substring(0, 16).replace('T', ' ');
}

function formatDateLocal(utcStr) {
    if (!utcStr) return '-';
    // SQLite datetime("now") is UTC — append Z so JS parses as UTC
    const d = new Date(utcStr.replace(' ', 'T') + (utcStr.includes('Z') || utcStr.includes('+') ? '' : 'Z'));
    if (isNaN(d)) return utcStr;
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Order Page (Zamów i opublikuj) ──────────────────────────
let orderSites = [];
let orderCategories = [];
let orderGeneratedData = null; // {html_content, featured_image_data, featured_image_filename, inline_images: []}
let orderWpPosts = []; // existing posts for internal linking
let bulkOrderItems = [];
let bulkOrderPublishedUrls = [];

function initOrderPage() {
    api('GET', 'api/sites.php').then(sites => {
        orderSites = sites;
        // Auto-select site if navigated from publish page
        const preselected = sessionStorage.getItem('orderSiteId');
        if (preselected) {
            sessionStorage.removeItem('orderSiteId');
            const site = sites.find(s => s.id == preselected);
            if (site) {
                orderSelectSite(site.id, site.name);
            }
        }
    });
    // Toggle date range visibility
    const rdCb = document.getElementById('bulkOrderRandomDates');
    if (rdCb) {
        rdCb.addEventListener('change', () => {
            const range = document.getElementById('bulkOrderDateRange');
            if (range) range.style.cssText = rdCb.checked ? '' : 'display:none!important';
        });
    }
}

// ── Anthropic API Key ───────────────────────────────────────
async function loadAnthropicKey() {
    try {
        const r = await api('GET', 'api/settings.php?key=anthropic_api_key');
        document.getElementById('anthropicApiKeyInput').value = r.value || '';
    } catch (e) {}
}

async function saveAnthropicKey() {
    const key = document.getElementById('anthropicApiKeyInput').value.trim();
    try {
        await api('POST', 'api/settings.php', { key: 'anthropic_api_key', value: key });
        showToast('Klucz Anthropic API zapisany', 'success');
    } catch (e) {
        showToast('Blad zapisu: ' + e.message, 'error');
    }
}

// ── Site Searchable Dropdown (Single mode) ──────────────────
function orderShowSiteDropdown() {
    const dd = document.getElementById('orderSiteDropdown');
    orderRenderSiteOptions(dd, orderSites, 'orderSelectSite');
    dd.classList.add('show');
    document.addEventListener('click', function close(e) {
        if (!e.target.closest('#orderSiteSearch') && !e.target.closest('#orderSiteDropdown')) {
            dd.classList.remove('show');
            document.removeEventListener('click', close);
        }
    });
}

function orderFilterSites() {
    const q = document.getElementById('orderSiteSearch').value.toLowerCase();
    const dd = document.getElementById('orderSiteDropdown');
    const filtered = orderSites.filter(s => s.name.toLowerCase().includes(q) || s.url.toLowerCase().includes(q));
    orderRenderSiteOptions(dd, filtered, 'orderSelectSite');
}

function orderRenderSiteOptions(dd, sites, fnName) {
    dd.innerHTML = sites.map(s =>
        `<a class="dropdown-item" href="#" onclick="${fnName}(${s.id}, '${esc(s.name)}'); return false;">${esc(s.name)} <small class="text-muted">${esc(s.url)}</small></a>`
    ).join('') || '<span class="dropdown-item text-muted">Brak wynikow</span>';
}

async function orderSelectSite(id, name) {
    document.getElementById('orderSiteId').value = id;
    document.getElementById('orderSiteSearch').value = name;
    document.getElementById('orderSiteDropdown').classList.remove('show');
    document.getElementById('orderSiteLabel').textContent = 'Ladowanie kategorii...';
    document.getElementById('orderFormCard').style.display = '';

    // Load categories
    try {
        const r = await api('GET', `api/wp-data.php?site_id=${id}&type=categories`);
        orderCategories = r;
        const catDD = document.getElementById('orderCategoryDropdown');
        catDD.innerHTML = r.map(c =>
            `<a class="dropdown-item" href="#" onclick="orderSelectCategory(${c.id}, '${esc(c.name)}'); return false;">${esc(c.name)}</a>`
        ).join('');
        // Also fill publish category select
        const pubCat = document.getElementById('orderPublishCategory');
        if (pubCat) {
            pubCat.innerHTML = '<option value="">-- brak --</option>' + r.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
        }
        document.getElementById('orderSiteLabel').textContent = `${r.length} kategorii`;
    } catch (e) {
        document.getElementById('orderSiteLabel').textContent = 'Blad ladowania kategorii';
    }
}

function orderShowCategoryDropdown() {
    const dd = document.getElementById('orderCategoryDropdown');
    dd.classList.add('show');
    document.addEventListener('click', function close(e) {
        if (!e.target.closest('#orderCategorySearch') && !e.target.closest('#orderCategoryDropdown')) {
            dd.classList.remove('show');
            document.removeEventListener('click', close);
        }
    });
}

function orderFilterCategories() {
    const q = document.getElementById('orderCategorySearch').value.toLowerCase();
    const dd = document.getElementById('orderCategoryDropdown');
    const filtered = orderCategories.filter(c => c.name.toLowerCase().includes(q));
    dd.innerHTML = filtered.map(c =>
        `<a class="dropdown-item" href="#" onclick="orderSelectCategory(${c.id}, '${esc(c.name)}'); return false;">${esc(c.name)}</a>`
    ).join('') || '<span class="dropdown-item text-muted">Brak wynikow</span>';
}

function orderSelectCategory(id, name) {
    document.getElementById('orderCategoryId').value = id;
    document.getElementById('orderCategorySearch').value = name;
    document.getElementById('orderCategoryDropdown').classList.remove('show');
}

// ── Generate Article (Single Mode) ──────────────────────────
async function orderGenerate() {
    const title = document.getElementById('orderTitle').value.trim();
    const mainKw = document.getElementById('orderMainKeyword').value.trim();
    if (!title) { showToast('Wpisz tytul artykulu'); return; }
    if (!mainKw) { showToast('Wpisz glowne slowo kluczowe'); return; }
    const siteId = document.getElementById('orderSiteId').value;
    if (!siteId) { showToast('Wybierz strone zapleczowa'); return; }

    const secondaryKw = document.getElementById('orderSecondaryKeywords').value.trim();
    const notes = document.getElementById('orderNotes').value.trim();
    const lang = document.getElementById('orderLang').value;
    const wantInlineImages = document.getElementById('orderInlineImages').checked;

    const btn = document.getElementById('orderGenerateBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generowanie...';

    document.getElementById('orderProgressCard').style.display = '';
    document.getElementById('orderEditCard').style.display = 'none';
    document.getElementById('orderResultCard').style.display = 'none';

    const bar = document.getElementById('orderProgressBar');
    const log = document.getElementById('orderProgressLog');
    log.innerHTML = '';

    orderGeneratedData = { html_content: '', featured_image_data: '', featured_image_filename: '', inline_images: [] };

    // Phase 1: Generate article text (0-40%)
    orderProgress(bar, 5, 'Generowanie tresci artykulu (Claude API)...');
    log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Generowanie tresci...</div>';

    try {
        const article = await api('POST', 'api/generate-article.php', {
            title, main_keyword: mainKw, secondary_keywords: secondaryKw, notes, lang
        });
        if (article.error) throw new Error(article.error);

        orderGeneratedData.html_content = article.html_content;
        orderGeneratedData.markdown = article.markdown;
        log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> Tresc wygenerowana (${article.char_count} znakow)</div>`;
        orderProgress(bar, 30, 'Tresc gotowa');
    } catch (e) {
        log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Blad: ${esc(e.message)}</div>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-stars"></i> Generuj artykul';
        return;
    }

    // Phase 1b: Proofreading (30-40%)
    orderProgress(bar, 32, 'Korekta ortograficzna...');
    log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Korekta ortograficzna...</div>';
    try {
        const proof = await api('POST', 'api/proofread-article.php', {
            markdown: orderGeneratedData.markdown, lang
        });
        if (!proof.error && proof.html_content) {
            orderGeneratedData.html_content = proof.html_content;
            orderGeneratedData.markdown = proof.markdown;
            log.innerHTML += '<div class="text-success"><i class="bi bi-check-circle"></i> Korekta zakonczona</div>';
        }
    } catch (e) {
        log.innerHTML += `<div class="text-warning"><i class="bi bi-exclamation-triangle"></i> Korekta: ${esc(e.message)}</div>`;
    }
    orderProgress(bar, 40, 'Korekta gotowa');

    // Phase 2: Generate featured image (40-60%)
    orderProgress(bar, 45, 'Generowanie grafiki wyrozniajacej...');
    log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Generowanie grafiki wyrozniajacej...</div>';

    try {
        const img = await api('POST', 'api/gemini-generate.php', { title });
        if (img.error) throw new Error(img.error);
        orderGeneratedData.featured_image_data = img.image_data;
        orderGeneratedData.featured_image_filename = slugifyText(title) + '.jpg';
        log.innerHTML += '<div class="text-success"><i class="bi bi-check-circle"></i> Grafika wyroznajaca wygenerowana</div>';
    } catch (e) {
        log.innerHTML += `<div class="text-warning"><i class="bi bi-exclamation-triangle"></i> Grafika: ${esc(e.message)}</div>`;
    }
    orderProgress(bar, 60, 'Grafika gotowa');

    // Phase 3: Generate inline images (60-85%)
    if (wantInlineImages && orderGeneratedData.html_content) {
        const h2Titles = extractH2Titles(orderGeneratedData.html_content);
        const toGenerate = h2Titles.slice(0, 3); // max 3 inline images

        for (let i = 0; i < toGenerate.length; i++) {
            const pct = 60 + Math.round((i + 1) / toGenerate.length * 25);
            orderProgress(bar, pct, `Grafika w tekscie ${i + 1}/${toGenerate.length}...`);
            log.innerHTML += `<div><i class="bi bi-hourglass-split text-primary"></i> Grafika: ${esc(toGenerate[i])}...</div>`;

            try {
                const img = await api('POST', 'api/generate-inline-image.php', {
                    section_title: toGenerate[i], article_title: title
                });
                if (img.error) throw new Error(img.error);
                orderGeneratedData.inline_images.push({
                    section_title: toGenerate[i],
                    image_data: img.image_data,
                    image_filename: img.image_filename,
                    alt_text: img.alt_text,
                });
                log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> Grafika: ${esc(toGenerate[i])}</div>`;
            } catch (e) {
                log.innerHTML += `<div class="text-warning"><i class="bi bi-exclamation-triangle"></i> ${esc(e.message)}</div>`;
            }

            if (i < toGenerate.length - 1) await sleep(1000);
        }
    }

    // Phase 4: Internal linking (85-100%)
    orderProgress(bar, 88, 'Finalizacja...');

    // Insert inline images into content
    if (orderGeneratedData.inline_images.length > 0) {
        orderGeneratedData.html_content = insertInlineImages(
            orderGeneratedData.html_content, orderGeneratedData.inline_images
        );
    }

    orderProgress(bar, 100, 'Gotowe!');
    log.innerHTML += '<div class="text-success fw-bold mt-2"><i class="bi bi-check-circle-fill"></i> Artykul gotowy do edycji i publikacji</div>';

    // Show edit card
    document.getElementById('orderEditCard').style.display = '';
    document.getElementById('orderEditTitle').value = title;
    document.getElementById('orderEditContent').innerHTML = orderGeneratedData.html_content;
    updateOrderCharCount();

    // Featured image preview
    if (orderGeneratedData.featured_image_data) {
        document.getElementById('orderFeaturedPreview').innerHTML =
            `<img src="data:image/jpeg;base64,${orderGeneratedData.featured_image_data}" class="img-fluid rounded">`;
    }

    // Set category if selected
    const catId = document.getElementById('orderCategoryId').value;
    if (catId) document.getElementById('orderPublishCategory').value = catId;

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-stars"></i> Generuj artykul';
}

function orderProgress(bar, pct, text) {
    bar.style.width = pct + '%';
    bar.textContent = text || (pct + '%');
}

// ── Rich Text Editor Toolbar Functions ───────────────────────
function editorCmd(command, value) {
    document.getElementById('orderEditContent').focus();
    document.execCommand(command, false, value || null);
    updateOrderCharCount();
}

function editorHeading(tag) {
    document.getElementById('orderEditContent').focus();
    document.execCommand('formatBlock', false, '<' + tag + '>');
    updateOrderCharCount();
}

function editorInsertLink() {
    const editor = document.getElementById('orderEditContent');
    editor.focus();

    // Check if cursor is inside an existing link
    const sel = window.getSelection();
    let existingLink = null;
    if (sel.rangeCount > 0) {
        let node = sel.getRangeAt(0).commonAncestorContainer;
        while (node && node !== editor) {
            if (node.nodeName === 'A') { existingLink = node; break; }
            node = node.parentNode;
        }
    }

    const currentUrl = existingLink ? existingLink.href : '';
    const currentTarget = existingLink ? (existingLink.target || '') : '_blank';
    const selectedText = sel.toString();

    // Build a small modal-like prompt
    const url = prompt('URL linku:', currentUrl || 'https://');
    if (url === null) return; // cancelled
    if (!url.trim()) {
        // Empty URL = remove link
        if (existingLink) {
            const text = existingLink.textContent;
            existingLink.replaceWith(document.createTextNode(text));
        }
        return;
    }

    const openInNew = confirm('Otworzyc w nowej karcie? (OK = tak, Anuluj = nie)');

    if (existingLink) {
        // Update existing link
        existingLink.href = url;
        existingLink.target = openInNew ? '_blank' : '';
        if (openInNew) existingLink.rel = 'noopener noreferrer';
        else existingLink.removeAttribute('rel');
    } else {
        // Create new link
        if (!selectedText) {
            const linkText = prompt('Tekst linku:', '');
            if (!linkText) return;
            const a = document.createElement('a');
            a.href = url;
            a.textContent = linkText;
            if (openInNew) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
            const range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(a);
            // Move cursor after link
            range.setStartAfter(a);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            document.execCommand('createLink', false, url);
            // Find the newly created link and set target
            if (openInNew) {
                const links = editor.querySelectorAll('a[href="' + CSS.escape(url) + '"]');
                links.forEach(a => {
                    if (!a.target) {
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                    }
                });
            }
        }
    }
    updateOrderCharCount();
}

function editorRemoveLink() {
    const editor = document.getElementById('orderEditContent');
    editor.focus();
    const sel = window.getSelection();
    if (sel.rangeCount === 0) return;

    let node = sel.getRangeAt(0).commonAncestorContainer;
    while (node && node !== editor) {
        if (node.nodeName === 'A') {
            const text = node.textContent;
            node.replaceWith(document.createTextNode(text));
            return;
        }
        node = node.parentNode;
    }
    // Fallback: use execCommand
    document.execCommand('unlink', false, null);
}

let orderSourceMode = false;
function editorToggleSource() {
    const editor = document.getElementById('orderEditContent');
    const source = document.getElementById('orderEditSource');
    const btn = document.getElementById('orderToggleSourceBtn');

    if (!orderSourceMode) {
        // Switch to source view
        source.value = editor.innerHTML;
        editor.style.display = 'none';
        source.style.display = '';
        btn.classList.add('active');
        orderSourceMode = true;
    } else {
        // Switch back to WYSIWYG
        editor.innerHTML = source.value;
        source.style.display = 'none';
        editor.style.display = '';
        btn.classList.remove('active');
        orderSourceMode = false;
        updateOrderCharCount();
    }
}

function updateOrderCharCount() {
    const el = document.getElementById('orderEditContent');
    if (!el) return;
    const text = el.innerText || el.textContent || '';
    document.getElementById('orderCharCount').textContent = `${text.length} znakow`;
}

// Watch for content changes
document.addEventListener('input', function(e) {
    if (e.target.id === 'orderEditContent') updateOrderCharCount();
});

async function orderRegenerateFeatured() {
    const title = document.getElementById('orderEditTitle').value.trim();
    if (!title) return;
    const preview = document.getElementById('orderFeaturedPreview');
    preview.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generowanie...';
    try {
        const img = await api('POST', 'api/gemini-generate.php', { title });
        if (img.error) throw new Error(img.error);
        orderGeneratedData.featured_image_data = img.image_data;
        orderGeneratedData.featured_image_filename = slugifyText(title) + '.jpg';
        preview.innerHTML = `<img src="data:image/jpeg;base64,${img.image_data}" class="img-fluid rounded">`;
    } catch (e) {
        preview.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
    }
}

// ── Publish (Single Mode) ───────────────────────────────────
async function orderPublish() {
    // Sync source mode back to editor if active
    if (orderSourceMode) editorToggleSource();

    const siteId = document.getElementById('orderSiteId').value;
    const title = document.getElementById('orderEditTitle').value.trim();
    const content = sanitizeArticleHtml(document.getElementById('orderEditContent').innerHTML);
    const categoryId = document.getElementById('orderPublishCategory').value;
    const status = document.getElementById('orderPublishStatus').value;
    const publishDate = document.getElementById('orderPublishDate').value;
    const speedLinks = document.getElementById('orderSpeedLinks').checked;

    if (!title || !content) { showToast('Brak tytulu lub tresci'); return; }

    const btn = document.getElementById('orderPublishBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Publikowanie...';

    document.getElementById('orderResultCard').style.display = '';
    const log = document.getElementById('orderResultLog');
    log.innerHTML = '<div><i class="bi bi-hourglass-split text-primary"></i> Publikowanie artykulu...</div>';

    try {
        // Upload featured image first if exists
        let mediaId = 0;
        if (orderGeneratedData.featured_image_data) {
            log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Przesylanie grafiki wyrozniajacej...</div>';
            const uploadResult = await api('POST', 'api/upload-image.php', {
                site_id: parseInt(siteId),
                image_data: orderGeneratedData.featured_image_data,
                image_filename: orderGeneratedData.featured_image_filename,
                keep_filename: true,
            });
            if (uploadResult.media_id) mediaId = uploadResult.media_id;
        }

        // Upload inline images and replace src in content
        let finalContent = content;
        if (orderGeneratedData.inline_images.length > 0) {
            log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Przesylanie grafik w tekscie...</div>';
            for (let idx = 0; idx < orderGeneratedData.inline_images.length; idx++) {
                const img = orderGeneratedData.inline_images[idx];
                try {
                    const uploadResult = await api('POST', 'api/upload-image.php', {
                        site_id: parseInt(siteId),
                        image_data: img.image_data,
                        image_filename: img.image_filename,
                        keep_filename: true,
                    });
                    if (uploadResult.url) {
                        finalContent = replaceInlineImageSrc(finalContent, idx, uploadResult.url);
                    }
                } catch (e) {
                    // Continue with base64 if upload fails
                }
                await sleep(500);
            }
        }

        // Create post
        const postData = {
            site_id: parseInt(siteId),
            title,
            content: finalContent,
            status,
            category_id: parseInt(categoryId) || 0,
            publish_date: publishDate,
        };
        if (mediaId) postData.media_id = mediaId;

        const result = await api('POST', 'api/publish.php', postData);

        if (result.error) throw new Error(result.error);

        log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> Artykul opublikowany: <a href="${esc(result.post_url)}" target="_blank">${esc(result.post_url)}</a></div>`;

        // Speed-Links
        if (speedLinks && result.post_url) {
            log.innerHTML += '<div><i class="bi bi-hourglass-split text-primary"></i> Wysylanie do Speed-Links...</div>';
            const slResult = await submitToSpeedLinks([result.post_url]);
            if (slResult && slResult.error) {
                log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Speed-Links: ${esc(slResult.error)}</div>`;
            } else if (slResult) {
                let msg = `Speed-Links: wyslano do indeksacji`;
                if (slResult.report_url) msg += ` — <a href="${esc(slResult.report_url)}" target="_blank">raport</a>`;
                log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> ${msg}</div>`;
            }
        }

        // Extract and save links
        if (result.post_url) {
            try {
                await extractAndSaveLinks(parseInt(siteId), result.post_url, title, finalContent);
            } catch (e) {}
        }

        // Show "Publikuj kolejny" button
        log.innerHTML += `<div class="mt-3"><button class="btn btn-primary" onclick="orderReset()"><i class="bi bi-plus-lg"></i> Publikuj kolejny</button></div>`;

    } catch (e) {
        log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Blad: ${esc(e.message)}</div>`;
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-send"></i> Opublikuj artykul';
}

function orderReset() {
    // Reset form for next article
    document.getElementById('orderTitle').value = '';
    document.getElementById('orderMainKeyword').value = '';
    document.getElementById('orderSecondaryKeywords').value = '';
    document.getElementById('orderNotes').value = '';
    document.getElementById('orderLang').value = 'pl';
    document.getElementById('orderInlineImages').checked = false;
    document.getElementById('orderProgressCard').style.display = 'none';
    document.getElementById('orderEditCard').style.display = 'none';
    document.getElementById('orderResultCard').style.display = 'none';
    document.getElementById('orderProgressLog').innerHTML = '';
    document.getElementById('orderResultLog').innerHTML = '';
    document.getElementById('orderProgressBar').style.width = '0%';
    document.getElementById('orderProgressBar').textContent = '0%';
    document.getElementById('orderFeaturedPreview').innerHTML = '<span class="text-muted">Brak</span>';
    document.getElementById('orderEditContent').innerHTML = '';
    orderGeneratedData = null;
    // Scroll to form
    document.getElementById('orderFormCard').scrollIntoView({ behavior: 'smooth' });
}

// ── Bulk Mode ───────────────────────────────────────────────
function bulkOrderShowSiteDropdown() {
    const dd = document.getElementById('bulkOrderSiteDropdown');
    orderRenderSiteOptions(dd, orderSites, 'bulkOrderSelectSite');
    dd.classList.add('show');
    document.addEventListener('click', function close(e) {
        if (!e.target.closest('#bulkOrderSiteSearch') && !e.target.closest('#bulkOrderSiteDropdown')) {
            dd.classList.remove('show');
            document.removeEventListener('click', close);
        }
    });
}

function bulkOrderFilterSites() {
    const q = document.getElementById('bulkOrderSiteSearch').value.toLowerCase();
    const dd = document.getElementById('bulkOrderSiteDropdown');
    const filtered = orderSites.filter(s => s.name.toLowerCase().includes(q) || s.url.toLowerCase().includes(q));
    orderRenderSiteOptions(dd, filtered, 'bulkOrderSelectSite');
}

let bulkOrderCategories = []; // WP categories for matching
let bulkOrderAuthors = []; // WP authors

async function bulkOrderSelectSite(id, name) {
    document.getElementById('bulkOrderSiteId').value = id;
    document.getElementById('bulkOrderSiteSearch').value = name;
    document.getElementById('bulkOrderSiteDropdown').classList.remove('show');
    document.getElementById('bulkOrderUploadCard').style.display = '';

    // Load categories and authors in parallel
    const [catResult, authResult] = await Promise.allSettled([
        api('GET', `api/wp-data.php?site_id=${id}&type=categories`),
        api('GET', `api/wp-data.php?site_id=${id}&type=authors`),
    ]);

    if (catResult.status === 'fulfilled') {
        bulkOrderCategories = catResult.value;
        const sel = document.getElementById('bulkOrderFallbackCategory');
        sel.innerHTML = '<option value="">-- brak --</option>' + catResult.value.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    }

    if (authResult.status === 'fulfilled') {
        bulkOrderAuthors = authResult.value;
        const authSel = document.getElementById('bulkOrderDefaultAuthor');
        authSel.innerHTML = '<option value="">-- brak (domyślny WP) --</option>' + authResult.value.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        // Build random author checkboxes
        const checksDiv = document.getElementById('bulkOrderRandomAuthorsChecks');
        checksDiv.innerHTML = authResult.value.map(a =>
            `<div class="form-check form-check-inline"><input class="form-check-input bulk-author-check" type="checkbox" value="${a.id}" id="bulkAuth${a.id}" checked><label class="form-check-label small" for="bulkAuth${a.id}">${esc(a.name)}</label></div>`
        ).join('');
    }
}

function matchBulkCategory(csvCategoryName) {
    if (!csvCategoryName) return { id: 0, name: '' };
    const lower = csvCategoryName.toLowerCase().trim();
    for (const cat of bulkOrderCategories) {
        if (cat.name.toLowerCase().trim() === lower) return cat;
    }
    return { id: 0, name: csvCategoryName + ' (?)' };
}

// ── Bulk file parsing (CSV + XLSX) with column mapping ──────
let bulkOrderParsedData = null; // { headers: [], rows: [] }

async function bulkOrderParseFile(input) {
    const file = input.files[0];
    if (!file) return;

    const statusEl = document.getElementById('bulkOrderFileStatus');
    statusEl.textContent = 'Wczytywanie pliku...';

    const formData = new FormData();
    formData.append('file', file);

    try {
        const resp = await fetch('api/parse-bulk-file.php', {
            method: 'POST',
            body: formData,
        });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); statusEl.textContent = ''; return; }

        bulkOrderParsedData = data;
        statusEl.textContent = `${file.name} — ${data.rows.length} wierszy`;

        // Show mapping modal
        bulkOrderShowMapping(data);
    } catch (e) {
        showToast('Błąd parsowania pliku: ' + e.message, 'error');
        statusEl.textContent = '';
    }
    input.value = '';
}

function bulkOrderShowMapping(data) {
    const headers = data.headers;
    const fields = [
        { id: 'mapColTitle', patterns: ['tytuł', 'tytul', 'title', 'temat', 'nazwa'], required: true },
        { id: 'mapColMainKw', patterns: ['główne', 'glowne', 'main', 'keyword', 'słowo kluczowe', 'slowo kluczowe', 'kw'], required: true },
        { id: 'mapColSecondaryKw', patterns: ['pomocnicze', 'poboczne', 'secondary', 'dodatkowe kw', 'słowa kluczowe poboczne'], required: false },
        { id: 'mapColNotes', patterns: ['dodatkowe informacje', 'notatki', 'notes', 'info', 'wskazówki', 'wskazowki', 'opis'], required: false },
        { id: 'mapColCategory', patterns: ['kategoria', 'category', 'cat', 'katalog', 'dział', 'dzial'], required: false },
        { id: 'mapColLang', patterns: ['język', 'jezyk', 'lang', 'language'], required: false },
    ];

    // Auto-detect mapping
    for (const field of fields) {
        const sel = document.getElementById(field.id);
        sel.innerHTML = '<option value="">— pomiń —</option>' +
            headers.map((h, i) => `<option value="${i}">${esc(h)}</option>`).join('');

        // Try to auto-match by header name
        let matched = false;
        for (let i = 0; i < headers.length; i++) {
            const lower = headers[i].toLowerCase().trim();
            for (const p of field.patterns) {
                if (lower.includes(p)) {
                    sel.value = String(i);
                    matched = true;
                    break;
                }
            }
            if (matched) break;
        }
    }

    // Preview table
    const previewHead = document.querySelector('#bulkOrderPreviewTable thead tr');
    previewHead.innerHTML = headers.map(h => `<th class="small">${esc(h)}</th>`).join('');

    const previewBody = document.querySelector('#bulkOrderPreviewTable tbody');
    const previewRows = data.rows.slice(0, 5);
    previewBody.innerHTML = previewRows.map(row => {
        return '<tr>' + headers.map((_, i) => `<td class="small">${esc(truncate(row[i] || '', 80))}</td>`).join('') + '</tr>';
    }).join('');

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('bulkOrderMappingModal'));
    modal.show();
}

function bulkOrderCancelMapping() {
    bulkOrderParsedData = null;
    document.getElementById('bulkOrderFileStatus').textContent = '';
}

function bulkOrderApplyMapping() {
    if (!bulkOrderParsedData) return;

    const colTitle = document.getElementById('mapColTitle').value;
    const colMainKw = document.getElementById('mapColMainKw').value;
    const colSecKw = document.getElementById('mapColSecondaryKw').value;
    const colNotes = document.getElementById('mapColNotes').value;
    const colCategory = document.getElementById('mapColCategory').value;
    const colLang = document.getElementById('mapColLang').value;

    if (colTitle === '') { showToast('Kolumna "Tytuł" jest wymagana', 'error'); return; }
    if (colMainKw === '') { showToast('Kolumna "Główne słowo kluczowe" jest wymagana', 'error'); return; }

    bulkOrderItems = [];
    for (const row of bulkOrderParsedData.rows) {
        const title = (row[parseInt(colTitle)] || '').trim();
        if (!title) continue;

        const csvCategory = colCategory !== '' ? (row[parseInt(colCategory)] || '').trim() : '';
        const matched = matchBulkCategory(csvCategory);

        bulkOrderItems.push({
            title,
            main_keyword: (row[parseInt(colMainKw)] || '').trim(),
            secondary_keywords: colSecKw !== '' ? (row[parseInt(colSecKw)] || '').trim() : '',
            category_name: csvCategory,
            category_id: matched.id,
            category_matched: matched.id > 0,
            notes: colNotes !== '' ? (row[parseInt(colNotes)] || '').trim() : '',
            lang: colLang !== '' ? (row[parseInt(colLang)] || '').trim() : '',
            selected: true,
            publish_date: '',
            author_id: 0,
            status: 'pending',
        });
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkOrderMappingModal'));
    if (modal) modal.hide();
    bulkOrderParsedData = null;

    if (bulkOrderItems.length === 0) {
        showToast('Plik nie zawiera artykułów', 'warning');
        return;
    }

    showToast(`Wczytano ${bulkOrderItems.length} artykułów`, 'success');

    // Show category mapping step if there are unmapped categories from the file
    const uniqueFileCats = [...new Set(bulkOrderItems.map(i => i.category_name).filter(n => n))];
    if (uniqueFileCats.length > 0) {
        bulkOrderShowCategoryMapping(uniqueFileCats);
    } else {
        bulkOrderShowArticleTable();
    }
}

function bulkOrderShowCategoryMapping(uniqueFileCats) {
    const tbody = document.querySelector('#bulkOrderCategoryMapTable tbody');
    const wpOptions = '<option value="">-- brak --</option>' + bulkOrderCategories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');

    tbody.innerHTML = uniqueFileCats.map((catName, i) => {
        const count = bulkOrderItems.filter(it => it.category_name === catName).length;
        const matched = matchBulkCategory(catName);
        const selHtml = `<select class="form-select form-select-sm bulk-cat-map" data-file-cat="${esc(catName)}">${wpOptions}</select>`;
        return `<tr>
            <td><code>${esc(catName)}</code></td>
            <td class="text-center">${count}</td>
            <td>${selHtml}</td>
        </tr>`;
    }).join('');

    // Auto-select matched categories
    tbody.querySelectorAll('select.bulk-cat-map').forEach(sel => {
        const fileCat = sel.dataset.fileCat;
        const matched = matchBulkCategory(fileCat);
        if (matched.id > 0) sel.value = String(matched.id);
    });

    document.getElementById('bulkOrderCategoryMapCard').style.display = '';
}

function bulkOrderApplyCategoryMapping() {
    // Read mappings from the table
    document.querySelectorAll('select.bulk-cat-map').forEach(sel => {
        const fileCat = sel.dataset.fileCat;
        const wpCatId = parseInt(sel.value) || 0;
        bulkOrderItems.forEach(item => {
            if (item.category_name === fileCat) {
                item.category_id = wpCatId;
                item.category_matched = wpCatId > 0;
            }
        });
    });
    document.getElementById('bulkOrderCategoryMapCard').style.display = 'none';
    bulkOrderShowArticleTable();
}

function bulkOrderSkipCategoryMapping() {
    document.getElementById('bulkOrderCategoryMapCard').style.display = 'none';
    bulkOrderShowArticleTable();
}

function bulkOrderShowArticleTable() {
    renderBulkOrderTable();
    document.getElementById('bulkOrderTableCard').style.display = '';
}

function renderBulkOrderTable() {
    const authorMode = (document.getElementById('bulkOrderAuthorMode') || {}).value || 'default';
    const showAuthorCol = authorMode === 'manual';
    const tbody = document.querySelector('#bulkOrderTable tbody');
    tbody.innerHTML = bulkOrderItems.map((item, i) => {
        const statusBadge = {
            pending: '<span class="badge bg-secondary">Oczekuje</span>',
            skipped: '<span class="badge bg-light text-dark">Pominieto</span>',
            generating: '<span class="badge bg-primary">Generowanie...</span>',
            publishing: '<span class="badge bg-info">Publikowanie...</span>',
            done: '<span class="badge bg-success">Gotowe</span>',
            error: `<span class="badge bg-danger">Blad</span>`,
        }[item.status] || '';
        let catOptions = '<option value="">-- brak --</option>' + bulkOrderCategories.map(c => {
            const sel = (item.category_id && item.category_id == c.id) ? ' selected' : '';
            return `<option value="${c.id}"${sel}>${esc(c.name)}</option>`;
        }).join('');
        const catCell = `<select class="form-select form-select-sm" style="min-width:120px" onchange="bulkOrderSetCategory(${i}, this.value)">${catOptions}</select>`;

        // Author cell (manual mode)
        let authorCell = '';
        if (showAuthorCol) {
            let authOptions = '<option value="">-- domyślny --</option>' + bulkOrderAuthors.map(a => {
                const sel = (item.author_id && item.author_id == a.id) ? ' selected' : '';
                return `<option value="${a.id}"${sel}>${esc(a.name)}</option>`;
            }).join('');
            authorCell = `<td><select class="form-select form-select-sm" style="min-width:120px" onchange="bulkOrderSetAuthor(${i}, this.value)">${authOptions}</select></td>`;
        } else {
            const authorName = bulkOrderGetAuthorName(item.author_id);
            authorCell = `<td>${authorName ? `<small>${esc(authorName)}</small>` : '<span class="text-muted">dom.</span>'}</td>`;
        }

        const checked = item.selected ? 'checked' : '';
        const rowClass = item.selected ? '' : 'class="table-light text-muted"';
        const dateVal = item.publish_date || '';
        return `<tr ${rowClass}>
            <td><input type="checkbox" class="form-check-input" ${checked} onchange="bulkOrderToggleItem(${i}, this.checked)"></td>
            <td>${i + 1}</td>
            <td>${esc(item.title)}</td>
            <td>${esc(item.main_keyword)}</td>
            <td>${esc(item.secondary_keywords)}</td>
            <td>${catCell}</td>
            ${authorCell}
            <td>${item.lang ? `<small>${esc(item.lang.toUpperCase())}</small>` : '<span class="text-muted">dom.</span>'}</td>
            <td>${dateVal ? `<small>${esc(dateVal)}</small>` : '<span class="text-muted">teraz</span>'}</td>
            <td>${item.notes ? `<small>${esc(truncate(item.notes, 50))}</small>` : '<span class="text-muted">-</span>'}</td>
            <td>${statusBadge}${item.url ? ` <a href="${esc(item.url)}" target="_blank" class="small">link</a>` : ''}${item.errorMsg ? ` <small class="text-danger">${esc(item.errorMsg)}</small>` : ''}</td>
        </tr>`;
    }).join('');
    bulkOrderUpdateSelectedCount();
}

function bulkOrderGetAuthorName(authorId) {
    if (!authorId) return '';
    const a = bulkOrderAuthors.find(a => a.id == authorId);
    return a ? a.name : '';
}

function bulkOrderSetAuthor(index, authorId) {
    bulkOrderItems[index].author_id = parseInt(authorId) || 0;
}

function bulkOrderAuthorModeChanged() {
    const mode = document.getElementById('bulkOrderAuthorMode').value;
    document.getElementById('bulkOrderDefaultAuthorWrap').style.display = mode === 'default' ? '' : 'none';
    document.getElementById('bulkOrderRandomAuthorsWrap').style.display = mode === 'random' ? '' : 'none';

    // Reset author assignments when switching modes
    if (mode === 'default') {
        bulkOrderItems.forEach(item => { item.author_id = 0; });
    } else if (mode === 'random') {
        bulkOrderAssignRandomAuthors();
    } else {
        bulkOrderItems.forEach(item => { item.author_id = 0; });
    }
    renderBulkOrderTable();
}

function bulkOrderAssignRandomAuthors() {
    const checked = document.querySelectorAll('.bulk-author-check:checked');
    const authorIds = Array.from(checked).map(c => parseInt(c.value));
    if (authorIds.length === 0) { showToast('Zaznacz przynajmniej jednego autora', 'warning'); return; }
    bulkOrderItems.forEach(item => {
        item.author_id = authorIds[Math.floor(Math.random() * authorIds.length)];
    });
    renderBulkOrderTable();
}

function bulkOrderToggleItem(index, checked) {
    bulkOrderItems[index].selected = checked;
    const allChecked = bulkOrderItems.every(i => i.selected);
    const selectAllEl = document.getElementById('bulkOrderSelectAll');
    if (selectAllEl) selectAllEl.checked = allChecked;
    renderBulkOrderTable();
}

function bulkOrderToggleAll(checked) {
    bulkOrderItems.forEach(item => { item.selected = checked; });
    renderBulkOrderTable();
}

function bulkOrderSetCategory(index, catId) {
    bulkOrderItems[index].category_id = parseInt(catId) || 0;
}

function bulkOrderUpdateSelectedCount() {
    const el = document.getElementById('bulkOrderSelectedCount');
    if (!el) return;
    const selected = bulkOrderItems.filter(i => i.selected).length;
    el.textContent = `Zaznaczono: ${selected} / ${bulkOrderItems.length}`;
}

function bulkOrderAssignRandomDates() {
    const fromStr = document.getElementById('bulkOrderDateFrom').value;
    const toStr = document.getElementById('bulkOrderDateTo').value;
    if (!fromStr || !toStr) { showToast('Podaj zakres dat (Od i Do)'); return; }
    const fromMs = new Date(fromStr + 'T00:00:00').getTime();
    const toMs = new Date(toStr + 'T23:59:59').getTime();
    if (fromMs > toMs) { showToast('Data "Od" musi byc przed data "Do"'); return; }

    // Generate random timestamps, then sort chronologically
    const randomDates = bulkOrderItems.map(() => {
        const ms = fromMs + Math.random() * (toMs - fromMs);
        const d = new Date(ms);
        // Random hour 6-22
        d.setHours(6 + Math.floor(Math.random() * 16), Math.floor(Math.random() * 60));
        return d;
    });
    randomDates.sort((a, b) => a - b);

    const pad = n => String(n).padStart(2, '0');
    bulkOrderItems.forEach((item, i) => {
        const d = randomDates[i];
        item.publish_date = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    });
    renderBulkOrderTable();
}

async function bulkOrderStart() {
    const siteId = document.getElementById('bulkOrderSiteId').value;
    if (!siteId) { showToast('Wybierz strone'); return; }

    const selectedItems = bulkOrderItems.filter(i => i.selected);
    if (selectedItems.length === 0) { showToast('Zaznacz przynajmniej jeden artykul'); return; }

    const fallbackCategoryId = document.getElementById('bulkOrderFallbackCategory').value;
    const fallbackLang = document.getElementById('bulkOrderLang').value || 'pl';
    const wantInlineImages = document.getElementById('bulkOrderInlineImages').checked;
    const wantSpeedLinks = document.getElementById('bulkOrderSpeedLinks').checked;
    const globalNotes = (document.getElementById('bulkOrderGlobalNotes').value || '').trim();
    const publishStatus = (document.getElementById('bulkOrderPublishStatus') || {}).value || 'publish';

    const btn = document.getElementById('bulkOrderStartBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generowanie...';

    document.getElementById('bulkOrderProgressCard').style.display = '';
    const bar = document.getElementById('bulkOrderProgressBar');
    const log = document.getElementById('bulkOrderLog');
    log.innerHTML = '';
    bulkOrderPublishedUrls = [];

    // Mark unselected as skipped
    bulkOrderItems.forEach(item => {
        if (!item.selected && item.status === 'pending') item.status = 'skipped';
    });
    renderBulkOrderTable();

    const total = selectedItems.length;
    let processed = 0;

    for (let i = 0; i < bulkOrderItems.length; i++) {
        const item = bulkOrderItems[i];
        if (!item.selected) continue;
        processed++;
        const pctBase = Math.round(processed / total * 100);
        bar.style.width = pctBase + '%';
        bar.textContent = `Artykul ${processed}/${total}`;

        item.status = 'generating';
        renderBulkOrderTable();
        log.innerHTML += `<div class="mt-1"><strong>${i + 1}/${total}: ${esc(item.title)}</strong></div>`;

        try {
            // 1. Generate article
            log.innerHTML += `<div class="text-muted small">  Generowanie tresci...</div>`;
            // Combine per-item notes with global notes; use per-item or fallback lang
            const itemNotes = [item.notes, globalNotes].filter(n => n).join('\n');
            const itemLang = item.lang || fallbackLang;
            const article = await api('POST', 'api/generate-article.php', {
                title: item.title,
                main_keyword: item.main_keyword,
                secondary_keywords: item.secondary_keywords,
                notes: itemNotes,
                lang: itemLang,
            });
            if (article.error) throw new Error(article.error);

            let htmlContent = article.html_content;
            let articleMarkdown = article.markdown || '';

            // 1b. Proofreading pass
            log.innerHTML += `<div class="text-muted small">  Korekta ortograficzna...</div>`;
            try {
                const proof = await api('POST', 'api/proofread-article.php', {
                    markdown: articleMarkdown, lang: itemLang
                });
                if (!proof.error && proof.html_content) {
                    htmlContent = proof.html_content;
                }
            } catch (e) {}

            // 2. Generate featured image
            log.innerHTML += `<div class="text-muted small">  Grafika wyroznajaca...</div>`;
            let featuredImageData = '', featuredImageFilename = '';
            try {
                const img = await api('POST', 'api/gemini-generate.php', { title: item.title });
                if (!img.error) {
                    featuredImageData = img.image_data;
                    featuredImageFilename = slugifyText(item.title) + '.jpg';
                }
            } catch (e) {}

            // 3. Inline images
            let inlineImgs = [];
            if (wantInlineImages) {
                const h2s = extractH2Titles(htmlContent).slice(0, 3);
                for (const h2 of h2s) {
                    try {
                        const img = await api('POST', 'api/generate-inline-image.php', {
                            section_title: h2, article_title: item.title
                        });
                        if (!img.error) {
                            inlineImgs.push({ section_title: h2, image_data: img.image_data, image_filename: img.image_filename, alt_text: img.alt_text });
                        }
                    } catch (e) {}
                    await sleep(1000);
                }
            }

            // 4. Insert inline images
            if (inlineImgs.length > 0) {
                htmlContent = insertInlineImages(htmlContent, inlineImgs);
            }

            // 6. Sanitize and publish
            htmlContent = sanitizeArticleHtml(htmlContent);
            item.status = 'publishing';
            renderBulkOrderTable();
            log.innerHTML += `<div class="text-muted small">  Publikowanie...</div>`;

            // Upload featured image
            let mediaId = 0;
            if (featuredImageData) {
                try {
                    const upResult = await api('POST', 'api/upload-image.php', {
                        site_id: parseInt(siteId), image_data: featuredImageData, image_filename: featuredImageFilename, keep_filename: true,
                    });
                    if (upResult.media_id) mediaId = upResult.media_id;
                } catch (e) {}
            }

            // Upload inline images and replace base64 with WP URLs
            for (let idx = 0; idx < inlineImgs.length; idx++) {
                const img = inlineImgs[idx];
                try {
                    const upResult = await api('POST', 'api/upload-image.php', {
                        site_id: parseInt(siteId), image_data: img.image_data, image_filename: img.image_filename, keep_filename: true,
                    });
                    if (upResult.url) {
                        htmlContent = replaceInlineImageSrc(htmlContent, idx, upResult.url);
                    }
                } catch (e) {}
                await sleep(300);
            }

            // Use per-item category if matched, otherwise fallback to global dropdown
            const itemCatId = item.category_id || parseInt(fallbackCategoryId) || 0;
            // Use future date → schedule status in WP; otherwise use selected status
            const effectiveStatus = item.publish_date ? 'future' : publishStatus;
            // Resolve author: per-item > default dropdown > 0 (WP default)
            const authorMode = (document.getElementById('bulkOrderAuthorMode') || {}).value || 'default';
            let itemAuthorId = item.author_id || 0;
            if (!itemAuthorId && authorMode === 'default') {
                itemAuthorId = parseInt(document.getElementById('bulkOrderDefaultAuthor').value) || 0;
            }
            const postData = {
                site_id: parseInt(siteId), title: item.title, content: htmlContent,
                status: effectiveStatus, category_id: itemCatId,
                publish_date: item.publish_date || '',
            };
            if (itemAuthorId) postData.author_id = itemAuthorId;
            if (mediaId) postData.media_id = mediaId;

            const result = await api('POST', 'api/publish.php', postData);
            if (result.error) throw new Error(result.error);

            item.status = 'done';
            item.url = result.post_url;
            if (result.post_url) {
                bulkOrderPublishedUrls.push(result.post_url);
            }
            log.innerHTML += `<div class="text-success small">  <i class="bi bi-check-circle"></i> <a href="${esc(result.post_url)}" target="_blank">${esc(result.post_url)}</a></div>`;

            // Extract links
            if (result.post_url) {
                try { await extractAndSaveLinks(parseInt(siteId), result.post_url, item.title, htmlContent); } catch (e) {}
            }

        } catch (e) {
            item.status = 'error';
            item.errorMsg = e.message;
            log.innerHTML += `<div class="text-danger small">  <i class="bi bi-x-circle"></i> ${esc(e.message)}</div>`;
        }

        renderBulkOrderTable();

        // Delay between articles
        if (processed < total) await sleep(5000);
    }

    // Speed-Links
    if (wantSpeedLinks && bulkOrderPublishedUrls.length > 0) {
        log.innerHTML += '<div class="mt-2"><i class="bi bi-hourglass-split text-primary"></i> Wysylanie do Speed-Links...</div>';
        const slResult = await submitToSpeedLinks(bulkOrderPublishedUrls);
        if (slResult && slResult.error) {
            log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> Speed-Links: ${esc(slResult.error)}</div>`;
        } else if (slResult) {
            let msg = `Speed-Links: wyslano ${slResult.submitted || bulkOrderPublishedUrls.length} URLi`;
            if (slResult.report_url) msg += ` — <a href="${esc(slResult.report_url)}" target="_blank">raport</a>`;
            log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> ${msg}</div>`;
        }
    }

    bar.style.width = '100%';
    bar.textContent = 'Gotowe!';
    bar.classList.remove('progress-bar-animated');

    // Show result card
    document.getElementById('bulkOrderResultCard').style.display = '';
    const resultLog = document.getElementById('bulkOrderResultLog');
    const successCount = bulkOrderItems.filter(i => i.status === 'done').length;
    const errorCount = bulkOrderItems.filter(i => i.status === 'error').length;
    const skippedCount = bulkOrderItems.filter(i => i.status === 'skipped').length;
    resultLog.innerHTML = `<div class="mb-2">Opublikowano: <strong>${successCount}/${total}</strong>${errorCount ? ` | Bledy: <strong class="text-danger">${errorCount}</strong>` : ''}${skippedCount ? ` | Pominieto: ${skippedCount}` : ''}</div>`;
    resultLog.innerHTML += bulkOrderPublishedUrls.map(u => `<div><a href="${esc(u)}" target="_blank">${esc(u)}</a></div>`).join('');

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-play-fill"></i> Generuj i publikuj wszystko';
}

function bulkOrderCopyUrls() {
    const text = bulkOrderPublishedUrls.join('\n');
    navigator.clipboard.writeText(text).then(() => showToast('Skopiowano ' + bulkOrderPublishedUrls.length + ' linkow'));
}

// ── Helper: Extract H2 titles from HTML ─────────────────────
function extractH2Titles(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    return Array.from(doc.querySelectorAll('h2')).map(h => h.textContent.trim());
}

// ── Helper: Insert internal links into HTML content ─────────
function insertInternalLinks(html, posts) {
    if (!posts || posts.length === 0) return html;

    // Pick 2-3 random posts
    const shuffled = [...posts].sort(() => Math.random() - 0.5);
    const selected = shuffled.slice(0, Math.min(3, shuffled.length));

    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    const paragraphs = doc.querySelectorAll('p');

    if (paragraphs.length < 3) return html;

    // Insert links after random paragraphs (not the first or last)
    const availableIndices = [];
    for (let i = 1; i < paragraphs.length - 1; i++) availableIndices.push(i);

    for (let i = 0; i < selected.length && availableIndices.length > 0; i++) {
        const randIdx = Math.floor(Math.random() * availableIndices.length);
        const pIdx = availableIndices.splice(randIdx, 1)[0];
        const p = paragraphs[pIdx];

        // Append link sentence at the end of paragraph
        const link = doc.createElement('a');
        link.href = selected[i].link;
        link.textContent = selected[i].title;

        // Add a natural-sounding sentence with the link
        const prefixes = [
            'Więcej na ten temat przeczytasz w artykule ',
            'Sprawdź również: ',
            'Polecamy także lekturę: ',
            'Warto przeczytać także ',
            'Zapoznaj się również z wpisem ',
        ];
        const prefix = prefixes[i % prefixes.length];
        const span = doc.createElement('span');
        span.textContent = ' ' + prefix;
        span.appendChild(link);
        span.appendChild(doc.createTextNode('.'));
        p.appendChild(span);
    }

    return doc.querySelector('div').innerHTML;
}

// ── Helper: Insert inline images into HTML after H2 sections
function insertInlineImages(html, images) {
    if (!images || images.length === 0) return html;

    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    const h2s = doc.querySelectorAll('h2');

    let imgIdx = 0;
    for (const h2 of h2s) {
        if (imgIdx >= images.length) break;

        const img = images[imgIdx];

        // Find first <p> after this h2
        let nextEl = h2.nextElementSibling;
        if (nextEl && nextEl.tagName === 'P') {
            const imgEl = doc.createElement('img');
            imgEl.src = `data:image/jpeg;base64,${img.image_data}`;
            imgEl.alt = img.alt_text || img.section_title;
            imgEl.setAttribute('data-inline-idx', imgIdx);

            const figure = doc.createElement('figure');
            figure.appendChild(imgEl);

            // Insert after the first paragraph
            nextEl.after(figure);
        }
        imgIdx++;
    }

    return doc.querySelector('div').innerHTML;
}

// Replace base64 inline images with uploaded WP URLs in content
function replaceInlineImageSrc(html, idx, wpUrl) {
    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    const img = doc.querySelector(`img[data-inline-idx="${idx}"]`);
    if (img) {
        img.src = wpUrl;
        img.removeAttribute('data-inline-idx');
        img.removeAttribute('data-filename');
    }
    return doc.querySelector('div').innerHTML;
}

// ── Helper: Slugify text (Polish chars) ─────────────────────
function slugifyText(text) {
    const pl = {'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z',
                'Ą':'a','Ć':'c','Ę':'e','Ł':'l','Ń':'n','Ó':'o','Ś':'s','Ź':'z','Ż':'z'};
    let s = text.replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, c => pl[c] || c);
    s = s.toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-|-$/g, '');
    return s.substring(0, 60) || 'artykul';
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Sanitize HTML: strip styles, classes, IDs, spans, data-* ─
function sanitizeArticleHtml(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString('<div>' + html + '</div>', 'text/html');
    const root = doc.querySelector('div');

    root.querySelectorAll('*').forEach(el => {
        // Remove style, class, id attributes
        el.removeAttribute('style');
        el.removeAttribute('class');
        el.removeAttribute('id');
        // Remove data-* attributes (except data-inline-idx which is needed before upload)
        Array.from(el.attributes).forEach(attr => {
            if (attr.name.startsWith('data-') && attr.name !== 'data-inline-idx') {
                el.removeAttribute(attr.name);
            }
        });
    });

    // Unwrap <span> tags
    root.querySelectorAll('span').forEach(span => {
        while (span.firstChild) span.parentNode.insertBefore(span.firstChild, span);
        span.parentNode.removeChild(span);
    });

    // Unwrap <div> tags inside content
    root.querySelectorAll('div').forEach(div => {
        while (div.firstChild) div.parentNode.insertBefore(div.firstChild, div);
        div.parentNode.removeChild(div);
    });

    return root.innerHTML;
}

// ══════════════════════════════════════════════════════════════
// ══ Site Card Page ═══════════════════════════════════════════
// ══════════════════════════════════════════════════════════════

let siteCardChart = null;

async function loadSiteCard(force = false) {
    const siteUrl = document.getElementById('siteCardUrl')?.value;
    if (!siteUrl) return;

    const range = document.getElementById('scDateRange')?.value || '28d';
    const forceParam = force ? '&force=1' : '';

    try {
        const data = await api('GET', `api/gsc-data.php?action=site-detail&site_url=${encodeURIComponent(siteUrl)}&range=${range}${forceParam}`);

        if (data.error) {
            document.getElementById('siteCardNoGsc').style.display = '';
            return;
        }

        // Show GSC sections
        ['siteCardGscClicks', 'siteCardGscImpressions', 'siteCardGscCtr', 'siteCardGscPos',
         'siteCardGscControls', 'siteCardChart', 'siteCardTables'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = '';
        });

        // Summary cards
        const s = data.summary || {};
        const ps = data.prev_summary || {};
        document.getElementById('scClicks').textContent = formatNumber(s.clicks || 0);
        document.getElementById('scImpressions').textContent = formatNumber(s.impressions || 0);
        document.getElementById('scCtr').textContent = (s.ctr || 0).toFixed(1) + '%';
        document.getElementById('scPosition').textContent = (s.position || 0).toFixed(1);

        const clicksChg = document.getElementById('scClicksChange');
        const impChg = document.getElementById('scImpressionsChange');
        if (clicksChg) clicksChg.innerHTML = formatChange(calcChangeJs(s.clicks, ps.clicks));
        if (impChg) impChg.innerHTML = formatChange(calcChangeJs(s.impressions, ps.impressions));

        // Date info
        const dateInfo = document.getElementById('scDateInfo');
        if (dateInfo) dateInfo.textContent = `${data.date_from} — ${data.date_to}`;

        // Chart
        renderSiteCardChart(data.daily || []);

        // Keywords table
        const kwBody = document.getElementById('scKeywordsBody');
        if (kwBody) {
            const kws = data.keywords || [];
            if (kws.length === 0) {
                kwBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Brak danych</td></tr>';
            } else {
                kwBody.innerHTML = kws.map(k => `
                    <tr>
                        <td class="text-truncate" style="max-width:250px" title="${esc(k.keyword)}">${esc(k.keyword)}</td>
                        <td class="text-end">${k.clicks}</td>
                        <td class="text-end">${k.impressions}</td>
                        <td class="text-end">${k.ctr.toFixed(1)}%</td>
                        <td class="text-end">${k.position.toFixed(1)}</td>
                    </tr>
                `).join('');
            }
        }

        // Pages table
        const pgBody = document.getElementById('scPagesBody');
        if (pgBody) {
            const pgs = data.pages || [];
            if (pgs.length === 0) {
                pgBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Brak danych</td></tr>';
            } else {
                pgBody.innerHTML = pgs.map(p => {
                    const shortUrl = p.url.replace(/^https?:\/\/[^/]+/, '');
                    return `
                    <tr>
                        <td class="text-truncate" style="max-width:300px" title="${esc(p.url)}"><a href="${esc(p.url)}" target="_blank">${esc(shortUrl || '/')}</a></td>
                        <td class="text-end">${p.clicks}</td>
                        <td class="text-end">${p.impressions}</td>
                        <td class="text-end">${p.ctr.toFixed(1)}%</td>
                        <td class="text-end">${p.position.toFixed(1)}</td>
                    </tr>`;
                }).join('');
            }
        }
    } catch (e) {
        document.getElementById('siteCardNoGsc').style.display = '';
    }
}

function renderSiteCardChart(daily) {
    const canvas = document.getElementById('scChartCanvas');
    if (!canvas || !window.Chart) return;

    if (siteCardChart) siteCardChart.destroy();

    const labels = daily.map(d => d.date);
    siteCardChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Kliknięcia',
                    data: daily.map(d => d.clicks),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y',
                },
                {
                    label: 'Wyświetlenia',
                    data: daily.map(d => d.impressions),
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255,193,7,0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { position: 'left', title: { display: true, text: 'Kliknięcia' } },
                y1: { position: 'right', title: { display: true, text: 'Wyświetlenia' }, grid: { drawOnChartArea: false } },
                x: { ticks: { maxTicksLimit: 15 } },
            },
            plugins: { legend: { position: 'top' } },
        },
    });
}

async function refreshSiteCardGsc() {
    showToast('Odświeżam dane GSC...', 'info');
    await loadSiteCard(true);
    showToast('Dane GSC odświeżone', 'success');
}

function calcChangeJs(current, previous) {
    if (!previous || previous === 0) return current > 0 ? 100 : 0;
    return ((current - previous) / previous) * 100;
}

// ══════════════════════════════════════════════════════════════
// ══ GSC Report Page ═════════════════════════════════════════
// ══════════════════════════════════════════════════════════════

// ── GSC Report ──────────────────────────────────────────────
let gscReportData = [];
let gscReportSortField = 'clicks';
let gscReportSortAsc = false;

async function loadGscReport(force = false) {
    const range = document.getElementById('gscReportRange')?.value || '28d';
    const forceParam = force ? '&force=1' : '';
    const tbody = document.getElementById('gscReportBody');
    const noData = document.getElementById('gscReportNoData');
    const initial = document.getElementById('gscReportInitial');
    const tableCard = document.getElementById('gscReportTableCard');
    const summary = document.getElementById('gscReportSummary');
    const genBtn = document.getElementById('gscReportGenerateBtn');
    if (!tbody) return;

    // Hide initial message, show loading
    if (initial) initial.style.display = 'none';
    if (tableCard) tableCard.style.display = '';
    if (genBtn) { genBtn.disabled = true; genBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Generuję...'; }
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted"><i class="bi bi-arrow-clockwise spin"></i> Pobieranie danych GSC...</td></tr>';

    try {
        const data = await api('GET', `api/gsc-data.php?action=report&range=${range}${forceParam}`);

        if (data.error) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">${esc(data.error)}</td></tr>`;
            if (noData) noData.style.display = '';
            return;
        }

        const dateInfo = document.getElementById('gscReportDateInfo');
        if (dateInfo) dateInfo.textContent = `${data.date_from} — ${data.date_to}`;

        gscReportData = data.sites || [];
        if (gscReportData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Brak danych GSC</td></tr>';
            if (noData) noData.style.display = '';
            if (summary) summary.style.display = 'none';
            return;
        }
        if (noData) noData.style.display = 'none';

        // Show summary cards
        updateGscReportSummary();

        // Show export button
        const exportBtn = document.getElementById('gscExportBtn');
        if (exportBtn) exportBtn.style.display = '';

        // Render table
        renderGscReportTable();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger">Błąd: ${esc(e.message)}</td></tr>`;
        if (noData) noData.style.display = '';
    } finally {
        if (genBtn) { genBtn.disabled = false; genBtn.innerHTML = '<i class="bi bi-play-fill"></i> Generuj raport'; }
    }
}

function updateGscReportSummary() {
    const summary = document.getElementById('gscReportSummary');
    if (!summary || gscReportData.length === 0) return;
    summary.style.display = '';

    const sites = gscReportData;

    // Best by clicks
    const bestClicks = sites.reduce((a, b) => (b.clicks || 0) > (a.clicks || 0) ? b : a);
    document.getElementById('gscRepBestClicks').textContent = formatNumber(bestClicks.clicks || 0);
    document.getElementById('gscRepBestClicksName').textContent = bestClicks.name;

    // Best trend (highest clicks_change with at least some clicks)
    const withClicks = sites.filter(s => (s.clicks || 0) > 0);
    const bestTrend = withClicks.length > 0
        ? withClicks.reduce((a, b) => (b.clicks_change || 0) > (a.clicks_change || 0) ? b : a)
        : sites[0];
    document.getElementById('gscRepBestTrend').innerHTML = formatChange(bestTrend.clicks_change);
    document.getElementById('gscRepBestTrendName').textContent = bestTrend.name;

    // Best impressions
    const bestImp = sites.reduce((a, b) => (b.impressions || 0) > (a.impressions || 0) ? b : a);
    document.getElementById('gscRepBestImpressions').textContent = formatNumber(bestImp.impressions || 0);
    document.getElementById('gscRepBestImpressionsName').textContent = bestImp.name;

    // Best CTR (min 10 clicks)
    const withMinClicks = sites.filter(s => (s.clicks || 0) >= 10);
    const bestCtr = withMinClicks.length > 0
        ? withMinClicks.reduce((a, b) => (b.ctr || 0) > (a.ctr || 0) ? b : a)
        : (withClicks.length > 0 ? withClicks.reduce((a, b) => (b.ctr || 0) > (a.ctr || 0) ? b : a) : sites[0]);
    document.getElementById('gscRepBestCtr').textContent = (bestCtr.ctr || 0).toFixed(1) + '%';
    document.getElementById('gscRepBestCtrName').textContent = bestCtr.name;

    // Total clicks
    const totalClicks = sites.reduce((sum, s) => sum + (s.clicks || 0), 0);
    document.getElementById('gscRepTotalClicks').textContent = formatNumber(totalClicks);
    // Weighted average clicks change
    if (totalClicks > 0) {
        const avgChange = sites.reduce((sum, s) => sum + (s.clicks_change || 0) * (s.clicks || 0), 0) / totalClicks;
        document.getElementById('gscRepTotalClicksChange').innerHTML = formatChange(avgChange);
    }
}

function sortGscReport(field) {
    if (gscReportSortField === field) {
        gscReportSortAsc = !gscReportSortAsc;
    } else {
        gscReportSortField = field;
        gscReportSortAsc = field === 'name'; // name ascending, numbers descending
    }
    // Update sort indicators
    document.querySelectorAll('#gscReportTable th.sortable i').forEach(icon => {
        icon.className = 'bi bi-chevron-expand small';
    });
    const th = document.querySelector(`#gscReportTable th[data-sort="${field}"]`);
    if (th) {
        th.querySelector('i').className = gscReportSortAsc ? 'bi bi-chevron-up small' : 'bi bi-chevron-down small';
    }
    renderGscReportTable();
}

function renderGscReportTable() {
    const tbody = document.getElementById('gscReportBody');
    if (!tbody || gscReportData.length === 0) return;

    const sorted = [...gscReportData].sort((a, b) => {
        let va = a[gscReportSortField], vb = b[gscReportSortField];
        if (gscReportSortField === 'name') {
            va = (va || '').toLowerCase(); vb = (vb || '').toLowerCase();
            return gscReportSortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
        }
        va = parseFloat(va) || 0; vb = parseFloat(vb) || 0;
        return gscReportSortAsc ? va - vb : vb - va;
    });

    tbody.innerHTML = sorted.map((s, i) => {
        const sparkline = renderSparklineSvg(s.daily || [], 'clicks');
        return `
        <tr>
            <td>${i + 1}</td>
            <td><a href="index.php?page=site-card&id=${s.site_id}">${esc(s.name)}</a></td>
            <td class="text-end fw-bold">${formatNumber(s.clicks)}</td>
            <td class="text-end">${formatNumber(s.impressions)}</td>
            <td class="text-end">${(s.ctr || 0).toFixed(1)}%</td>
            <td class="text-end">${(s.position || 0).toFixed(1)}</td>
            <td class="text-end">${formatChange(s.clicks_change)}</td>
            <td class="text-end">${formatChange(s.impressions_change)}</td>
            <td>${sparkline}</td>
        </tr>`;
    }).join('');
}

async function refreshGscReport() {
    const btn = document.querySelector('[onclick="refreshGscReport()"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Odświeżam...'; }
    try {
        showToast('Odświeżam dane z GSC API...', 'info');
        await api('GET', 'api/gsc-data.php?action=refresh');
        await loadGscReport(true);
        showToast('Dane GSC odświeżone', 'success');
    } catch(e) {
        showToast('Błąd odświeżania: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Odśwież dane z GSC'; }
    }
}

function exportGscReportXlsx() {
    if (gscReportData.length === 0) { showToast('Brak danych do eksportu', 'warning'); return; }

    const dateInfo = document.getElementById('gscReportDateInfo')?.textContent || '';
    const sorted = [...gscReportData].sort((a, b) => (b.clicks || 0) - (a.clicks || 0));

    // Build CSV-like TSV for XLSX (simple approach using Blob)
    let tsv = 'Lp.\tStrona\tKliknięcia\tWyświetlenia\tCTR (%)\tŚr. pozycja\tKlik. zmiana (%)\tWyśw. zmiana (%)\n';
    sorted.forEach((s, i) => {
        tsv += `${i+1}\t${s.name}\t${s.clicks||0}\t${s.impressions||0}\t${(s.ctr||0).toFixed(1)}\t${(s.position||0).toFixed(1)}\t${(s.clicks_change||0).toFixed(1)}\t${(s.impressions_change||0).toFixed(1)}\n`;
    });

    // Create XLSX-compatible XML (SpreadsheetML)
    let xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
    xml += '<?mso-application progid="Excel.Sheet"?>\n';
    xml += '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n';
    xml += '<Styles><Style ss:ID="header"><Font ss:Bold="1"/><Interior ss:Color="#1a1f2e" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF" ss:Bold="1"/></Style>';
    xml += '<Style ss:ID="num"><NumberFormat ss:Format="0.0"/></Style>';
    xml += '<Style ss:ID="pct"><NumberFormat ss:Format="0.0&quot;%&quot;"/></Style></Styles>\n';
    xml += `<Worksheet ss:Name="Raport GSC ${dateInfo}">\n<Table>\n`;

    // Header row
    const headers = ['#', 'Strona', 'Kliknięcia', 'Wyświetlenia', 'CTR (%)', 'Śr. pozycja', 'Klik. zmiana (%)', 'Wyśw. zmiana (%)'];
    xml += '<Row>';
    headers.forEach(h => { xml += `<Cell ss:StyleID="header"><Data ss:Type="String">${h}</Data></Cell>`; });
    xml += '</Row>\n';

    // Data rows
    sorted.forEach((s, i) => {
        xml += '<Row>';
        xml += `<Cell><Data ss:Type="Number">${i+1}</Data></Cell>`;
        xml += `<Cell><Data ss:Type="String">${s.name}</Data></Cell>`;
        xml += `<Cell><Data ss:Type="Number">${s.clicks||0}</Data></Cell>`;
        xml += `<Cell><Data ss:Type="Number">${s.impressions||0}</Data></Cell>`;
        xml += `<Cell ss:StyleID="num"><Data ss:Type="Number">${(s.ctr||0).toFixed(1)}</Data></Cell>`;
        xml += `<Cell ss:StyleID="num"><Data ss:Type="Number">${(s.position||0).toFixed(1)}</Data></Cell>`;
        xml += `<Cell ss:StyleID="num"><Data ss:Type="Number">${(s.clicks_change||0).toFixed(1)}</Data></Cell>`;
        xml += `<Cell ss:StyleID="num"><Data ss:Type="Number">${(s.impressions_change||0).toFixed(1)}</Data></Cell>`;
        xml += '</Row>\n';
    });

    xml += '</Table>\n</Worksheet>\n</Workbook>';

    const blob = new Blob([xml], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `raport-gsc-${dateInfo.replace(/\s/g, '')}.xls`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Raport wyeksportowany', 'success');
}

// ══════════════════════════════════════════════════════════════
// ── Auto-Publish ─────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════
let apSitesData = [];
let apCurrentSiteId = null;
let apQueueData = [];
let apWpCategories = {};

function initAutoPublish() {
    if (!document.getElementById('apSitesTable')) return;
    loadAutoPublish();
}

async function loadAutoPublish() {
    if (!document.getElementById('apSitesBody')) return;
    try {
        const data = await api('GET', 'api/auto-publish.php?action=sites');
        if (data.error) throw new Error(data.error);
        apSitesData = data.sites || [];
        renderApSites();
        updateApSummary();
    } catch (e) {
        showToast('Błąd ładowania: ' + e.message, 'error');
    }
}

function updateApSummary() {
    const el = document.getElementById('apSummary');
    if (!el) return;

    let totalPublished = 0, totalPending = 0, totalErrors = 0, totalQueued = 0, activeSites = 0;
    apSitesData.forEach(s => {
        const q = s.queue || {};
        totalPublished += (q.published || 0);
        totalPending += (q.pending || 0);
        totalErrors += (q.error || 0);
        totalQueued += (s.queue_total || 0);
        if (s.enabled) activeSites++;
    });

    document.getElementById('apTotalPublished').textContent = totalPublished;
    document.getElementById('apTotalPending').textContent = totalPending;
    document.getElementById('apTotalErrors').textContent = totalErrors;
    document.getElementById('apTotalQueued').textContent = totalQueued;
    document.getElementById('apTotalSites').textContent = activeSites;
    el.style.display = totalQueued > 0 ? '' : 'none';
}

function renderApSites() {
    const tbody = document.getElementById('apSitesBody');
    if (!tbody) return;

    if (!apSitesData.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">Brak stron zapleczowych</td></tr>';
        return;
    }

    tbody.innerHTML = apSitesData.map(s => {
        const q = s.queue || {};
        const total = s.queue_total || 0;
        const published = q.published || 0;
        const pending = q.pending || 0;
        const errors = q.error || 0;
        const generating = (q.generating || 0) + (q.generated || 0) + (q.publishing || 0);
        const pctPublished = total > 0 ? Math.round(published / total * 100) : 0;
        const pctPending = total > 0 ? Math.round(pending / total * 100) : 0;
        const pctError = total > 0 ? Math.round(errors / total * 100) : 0;
        const pctGenerating = total > 0 ? Math.round(generating / total * 100) : 0;

        const dailyLimit = s.daily_limit ?? 1;
        const useSpeedLinks = s.use_speed_links ?? 0;
        const useInlineImages = s.use_inline_images ?? 0;
        const randomAuthor = s.random_author ?? 0;
        const enabled = s.enabled ?? 1;

        return `<tr data-site-id="${s.id}">
            <td>
                <div class="fw-semibold">${esc(s.name)}</div>
                <small class="text-muted">${esc(s.url)}</small>
            </td>
            <td class="text-center">
                <input type="number" class="form-control form-control-sm text-center ap-daily-limit"
                    value="${dailyLimit}" min="1" max="50" style="width:60px;margin:0 auto"
                    data-site-id="${s.id}" onchange="saveApConfig(${s.id})">
            </td>
            <td class="text-center">
                <input type="checkbox" class="form-check-input ap-speed-links" ${useSpeedLinks ? 'checked' : ''}
                    data-site-id="${s.id}" onchange="saveApConfig(${s.id})">
            </td>
            <td class="text-center">
                <input type="checkbox" class="form-check-input ap-inline-images" ${useInlineImages ? 'checked' : ''}
                    data-site-id="${s.id}" onchange="saveApConfig(${s.id})">
            </td>
            <td class="text-center">
                <input type="checkbox" class="form-check-input ap-random-author" ${randomAuthor ? 'checked' : ''}
                    data-site-id="${s.id}" onchange="saveApConfig(${s.id})">
            </td>
            <td class="text-center">
                <div class="form-check form-switch d-flex justify-content-center mb-0">
                    <input type="checkbox" class="form-check-input ap-enabled" ${enabled ? 'checked' : ''}
                        data-site-id="${s.id}" onchange="saveApConfig(${s.id})">
                </div>
            </td>
            <td class="text-center">${apSiteStatusBadge(s)}</td>
            <td>
                ${total > 0 ? `
                    <div class="progress" style="height:18px;cursor:pointer" onclick="showApQueue(${s.id}, '${esc(s.name)}')" title="Kliknij aby zobaczyć kolejkę">
                        ${pctPublished > 0 ? `<div class="progress-bar bg-success" style="width:${pctPublished}%">${published}</div>` : ''}
                        ${pctGenerating > 0 ? `<div class="progress-bar bg-info" style="width:${pctGenerating}%">${generating}</div>` : ''}
                        ${pctPending > 0 ? `<div class="progress-bar bg-warning" style="width:${pctPending}%">${pending}</div>` : ''}
                        ${pctError > 0 ? `<div class="progress-bar bg-danger" style="width:${pctError}%">${errors}</div>` : ''}
                    </div>
                    <small class="text-muted">${published}/${total} opublikowanych</small>
                ` : '<span class="text-muted small">Brak</span>'}
            </td>
            <td>
                <input type="file" class="form-control form-control-sm" accept=".xlsx"
                    onchange="uploadApContentPlan(${s.id}, this)" id="apFile${s.id}">
            </td>
            <td class="text-center">
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="showApQueue(${s.id}, '${esc(s.name)}')" title="Kolejka">
                        <i class="bi bi-list-check"></i>
                    </button>
                    <button class="btn btn-outline-secondary" onclick="showApCategoryMap(${s.id}, '${esc(s.name)}')" title="Mapowanie kategorii">
                        <i class="bi bi-diagram-3"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    // Sync header checkboxes with column state
    ['ap-speed-links', 'ap-inline-images', 'ap-random-author', 'ap-enabled'].forEach(cls => {
        const all = document.querySelectorAll(`.${cls}`);
        const thCb = document.querySelector(`th input[onchange*="${cls}"]`);
        if (thCb && all.length) thCb.checked = [...all].every(cb => cb.checked);
    });
}

function apSiteStatusBadge(s) {
    const enabled = s.enabled ?? 0;
    const pending = s.queue?.pending || 0;
    const unmapped = s.unmapped_categories || 0;
    const total = s.queue_total || 0;

    if (!enabled) {
        return '<span class="badge bg-secondary" title="Strona wyłączona z auto-publikacji"><i class="bi bi-pause-circle"></i> Wyłączona</span>';
    }
    if (total === 0) {
        return '<span class="badge bg-warning text-dark" title="Brak wgranych artykułów — załaduj content plan"><i class="bi bi-exclamation-triangle"></i> Brak planu</span>';
    }
    if (pending === 0) {
        return '<span class="badge bg-info" title="Wszystkie artykuły przetworzone, załaduj nowy content plan"><i class="bi bi-check-circle"></i> Kolejka pusta</span>';
    }
    if (unmapped > 0) {
        return `<span class="badge bg-warning text-dark" title="${unmapped} niezmapowanych kategorii — skonfiguruj mapowanie"><i class="bi bi-diagram-3"></i> Mapuj (${unmapped})</span>`;
    }
    return `<span class="badge bg-success" title="Gotowa — CRON opublikuje ${pending} artykułów"><i class="bi bi-rocket-takeoff"></i> Gotowa (${pending})</span>`;
}

async function toggleAllApCheckbox(className, checked) {
    const boxes = document.querySelectorAll(`.${className}`);
    const promises = [];
    boxes.forEach(cb => {
        if (cb.checked !== checked) {
            cb.checked = checked;
            const siteId = parseInt(cb.dataset.siteId);
            if (siteId) promises.push(saveApConfig(siteId));
        }
    });
    await Promise.all(promises);
}

async function saveApConfig(siteId) {
    const row = document.querySelector(`tr[data-site-id="${siteId}"]`);
    if (!row) return;

    const dailyLimit = parseInt(row.querySelector('.ap-daily-limit').value) || 1;
    const useSpeedLinks = row.querySelector('.ap-speed-links').checked ? 1 : 0;
    const useInlineImages = row.querySelector('.ap-inline-images').checked ? 1 : 0;
    const randomAuthor = row.querySelector('.ap-random-author').checked ? 1 : 0;
    const enabled = row.querySelector('.ap-enabled').checked ? 1 : 0;

    try {
        const data = await api('POST', 'api/auto-publish.php?action=save-config', {
            site_id: siteId,
            daily_limit: dailyLimit,
            use_speed_links: useSpeedLinks,
            use_inline_images: useInlineImages,
            random_author: randomAuthor,
            enabled: enabled,
        });
        if (data.error) throw new Error(data.error);
        showToast('Konfiguracja zapisana', 'success');
    } catch (e) {
        showToast('Błąd zapisu: ' + e.message, 'error');
    }
}

async function uploadApContentPlan(siteId, input) {
    const file = input.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('site_id', siteId);
    formData.append('action', 'upload-plan');

    try {
        const resp = await fetch('api/auto-publish.php?action=upload-plan', {
            method: 'POST',
            body: formData,
        });
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        showToast(data.message || `Załadowano ${data.inserted} artykułów`, 'success');

        // Reset file input
        input.value = '';

        // If there are categories, suggest mapping
        if (data.categories && data.categories.length > 0) {
            const siteName = apSitesData.find(s => s.id === siteId)?.name || '';
            showToast(`Znaleziono ${data.categories.length} kategorii — skonfiguruj mapowanie`, 'info');
        }

        // Reload
        loadAutoPublish();
    } catch (e) {
        showToast('Błąd uploadu: ' + e.message, 'error');
        input.value = '';
    }
}

// ── Queue Modal ─────────────────────────────────────────────
async function showApQueue(siteId, siteName) {
    apCurrentSiteId = siteId;
    document.getElementById('apQueueSiteName').textContent = siteName;
    document.getElementById('apQueueFilter').value = 'all';

    const modal = new bootstrap.Modal(document.getElementById('apQueueModal'));
    modal.show();

    await loadApQueue(siteId);
}

async function loadApQueue(siteId) {
    const tbody = document.getElementById('apQueueBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></td></tr>';

    try {
        const data = await api('GET', `api/auto-publish.php?action=queue&site_id=${siteId}`);
        if (data.error) throw new Error(data.error);
        apQueueData = data.items || [];
        renderApQueue();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${e.message}</td></tr>`;
    }
}

function renderApQueue() {
    const tbody = document.getElementById('apQueueBody');
    const filter = document.getElementById('apQueueFilter').value;

    const items = filter === 'all' ? apQueueData : apQueueData.filter(i => i.status === filter);

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Brak artykułów</td></tr>';
        return;
    }

    tbody.innerHTML = items.map((item, idx) => {
        const statusBadge = {
            pending: '<span class="badge bg-warning">Oczekuje</span>',
            generating: '<span class="badge bg-info">Generowanie</span>',
            generated: '<span class="badge bg-primary">Wygenerowany</span>',
            publishing: '<span class="badge bg-info">Publikacja</span>',
            published: '<span class="badge bg-success">Opublikowany</span>',
            error: '<span class="badge bg-danger">Błąd</span>',
        }[item.status] || `<span class="badge bg-secondary">${item.status}</span>`;

        let urlOrError = '';
        if (item.status === 'published' && item.published_url) {
            urlOrError = `<a href="${esc(item.published_url)}" target="_blank" class="small">${esc(item.published_url)}</a>`;
        } else if (item.status === 'error' && item.error_message) {
            urlOrError = `<span class="text-danger small">${esc(item.error_message)}</span>`;
        }

        return `<tr>
            <td class="text-muted">${item.id}</td>
            <td>${esc(item.title)}</td>
            <td class="small">${esc(item.main_keyword)}</td>
            <td class="small">${esc(item.category_name)}</td>
            <td class="text-center">${statusBadge}</td>
            <td>${urlOrError}</td>
        </tr>`;
    }).join('');
}

function filterApQueue() {
    renderApQueue();
}

async function clearApQueue(status) {
    if (!apCurrentSiteId) return;
    const label = status === 'all' ? 'wszystkie (bez opublikowanych)' : status === 'pending' ? 'oczekujące' : 'z błędami';
    if (!confirm(`Usunąć ${label} artykuły z kolejki?`)) return;

    try {
        const data = await api('POST', 'api/auto-publish.php?action=clear-queue', {
            site_id: apCurrentSiteId,
            status: status,
        });
        if (data.error) throw new Error(data.error);
        showToast(`Usunięto ${data.deleted} artykułów`, 'success');
        loadApQueue(apCurrentSiteId);
        loadAutoPublish();
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

// ── Category Mapping Modal ──────────────────────────────────
async function showApCategoryMap(siteId, siteName) {
    apCurrentSiteId = siteId;
    document.getElementById('apCatSiteName').textContent = siteName;
    document.getElementById('apCatLoading').style.display = '';
    document.getElementById('apCatContent').style.display = 'none';

    const modal = new bootstrap.Modal(document.getElementById('apCategoryModal'));
    modal.show();

    try {
        // Load category map + WP categories in parallel
        const [mapData, wpCats] = await Promise.all([
            api('GET', `api/auto-publish.php?action=category-map&site_id=${siteId}`),
            fetch(`api/wp-data.php?site_id=${siteId}&type=categories`).then(r => r.json()),
        ]);

        if (mapData.error) throw new Error(mapData.error);

        const mappings = mapData.mappings || [];
        const unmapped = mapData.unmapped || [];
        const wpCategories = Array.isArray(wpCats) ? wpCats : [];
        apWpCategories[siteId] = wpCategories;

        // Combine mapped + unmapped categories
        const allCats = [];
        mappings.forEach(m => allCats.push({ name: m.category_name, wpCatId: m.wp_category_id, wpCatName: m.wp_category_name }));
        unmapped.forEach(name => allCats.push({ name, wpCatId: 0, wpCatName: '' }));

        const tbody = document.getElementById('apCatBody');
        if (!allCats.length) {
            tbody.innerHTML = '<tr><td colspan="2" class="text-center text-muted">Brak kategorii do zmapowania. Załaduj content plan.</td></tr>';
        } else {
            tbody.innerHTML = allCats.map(cat => {
                const options = wpCategories.map(wc =>
                    `<option value="${wc.id}" ${wc.id === cat.wpCatId ? 'selected' : ''}>${esc(wc.name)}</option>`
                ).join('');
                return `<tr>
                    <td><strong>${esc(cat.name)}</strong></td>
                    <td>
                        <select class="form-select form-select-sm ap-cat-select" data-cat-name="${esc(cat.name)}">
                            <option value="0">— Wybierz —</option>
                            ${options}
                        </select>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('apCatLoading').style.display = 'none';
        document.getElementById('apCatContent').style.display = '';
    } catch (e) {
        document.getElementById('apCatLoading').innerHTML = `<span class="text-danger">${e.message}</span>`;
    }
}

async function saveApCategoryMap() {
    if (!apCurrentSiteId) return;

    const selects = document.querySelectorAll('.ap-cat-select');
    const mappings = [];
    const wpCats = apWpCategories[apCurrentSiteId] || [];

    selects.forEach(sel => {
        const catName = sel.dataset.catName;
        const wpCatId = parseInt(sel.value) || 0;
        if (wpCatId > 0) {
            const wpCat = wpCats.find(c => c.id === wpCatId);
            mappings.push({
                category_name: catName,
                wp_category_id: wpCatId,
                wp_category_name: wpCat ? wpCat.name : '',
            });
        }
    });

    if (!mappings.length) {
        showToast('Nie wybrano żadnych kategorii', 'warning');
        return;
    }

    try {
        const data = await api('POST', 'api/auto-publish.php?action=save-category-map', {
            site_id: apCurrentSiteId,
            mappings: mappings,
        });
        if (data.error) throw new Error(data.error);
        showToast(`Zapisano ${mappings.length} mapowań kategorii`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('apCategoryModal'))?.hide();
        loadAutoPublish();
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

// ── Run Manual ──────────────────────────────────────────────
async function runAutoPublishManual() {
    if (!confirm('Uruchomić auto-publikację teraz? Może to potrwać kilka minut.')) return;

    const btn = document.getElementById('apRunManualBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Publikowanie...';

    try {
        const data = await api('POST', 'api/auto-publish.php?action=run-manual');
        if (data.error) throw new Error(data.error);
        showToast(data.message || 'Zakończono', data.errors > 0 ? 'warning' : 'success');
        loadAutoPublish();
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

// ── Telegram Settings ───────────────────────────────────────
async function loadTelegramSettings() {
    try {
        const data = await api('GET', 'api/telegram.php?action=get');
        if (data.bot_token) document.getElementById('telegramBotToken').value = data.bot_token;
        if (data.chat_id) document.getElementById('telegramChatId').value = data.chat_id;
    } catch (e) { /* ignore */ }
}

async function saveTelegramSettings() {
    const botToken = document.getElementById('telegramBotToken').value.trim();
    const chatId = document.getElementById('telegramChatId').value.trim();

    try {
        const data = await api('POST', 'api/telegram.php?action=save', { bot_token: botToken, chat_id: chatId });
        if (data.error) throw new Error(data.error);
        showToast('Ustawienia Telegram zapisane', 'success');
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

async function testTelegram() {
    try {
        const data = await api('POST', 'api/telegram.php?action=test');
        if (data.error) throw new Error(data.error);
        showToast(data.message || 'Wiadomość testowa wysłana', 'success');
    } catch (e) {
        showToast('Błąd: ' + e.message, 'error');
    }
}

// ── Init auto-publish on page load ──────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.search.includes('page=auto-publish')) {
        initAutoPublish();
    }
    if (window.location.search.includes('page=settings') && document.getElementById('telegramBotToken')) {
        loadTelegramSettings();
    }
});

function renderSparklineSvg(daily, metric) {
    if (!daily || daily.length < 2) return '<span class="text-muted">—</span>';

    const values = daily.map(d => d[metric] || 0);
    const max = Math.max(...values, 1);
    const width = 120;
    const height = 30;

    const points = values.map((v, i) => {
        const x = (i / (values.length - 1)) * width;
        const y = height - (v / max) * (height - 4) - 2;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');

    const trend = values[values.length - 1] >= values[0] ? '#198754' : '#dc3545';

    return `<svg width="${width}" height="${height}" style="display:block">
        <polyline points="${points}" fill="none" stroke="${trend}" stroke-width="1.5" />
    </svg>`;
}
