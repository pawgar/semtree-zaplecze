<?php require_once __DIR__ . '/../config.php'; ?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="dark">
<head>
    <script>(function(){try{var t=localStorage.getItem('tabler-theme');if(t==='light')document.documentElement.setAttribute('data-bs-theme','light');}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Wymagana konfiguracja 2FA — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link href="assets/vendor/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/vendor/tabler-icons/tabler-icons.min.css" rel="stylesheet">
    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/qrcode/qrcode.min.js"></script>
</head>
<body class="d-flex flex-column">
<div class="page page-center">
    <div class="container container-tight py-4" style="max-width:560px">
        <div class="text-center mb-4">
            <a href="." class="d-inline-flex flex-column align-items-center text-decoration-none">
                <img src="https://semtree.pl/wp-content/themes/semtree/assets/img/footer-f.svg" class="hide-theme-light" alt="Semtree" style="height:48px">
                <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" class="hide-theme-dark" alt="Semtree" style="height:48px">
                <div class="text-secondary mt-2 small">Panel zaplecza &middot; wymagana konfiguracja 2FA</div>
            </a>
        </div>

        <div class="card card-md">
            <div class="card-body">
                <!-- STEP 1: intro + auto-generate -->
                <div id="step1">
                    <div class="alert alert-info mb-3" role="alert">
                        <div class="d-flex">
                            <div><i class="ti ti-shield-lock fs-2 me-2"></i></div>
                            <div>
                                <h4 class="alert-title">Włącz logowanie dwuetapowe</h4>
                                <div class="text-secondary small">
                                    Z powodów bezpieczeństwa <strong>2FA jest obowiązkowe</strong> dla wszystkich
                                    użytkowników panelu. Konfiguracja zajmie ~2 minuty.
                                </div>
                            </div>
                        </div>
                    </div>

                    <ol class="mb-3 small">
                        <li>Zainstaluj na telefonie aplikację: <strong>Google Authenticator</strong>, <strong>Authy</strong>, <strong>Microsoft Authenticator</strong> lub <strong>1Password</strong>.</li>
                        <li>Zeskanuj kod QR (albo wpisz sekret ręcznie).</li>
                        <li>Wpisz 6-cyfrowy kod, który pokaże aplikacja.</li>
                        <li>Zapisz w bezpiecznym miejscu kody odzyskiwania.</li>
                    </ol>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-fill" id="startBtn" onclick="startSetup()">
                            <i class="ti ti-shield-plus me-2"></i>Rozpocznij konfigurację
                        </button>
                        <a href="index.php?page=logout" class="btn btn-outline-secondary" title="Wyloguj">
                            <i class="ti ti-logout"></i>
                        </a>
                    </div>
                </div>

                <!-- STEP 2: QR + verify -->
                <div id="step2" style="display:none">
                    <h3 class="mb-3 text-center">Zeskanuj kod QR</h3>
                    <div class="text-center mb-3">
                        <div id="qrBox" class="d-inline-block p-2" style="background:#fff;border-radius:4px;min-height:220px;min-width:220px"></div>
                    </div>
                    <p class="small text-secondary text-center mb-2">
                        Nie możesz zeskanować? Wpisz sekret ręcznie:
                    </p>
                    <div class="input-group input-group-sm mb-3">
                        <input type="text" class="form-control text-center" id="secretText" readonly
                               style="letter-spacing:0.1em;font-family:monospace">
                        <button class="btn btn-outline-secondary" type="button" onclick="copySecret()" title="Kopiuj"><i class="ti ti-copy"></i></button>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Wpisz 6-cyfrowy kod z aplikacji</label>
                        <input type="text" class="form-control form-control-lg text-center" id="verifyCode"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code" autofocus
                               style="letter-spacing:0.5em;font-size:1.4rem">
                    </div>
                    <div id="errMsg"></div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-success flex-fill" id="activateBtn" onclick="confirmEnable()">
                            <i class="ti ti-check me-2"></i>Aktywuj 2FA
                        </button>
                        <a href="index.php?page=logout" class="btn btn-outline-secondary" title="Wyloguj">
                            <i class="ti ti-logout"></i>
                        </a>
                    </div>
                </div>

                <!-- STEP 3: recovery codes -->
                <div id="step3" style="display:none">
                    <div class="alert alert-warning mb-3">
                        <h4 class="alert-title"><i class="ti ti-alert-triangle me-1"></i>Zapisz kody odzyskiwania!</h4>
                        <div class="small">
                            Pozwolą zalogować się, jeśli stracisz dostęp do aplikacji 2FA.
                            Każdy kod jest <strong>jednorazowy</strong>. <strong>Nie będą wyświetlone ponownie.</strong>
                        </div>
                    </div>
                    <div id="codesList" class="row g-2 small font-monospace mb-3"></div>
                    <div class="btn-list mb-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="copyCodes()"><i class="ti ti-copy me-1"></i>Kopiuj</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="downloadCodes()"><i class="ti ti-download me-1"></i>Pobierz .txt</button>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="ackCheck" onchange="document.getElementById('finishBtn').disabled=!this.checked">
                        <label class="form-check-label small" for="ackCheck">
                            Zapisałem kody w bezpiecznym miejscu
                        </label>
                    </div>
                    <button class="btn btn-primary w-100" id="finishBtn" disabled onclick="finish()">
                        <i class="ti ti-arrow-right me-2"></i>Przejdź do panelu
                    </button>
                </div>
            </div>
        </div>

        <div class="text-center text-secondary mt-3 small">
            Zalogowano jako <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong> &middot;
            <a href="index.php?page=logout" class="link-secondary">Wyloguj</a>
            <div class="mt-2">Czas serwera: <strong><?= gmdate('H:i:s') ?></strong> UTC</div>
        </div>
    </div>
</div>

<script>
let _secret = null;
let _otpauth = null;
let _codes = [];

function api(method, url, body) {
    const opts = {method: method, headers: {'Content-Type': 'application/json'}, credentials: 'same-origin'};
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(r => r.json());
}

async function startSetup() {
    const btn = document.getElementById('startBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader spin me-2"></i>Generuję sekret...';
    const r = await api('POST', '/api/2fa-setup.php');
    if (r.error) {
        alert('Błąd: ' + r.error);
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-shield-plus me-2"></i>Rozpocznij konfigurację';
        return;
    }
    _secret = r.secret;
    _otpauth = r.otpauth;
    document.getElementById('secretText').value = r.secret;

    const qrBox = document.getElementById('qrBox');
    qrBox.innerHTML = '';
    if (typeof qrcode === 'function') {
        try {
            // typeNumber=0 (auto-detect), errorCorrectionLevel='M'
            const qr = qrcode(0, 'M');
            qr.addData(r.otpauth);
            qr.make();
            // cellSize=8 px, margin=2 cells → ~final 200-220px
            qrBox.innerHTML = qr.createImgTag(8, 16);
        } catch (e) {
            qrBox.innerHTML = '<div class="text-danger small p-3">Błąd QR: ' + e.message + '</div>';
        }
    } else {
        qrBox.innerHTML = '<div class="text-secondary small p-3">Biblioteka QR niedostępna — użyj sekretu poniżej</div>';
    }

    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = '';
    document.getElementById('verifyCode').focus();
}

function copySecret() {
    navigator.clipboard.writeText(_secret).then(() => {
        const t = event.target.closest('button');
        const orig = t.innerHTML;
        t.innerHTML = '<i class="ti ti-check"></i>';
        setTimeout(() => t.innerHTML = orig, 1500);
    });
}

async function confirmEnable() {
    const code = (document.getElementById('verifyCode').value || '').replace(/\D/g, '');
    const err = document.getElementById('errMsg');
    err.innerHTML = '';
    if (code.length !== 6) {
        err.innerHTML = '<div class="alert alert-danger py-2 small mb-2">Wpisz pełny 6-cyfrowy kod.</div>';
        return;
    }
    const btn = document.getElementById('activateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader spin me-2"></i>Weryfikuję...';

    const r = await api('POST', '/api/2fa-enable.php', {code: code});
    if (r.error) {
        let html = '<div class="alert alert-danger py-2 small mb-2">' + escapeHtml(r.error) + '</div>';
        if (r.debug) {
            const d = r.debug;
            html += '<div class="alert alert-warning small mb-2"><strong>Diagnostyka:</strong><br>' +
                'Wpisany kod: <code>' + escapeHtml(d.received_code || '') + '</code><br>' +
                'Oczekiwany przez serwer TERAZ: <code>' + escapeHtml(d.expected_now || '') + '</code><br>' +
                'Poprzedni krok (–30s): <code>' + escapeHtml(d.expected_prev || '') + '</code><br>' +
                'Następny krok (+30s): <code>' + escapeHtml(d.expected_next || '') + '</code><br>' +
                'Czas serwera: <code>' + escapeHtml(d.server_time || '') + '</code><br>' +
                'Sekret odszyfrowany: ' + (d.secret_loaded ? 'TAK ('+escapeHtml(d.secret_first8 || '')+')' : 'NIE') + '<br>' +
                '<em>Porównaj "Oczekiwany TERAZ" z tym co pokazuje Twoja aplikacja. ' +
                'Jeśli się różnią — aplikacja używa innego sekretu albo Twojego zegara na telefonie odjechał. ' +
                'Jeśli się zgadzają — wpisz ten kod i powinno przejść.</em></div>';
        }
        err.innerHTML = html;
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-2"></i>Aktywuj 2FA';
        document.getElementById('verifyCode').select();
        return;
    }
    _codes = r.recovery_codes || [];
    const list = document.getElementById('codesList');
    list.innerHTML = _codes.map(c =>
        '<div class="col-6"><div class="border rounded px-2 py-2 text-center">' + escapeHtml(c) + '</div></div>'
    ).join('');
    document.getElementById('step2').style.display = 'none';
    document.getElementById('step3').style.display = '';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function copyCodes() {
    navigator.clipboard.writeText(_codes.join('\n')).then(() => {
        const t = event.target.closest('button');
        const orig = t.innerHTML;
        t.innerHTML = '<i class="ti ti-check me-1"></i>Skopiowano';
        setTimeout(() => t.innerHTML = orig, 1500);
    });
}

function downloadCodes() {
    const text = 'Semtree Zaplecze — kody odzyskiwania 2FA\n' +
                 'Wygenerowano: ' + new Date().toISOString() + '\n\n' +
                 _codes.join('\n') + '\n';
    const blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'semtree-2fa-recovery-codes.txt';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function finish() {
    window.location.href = 'index.php';
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

document.getElementById('verifyCode').addEventListener('input', function(){
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
});
document.getElementById('verifyCode').addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); confirmEnable(); }
});
</script>
<style>
.spin { animation: spin 1s linear infinite; display: inline-block; }
@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
</style>
</body>
</html>
