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

    // Add status columns to sites
    $siteCols = [];
    $colsResult = $db->query("PRAGMA table_info(sites)");
    while ($col = $colsResult->fetchArray(SQLITE3_ASSOC)) {
        $siteCols[] = $col['name'];
    }
    if (!in_array('post_count', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN post_count INTEGER DEFAULT NULL');
    }
    if (!in_array('http_status', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN http_status INTEGER DEFAULT 0');
    }
    if (!in_array('api_ok', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN api_ok INTEGER DEFAULT 0');
    }
    if (!in_array('last_status_check', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN last_status_check DATETIME DEFAULT NULL');
    }

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

    // Create GSC cache table
    $db->exec('
        CREATE TABLE IF NOT EXISTS gsc_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_url TEXT NOT NULL,
            metric_type TEXT NOT NULL,
            date_from TEXT NOT NULL,
            date_to TEXT NOT NULL,
            data TEXT NOT NULL DEFAULT "{}",
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(site_url, metric_type, date_from, date_to)
        )
    ');

    // Create publications table — tracks who published which article
    $db->exec('
        CREATE TABLE IF NOT EXISTS publications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            site_id INTEGER NOT NULL,
            post_url TEXT NOT NULL DEFAULT "",
            post_title TEXT NOT NULL DEFAULT "",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        )
    ');

    // ── Auto-publish tables ──────────────────────────────────
    $db->exec('
        CREATE TABLE IF NOT EXISTS auto_publish_config (
            site_id INTEGER PRIMARY KEY,
            daily_limit INTEGER NOT NULL DEFAULT 1,
            use_speed_links INTEGER NOT NULL DEFAULT 0,
            use_inline_images INTEGER NOT NULL DEFAULT 0,
            random_author INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT "pl",
            enabled INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS auto_publish_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            main_keyword TEXT NOT NULL DEFAULT "",
            secondary_keywords TEXT NOT NULL DEFAULT "",
            category_name TEXT NOT NULL DEFAULT "",
            notes TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL DEFAULT "pending",
            wp_category_id INTEGER DEFAULT NULL,
            published_url TEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            scheduled_date DATE DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_apq_site_status ON auto_publish_queue(site_id, status)');

    $db->exec('
        CREATE TABLE IF NOT EXISTS auto_publish_category_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            category_name TEXT NOT NULL,
            wp_category_id INTEGER NOT NULL,
            wp_category_name TEXT NOT NULL DEFAULT "",
            UNIQUE(site_id, category_name),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        )
    ');

    // ── 2FA columns on users table ─────────────────────────────
    $userCols = [];
    $colsResult = $db->query("PRAGMA table_info(users)");
    while ($col = $colsResult->fetchArray(SQLITE3_ASSOC)) {
        $userCols[] = $col['name'];
    }
    if (!in_array('totp_secret', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL');
    }
    if (!in_array('totp_enabled', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('totp_recovery_codes', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_recovery_codes TEXT DEFAULT NULL');
    }
    if (!in_array('totp_enabled_at', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME DEFAULT NULL');
    }
    if (!in_array('totp_failed_attempts', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_failed_attempts INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('totp_locked_until', $userCols)) {
        $db->exec('ALTER TABLE users ADD COLUMN totp_locked_until DATETIME DEFAULT NULL');
    }

    // One-shot: po wdrozeniu zlagodzenia polityki lockout, wyczysc istniejace blokady
    // (np. adminow ktorzy zalapali sie na stary lockout=5 prob). Idempotentne — flaga
    // w settings sprawia, ze leci tylko raz.
    $flag = $db->querySingle("SELECT value FROM settings WHERE key='lockout_purge_v1'");
    if ($flag !== '1') {
        $db->exec("UPDATE users SET totp_failed_attempts = 0, totp_locked_until = NULL");
        $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('lockout_purge_v1', '1')");
    }

    // Add GSC metric columns to sites table (for instant dashboard loading)
    if (!in_array('gsc_clicks', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_clicks INTEGER DEFAULT NULL');
    }
    if (!in_array('gsc_impressions', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_impressions INTEGER DEFAULT NULL');
    }
    if (!in_array('gsc_clicks_change', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_clicks_change REAL DEFAULT NULL');
    }
    if (!in_array('gsc_impressions_change', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_impressions_change REAL DEFAULT NULL');
    }
    if (!in_array('gsc_keywords_count', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_keywords_count INTEGER DEFAULT NULL');
    }
    if (!in_array('gsc_last_update', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_last_update DATETIME DEFAULT NULL');
    }

    // Per-site GSC goals (Raport GSC → kolumna "Cel"): target clicks/impressions + value
    if (!in_array('gsc_goal_type', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_goal_type TEXT DEFAULT NULL');
    }
    if (!in_array('gsc_goal_value', $siteCols)) {
        $db->exec('ALTER TABLE sites ADD COLUMN gsc_goal_value INTEGER DEFAULT NULL');
    }

    // Indeksacja (zakładka Indeksacja): stan per URL z GSC URL Inspection + dzienne migawki
    $db->exec('CREATE TABLE IF NOT EXISTS index_status (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        verdict TEXT,
        coverage_state TEXT,
        is_indexed INTEGER DEFAULT 0,
        last_crawl TEXT,
        checked_at DATETIME,
        submitted_at DATETIME,
        UNIQUE(site_id, url)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_index_status_site ON index_status(site_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_index_status_indexed ON index_status(site_id, is_indexed)');

    $db->exec('CREATE TABLE IF NOT EXISTS index_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        snap_date TEXT NOT NULL,
        total INTEGER DEFAULT 0,
        indexed INTEGER DEFAULT 0,
        not_indexed INTEGER DEFAULT 0,
        UNIQUE(site_id, snap_date)
    )');
}
