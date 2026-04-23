<?php require_once __DIR__ . '/../config.php'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Logowanie — <?= APP_NAME ?></title>
    <link href="assets/vendor/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/vendor/tabler-icons/tabler-icons.min.css" rel="stylesheet">
    <script src="assets/vendor/tabler/js/tabler-theme.min.js"></script>
</head>
<body class="d-flex flex-column bg-light">
<div class="page page-center">
    <div class="container container-tight py-4">
        <div class="text-center mb-4">
            <a href="." class="navbar-brand navbar-brand-autodark">
                <span class="text-dark fw-bold fs-2">Sem<span class="text-success">tree</span></span>
                <div class="text-secondary mt-1">Panel Zaplecza</div>
            </a>
        </div>
        <div class="card card-md">
            <div class="card-body">
                <h2 class="h2 text-center mb-4">Zaloguj się</h2>

                <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger" role="alert">
                    <div class="d-flex">
                        <div><i class="ti ti-alert-circle me-2"></i></div>
                        <div><?= htmlspecialchars($loginError) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=login" autocomplete="off" novalidate>
                    <div class="mb-3">
                        <label class="form-label" for="username">Login</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Hasło</label>
                        <div class="input-group input-group-flat">
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                            <span class="input-group-text">
                                <a href="#" class="link-secondary" onclick="event.preventDefault();const i=this.closest('.input-group').querySelector('input');i.type=i.type==='password'?'text':'password';this.querySelector('i').className=i.type==='password'?'ti ti-eye':'ti ti-eye-off';">
                                    <i class="ti ti-eye"></i>
                                </a>
                            </span>
                        </div>
                    </div>
                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="ti ti-login me-2"></i>Zaloguj się
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="text-center text-secondary mt-3 small">
            © <?= date('Y') ?> Semtree Zaplecze &middot; v2.7
        </div>
    </div>
</div>
</body>
</html>
