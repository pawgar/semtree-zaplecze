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
        renderSites(sites);
    });
}

function renderSites(sites) {
    const tbody = document.getElementById('sitesBody');
    if (!tbody) return;

    if (sites.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Brak stron. Dodaj pierwsza strone.</td></tr>';
        return;
    }

    tbody.innerHTML = sites.map((s, i) => `
        <tr data-id="${s.id}">
            <td>${i + 1}</td>
            <td>${esc(s.name)}</td>
            <td><a href="${esc(s.url)}" target="_blank">${esc(s.url)}</a></td>
            <td>${esc(s.username)}</td>
            <td class="status-loading" id="posts-${s.id}">-</td>
            <td class="status-loading" id="status-${s.id}">-</td>
            ${IS_ADMIN ? `
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="editSite(${s.id})" title="Edytuj">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteSite(${s.id}, '${esc(s.name)}')" title="Usun">
                    <i class="bi bi-trash"></i>
                </button>
            </td>` : ''}
        </tr>
    `).join('');
}

function saveSite() {
    const editId = document.getElementById('siteEditId').value;
    const data = {
        name: document.getElementById('siteName').value.trim(),
        url: document.getElementById('siteUrl').value.trim(),
        username: document.getElementById('siteUsername').value.trim(),
        app_password: document.getElementById('siteAppPassword').value.trim(),
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

    new bootstrap.Modal(document.getElementById('addSiteModal')).show();
}

function deleteSite(id, name) {
    if (!confirm(`Usunac strone "${name}"?`)) return;
    api('DELETE', 'api/sites.php', {id}).then(r => {
        if (r.error) return alert(r.error);
        loadSites();
    });
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
        });
    }

    // Auto-load data based on page
    if (document.getElementById('sitesBody')) loadSites();
    if (document.getElementById('usersBody')) loadUsers();
});

// ── Status Refresh ───────────────────────────────────────────
function refreshAllStatuses() {
    sitesData.forEach(site => {
        const postsCell = document.getElementById('posts-' + site.id);
        const statusCell = document.getElementById('status-' + site.id);
        if (postsCell) postsCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (statusCell) statusCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';

        api('POST', 'api/status.php', {id: site.id}).then(r => {
            if (postsCell) {
                postsCell.textContent = r.post_count !== null ? r.post_count : '?';
                postsCell.className = r.post_count !== null ? 'status-ok' : 'status-error';
            }
            if (statusCell) {
                statusCell.textContent = r.http_status || 'ERR';
                statusCell.className = (r.http_status >= 200 && r.http_status < 400) ? 'status-ok' : 'status-error';
            }
        }).catch(() => {
            if (postsCell) { postsCell.textContent = 'ERR'; postsCell.className = 'status-error'; }
            if (statusCell) { statusCell.textContent = 'ERR'; statusCell.className = 'status-error'; }
        });
    });
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
        const indices = {};
        for (const col of required) {
            const idx = header.indexOf(col);
            if (idx === -1) return alert(`Brak kolumny: ${col}\nWymagane: ${required.join(';')}`);
            indices[col] = idx;
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

    let csv = 'name;url;username;app_password\n';
    sitesData.forEach(s => {
        csv += `${s.name};${s.url};${s.username};${s.app_password}\n`;
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
                <td><span class="badge bg-${u.role === 'admin' ? 'danger' : 'secondary'}">${u.role}</span></td>
                <td>${u.created_at}</td>
                <td>
                    ${u.role !== 'admin' ? `
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id}, '${esc(u.username)}')">
                        <i class="bi bi-trash"></i> Usun
                    </button>` : '<span class="text-muted">-</span>'}
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

// ── Utility ──────────────────────────────────────────────────
function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
