<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" alt="Semtree" height="28" class="me-2">
            Zaplecze
        </a>
        <div class="navbar-nav ms-auto d-flex flex-row align-items-center gap-3">
            <a class="nav-link <?= ($page ?? '') === '' ? 'active' : '' ?>" href="index.php">
                <i class="bi bi-grid"></i> Dashboard
            </a>
            <?php if (isAdmin()): ?>
            <a class="nav-link <?= ($page ?? '') === 'publish' ? 'active' : '' ?>" href="index.php?page=publish">
                <i class="bi bi-pencil-square"></i> Publikuj artykuly
            </a>
            <a class="nav-link <?= ($page ?? '') === 'import' ? 'active' : '' ?>" href="index.php?page=import">
                <i class="bi bi-cloud-upload"></i> Import masowy
            </a>
            <a class="nav-link <?= ($page ?? '') === 'links' ? 'active' : '' ?>" href="index.php?page=links">
                <i class="bi bi-link-45deg"></i> Linki
            </a>
            <a class="nav-link <?= ($page ?? '') === 'users' ? 'active' : '' ?>" href="index.php?page=users">
                <i class="bi bi-people"></i> Uzytkownicy
            </a>
            <?php endif; ?>
            <span class="navbar-text text-light">
                <i class="bi bi-person-circle"></i>
                <?= htmlspecialchars($_SESSION['username']) ?>
                <span class="badge bg-<?= isAdmin() ? 'danger' : 'secondary' ?>"><?= $_SESSION['role'] ?></span>
            </span>
            <a class="nav-link" href="index.php?page=logout">
                <i class="bi bi-box-arrow-right"></i> Wyloguj
            </a>
        </div>
    </div>
</nav>
<?php endif; ?>
<div class="container-fluid">
