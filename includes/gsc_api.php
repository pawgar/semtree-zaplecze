<?php
/**
 * Google Search Console API client.
 * Handles OAuth token management and Search Analytics queries.
 */

class GscApi {
    private string $clientId;
    private string $clientSecret;
    private string $accessToken;
    private string $refreshToken;
    private int $timeout = 30;
    private ?array $cachedSiteList = null;

    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const API_BASE = 'https://www.googleapis.com/webmasters/v3';
    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct() {
        $db = getDb();
        $keys = ['gsc_client_id', 'gsc_client_secret', 'gsc_access_token', 'gsc_refresh_token'];
        $settings = [];
        foreach ($keys as $key) {
            $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $settings[$key] = $row ? trim($row['value']) : '';
        }

        $this->clientId = $settings['gsc_client_id'];
        $this->clientSecret = $settings['gsc_client_secret'];
        $this->accessToken = $settings['gsc_access_token'];
        $this->refreshToken = $settings['gsc_refresh_token'];
    }

    public function isConnected(): bool {
        return !empty($this->refreshToken) && !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Generate the OAuth authorization URL.
     */
    public function getAuthUrl(string $redirectUri): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function exchangeCode(string $code, string $redirectUri): array {
        $payload = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $result = $this->httpPost(self::TOKEN_URL, http_build_query($payload), 'application/x-www-form-urlencoded');

        if (isset($result['error'])) {
            throw new RuntimeException('OAuth error: ' . ($result['error_description'] ?? $result['error']));
        }

        // Save tokens
        $db = getDb();
        $this->saveToken($db, 'gsc_access_token', $result['access_token'] ?? '');
        $this->saveToken($db, 'gsc_refresh_token', $result['refresh_token'] ?? '');
        if (isset($result['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$result['expires_in']);
            $this->saveToken($db, 'gsc_token_expires', $expiresAt);
        }

        $this->accessToken = $result['access_token'] ?? '';
        $this->refreshToken = $result['refresh_token'] ?? '';

        return $result;
    }

    /**
     * Refresh access token using refresh token.
     */
    public function refreshAccessToken(): void {
        if (empty($this->refreshToken)) {
            throw new RuntimeException('Brak refresh tokena GSC. Połącz ponownie z Google.');
        }

        $payload = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $result = $this->httpPost(self::TOKEN_URL, http_build_query($payload), 'application/x-www-form-urlencoded');

        if (isset($result['error'])) {
            throw new RuntimeException('Token refresh error: ' . ($result['error_description'] ?? $result['error']));
        }

        $db = getDb();
        $this->accessToken = $result['access_token'] ?? '';
        $this->saveToken($db, 'gsc_access_token', $this->accessToken);
        if (isset($result['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$result['expires_in']);
            $this->saveToken($db, 'gsc_token_expires', $expiresAt);
        }
    }

    /**
     * Ensure we have a valid access token, refreshing if needed.
     */
    private function ensureValidToken(): void {
        $db = getDb();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = "gsc_token_expires"');
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $expiresAt = $row ? $row['value'] : '';

        if (empty($this->accessToken) || (!empty($expiresAt) && strtotime($expiresAt) < time() + 60)) {
            $this->refreshAccessToken();
        }
    }

    /**
     * Get list of all GSC properties (sites).
     */
    public function getSiteList(): array {
        if ($this->cachedSiteList !== null) {
            return $this->cachedSiteList;
        }

        $this->ensureValidToken();
        $data = $this->apiGet('/sites');
        $sites = [];
        foreach (($data['siteEntry'] ?? []) as $entry) {
            $sites[] = [
                'siteUrl' => $entry['siteUrl'],
                'permissionLevel' => $entry['permissionLevel'] ?? '',
            ];
        }
        $this->cachedSiteList = $sites;
        return $sites;
    }

    /**
     * Query Search Analytics.
     * @param string $siteUrl GSC property URL (e.g., "https://example.com/" or "sc-domain:example.com")
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @param array $dimensions e.g. ['query'], ['page'], ['query','page']
     * @param int $rowLimit
     * @return array
     */
    public function searchAnalytics(string $siteUrl, string $startDate, string $endDate, array $dimensions = [], int $rowLimit = 1000): array {
        $this->ensureValidToken();

        $payload = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rowLimit' => $rowLimit,
        ];
        if (!empty($dimensions)) {
            $payload['dimensions'] = $dimensions;
        }

        $encodedUrl = urlencode($siteUrl);
        $result = $this->apiPost("/sites/{$encodedUrl}/searchAnalytics/query", $payload);

        return $result['rows'] ?? [];
    }

    /**
     * Get summary metrics (clicks, impressions, ctr, position) for a site.
     */
    public function getSiteSummary(string $siteUrl, string $startDate, string $endDate): array {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, [], 1);

        if (empty($rows)) {
            return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        }

        // When no dimensions, API returns a single row with totals
        $row = $rows[0];
        return [
            'clicks' => (int)($row['clicks'] ?? 0),
            'impressions' => (int)($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ];
    }

    /**
     * Get daily breakdown for chart data.
     */
    public function getDailyData(string $siteUrl, string $startDate, string $endDate): array {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, ['date'], 500);

        $daily = [];
        foreach ($rows as $row) {
            $daily[] = [
                'date' => $row['keys'][0],
                'clicks' => (int)$row['clicks'],
                'impressions' => (int)$row['impressions'],
                'ctr' => round($row['ctr'] * 100, 2),
                'position' => round($row['position'], 1),
            ];
        }
        usort($daily, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $daily;
    }

    /**
     * Get top keywords for a site.
     */
    public function getTopKeywords(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, ['query'], $limit);

        $keywords = [];
        foreach ($rows as $row) {
            $keywords[] = [
                'keyword' => $row['keys'][0],
                'clicks' => (int)$row['clicks'],
                'impressions' => (int)$row['impressions'],
                'ctr' => round($row['ctr'] * 100, 2),
                'position' => round($row['position'], 1),
            ];
        }
        return $keywords;
    }

    /**
     * Get top pages for a site.
     */
    public function getTopPages(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array {
        $rows = $this->searchAnalytics($siteUrl, $startDate, $endDate, ['page'], $limit);

        $pages = [];
        foreach ($rows as $row) {
            $pages[] = [
                'url' => $row['keys'][0],
                'clicks' => (int)$row['clicks'],
                'impressions' => (int)$row['impressions'],
                'ctr' => round($row['ctr'] * 100, 2),
                'position' => round($row['position'], 1),
            ];
        }
        return $pages;
    }

    // ── Cache helpers ──────────────────────────────────────────

    /**
     * Get cached data or fetch fresh from API.
     * @param string $siteUrl
     * @param string $metricType 'summary', 'keywords', 'pages', 'daily'
     * @param string $dateFrom
     * @param string $dateTo
     * @param int $ttlSeconds Cache TTL in seconds (default 6h)
     * @return array
     */
    public function getCachedOrFetch(string $siteUrl, string $metricType, string $dateFrom, string $dateTo, int $ttlSeconds = 21600): array {
        $db = getDb();

        // Check cache
        $stmt = $db->prepare('SELECT data, fetched_at FROM gsc_cache WHERE site_url = :url AND metric_type = :type AND date_from = :df AND date_to = :dt');
        $stmt->bindValue(':url', $siteUrl, SQLITE3_TEXT);
        $stmt->bindValue(':type', $metricType, SQLITE3_TEXT);
        $stmt->bindValue(':df', $dateFrom, SQLITE3_TEXT);
        $stmt->bindValue(':dt', $dateTo, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($row && (time() - strtotime($row['fetched_at'])) < $ttlSeconds) {
            return json_decode($row['data'], true) ?? [];
        }

        // Fetch fresh data
        $data = match ($metricType) {
            'summary' => $this->getSiteSummary($siteUrl, $dateFrom, $dateTo),
            'keywords' => $this->getTopKeywords($siteUrl, $dateFrom, $dateTo),
            'pages' => $this->getTopPages($siteUrl, $dateFrom, $dateTo),
            'daily' => $this->getDailyData($siteUrl, $dateFrom, $dateTo),
            default => [],
        };

        // Store in cache
        $stmt = $db->prepare('INSERT OR REPLACE INTO gsc_cache (site_url, metric_type, date_from, date_to, data, fetched_at) VALUES (:url, :type, :df, :dt, :data, datetime("now"))');
        $stmt->bindValue(':url', $siteUrl, SQLITE3_TEXT);
        $stmt->bindValue(':type', $metricType, SQLITE3_TEXT);
        $stmt->bindValue(':df', $dateFrom, SQLITE3_TEXT);
        $stmt->bindValue(':dt', $dateTo, SQLITE3_TEXT);
        $stmt->bindValue(':data', json_encode($data, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();

        return $data;
    }

    /**
     * Invalidate cache for a site (or all sites if url is empty).
     */
    public static function invalidateCache(string $siteUrl = ''): void {
        $db = getDb();
        if ($siteUrl) {
            $stmt = $db->prepare('DELETE FROM gsc_cache WHERE site_url = :url');
            $stmt->bindValue(':url', $siteUrl, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            $db->exec('DELETE FROM gsc_cache');
        }
    }

    /**
     * Match app site URL to GSC property URL.
     * Tries both URL-prefix and sc-domain: formats.
     */
    public function matchSiteToProperty(string $appSiteUrl): ?string {
        $gscSites = $this->getSiteList();
        $appDomain = parse_url($appSiteUrl, PHP_URL_HOST);
        $appDomain = preg_replace('/^www\./', '', $appDomain);
        $normalizedUrl = rtrim($appSiteUrl, '/') . '/';

        foreach ($gscSites as $site) {
            $gscUrl = $site['siteUrl'];

            // Exact URL-prefix match
            if (rtrim($gscUrl, '/') . '/' === $normalizedUrl) {
                return $gscUrl;
            }

            // sc-domain match
            if (str_starts_with($gscUrl, 'sc-domain:')) {
                $gscDomain = substr($gscUrl, 10);
                if ($gscDomain === $appDomain) {
                    return $gscUrl;
                }
            }

            // Domain match in URL-prefix
            $gscHost = parse_url($gscUrl, PHP_URL_HOST);
            if ($gscHost) {
                $gscHost = preg_replace('/^www\./', '', $gscHost);
                if ($gscHost === $appDomain) {
                    return $gscUrl;
                }
            }
        }

        return null;
    }

    // ── Private HTTP helpers ───────────────────────────────────

    private function apiGet(string $endpoint, bool $retried = false): array {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('GSC API error: ' . $error);
        }
        curl_close($ch);

        $data = json_decode($response, true) ?? [];

        if ($httpCode === 401 && !$retried) {
            $this->refreshAccessToken();
            return $this->apiGet($endpoint, true);
        }

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException('GSC API error: ' . $msg);
        }

        return $data;
    }

    private function apiPost(string $endpoint, array $payload, bool $retried = false): array {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('GSC API error: ' . $error);
        }
        curl_close($ch);

        $data = json_decode($response, true) ?? [];

        if ($httpCode === 401 && !$retried) {
            $this->refreshAccessToken();
            return $this->apiPost($endpoint, $payload, true);
        }

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException('GSC API error: ' . $msg);
        }

        return $data;
    }

    private function httpPost(string $url, string $body, string $contentType): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $contentType,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $error);
        }
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    private function saveToken(SQLite3 $db, string $key, string $value): void {
        $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
}
