<?php require_once __DIR__ . '/../config.php'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="card shadow">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" alt="Semtree" class="login-logo">
                    <h5 class="mt-3 text-muted">Panel Zaplecza</h5>
                </div>

                <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=login">
                    <div class="mb-3">
                        <label for="username" class="form-label">Login</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Haslo</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="const i=this.previousElementSibling;if(i.type==='password'){i.type='text';this.innerHTML='<i class=\'bi bi-eye-slash\'></i>'}else{i.type='password';this.innerHTML='<i class=\'bi bi-eye\'></i>'}">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Zaloguj sie</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
