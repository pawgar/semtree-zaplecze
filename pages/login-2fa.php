<?php require_once __DIR__ . '/../config.php'; ?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="dark">
<head>
    <script>(function(){try{var t=localStorage.getItem('tabler-theme');if(t==='light')document.documentElement.setAttribute('data-bs-theme','light');}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Weryfikacja 2FA — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link href="assets/vendor/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/vendor/tabler-icons/tabler-icons.min.css" rel="stylesheet">
    <script defer src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</head>
<body class="d-flex flex-column">
<div class="page page-center">
    <div class="container container-tight py-4">
        <div class="text-center mb-4">
            <a href="." class="d-inline-flex flex-column align-items-center text-decoration-none">
                <img src="https://semtree.pl/wp-content/themes/semtree/assets/img/footer-f.svg" class="hide-theme-light" alt="Semtree" style="height:48px">
                <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" class="hide-theme-dark" alt="Semtree" style="height:48px">
                <div class="text-secondary mt-2 small">Panel zaplecza &middot; weryfikacja dwuetapowa</div>
            </a>
        </div>
        <div class="card card-md">
            <div class="card-body">
                <h2 class="h2 text-center mb-1">Kod uwierzytelniający</h2>
                <p class="text-secondary text-center mb-4 small">
                    Wprowadź 6-cyfrowy kod z aplikacji uwierzytelniającej (Google&nbsp;Authenticator, Authy, 1Password&hellip;).
                </p>

                <?php if (!empty($loginError)): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="ti ti-alert-triangle me-2"></i><?= htmlspecialchars($loginError) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($twoFactorError)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($twoFactorError) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($twoFactorDebug)): ?>
                <div class="alert alert-warning small" role="alert" style="display:block">
                    <div class="mb-2"><strong><i class="ti ti-bug me-1"></i>Diagnostyka:</strong></div>
                    <?php if (!empty($twoFactorDebug['secret_loaded'])): ?>
                        <div>Wpisany kod: <code><?= htmlspecialchars($twoFactorDebug['received_code'] ?? '') ?></code></div>
                        <div>Oczekiwany TERAZ: <code style="font-size:1.1em;font-weight:bold;color:#b02a37"><?= htmlspecialchars($twoFactorDebug['expected_now'] ?? '') ?></code></div>
                        <div>Poprzedni (–30s): <code><?= htmlspecialchars($twoFactorDebug['expected_prev'] ?? '') ?></code></div>
                        <div>Następny (+30s): <code><?= htmlspecialchars($twoFactorDebug['expected_next'] ?? '') ?></code></div>
                        <div>Czas serwera: <code><?= htmlspecialchars($twoFactorDebug['server_time'] ?? '') ?></code></div>
                        <div>Sekret: <code><?= htmlspecialchars($twoFactorDebug['secret_first8'] ?? '') ?></code> <span class="text-success">(odszyfrowany OK)</span></div>
                        <hr class="my-2">
                        <div><em>Spójrz teraz w swoją aplikację (Authy/Google Authenticator). Czy kod dla "Semtree" pokazuje to samo co "Oczekiwany TERAZ"?</em></div>
                    <?php else: ?>
                        <div><strong>Sekret w bazie się nie odszyfrował!</strong></div>
                        <div><?= htmlspecialchars($twoFactorDebug['note'] ?? '') ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=login-2fa" autocomplete="off" novalidate id="otpForm">
                    <div class="mb-3" id="otpBlock">
                        <label class="form-label" for="code">Kod 6-cyfrowy</label>
                        <input type="text" class="form-control form-control-lg text-center" id="code" name="code"
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                               required autofocus
                               style="letter-spacing:0.5em;font-size:1.4rem">
                    </div>
                    <div class="mb-3 d-none" id="recoveryBlock">
                        <label class="form-label" for="recoveryCode">Kod odzyskiwania</label>
                        <input type="text" class="form-control" id="recoveryCode" name="code"
                               placeholder="XXXX-XXXX" autocomplete="one-time-code"
                               style="text-transform:uppercase;letter-spacing:0.2em">
                        <div class="form-hint">Jednorazowy kod zapisany podczas konfiguracji 2FA.</div>
                    </div>
                    <input type="hidden" name="recovery" id="recoveryFlag" value="">
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ti ti-shield-check me-2"></i>Zweryfikuj
                        </button>
                    </div>
                </form>

                <div class="text-center mt-3">
                    <a href="#" class="link-secondary small" id="toggleRecovery">
                        <i class="ti ti-key me-1"></i>Użyj kodu odzyskiwania
                    </a>
                </div>
                <div class="text-center mt-2">
                    <a href="index.php?page=login" class="link-secondary small">
                        <i class="ti ti-arrow-left me-1"></i>Anuluj i wróć do logowania
                    </a>
                </div>
            </div>
        </div>
        <div class="text-center text-secondary mt-3 small">
            <div>Czas serwera: <strong><?= gmdate('H:i:s') ?></strong> UTC — porównaj z zegarem telefonu (powinien być w ciągu ±60s)</div>
            <div class="mt-2">© <?= date('Y') ?> Semtree Zaplecze</div>
        </div>
    </div>
</div>
<script>
(function(){
    var otpBlock = document.getElementById('otpBlock');
    var recBlock = document.getElementById('recoveryBlock');
    var otpInput = document.getElementById('code');
    var recInput = document.getElementById('recoveryCode');
    var flag = document.getElementById('recoveryFlag');
    var toggle = document.getElementById('toggleRecovery');
    var useRec = false;
    toggle.addEventListener('click', function(e){
        e.preventDefault();
        useRec = !useRec;
        if (useRec) {
            otpBlock.classList.add('d-none');
            recBlock.classList.remove('d-none');
            otpInput.removeAttribute('required');
            otpInput.removeAttribute('name');
            recInput.setAttribute('required','required');
            recInput.setAttribute('name','code');
            flag.value = '1';
            toggle.innerHTML = '<i class="ti ti-device-mobile me-1"></i>Użyj kodu z aplikacji';
            recInput.focus();
        } else {
            recBlock.classList.add('d-none');
            otpBlock.classList.remove('d-none');
            recInput.removeAttribute('required');
            recInput.removeAttribute('name');
            otpInput.setAttribute('required','required');
            otpInput.setAttribute('name','code');
            flag.value = '';
            toggle.innerHTML = '<i class="ti ti-key me-1"></i>Użyj kodu odzyskiwania';
            otpInput.focus();
        }
    });
    // Strip non-digits in OTP field
    otpInput.addEventListener('input', function(){
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });
})();
</script>
</body>
</html>
