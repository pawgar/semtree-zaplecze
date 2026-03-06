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
    } else {
        migrateSchema($db);
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
            categories TEXT NOT NULL DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ""
        )
    ');

    migrateSchema($db);

    // Create default admin account
    $hash = password_hash(DEFAULT_ADMIN_PASS, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT OR IGNORE INTO users (username, password, role) VALUES (:u, :p, "admin")');
    $stmt->bindValue(':u', DEFAULT_ADMIN_USER, SQLITE3_TEXT);
    $stmt->bindValue(':p', $hash, SQLITE3_TEXT);
    $stmt->execute();
}

function migrateSchema(SQLite3 $db): void {
    // Add categories column if it doesn't exist
    $cols = $db->query("PRAGMA table_info(sites)");
    $hasCategories = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'categories') {
            $hasCategories = true;
            break;
        }
    }
    if (!$hasCategories) {
        $db->exec('ALTER TABLE sites ADD COLUMN categories TEXT NOT NULL DEFAULT ""');
    }

    // Create settings table if it doesn't exist
    $db->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ""
        )
    ');

    // Create clients table
    $db->exec('
        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            domain TEXT NOT NULL,
            color TEXT NOT NULL DEFAULT "#6c757d",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // Create links table
    $db->exec('
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            client_id INTEGER,
            post_url TEXT NOT NULL,
            post_title TEXT NOT NULL DEFAULT "",
            target_url TEXT NOT NULL,
            anchor_text TEXT NOT NULL DEFAULT "",
            link_type TEXT NOT NULL DEFAULT "dofollow",
            notes TEXT NOT NULL DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
        )
    ');

    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_links_unique ON links(site_id, post_url, target_url)');
}
