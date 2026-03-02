<?php
require_once __DIR__ . '/config.php';

function getDb(): SQLite3 {
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $isNew = !file_exists(DB_PATH);
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    if ($isNew) {
        initSchema($db);
    }

    return $db;
}

function initSchema(SQLite3 $db): void {
    $db->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "worker",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL,
            username TEXT NOT NULL,
            app_password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Create default admin account
    $hash = password_hash(DEFAULT_ADMIN_PASS, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT OR IGNORE INTO users (username, password, role) VALUES (:u, :p, "admin")');
    $stmt->bindValue(':u', DEFAULT_ADMIN_USER, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt->execute();
}
