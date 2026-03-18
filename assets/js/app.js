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
            <td>${s.link_count || 0}</td>
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
    if (document.getElementById('linksOverviewBody')) {
        initLinksPage();
    } else if (document.getElementById('xlsxFile')) {
        initImportPage();
    } else if (document.getElementById('publishSiteSelect')) {
        initPublishPage();
    }
});

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

function refreshAllStatuses() {
    sitesData.forEach(site => refreshSiteStatus(site.id));
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
    // Load sites for filter dropdowns
    api('GET', 'api/sites.php').then(sites => {
        linksSites = sites;
        // History site filter
        const hSel = document.getElementById('historySiteFilter');
        if (hSel) {
            sites.forEach(s => { hSel.innerHTML += `<option value="${s.id}">${esc(s.name)}</option>`; });
        }
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
        });
    });
}

// ── Clients ──────────────────────────────────────────────────
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
