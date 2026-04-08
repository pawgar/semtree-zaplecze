// ── Helper ────────────────────────────────────────────────────
function api(method, url, body = null) {
    const opts = {method, headers: {'Content-Type': 'application/json'}};
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(r => r.json());
}

// ── Sites (Dashboard) ────────────────────────────────────────
let sitesData = [];

function loadSites() {
    api('GET', 'api/sites.php').then(sites => {
        sitesData = sites;
        buildCategoryFilter();
        buildClientFilter();
        filterSites();
    });
}

function renderSites(sites) {
    const tbody = document.getElementById('sitesBody');
    if (!tbody) return;

    if (sites.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">Brak stron. Dodaj pierwsza strone.</td></tr>';
        return;
    }

    tbody.innerHTML = sites.map((s, i) => {
        const cats = (s.categories || '').split(',').map(c => c.trim()).filter(c => c);
        const badges = cats.map(c => `<span class="badge bg-secondary category-badge">${esc(c)}</span>`).join(' ');
        const pwdId = 'pwd-' + s.id;
        return `
        <tr data-id="${s.id}">
            <td>${i + 1}</td>
            <td>${esc(s.name)}</td>
            <td><a href="${esc(s.url)}" target="_blank">${esc(s.url)}</a></td>
            <td>${esc(s.username)}</td>
            <td class="text-nowrap">
                <span id="${pwdId}" class="small" data-pw="${esc(s.app_password)}" data-visible="0">${'•'.repeat(8)}</span>
                <button class="btn btn-sm btn-link p-0 ms-1" onclick="toggleTablePwd('${pwdId}')" title="Pokaz/ukryj"><i class="bi bi-eye small"></i></button>
            </td>
            <td>${badges}</td>
            <td class="status-loading" id="posts-${s.id}">-</td>
            <td><a href="#" onclick="goToLinks(${s.id}); return false;" title="Pokaz linki">${s.link_count || 0}</a></td>
            <td class="status-loading" id="status-${s.id}">-</td>
            <td class="status-loading" id="api-${s.id}">-</td>
            <td class="text-nowrap">
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
        alert('Wypelnij wszystkie pola');
        return;
    }

    if (editId) {
        data.id = parseInt(editId);
        api('PUT', 'api/sites.php', data).then(r => {
            if (r.error) return alert(r.error);
            bootstrap.Modal.getInstance(document.getElementById('addSiteModal')).hide();
            loadSites();
        });
    } else {
        api('POST', 'api/sites.php', data).then(r => {
            if (r.error) return alert(r.error);
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
        if (r.error) return alert(r.error);
        loadSites();
    });
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
        sel.innerHTML = '<option value="">Wszyscy klienci</option>' +
            clients.map(c => `<option value="${c.id}">Bez linka do: ${esc(c.name)}</option>`).join('');
        sel.value = current;
        initSearchableSelects();
    });
}

function filterSites() {
    const catSel = document.getElementById('categoryFilter');
    const clientSel = document.getElementById('clientFilter');
    const cat = catSel ? catSel.value : '';
    const clientId = clientSel ? clientSel.value : '';
    let filtered = sitesData;
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
    if (document.getElementById('sitesBody')) loadSites();
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
        if (postsCell) {
            postsCell.textContent = r.post_count !== null ? r.post_count : '?';
            postsCell.className = r.post_count !== null ? 'status-ok' : 'status-error';
        }
        if (statusCell) {
            statusCell.textContent = r.http_status || 'ERR';
            statusCell.className = (r.http_status >= 200 && r.http_status < 400) ? 'status-ok' : 'status-error';
        }
        if (apiCell) {
            apiCell.textContent = r.api_ok ? 'OK' : 'FAILED';
            apiCell.className = r.api_ok ? 'status-ok' : 'status-error';
        }
    }).catch(() => {
        if (postsCell) { postsCell.textContent = 'ERR'; postsCell.className = 'status-error'; }
        if (statusCell) { statusCell.textContent = 'ERR'; statusCell.className = 'status-error'; }
        if (apiCell) { apiCell.textContent = 'ERR'; apiCell.className = 'status-error'; }
    }).finally(() => {
        if (btn) { btn.disabled = false; btn.querySelector('i').classList.remove('spin'); }
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
}

function applyStatusResult(r) {
    const postsCell = document.getElementById('posts-' + r.id);
    const statusCell = document.getElementById('status-' + r.id);
    const apiCell = document.getElementById('api-' + r.id);
    const btn = document.getElementById('refresh-btn-' + r.id);

    if (postsCell) {
        postsCell.textContent = r.post_count !== null ? r.post_count : '?';
        postsCell.className = r.post_count !== null ? 'status-ok' : 'status-error';
    }
    if (statusCell) {
        statusCell.textContent = r.http_status || 'ERR';
        statusCell.className = (r.http_status >= 200 && r.http_status < 400) ? 'status-ok' : 'status-error';
    }
    if (apiCell) {
        apiCell.textContent = r.api_ok ? 'OK' : 'FAILED';
        apiCell.className = r.api_ok ? 'status-ok' : 'status-error';
    }
    if (btn) { btn.disabled = false; btn.querySelector('i').classList.remove('spin'); }
}

// ── Change Password (all sites) ──────────────────────────────
function changeAllPasswords() {
    const password = document.getElementById('newGlobalPassword').value;
    if (!password) return alert('Wpisz nowe haslo');
    if (password.length < 6) return alert('Haslo musi miec co najmniej 6 znakow');
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
        if (lines.length < 2) return alert('Plik CSV jest pusty');

        const header = lines[0].split(';');
        const required = ['name', 'url', 'username', 'app_password'];
        const optional = ['categories'];
        const indices = {};
        for (const col of required) {
            const idx = header.indexOf(col);
            if (idx === -1) return alert(`Brak kolumny: ${col}\nWymagane: ${required.join(';')}`);
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
            alert(`Zaimportowano: ${imported}\nPominieto: ${skipped}`);
            loadSites();
        });
    };
    reader.readAsText(file, 'UTF-8');
}

function exportCsv() {
    if (sitesData.length === 0) return alert('Brak stron do eksportu');

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

    if (!username || !password) return alert('Wypelnij login i haslo');

    api('POST', 'api/users.php', {username, password}).then(r => {
        if (r.error) return alert(r.error);
        bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
        document.getElementById('newUserLogin').value = '';
        document.getElementById('newUserPassword').value = '';
        loadUsers();
    });
}

function deleteUser(id, name) {
    if (!confirm(`Usunac uzytkownika "${name}"?`)) return;
    api('DELETE', 'api/users.php', {id}).then(r => {
        if (r.error) return alert(r.error);
        loadUsers();
    });
}

function changeRole(id, role) {
    api('PATCH', 'api/users.php', {id, action: 'change_role', role}).then(r => {
        if (r.error) { alert(r.error); loadUsers(); return; }
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
    if (!password) return alert('Wpisz nowe haslo');
    api('PATCH', 'api/users.php', {id, action: 'reset_password', password}).then(r => {
        if (r.error) return alert(r.error);
        bootstrap.Modal.getInstance(document.getElementById('resetPasswordModal')).hide();
        alert('Haslo zostalo zmienione');
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

        // Set title
        document.getElementById('profileTitle').textContent = `Profil: ${data.user.username}`;

        // Monthly stats
        const monthlyBody = document.getElementById('monthlyStatsBody');
        if (data.monthly.length === 0) {
            monthlyBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Brak danych</td></tr>';
        } else {
            monthlyBody.innerHTML = data.monthly.map(m => `
                <tr>
                    <td>${esc(m.month)}</td>
                    <td>${m.total_articles}</td>
                    <td>${m.articles_with_links}</td>
                </tr>
            `).join('');
        }

        // Publications list
        allProfilePublications = data.publications || [];
        renderProfilePublications(allProfilePublications);
    });
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
    if (!siteId) return alert('Wybierz strone');

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
    if (!title) return alert('Wpisz najpierw tytul');
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

    if (!title) return alert('Wpisz tytul');

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
                    alert(`Blad parsowania ${file.name}: ${r.error}`);
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
            .catch(e => alert(`Blad uploadu ${file.name}: ${e.message}`))
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
    if (!fromStr || !toStr) return alert('Ustaw zakres dat (od - do)');

    const from = new Date(fromStr).getTime();
    const to = new Date(toStr).getTime();
    if (from >= to) return alert('Data "od" musi byc wczesniejsza niz "do"');
    if (!articles.length) return alert('Brak artykulow');

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
    if (!articles.length) return alert('Brak artykulow do zapisania');
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
            alert(`Wczytano ${data.length} artykulow (${imgCount} z obrazkami, ${mediaCount} juz uploadowanych).`);
        } catch (err) {
            alert('Blad wczytywania: ' + err.message);
        }
    };
    reader.readAsText(file);
    input.value = '';
}

// ── Publish all (batched image upload + sequential post creation) ──
async function publishAllArticles() {
    const siteId = document.getElementById('publishSiteSelect').value;
    if (!siteId) return alert('Wybierz strone');
    if (!articles.length) return alert('Dodaj artykuly');
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
    if (!publishedUrls.length) return alert('Brak linkow do skopiowania');
    navigator.clipboard.writeText(publishedUrls.join('\n')).then(() => {
        alert('Skopiowano ' + publishedUrls.length + ' linkow');
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
    if (!indices.length) return alert('Wszystkie artykuly maja juz obrazki');
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
        bootstrap.Modal.getInstance(document.getElementById('geminiKeyModal')).hide();
        alert('Klucz API zapisany');
    } catch (e) {
        alert('Blad zapisu: ' + e.message);
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
        bootstrap.Modal.getInstance(document.getElementById('speedLinksKeyModal')).hide();
        alert('Klucz API zapisany');
    } catch (e) {
        alert('Blad zapisu: ' + e.message);
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
        alert('Wypelnij nazwe i domene');
        return;
    }

    const method = editId ? 'PUT' : 'POST';
    if (editId) data.id = parseInt(editId);

    api(method, 'api/clients.php', data).then(r => {
        if (r.error) return alert(r.error);
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
        if (r.error) return alert(r.error);
        loadClients();
    });
}

function exportClientsCsv() {
    if (!linksClients || linksClients.length === 0) return alert('Brak klientow do eksportu');
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
        if (lines.length < 2) return alert('Plik CSV jest pusty');

        const header = lines[0].split(';').map(h => h.trim().toLowerCase());
        const required = ['name', 'domain'];
        const optional = ['color'];
        const indices = {};

        for (const col of required) {
            const idx = header.indexOf(col);
            if (idx === -1) return alert(`Brak kolumny: ${col}\nWymagane: ${required.join(';')}`);
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
            alert(`Zaimportowano: ${imported}\nPominieto: ${skipped}`);
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
            alert('Blad skanowania: ' + r.error);
        } else {
            if (btn) btn.innerHTML = `<i class="bi bi-check text-success"></i> +${r.links_inserted}`;
        }
    }).catch(e => {
        alert('Blad: ' + e.message);
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
        if (r.error) return alert(r.error);
        loadLinksHistory();
    });
}

function clearAllLinks() {
    if (!confirm('UWAGA: Usunac WSZYSTKIE linki z bazy? Tej operacji nie mozna cofnac!')) return;
    if (!confirm('Na pewno? To usunie cala historie linkow.')) return;
    api('DELETE', 'api/links.php', { all: true }).then(r => {
        if (r.error) return alert(r.error);
        alert(`Usunieto ${r.deleted} linkow.`);
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
        if (!links.length) return alert('Brak linkow do eksportu');

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

    if (!clientId) return alert('Wybierz klienta');

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
    if (!container || !container.textContent.trim()) return alert('Brak raportu');

    // Build plain text version
    const client = linksClients.find(c => c.id === parseInt(document.getElementById('reportClientSelect').value));
    let text = `RAPORT LINKOW: ${client?.name || '?'} (${client?.domain || '?'})\n`;
    text += `Linkow: ${reportLinks.length}\n\n`;

    reportLinks.forEach(l => {
        text += `${l.created_at} | ${l.site_name} | ${l.post_url} | ${l.anchor_text} | ${l.target_url} | ${l.link_type}\n`;
    });

    navigator.clipboard.writeText(text).then(() => alert('Raport skopiowany do schowka'));
}

function exportReportCsv() {
    if (!reportLinks.length) return alert('Brak danych raportu');
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
    if (checked.length === 0) return alert('Zaznacz linki do usuniecia');
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
    input.classList.add('ss-input');

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

    input.addEventListener('focus', () => open());
    input.addEventListener('input', () => buildOptions(input.value));
    input.addEventListener('blur', () => setTimeout(close, 150));
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
        bootstrap.Modal.getInstance(document.getElementById('anthropicKeyModal')).hide();
        alert('Klucz API zapisany');
    } catch (e) {
        alert('Blad zapisu: ' + e.message);
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
    if (!title) { alert('Wpisz tytul artykulu'); return; }
    if (!mainKw) { alert('Wpisz glowne slowo kluczowe'); return; }
    const siteId = document.getElementById('orderSiteId').value;
    if (!siteId) { alert('Wybierz strone zapleczowa'); return; }

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

    if (!title || !content) { alert('Brak tytulu lub tresci'); return; }

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

async function bulkOrderSelectSite(id, name) {
    document.getElementById('bulkOrderSiteId').value = id;
    document.getElementById('bulkOrderSiteSearch').value = name;
    document.getElementById('bulkOrderSiteDropdown').classList.remove('show');
    document.getElementById('bulkOrderUploadCard').style.display = '';

    // Load categories for bulk
    try {
        const r = await api('GET', `api/wp-data.php?site_id=${id}&type=categories`);
        bulkOrderCategories = r;
        const sel = document.getElementById('bulkOrderFallbackCategory');
        sel.innerHTML = '<option value="">-- brak --</option>' + r.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    } catch (e) {}
}

function matchBulkCategory(csvCategoryName) {
    if (!csvCategoryName) return { id: 0, name: '' };
    const lower = csvCategoryName.toLowerCase().trim();
    for (const cat of bulkOrderCategories) {
        if (cat.name.toLowerCase().trim() === lower) return cat;
    }
    return { id: 0, name: csvCategoryName + ' (?)' };
}

function bulkOrderParseCsv(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const text = e.target.result.replace(/^\uFEFF/, ''); // strip BOM
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        bulkOrderItems = [];

        for (const line of lines) {
            const parts = line.split(';').map(p => p.trim());
            const title = parts[0] || '';
            if (!title) continue;
            // Skip header row
            if (['tytuł', 'tytul', 'title'].includes(title.toLowerCase())) continue;

            const csvCategory = parts[3] || '';
            const matched = matchBulkCategory(csvCategory);
            bulkOrderItems.push({
                title,
                main_keyword: parts[1] || '',
                secondary_keywords: parts[2] || '',
                category_name: csvCategory,
                category_id: matched.id,
                category_matched: matched.id > 0,
                notes: parts[4] || '',
                lang: parts[5] || '',
                selected: true,
                publish_date: '',
                status: 'pending',
            });
        }

        if (bulkOrderItems.length === 0) {
            alert('Plik CSV nie zawiera artykulow');
            return;
        }

        renderBulkOrderTable();
        document.getElementById('bulkOrderTableCard').style.display = '';
    };
    reader.readAsText(file, 'UTF-8');
    input.value = '';
}

function renderBulkOrderTable() {
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
            <td>${item.lang ? `<small>${esc(item.lang.toUpperCase())}</small>` : '<span class="text-muted">dom.</span>'}</td>
            <td>${dateVal ? `<small>${esc(dateVal)}</small>` : '<span class="text-muted">teraz</span>'}</td>
            <td>${item.notes ? `<small>${esc(truncate(item.notes, 50))}</small>` : '<span class="text-muted">-</span>'}</td>
            <td>${statusBadge}${item.url ? ` <a href="${esc(item.url)}" target="_blank" class="small">link</a>` : ''}${item.errorMsg ? ` <small class="text-danger">${esc(item.errorMsg)}</small>` : ''}</td>
        </tr>`;
    }).join('');
    bulkOrderUpdateSelectedCount();
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
    if (!fromStr || !toStr) { alert('Podaj zakres dat (Od i Do)'); return; }
    const fromMs = new Date(fromStr + 'T00:00:00').getTime();
    const toMs = new Date(toStr + 'T23:59:59').getTime();
    if (fromMs > toMs) { alert('Data "Od" musi byc przed data "Do"'); return; }

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
    if (!siteId) { alert('Wybierz strone'); return; }

    const selectedItems = bulkOrderItems.filter(i => i.selected);
    if (selectedItems.length === 0) { alert('Zaznacz przynajmniej jeden artykul'); return; }

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
            const postData = {
                site_id: parseInt(siteId), title: item.title, content: htmlContent,
                status: effectiveStatus, category_id: itemCatId,
                publish_date: item.publish_date || '',
            };
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
    navigator.clipboard.writeText(text).then(() => alert('Skopiowano ' + bulkOrderPublishedUrls.length + ' linkow'));
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
