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
        filterSites();
    });
}

function renderSites(sites) {
    const tbody = document.getElementById('sitesBody');
    if (!tbody) return;

    if (sites.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Brak stron. Dodaj pierwsza strone.</td></tr>';
        return;
    }

    tbody.innerHTML = sites.map((s, i) => {
        const cats = (s.categories || '').split(',').map(c => c.trim()).filter(c => c);
        const badges = cats.map(c => `<span class="badge bg-secondary category-badge">${esc(c)}</span>`).join(' ');
        return `
        <tr data-id="${s.id}">
            <td>${i + 1}</td>
            <td>${esc(s.name)}</td>
            <td><a href="${esc(s.url)}" target="_blank">${esc(s.url)}</a></td>
            <td>${esc(s.username)}</td>
            <td>${badges}</td>
            <td class="status-loading" id="posts-${s.id}">-</td>
            <td class="status-loading" id="status-${s.id}">-</td>
            <td class="status-loading" id="api-${s.id}">-</td>
            ${IS_ADMIN ? `
            <td>
                <button class="btn btn-sm btn-outline-primary me-1" onclick="editSite(${s.id})" title="Edytuj">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteSite(${s.id}, '${esc(s.name)}')" title="Usun">
                    <i class="bi bi-trash"></i>
                </button>
            </td>` : ''}
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

function filterSites(category) {
    const cat = category || (document.getElementById('categoryFilter') ? document.getElementById('categoryFilter').value : '');
    if (!cat) {
        renderSites(sitesData);
    } else {
        const filtered = sitesData.filter(s => {
            const cats = (s.categories || '').split(',').map(c => c.trim());
            return cats.includes(cat);
        });
        renderSites(filtered);
    }
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
    if (document.getElementById('publishSiteSelect')) initPublishPage();
});

// ── Status Refresh ───────────────────────────────────────────
function refreshAllStatuses() {
    sitesData.forEach(site => {
        const postsCell = document.getElementById('posts-' + site.id);
        const statusCell = document.getElementById('status-' + site.id);
        const apiCell = document.getElementById('api-' + site.id);
        if (postsCell) postsCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (statusCell) statusCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        if (apiCell) apiCell.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';

        api('POST', 'api/status.php', {id: site.id}).then(r => {
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

// ══════════════════════════════════════════════════════════════
// ── Publish Articles ─────────────────────────────────────────
// ══════════════════════════════════════════════════════════════

let articles = [];
let wpCategories = [];
let wpAuthors = [];
let publishedUrls = [];

function initPublishPage() {
    // Load sites into dropdown
    api('GET', 'api/sites.php').then(sites => {
        sitesData = sites;
        const sel = document.getElementById('publishSiteSelect');
        sites.forEach(s => {
            sel.innerHTML += `<option value="${s.id}">${esc(s.name)} (${esc(s.url)})</option>`;
        });
    });

    // Reset manual article modal on close
    const modal = document.getElementById('manualArticleModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', () => {
            document.getElementById('manualTitle').value = '';
            document.getElementById('manualContent').value = '';
            document.getElementById('manualImage').value = '';
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

    if (imageInput.files[0]) {
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
                    ? `<span class="text-success small"><i class="bi bi-image"></i> ${esc(a.image_filename)}</span>`
                    : `<input type="file" class="form-control form-control-sm" accept="image/*" onchange="setArticleImage(${i}, this)">`
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

// ── Publish all ──────────────────────────────────────────────
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
    btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Publikuje...';
    progressWrap.classList.remove('d-none');
    report.classList.remove('d-none');
    log.innerHTML = '';
    publishedUrls = [];

    const total = articles.length;

    for (let i = 0; i < total; i++) {
        const a = articles[i];
        progressBar.style.width = ((i + 1) / total * 100) + '%';
        progressBar.textContent = `${i + 1} / ${total}`;

        try {
            const r = await api('POST', 'api/publish.php', {
                site_id: parseInt(siteId),
                title: a.title,
                content: a.content,
                status: a.status,
                category_id: a.category_id ? parseInt(a.category_id) : 0,
                author_id: a.author_id ? parseInt(a.author_id) : 0,
                publish_date: a.publish_date || '',
                image_data: a.image_data || '',
                image_filename: a.image_filename || '',
            });

            if (r.success) {
                publishedUrls.push(r.post_url);
                log.innerHTML += `<div class="text-success"><i class="bi bi-check-circle"></i> <strong>${esc(r.title)}</strong> → <a href="${esc(r.post_url)}" target="_blank">${esc(r.post_url)}</a></div>`;
            } else {
                log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> <strong>${esc(r.title)}</strong> → ${esc(r.error)}</div>`;
            }
        } catch (e) {
            log.innerHTML += `<div class="text-danger"><i class="bi bi-x-circle"></i> <strong>${esc(a.title)}</strong> → ${esc(e.message)}</div>`;
        }

        // 500ms delay between posts
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

// ── Utility ──────────────────────────────────────────────────
function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
