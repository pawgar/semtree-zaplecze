<?php

class WpApi {
    private string $siteUrl;
    private string $authHeader;
    private int $timeout = 15;
    private bool $usePlainPermalinks = false;

    public function __construct(string $siteUrl, string $username, string $appPassword) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $token = base64_encode($username . ':' . $appPassword);
        $this->authHeader = 'Authorization: Basic ' . $token;

        // Auto-detect API URL format: try /wp-json/ first, fall back to ?rest_route=
        $ch = curl_init($this->siteUrl . '/wp-json/wp/v2');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 0 || $code === 404) {
            $this->usePlainPermalinks = true;
        }
    }

    /**
     * Build the full API URL for an endpoint.
     * Handles both pretty (/wp-json/wp/v2/...) and plain (?rest_route=/wp/v2/...) permalinks.
     */
    private function buildUrl(string $endpoint): string {
        if ($this->usePlainPermalinks) {
            // Replace first ? in endpoint with & since ?rest_route= already uses ?
            $endpoint = preg_replace('/\?/', '&', $endpoint, 1);
            return $this->siteUrl . '/?rest_route=/wp/v2' . $endpoint;
        }
        return $this->siteUrl . '/wp-json/wp/v2' . $endpoint;
    }

    /**
     * Get HTTP status code of the site's main URL.
     */
    public static function getHttpStatus(string $url): int {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SemtreeZaplecze/1.0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    /**
     * Test connection - returns user info or throws exception.
     */
    public function testConnection(): array {
        $data = $this->request('GET', '/users/me');
        return [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? '',
            'roles' => $data['roles'] ?? [],
        ];
    }

    /**
     * Get total number of published posts.
     */
    public function getPostCount(): int {
        $ch = curl_init($this->buildUrl('/posts?per_page=1&status=publish&_fields=id'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [$this->authHeader],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => true,
        ]);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . curl_error($ch));
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);

        if (preg_match('/X-WP-Total:\s*(\d+)/i', $headers, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Change the authenticated user's login password.
     */
    public function changePassword(string $newPassword): array {
        return $this->request('POST', '/users/me', ['password' => $newPassword]);
    }

    /**
     * Get all categories (paginated).
     */
    public function getCategories(): array {
        return $this->requestPaginated('/categories');
    }

    /**
     * Get all authors/users (paginated).
     */
    public function getAuthors(): array {
        return $this->requestPaginated('/users');
    }

    /**
     * Get all published posts (paginated).
     * Returns id, link, title (rendered), content (rendered).
     */
    public function getPosts(): array {
        return $this->requestPaginated('/posts?status=publish&_fields=id,link,title,content');
    }

    /**
     * Upload media file to WordPress. Returns media ID.
     */
    public function uploadMedia(string $filename, string $binaryData, string $mimeType): int {
        $url = $this->buildUrl('/media');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                $this->authHeader,
                'Content-Type: ' . $mimeType,
                'Content-Disposition: attachment; filename="' . $filename . '"',
            ],
            CURLOPT_POSTFIELDS => $binaryData,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Upload error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            throw new RuntimeException($data['message'] ?? "Upload failed: HTTP $httpCode");
        }

        return (int) ($data['id'] ?? 0);
    }

    /**
     * Create a new post on WordPress.
     */
    public function createPost(array $postData): array {
        return $this->request('POST', '/posts', $postData);
    }

    private function requestPaginated(string $endpoint): array {
        $all = [];
        $page = 1;
        do {
            $url = $this->buildUrl($endpoint);
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . 'per_page=100&page=' . $page;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [$this->authHeader, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => true,
            ]);
            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('cURL error: ' . $error);
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            curl_close($ch);

            if ($httpCode >= 400) {
                $data = json_decode($body, true) ?? [];
                $msg = $data['message'] ?? "HTTP $httpCode";
                throw new RuntimeException($msg);
            }

            $items = json_decode($body, true) ?? [];
            if (!is_array($items) || empty($items)) break;

            // Skip if WP returned an error object instead of a list
            if (isset($items['code'])) {
                throw new RuntimeException($items['message'] ?? $items['code']);
            }

            $all = array_merge($all, $items);

            $totalPages = 1;
            if (preg_match('/X-WP-TotalPages:\s*(\d+)/i', $headers, $m)) {
                $totalPages = (int) $m[1];
            }
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    private function request(string $method, string $endpoint, ?array $body = null): array {
        $ch = curl_init($this->buildUrl($endpoint));
        $headers = [
            $this->authHeader,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? "HTTP $httpCode";
            throw new RuntimeException($msg);
        }

        return $data;
    }
}
