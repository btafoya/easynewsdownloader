<?php

namespace EasyNewsDownloader\EasyNews;

use DateTime;
use RuntimeException;
use Throwable;

/**
 * EasyNews search and NZB download service.
 *
 * Wraps the EasyNews members API for searching Usenet video releases and
 * downloading NZB files. Configuration is read from environment variables or
 * passed explicitly to the constructor.
 */
class EasynewsService
{
    /** @var string EasyNews members base URL. */
    public const EASYNEWS_BASE_URL = 'https://members.easynews.com';

    /** @var int Default HTTP request timeout in milliseconds. */
    public const DEFAULT_TIMEOUT_MS = 15000;

    /** @var int Timeout for standalone search requests in milliseconds. */
    public const EASYNEWS_SEARCH_STANDALONE_TIMEOUT_MS = 7000;

    /** @var int Timeout for NZB download requests in milliseconds. */
    public const EASYNEWS_NZB_DOWNLOAD_TIMEOUT_MS = 30000;

    /** @var int Default absolute timeout for video file download requests in milliseconds. Zero disables the absolute timeout. */
    public const EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS = 0;

    /** @var int Default low-speed detection window for video downloads in milliseconds. */
    public const EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS = 60000;

    /** @var int Default low-speed detection threshold for video downloads in bytes per second. */
    public const EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS = 10240;

    /** @var int Default minimum release size in megabytes. */
    public const DEFAULT_MIN_SIZE_MB = 100;

    /** @var int Maximum results returned per search page. */
    public const MAX_RESULTS_PER_PAGE = 250;

    /** @var string Internal indexer identifier. */
    public const EASYNEWS_INDEXER_ID = 'easynews';

    /** @var string Human-readable indexer name. */
    public const EASYNEWS_INDEXER_NAME = 'Easynews';

    /** @var string Regex used to split a title into searchable tokens. */
    private const TOKEN_SPLIT_REGEX = '/[^\w]+/u';

    /** @var string[] Words ignored during tokenization. */
    private const STOPWORDS = ['the', 'a', 'an', 'and', 'of', 'in', 'for', 'on'];

    /** @var string Regex for detecting video resolutions. */
    private const QUALITY_REGEX = '/(4320|2160|1440|1080|720|576|540|480|360)\s*(p|i)?/i';

    /** @var string Regex for detecting a four-digit year. */
    private const YEAR_REGEX = '/(19|20)\d{2}/';

    /** @var string Regex for detecting season/episode markers. */
    private const SEASON_EP_REGEX = '/(?:s(?<season>\d{1,2})e(?<episode>\d{1,2})|(?<season2>\d{1,2})x(?<episode2>\d{1,2}))/i';

    /** @var string[] File extensions that should always be rejected. */
    private const DISALLOWED_EXTENSIONS = ['.rar', '.zip', '.exe', '.jpg', '.png'];

    /** @var string[] Accepted video file extensions. */
    private const ALLOWED_VIDEO_EXTENSIONS = ['.mkv', '.mp4', '.m4v', '.avi', '.ts', '.mov', '.wmv', '.mpg', '.mpeg', '.flv', '.webm'];

    /** @var bool Whether the service is enabled. */
    private bool $enabled = false;

    /** @var string EasyNews username. */
    private string $username = '';

    /** @var string EasyNews password. */
    private string $password = '';

    /** @var int Minimum release size in bytes. */
    private int $minSizeBytes;

    /** @var string Base URL used for generated download links. */
    private string $downloadBase = '';

    /** @var string Optional secret segment added to download URLs. */
    private string $sharedSecret = '';

    /** @var bool When true, strict metadata matching is disabled. */
    private bool $safeTextMode = false;

    /** @var int Absolute timeout for video downloads in milliseconds. Zero disables the absolute timeout. */
    private int $videoDownloadTimeoutMs = self::EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS;

    /** @var int Low-speed detection window for video downloads in milliseconds. */
    private int $downloadLowSpeedTimeMs = self::EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS;

    /** @var int Low-speed detection threshold for video downloads in bytes per second. */
    private int $downloadLowSpeedLimitBps = self::EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS;

    /** @var array<string,string>|null Optional environment/config override. */
    private ?array $env = null;

    /**
     * Create a new service instance.
     *
     * @param array<string,string>|null $env Environment values used for configuration. When null, $_ENV is used.
     * @param array<string,mixed> $config Runtime configuration overrides such as addonBaseUrl, sharedSecret, videoDownloadTimeoutMs, downloadLowSpeedTimeMs, and downloadLowSpeedLimitBps.
     */
    public function __construct(?array $env = null, array $config = [])
    {
        $this->env = $env;
        $this->minSizeBytes = self::DEFAULT_MIN_SIZE_MB * 1024 * 1024;
        $this->reloadConfig($config);
    }

    /**
     * Reload configuration from the environment and config array.
     *
     * @param array<string,string> $config Runtime configuration overrides.
     */
    public function reloadConfig(array $config = []): void
    {
        /** @var array<string,string> $env */
        $env = $this->env ?? $_ENV;

        $enabledValue = $env['EASYNEWS_ENABLED'] ?? false;
        $this->enabled = EasynewsUtils::toBoolean($enabledValue, false);
        $this->username = trim((string)($env['EASYNEWS_USERNAME'] ?? ''));
        $this->password = trim((string)($env['EASYNEWS_PASSWORD'] ?? ''));

        $minSizeMbRaw = $env['EASYNEWS_MIN_SIZE_MB'] ?? '0';
        $minSizeMb = is_numeric($minSizeMbRaw) ? (float)$minSizeMbRaw : 0.0;
        if (is_finite($minSizeMb) && $minSizeMb >= 20) {
            $this->minSizeBytes = (int)($minSizeMb * 1024 * 1024);
        } else {
            $this->minSizeBytes = self::DEFAULT_MIN_SIZE_MB * 1024 * 1024;
        }

        $addonBaseUrl = $config['addonBaseUrl'] ?? (string)($env['ADDON_BASE_URL'] ?? '');
        $this->downloadBase = $this->resolveDownloadBase($addonBaseUrl);
        $this->sharedSecret = (string)($config['sharedSecret'] ?? '');
        $textModeOnly = $env['EASYNEWS_TEXT_MODE_ONLY'] ?? false;
        $this->safeTextMode = EasynewsUtils::toBoolean($textModeOnly, false);

        $this->videoDownloadTimeoutMs = $this->coerceIntConfig(
            $config['videoDownloadTimeoutMs'] ?? null,
            $env['EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS'] ?? null,
            self::EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS,
            0
        );
        $this->downloadLowSpeedTimeMs = $this->coerceIntConfig(
            $config['downloadLowSpeedTimeMs'] ?? null,
            $env['EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS'] ?? null,
            self::EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS,
            1000
        );
        $this->downloadLowSpeedLimitBps = $this->coerceIntConfig(
            $config['downloadLowSpeedLimitBps'] ?? null,
            $env['EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS'] ?? null,
            self::EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS,
            1
        );
    }

    /**
     * Coerce a configuration value with config precedence over environment.
     *
     * @param mixed $configValue Explicit config value, if any.
     * @param mixed $envValue Environment value, if any.
     * @param int $default Default when neither is provided.
     * @param int $minimum Smallest permitted value.
     */
    private function coerceIntConfig(mixed $configValue, mixed $envValue, int $default, int $minimum): int
    {
        $raw = $configValue ?? $envValue ?? $default;
        $value = is_numeric($raw) ? (int)$raw : $default;
        return max($minimum, $value);
    }

    /**
     * Resolve the base URL for generated download links.
     *
     * Falls back to http://127.0.0.1:<PORT> when no base URL is provided.
     *
     * @param string $rawBase Base URL supplied via config or environment.
     * @return string Resolved download base URL.
     */
    private function resolveDownloadBase(string $rawBase): string
    {
        $trimmed = EasynewsUtils::stripTrailingSlashes($rawBase);
        if ($trimmed !== '') {
            return $trimmed;
        }
        $portRaw = $_ENV['PORT'] ?? '7000';
        $fallbackPort = is_numeric($portRaw) ? (int)$portRaw : 7000;
        return 'http://127.0.0.1:' . ($fallbackPort ?: 7000);
    }

    /**
     * Check whether the service is enabled and configured.
     *
     * @return bool True when enabled and both username/password are set.
     */
    public function isEasynewsEnabled(): bool
    {
        return $this->enabled && $this->username !== '' && $this->password !== '';
    }

    /**
     * Determine whether Cinemeta metadata is required for a request.
     *
     * Metadata is required when the service is enabled and the request is not
     * a special/text-only request.
     *
     * @param bool $isSpecialRequest Whether the request is a special/text-only request.
     * @return bool True when metadata is required.
     */
    public function requiresCinemetaMetadata(bool $isSpecialRequest): bool
    {
        if (!$this->isEasynewsEnabled()) {
            return false;
        }
        return !$isSpecialRequest;
    }

    /**
     * Build the authentication configuration used by cURL requests.
     *
     * @param array<string,string>|null $override Optional username/password override.
     * @return array<string,array<string,string>> Authentication and header config.
     * @throws RuntimeException When credentials are missing.
     */
    private function buildAuthConfig(?array $override = null): array
    {
        $username = trim((string)(isset($override['username']) ? $override['username'] : $this->username));
        $password = trim((string)(isset($override['password']) ? $override['password'] : $this->password));
        if ($username === '' || $password === '') {
            throw new RuntimeException('Easynews credentials are not configured');
        }
        return [
            'auth' => [
                'username' => $username,
                'password' => $password,
            ],
            'headers' => [
                'User-Agent' => 'UsenetStreamer-Easynews/1.0',
            ],
        ];
    }

    /**
     * Execute an HTTP request against the EasyNews API.
     *
     * @param string $method HTTP method, e.g. GET or POST.
     * @param string $path API path relative to EASYNEWS_BASE_URL.
     * @param string|null $queryString Optional URL-encoded query string.
     * @param string|null $body Optional request body for POST requests.
     * @param array<string,string>|null $authOverride Optional credential override.
     * @param int $timeout Request timeout in milliseconds.
     * @param bool $decodeJson Whether to decode a JSON response body.
     * @return array{status:int, body:string, contentType:?string, headers:array<string,string>, data:mixed} Response data.
     * @throws RuntimeException On cURL failure or transport error.
     */
    private function httpRequest(string $method, string $path, ?string $queryString = null, ?string $body = null, ?array $authOverride = null, int $timeout = self::DEFAULT_TIMEOUT_MS, bool $decodeJson = false): array
    {
        $url = self::EASYNEWS_BASE_URL . $path;
        if ($queryString !== null && $queryString !== '') {
            $url .= (str_contains($path, '?') ? '&' : '?') . $queryString;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $requestHeaders = [];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($timeout, 5000));
        curl_setopt($ch, CURLOPT_USERAGENT, 'UsenetStreamer-Easynews/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $auth = $this->buildAuthConfig($authOverride);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['auth']['username'] . ':' . $auth['auth']['password']);

        if (isset($auth['headers']['Content-Type'])) {
            $requestHeaders[] = 'Content-Type: ' . $auth['headers']['Content-Type'];
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $header) use (&$responseHeaders): int {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $responseHeaders[$name] = $value;
            }
            return $len;
        });

        if ($requestHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Easynews request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ($responseHeaders['content-type'] ?? null);
        curl_close($ch);

        $decoded = null;
        if ($decodeJson && is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        }

        return [
            'status' => $status,
            'body' => is_string($raw) ? $raw : '',
            'contentType' => $contentType,
            'headers' => $responseHeaders,
            'data' => $decoded,
        ];
    }

    /**
     * Fetch raw search results from the EasyNews Solr search endpoint.
     *
     * @param string $query Search query string.
     * @param array<string,string>|null $authOverride Optional credentials.
     * @return array<string,mixed> Decoded JSON response data.
     * @throws RuntimeException When credentials are rejected or the request fails.
     */
    public function fetchSearchResults(string $query, ?array $authOverride = null): array
    {
        $params = [
            'fly' => '2',
            'sb' => '1',
            'pno' => '1',
            'pby' => (string)self::MAX_RESULTS_PER_PAGE,
            'u' => '1',
            'chxu' => '1',
            'chxgx' => '1',
            'st' => 'basic',
            'gps' => $query,
            'vv' => '1',
            'safeO' => '0',
            's1' => 'relevance',
            's1d' => '-',
            'fty' => ['VIDEO'],
        ];

        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = urlencode($key . '[]') . '=' . urlencode((string)$item);
                }
            } else {
                $parts[] = urlencode($key) . '=' . urlencode((string)$value);
            }
        }
        $queryString = implode('&', $parts);

        $response = $this->httpRequest('GET', '/2.0/search/solr-search/', $queryString, null, $authOverride, self::DEFAULT_TIMEOUT_MS, true);
        $status = $response['status'];

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Easynews rejected credentials');
        }
        if ($status >= 400) {
            throw new RuntimeException('Easynews search failed with status ' . $status);
        }
        $data = $response['data'];
        return is_array($data) ? $data : [];
    }

    /**
     * Sanitize a phrase using strict search rules.
     *
     * @param string|null $text Input phrase.
     * @return string Sanitized phrase.
     */
    private function sanitizePhrase(?string $text): string
    {
        return EasynewsUtils::sanitizeStrictSearchPhrase($text);
    }

    /**
     * Split text into significant search tokens.
     *
     * Stopwords and single-character tokens are removed.
     *
     * @param string|null $text Input text.
     * @return array<int,string> Token list.
     */
    private function tokenize(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $tokens = preg_split(self::TOKEN_SPLIT_REGEX, strtolower($text)) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $token = (string)$token;
            if (strlen($token) > 1 && !in_array($token, self::STOPWORDS, true)) {
                $out[] = $token;
            }
        }
        return $out;
    }

    /**
     * Check whether a candidate title satisfies a strict token-boundary phrase.
     *
     * The first and last tokens must match exactly, and every inner phrase
     * token must appear in order inside the candidate.
     *
     * @param string|null $title Candidate title.
     * @param string $strictPhrase Lowercased, sanitized phrase.
     * @return bool True when the title satisfies the strict match.
     */
    private function matchesStrict(?string $title, string $strictPhrase): bool
    {
        if ($strictPhrase === '') {
            return true;
        }
        $candidate = $this->sanitizePhrase($title);
        if ($candidate === '') {
            return false;
        }
        if ($candidate === $strictPhrase) {
            return true;
        }
        $candidateTokens = array_values(array_filter(explode(' ', $candidate), static fn ($t) => $t !== ''));
        $phraseTokens = array_values(array_filter(explode(' ', $strictPhrase), static fn ($t) => $t !== ''));
        if ($phraseTokens === []) {
            return true;
        }
        $candidateFirst = $candidateTokens[0] ?? '';
        $candidateLast = $candidateTokens[count($candidateTokens) - 1] ?? '';
        if ($candidateFirst !== $phraseTokens[0]) {
            return false;
        }
        if ($candidateLast !== $phraseTokens[count($phraseTokens) - 1]) {
            return false;
        }
        $candidateIdx = 1;
        for ($i = 1; $i < count($phraseTokens); $i++) {
            $token = $phraseTokens[$i];
            $found = false;
            while ($candidateIdx < count($candidateTokens)) {
                if ($candidateTokens[$candidateIdx] === $token) {
                    $found = true;
                    $candidateIdx++;
                    break;
                }
                $candidateIdx++;
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract a resolution label from a title.
     *
     * Recognizes common labels such as 720p, 1080p, 2160p/4k, and 4320p/8k.
     *
     * @param string|null $text Input text.
     * @return string|null Resolution label or null when none is found.
     */
    private function extractQuality(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $normalized = strtolower($text);
        if (str_contains($normalized, '8k') || str_contains($normalized, '4320p')) {
            return '8k';
        }
        if (str_contains($normalized, '4k') || str_contains($normalized, '2160p') || str_contains($normalized, 'uhd')) {
            return '4k';
        }
        if (preg_match(self::QUALITY_REGEX, $normalized, $match)) {
            $value = $match[1];
            $suffix = $match[2] ?? 'p';
            $resolution = $value . strtolower($suffix);
            if ($resolution === '2160p') {
                return '4k';
            }
            if ($resolution === '4320p') {
                return '8k';
            }
            return $resolution;
        }
        return null;
    }

    /**
     * Extract season, episode, year, and quality markers from a release title.
     *
     * @param string|null $text Title or filename to inspect.
     * @param string|null $qualityHint Optional precomputed quality label.
     * @return array<string,mixed> Map of detected markers.
     */
    private function extractReleaseMarkers(?string $text, ?string $qualityHint = null): array
    {
        $info = [];
        if ($text === null || $text === '') {
            return $info;
        }
        if (preg_match(self::SEASON_EP_REGEX, $text, $season)) {
            $seasonValue = (string)$season['season'];
            $episodeValue = (string)$season['episode'];
            $info['season'] = $seasonValue !== '' && (int)$seasonValue !== 0 ? (int)$seasonValue : null;
            $info['episode'] = $episodeValue !== '' && (int)$episodeValue !== 0 ? (int)$episodeValue : null;
        }
        if (preg_match(self::YEAR_REGEX, $text, $yearMatch)) {
            $info['year'] = (int)$yearMatch[0];
        }
        $quality = $qualityHint ?? $this->extractQuality($text);
        if ($quality !== null) {
            $info['quality'] = $quality;
        }
        return $info;
    }

    /**
     * Determine whether a result item should be rejected.
     *
     * Items are flagged for disallowed extensions, non-video extensions,
     * suspicious metadata (password/virus), non-video file types, or
     * durations shorter than 60 seconds.
     *
     * @param mixed $raw Raw EasyNews entry.
     * @param string|null $ext File extension.
     * @param int|null $durationSeconds Duration in seconds.
     * @return bool True when the item should be skipped.
     */
    private function isFlaggedItem(mixed $raw, ?string $ext, ?int $durationSeconds): bool
    {
        $extension = strtolower((string)$ext);
        if (in_array($extension, self::DISALLOWED_EXTENSIONS, true)) {
            return true;
        }
        if ($extension !== '' && !in_array($extension, self::ALLOWED_VIDEO_EXTENSIONS, true)) {
            return true;
        }
        if ($durationSeconds !== null && $durationSeconds < 60) {
            return true;
        }
        if (is_array($raw)) {
            $flagged = !empty($raw['passwd']) || !empty($raw['password']) || !empty($raw['virus']);
            if ($flagged) {
                return true;
            }
            $typeRaw = isset($raw['type']) && (is_string($raw['type']) || is_int($raw['type']) || is_float($raw['type'])) ? (string)$raw['type'] : (isset($raw['file_type']) && (is_string($raw['file_type']) || is_int($raw['file_type']) || is_float($raw['file_type'])) ? (string)$raw['file_type'] : '');
            $type = strtoupper($typeRaw);
            if ($type !== '' && $type !== 'VIDEO') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a display title from EasyNews entry metadata.
     *
     * @param array<string,mixed> $ctx Context with displayFn, filenameNoExt, ext, and subject.
     * @return string Computed display title.
     */
    private function buildTitle(array $ctx): string
    {
        $displayFnRaw = $ctx['displayFn'] ?? '';
        $displayFn = is_scalar($displayFnRaw) ? (string)$displayFnRaw : '';
        $filenameNoExtRaw = $ctx['filenameNoExt'] ?? '';
        $filenameNoExt = is_scalar($filenameNoExtRaw) ? (string)$filenameNoExtRaw : '';
        $extRaw = $ctx['ext'] ?? '';
        $ext = is_scalar($extRaw) ? (string)$extRaw : '';
        $subjectRaw = $ctx['subject'] ?? '';
        $subject = is_scalar($subjectRaw) ? (string)$subjectRaw : '';

        if ($displayFn !== '') {
            $cleaned = trim($displayFn);
            if ($cleaned !== '') {
                $normalized = str_replace(' - ', '-', $cleaned);
                $parts = array_filter(explode(' ', $normalized), static fn($p) => $p !== '');
                $sanitized = implode('.', $parts);
                if ($ext !== '') {
                    return $sanitized . (str_starts_with($ext, '.') ? $ext : '.' . $ext);
                }
                return $sanitized;
            }
        }
        $fallback = $subject !== '' ? $subject : ($filenameNoExt . $ext);
        return $fallback !== '' ? $fallback : 'Untitled';
    }

    /**
     * Tokenize a title into a token lookup set.
     *
     * @param string|null $title Title to tokenize.
     * @return array<string,bool> Tokens keyed by token string.
     */
    private function tokenizeTitle(?string $title): array
    {
        $tokens = $this->tokenize($title);
        $set = [];
        foreach ($tokens as $token) {
            $set[$token] = true;
        }
        return $set;
    }

    /**
     * Build a thumbnail URL for an EasyNews entry.
     *
     * @param string|null $base Thumbnail base URL.
     * @param string|null $hashId Entry hash.
     * @param string|null $slug Optional slug for the filename.
     * @return string|null Thumbnail URL or null when inputs are missing.
     */
    private function buildThumbnailUrl(?string $base, ?string $hashId, ?string $slug): ?string
    {
        if ($base === null || $base === '' || $hashId === null || $hashId === '') {
            return null;
        }
        $trimmed = EasynewsUtils::stripTrailingSlashes($base);
        $prefix = substr($hashId, 0, 3);
        $safeSlug = urlencode(str_replace('/', '_', (string)($slug ?? $hashId)));
        return $trimmed . '/' . $prefix . '/pr-' . (string)$hashId . '.jpg/th-' . $safeSlug . '.jpg';
    }

    /**
     * Encode an array payload into a URL-safe base64 string.
     *
     * @param array<string,mixed> $payload Payload to encode.
     * @return string URL-safe base64 payload.
     */
    public function encodePayload(array $payload): string
    {
        $json = json_encode($payload);
        $base = base64_encode((string)$json);
        $base = rtrim($base, '=');
        return strtr($base, ['+' => '-', '/' => '_']);
    }

    /**
     * Decode a URL-safe base64 payload back into an array.
     *
     * @param string|null $token Payload token.
     * @return array<string,mixed>|null Decoded payload, or null on failure.
     */
    public function decodePayload(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $normalized = strtr($token, ['-' => '+', '_' => '/']);
        $padLength = (4 - (strlen($normalized) % 4)) % 4;
        $padded = $normalized . str_repeat('=', $padLength);
        try {
            $json = base64_decode($padded, true);
            if ($json === false) {
                return null;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return null;
            }
            /** @var array<string,mixed> $typedDecoded */
            $typedDecoded = $decoded;
            return $typedDecoded;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Build a value token describing a single NZB entry.
     *
     * @param array<string,mixed> $item Item with hash, filename, and ext.
     * @return string Value token string.
     */
    private function buildValueToken(array $item): string
    {
        $filename = isset($item['filename']) && is_scalar($item['filename']) ? (string)$item['filename'] : '';
        $ext = isset($item['ext']) && is_scalar($item['ext']) ? (string)$item['ext'] : '';
        $hash = isset($item['hash']) && is_scalar($item['hash']) ? (string)$item['hash'] : '';
        $fnB64 = rtrim(base64_encode($filename), '=');
        $extB64 = rtrim(base64_encode($ext), '=');
        return $hash . '|' . $fnB64 . ':' . $extB64;
    }

    /**
     * Build the form payload for an NZB download request.
     *
     * @param array<int,array<string,mixed>> $items Selected EasyNews items.
     * @param string|null $name Optional archive name.
     * @return array<int,array{0:string,1:string}> Key/value pairs for the download form.
     */
    private function buildNzbPayload(array $items, ?string $name = null): array
    {
        $entries = [['autoNZB', '1']];
        foreach ($items as $idx => $item) {
            $sig = isset($item['sig']) && is_scalar($item['sig']) && (string)$item['sig'] !== '' ? (string)$item['sig'] : '';
            $key = $sig !== '' ? ((string)$idx . '&sig=' . $sig) : (string)$idx;
            $entries[] = [$key, $this->buildValueToken($item)];
        }
        if ($name !== null && $name !== '') {
            $entries[] = ['nameZipQ0', $name];
        }
        return $entries;
    }

    /**
     * Build a download URL for a payload token.
     *
     * @param string $token URL-safe payload token.
     * @return string Fully qualified download URL.
     */
    private function buildDownloadUrl(string $token): string
    {
        $secretSegment = $this->sharedSecret !== '' ? '/' . urlencode($this->sharedSecret) : '';
        return $this->downloadBase . $secretSegment . '/easynews/nzb?payload=' . urlencode($token);
    }

    /**
     * Build a direct video download URL from a normalized item.
     *
     * Uses the EasyNews /dl/{farm}/{port}/{hash}{fileId}{ext}/{filename}{ext}
     * URL format with the session id and per-item signature.
     *
     * @param array<string,mixed> $rawItem Normalized item from filterAndMap().
     * @param string $hash File hash.
     * @param string $filename Filename without extension.
     * @param string $ext File extension.
     * @return string Fully qualified direct download URL.
     */
    private function buildDirectUrl(array $rawItem, string $hash, string $filename, string $ext): string
    {
        $downUrlBase = isset($rawItem['downUrlBase']) && is_scalar($rawItem['downUrlBase']) ? (string)$rawItem['downUrlBase'] : '';
        $dlFarm = isset($rawItem['dlFarm']) && is_scalar($rawItem['dlFarm']) ? (string)$rawItem['dlFarm'] : '';
        $dlPort = isset($rawItem['dlPort']) ? (int)$rawItem['dlPort'] : 0;
        $sid = isset($rawItem['sid']) && is_scalar($rawItem['sid']) ? (string)$rawItem['sid'] : '';
        $fileId = isset($rawItem['fileId']) && is_scalar($rawItem['fileId']) ? (string)$rawItem['fileId'] : '';
        $sig = $rawItem['sig'] ?? null;
        $sigStr = $sig !== null && is_scalar($sig) ? (string)$sig : '';

        if ($downUrlBase === '') {
            $downUrlBase = self::EASYNEWS_BASE_URL . '/dl';
        }
        if ($dlFarm === '') {
            $dlFarm = 'auto';
        }
        if ($dlPort <= 0) {
            $dlPort = 443;
        }

        $filePart = $hash . $fileId . $ext;
        $namePart = $filename . $ext;
        $query = [];
        if ($sid !== '') {
            $query['sid'] = $sid;
        }
        if ($sigStr !== '') {
            $query['sig'] = $sigStr;
        }

        $url = $downUrlBase . '/' . $dlFarm . '/' . $dlPort . '/' . urlencode($filePart) . '/' . urlencode($namePart);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    /**
     * Parse a torrent-like release title into structured metadata.
     *
     * Extracts title, year, seasons, episodes, resolution, container, and
     * source label. This is a lightweight stand-in for parse-torrent-title.
     *
     * @param string|null $title Release title or filename.
     * @return array<string,mixed>|null Parsed metadata, or null for empty input.
     */
    private function parseTorrentTitle(?string $title): ?array
    {
        if ($title === null || $title === '') {
            return null;
        }
        $result = [];
        $work = $title;

        $work = preg_replace('/\s*\[[^\]]+\]\s*$/', '', $work) ?? $work;
        $work = preg_replace('/\s*\([^\)]+\)\s*$/', '', $work) ?? $work;

        if (preg_match('/\.([a-zA-Z0-9]{2,4})$/', $work, $m)) {
            $result['container'] = strtolower($m[1]);
            $work = substr($work, 0, -strlen($m[0]));
        }

        if (preg_match(self::QUALITY_REGEX, $work, $m)) {
            $suffix = isset($m[2]) ? strtolower((string)$m[2]) : 'p';
            $res = $m[1] . $suffix;
            if ($res === '2160p') {
                $res = '4k';
            }
            if ($res === '4320p') {
                $res = '8k';
            }
            $result['resolution'] = $res;
        }

        if (preg_match('/\b(BRRip|BDRip|BluRay|Blu-Ray|WEB[-\s]?DL|WEBDL|WEBRip|HDTV|HDTVRip|DVDRip|DVDScr|CAM|TS|TC|R5|HDRip)\b/i', $work, $m)) {
            $result['source'] = $m[1];
        }

        if (preg_match(self::YEAR_REGEX, $work, $m)) {
            $result['year'] = (int)$m[0];
        }

        $seasons = [];
        $episodes = [];
        if (preg_match_all('/\bS(\d{1,2})[ .-]*E(\d{1,2})\b/i', $work, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $seasons[] = (int)$match[1];
                $episodes[] = (int)$match[2];
            }
        } elseif (preg_match('/\b(\d{1,2})x(\d{1,2})\b/i', $work, $m)) {
            $seasons[] = (int)$m[1];
            $episodes[] = (int)$m[2];
        }
        if ($seasons !== []) {
            $result['seasons'] = array_values(array_unique($seasons));
        }
        if ($episodes !== []) {
            $result['episodes'] = array_values(array_unique($episodes));
        }

        $cut = $work;
        if (preg_match('/^(.*?)\s*(19|20)\d{2}/', $cut, $m)) {
            $cut = $m[1];
        }
        if (preg_match('/^(.*?)\s*S\d{1,2}E\d{1,2}/i', $cut, $m)) {
            $cut = $m[1];
        }
        if (preg_match('/^(.*?)[.\s_-]*(?:4320|2160|1440|1080|720|576|540|480|360)\s*(?:p|i)?/i', $cut, $m)) {
            $cut = $m[1];
        }
        $cut = trim(preg_replace('/[._-]+/u', ' ', $cut) ?? $cut);
        if ($cut !== '') {
            $result['title'] = $cut;
        }

        return $result ?: null;
    }

    /**
     * Filter and map raw EasyNews entries into normalized items.
     *
     * Applies size, extension, duration, sample, token, and strict metadata
     * filtering. The returned items are used by searchEasynews() and
     * buildResult().
     *
     * @param array<string,mixed> $jsonData Raw response from fetchSearchResults().
     * @param array<string,mixed> $options Filtering options:
     *        - minBytes: int minimum size in bytes
     *        - queryTokens: array<string> tokens that must be present
     *        - queryMeta: array{title?:string,year?:int,season?:int,episode?:int}|null
     *        - strictPhrase: string sanitized strict phrase
     *        - strictMatch: bool enable strict metadata validation
     *        - skipSamples: bool skip sample releases
     * @return array<int,array<string,mixed>> Normalized item list.
     */
    public function filterAndMap(array $jsonData, array $options): array
    {
        $minBytesRaw = $options['minBytes'] ?? 0;
        $minBytes = is_numeric($minBytesRaw) ? (int)$minBytesRaw : 0;
        $queryTokens = (array)($options['queryTokens'] ?? []);
        $queryMeta = $options['queryMeta'] ?? null;
        $strictPhraseRaw = $options['strictPhrase'] ?? '';
        $strictPhrase = is_scalar($strictPhraseRaw) ? (string)$strictPhraseRaw : '';
        $strictMatchRaw = $options['strictMatch'] ?? false;
        $strictMatch = is_bool($strictMatchRaw) ? $strictMatchRaw : EasynewsUtils::toBoolean($strictMatchRaw, false);
        $skipSamplesRaw = $options['skipSamples'] ?? true;
        $skipSamples = is_bool($skipSamplesRaw) ? $skipSamplesRaw : EasynewsUtils::toBoolean($skipSamplesRaw, true);
        $debugEnvRaw = $_ENV['DEBUG_NEWZNAB_SEARCH'] ?? '';
        $debugEnv = is_scalar($debugEnvRaw) ? (string)$debugEnvRaw : '';
        $debugEnabled = strtolower(trim($debugEnv)) === 'true';

        $tokenSet = [];
        foreach ($queryTokens as $token) {
            $tokenStr = is_scalar($token) ? (string)$token : '';
            if ($tokenStr !== '') {
                $tokenSet[$tokenStr] = true;
            }
        }

        $thumbBaseRaw = $jsonData['thumbURL'] ?? ($jsonData['thumbUrl'] ?? null);
        $thumbBase = is_scalar($thumbBaseRaw) ? (string)$thumbBaseRaw : null;

        $downUrlBaseRaw = $jsonData['downURL'] ?? ($jsonData['downUrl'] ?? null);
        $downUrlBase = is_scalar($downUrlBaseRaw) ? (string)$downUrlBaseRaw : '';
        $dlFarmRaw = $jsonData['dlFarm'] ?? null;
        $dlFarm = is_scalar($dlFarmRaw) ? (string)$dlFarmRaw : '';
        $dlPortRaw = $jsonData['dlPort'] ?? null;
        $dlPort = is_numeric($dlPortRaw) ? (int)$dlPortRaw : 0;
        $sessionIdRaw = $jsonData['sid'] ?? null;
        $sessionId = is_scalar($sessionIdRaw) ? (string)$sessionIdRaw : '';

        $items = [];
        $data = is_array($jsonData['data'] ?? null) ? $jsonData['data'] : [];

        foreach ($data as $entry) {
            $hashId = null;
            $fileId = null;
            $subject = null;
            $filenameNoExt = null;
            $ext = null;
            $size = 0;
            $poster = null;
            $postedRaw = null;
            $sig = null;
            $displayFn = null;
            $extensionField = null;
            $durationRaw = null;
            $fullres = null;
            $usenetGroup = null;

            if (is_array($entry) && EasynewsUtils::isListArray($entry)) {
                $hashId = $entry[0] ?? null;
                $fileId = null;
                $subject = $entry[6] ?? null;
                $filenameNoExt = $entry[10] ?? null;
                $ext = $entry[11] ?? null;
                $poster = $entry[7] ?? null;
                $postedRaw = $entry[5] ?? null;
                $durationRaw = $entry[14] ?? null;
                $usenetGroup = $entry[9] ?? null;
            } elseif (is_array($entry)) {
                $hashId = $entry['hash'] ?? ($entry['0'] ?? null);
                $idRaw = $entry['id'] ?? null;
                $fileId = null;
                if (is_scalar($idRaw)) {
                    $idStr = (string)$idRaw;
                    $hashStr = is_scalar($hashId) ? (string)$hashId : '';
                    if ($idStr !== '' && $idStr !== $hashStr && strlen($idStr) < 20) {
                        $fileId = $idRaw;
                    }
                }
                $subject = $entry['subject'] ?? ($entry['6'] ?? null);
                $filenameNoExt = $entry['filename'] ?? ($entry['10'] ?? null);
                $ext = $entry['ext'] ?? ($entry['11'] ?? null);
                $size = $entry['size'] ?? ($entry['Length'] ?? ($entry['length'] ?? 0));
                $poster = $entry['poster'] ?? ($entry['7'] ?? null);
                $postedRaw = $entry['ts'] ?? ($entry['timestamp'] ?? ($entry['5'] ?? ($entry['dtime'] ?? ($entry['date'] ?? null))));
                $sig = $entry['sig'] ?? null;
                $displayFn = $entry['fn'] ?? ($entry['filename'] ?? null);
                $extensionField = $entry['extension'] ?? ($entry['ext'] ?? null);
                $durationRaw = $entry['14'] ?? ($entry['duration'] ?? ($entry['len'] ?? null));
                $fullres = $entry['fullres'] ?? ($entry['resolution'] ?? null);
                $usenetGroup = $entry['groups'] ?? ($entry['group'] ?? ($entry['9'] ?? null));
            }

            $hashIdStr = is_scalar($hashId) ? (string)$hashId : '';
            if ($hashIdStr === '') {
                continue;
            }
            $normalizedExtRaw = $extensionField ?? $ext;
            $normalizedExt = is_scalar($normalizedExtRaw) ? (string)$normalizedExtRaw : '';
            $sizeValue = is_numeric($size) ? (float)$size : 0.0;
            if (!is_finite($sizeValue) || $sizeValue < $minBytes) {
                continue;
            }

            $durationSeconds = EasynewsUtils::parseDurationSeconds($durationRaw);
            if ($this->isFlaggedItem($entry, $normalizedExt, $durationSeconds)) {
                continue;
            }

            $title = $this->buildTitle([
                'displayFn' => $displayFn,
                'filenameNoExt' => $filenameNoExt,
                'ext' => $normalizedExt,
                'subject' => $subject,
            ]);
            $quality = $this->extractQuality($title) ?? $this->extractQuality(is_scalar($fullres) ? (string)$fullres : '');
            $titleMeta = $this->extractReleaseMarkers($title, $quality);

            if ($skipSamples && preg_match('/sample/i', $title)) {
                continue;
            }

            if ($strictMatch && $strictPhrase !== '') {
                $parsedCandidateTitle = $title;
                $parsedRelease = null;
                try {
                    $parsedRelease = $this->parseTorrentTitle($title);
                    if (!empty($parsedRelease['title'])) {
                        $parsedCandidateTitle = is_scalar($parsedRelease['title']) ? (string)$parsedRelease['title'] : $parsedCandidateTitle;
                    }
                } catch (Throwable $e) {
                    // use raw title
                }

                $strictTitlePhrase = $strictPhrase;
                if (is_array($queryMeta) && isset($queryMeta['title']) && is_scalar($queryMeta['title'])) {
                    $strictTitlePhrase = $this->sanitizePhrase((string)$queryMeta['title']);
                }

                if (!$this->matchesStrict($parsedCandidateTitle, $strictTitlePhrase)) {
                    if ($debugEnabled) {
                        error_log('[EASYNEWS] Strict match failed: title=' . $title . ' parsed=' . $parsedCandidateTitle . ' query=' . $strictTitlePhrase);
                    }
                    continue;
                }

                $queryTitle = is_array($queryMeta) && isset($queryMeta['title']) && is_scalar($queryMeta['title']) ? (string)$queryMeta['title'] : null;
                if (!EasynewsUtils::titleSimilarityCheck($parsedCandidateTitle, $queryTitle)) {
                    if ($debugEnabled) {
                        $ratio = EasynewsUtils::levenshteinRatio(EasynewsUtils::normaliseTitle($parsedCandidateTitle), EasynewsUtils::normaliseTitle($queryTitle ?? ''));
                        error_log('[EASYNEWS] Strict match failed (title similarity too low): title=' . $title . ' parsed=' . $parsedCandidateTitle . ' query=' . $queryTitle . ' ratio=' . round($ratio, 3) . ' threshold=' . EasynewsUtils::TITLE_SIMILARITY_THRESHOLD);
                    }
                    continue;
                }

                $parsedYear = isset($parsedRelease['year']) && is_scalar($parsedRelease['year']) ? (int)$parsedRelease['year'] : null;
                $parsedSeason = null;
                $parsedEpisode = null;
                if (isset($parsedRelease['seasons']) && is_array($parsedRelease['seasons']) && $parsedRelease['seasons'] !== []) {
                    $firstSeason = $parsedRelease['seasons'][0];
                    $parsedSeason = is_scalar($firstSeason) ? (int)$firstSeason : null;
                }
                if (isset($parsedRelease['episodes']) && is_array($parsedRelease['episodes']) && $parsedRelease['episodes'] !== []) {
                    $firstEpisode = $parsedRelease['episodes'][0];
                    $parsedEpisode = is_scalar($firstEpisode) ? (int)$firstEpisode : null;
                }

                $queryYear = is_array($queryMeta) && isset($queryMeta['year']) && is_scalar($queryMeta['year']) ? (int)$queryMeta['year'] : null;
                $querySeason = is_array($queryMeta) && isset($queryMeta['season']) && is_scalar($queryMeta['season']) ? (int)$queryMeta['season'] : null;
                $queryEpisode = is_array($queryMeta) && isset($queryMeta['episode']) && is_scalar($queryMeta['episode']) ? (int)$queryMeta['episode'] : null;
                $isSeries = $querySeason !== null;
                if ($queryYear !== null && $isSeries) {
                    if ($parsedYear !== null && abs($queryYear - $parsedYear) > 1) {
                        if ($debugEnabled) {
                            error_log('[EASYNEWS] Strict match failed (series year mismatch): title=' . $title . ' year=' . $parsedYear . ' expected=' . $queryYear);
                        }
                        continue;
                    }
                } elseif ($queryYear !== null) {
                    if ($parsedYear === null) {
                        if ($debugEnabled) {
                            error_log('[EASYNEWS] Strict match failed (missing year): title=' . $title . ' expected=' . $queryYear);
                        }
                        continue;
                    }
                    if (abs($queryYear - $parsedYear) > 1) {
                        if ($debugEnabled) {
                            error_log('[EASYNEWS] Strict match failed (year mismatch): title=' . $title . ' year=' . $parsedYear . ' expected=' . $queryYear);
                        }
                        continue;
                    }
                }
                if ($querySeason !== null && $parsedSeason !== null && $querySeason !== $parsedSeason) {
                    if ($debugEnabled) {
                        error_log('[EASYNEWS] Strict match failed (season mismatch): title=' . $title . ' season=' . $parsedSeason . ' expected=' . $querySeason);
                    }
                    continue;
                }
                if ($queryEpisode !== null && $parsedEpisode !== null && $queryEpisode !== $parsedEpisode) {
                    if ($debugEnabled) {
                        error_log('[EASYNEWS] Strict match failed (episode mismatch): title=' . $title . ' episode=' . $parsedEpisode . ' expected=' . $queryEpisode);
                    }
                    continue;
                }

                if ($debugEnabled) {
                    error_log('[EASYNEWS] Strict match passed: title=' . $title . ' parsed=' . $parsedCandidateTitle . ' query=' . $strictTitlePhrase);
                }
            }

            if (is_array($queryMeta)) {
                $queryYear = isset($queryMeta['year']) && is_scalar($queryMeta['year']) ? (int)$queryMeta['year'] : null;
                $querySeason = isset($queryMeta['season']) && is_scalar($queryMeta['season']) ? (int)$queryMeta['season'] : null;
                $queryEpisode = isset($queryMeta['episode']) && is_scalar($queryMeta['episode']) ? (int)$queryMeta['episode'] : null;
                $titleYear = isset($titleMeta['year']) && is_scalar($titleMeta['year']) ? (int)$titleMeta['year'] : null;
                $titleSeason = isset($titleMeta['season']) && is_scalar($titleMeta['season']) ? (int)$titleMeta['season'] : null;
                $titleEpisode = isset($titleMeta['episode']) && is_scalar($titleMeta['episode']) ? (int)$titleMeta['episode'] : null;
                if ($queryYear !== null && $titleYear !== null && $queryYear !== $titleYear) {
                    continue;
                }
                if ($querySeason !== null && $titleSeason !== null && $querySeason !== $titleSeason) {
                    continue;
                }
                if ($queryEpisode !== null && $titleEpisode !== null && $queryEpisode !== $titleEpisode) {
                    continue;
                }
            }

            if ($tokenSet !== []) {
                $titleTokens = $this->tokenizeTitle($title);
                foreach ($tokenSet as $token => $_) {
                    if (!isset($titleTokens[$token])) {
                        continue 2;
                    }
                }
            }

            $posted = EasynewsUtils::coerceDate($postedRaw) ?: new DateTime();
            $durationHms = $durationSeconds !== null ? gmdate('H:i:s', $durationSeconds) : null;
            $thumbBaseStr = is_scalar($thumbBase) ? (string)$thumbBase : '';
            $filenameNoExtStr = is_scalar($filenameNoExt) ? (string)$filenameNoExt : '';
            $thumbnail = $this->buildThumbnailUrl($thumbBaseStr, $hashIdStr, $filenameNoExtStr);

            $extValue = (string)$normalizedExt;
            $fileIdStr = is_scalar($fileId) ? (string)$fileId : '';

            $items[] = [
                'hash' => $hashIdStr,
                'fileId' => $fileIdStr,
                'filename' => $filenameNoExtStr !== '' ? $filenameNoExtStr : $hashIdStr,
                'ext' => str_starts_with($extValue, '.') ? $extValue : '.' . $extValue,
                'sig' => $sig,
                'size' => (int)$sizeValue,
                'title' => $title,
                'poster' => $poster,
                'group' => $usenetGroup ?? null,
                'posted' => $posted,
                'durationSeconds' => $durationSeconds,
                'durationHms' => $durationHms,
                'quality' => $quality ?? ($titleMeta['quality'] ?? null),
                'thumbnail' => $thumbnail,
                'year' => $titleMeta['year'] ?? null,
                'season' => $titleMeta['season'] ?? null,
                'episode' => $titleMeta['episode'] ?? null,
                'downUrlBase' => $downUrlBase,
                'dlFarm' => $dlFarm,
                'dlPort' => $dlPort,
                'sid' => $sessionId,
            ];
        }

        return $items;
    }

    /**
     * Build metadata for strict query matching from search options.
     *
     * @param array<string,mixed> $params Search options with rawQuery, year, season, and episode.
     * @return array<string,mixed> Parsed metadata including title, year, season, and episode.
     */
    private function buildQueryMeta(array $params): array
    {
        $rawQueryRaw = $params['rawQuery'] ?? '';
        $rawQuery = is_scalar($rawQueryRaw) ? trim((string)$rawQueryRaw) : '';
        $year = $params['year'] ?? null;
        $season = $params['season'] ?? null;
        $episode = $params['episode'] ?? null;

        $markers = $this->extractReleaseMarkers($rawQuery);
        if ($year !== null && is_scalar($year) && is_numeric($year)) {
            $markers['year'] = (int)$year;
        }
        if ($season !== null && is_scalar($season) && is_numeric($season)) {
            $markers['season'] = (int)$season;
        }
        if ($episode !== null && is_scalar($episode) && is_numeric($episode)) {
            $markers['episode'] = (int)$episode;
        }
        try {
            $parsed = $this->parseTorrentTitle($rawQuery);
            if (isset($parsed['title']) && is_scalar($parsed['title'])) {
                $markers['title'] = (string)$parsed['title'];
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $markers;
    }

    /**
     * Build a final NZB-style result from a normalized item.
     *
     * @param array<string,mixed> $rawItem Normalized item from filterAndMap().
     * @return array<string,mixed> Result array suitable for downstream consumers.
     */
    private function buildResult(array $rawItem): array
    {
        $hash = isset($rawItem['hash']) && is_scalar($rawItem['hash']) ? (string)$rawItem['hash'] : '';
        $filename = isset($rawItem['filename']) && is_scalar($rawItem['filename']) ? (string)$rawItem['filename'] : '';
        $ext = isset($rawItem['ext']) && is_scalar($rawItem['ext']) ? (string)$rawItem['ext'] : '';
        $sig = $rawItem['sig'] ?? null;
        $title = isset($rawItem['title']) && is_scalar($rawItem['title']) ? (string)$rawItem['title'] : '';

        $payload = $this->encodePayload([
            'hash' => $hash,
            'filename' => $filename,
            'ext' => $ext,
            'sig' => $sig,
            'title' => $title,
        ]);
        $downloadUrl = $this->buildDownloadUrl($payload);
        $directUrl = $this->buildDirectUrl($rawItem, $hash, $filename, $ext);
        $posted = $rawItem['posted'] ?? null;
        $publishDateMs = $posted instanceof DateTime ? ($posted->getTimestamp() * 1000) : (time() * 1000);

        return [
            'title' => $title,
            'downloadUrl' => $downloadUrl,
            'directUrl' => $directUrl,
            'guid' => 'easynews-' . $hash,
            'indexer' => self::EASYNEWS_INDEXER_NAME,
            'indexerId' => self::EASYNEWS_INDEXER_ID,
            'size' => $rawItem['size'] ?? 0,
            'pubDate' => $posted instanceof DateTime ? $posted->format('c') : null,
            'publishDateMs' => $publishDateMs,
            'ageDays' => (int)round(((time() * 1000) - $publishDateMs) / (24 * 60 * 60 * 1000)),
            'release' => [
                'resolution' => $rawItem['quality'] ?? null,
                'languages' => [],
            ],
            'poster' => $rawItem['poster'] ?? null,
            'easynewsPayload' => $payload,
            '_sourceType' => 'easynews',
            'group' => $rawItem['group'] ?? null,
        ];
    }

    /**
     * Search EasyNews and return normalized NZB-style results.
     *
     * Returns an empty array when the service is disabled or no query is
     * provided. Throws RuntimeException when the API rejects credentials or
     * returns an error status.
     *
     * @param array<string,mixed> $options Search options:
     *        - rawQuery: string primary search query
     *        - fallbackQuery: string fallback query
     *        - year: int expected release year
     *        - season: int expected season number
     *        - episode: int expected episode number
     *        - strictMode: bool enable strict metadata validation
     *        - specialTextOnly: bool treat as text-only special request
     * @return array<int,array<string,mixed>> Normalized result list.
     */
    public function searchEasynews(array $options = []): array
    {
        if (!$this->isEasynewsEnabled()) {
            return [];
        }

        $rawQuery = isset($options['rawQuery']) && is_scalar($options['rawQuery']) ? trim((string)$options['rawQuery']) : '';
        $fallbackQuery = isset($options['fallbackQuery']) && is_scalar($options['fallbackQuery']) ? trim((string)$options['fallbackQuery']) : '';
        $year = $options['year'] ?? null;
        $season = $options['season'] ?? null;
        $episode = $options['episode'] ?? null;
        $strictMode = (bool)($options['strictMode'] ?? false);
        $specialTextOnly = (bool)($options['specialTextOnly'] ?? false);

        $query = $rawQuery !== '' ? $rawQuery : $fallbackQuery;
        if ($query === '') {
            return [];
        }

        $strict = $strictMode && !$specialTextOnly && !$this->safeTextMode;
        $strictPhrase = $strict ? $this->sanitizePhrase($query) : '';
        $queryTokens = $strict ? $this->tokenize($query) : [];
        $queryMeta = $strict ? $this->buildQueryMeta(['rawQuery' => $query, 'year' => $year, 'season' => $season, 'episode' => $episode]) : null;
        $minBytes = $this->minSizeBytes;

        $data = $this->fetchSearchResults($query);
        $mapped = $this->filterAndMap($data, [
            'minBytes' => $minBytes,
            'queryTokens' => $queryTokens,
            'queryMeta' => $queryMeta,
            'strictPhrase' => $strictPhrase,
            'strictMatch' => $strict,
            'skipSamples' => true,
        ]);

        $results = [];
        foreach ($mapped as $item) {
            $results[] = $this->buildResult($item);
        }
        return $results;
    }

    /**
     * Download an NZB from EasyNews using a payload token.
     *
     * The payload is expected to contain hash, filename, ext, sig, and title
     * as produced by encodePayload().
     *
     * @param string $payloadToken URL-safe payload token.
     * @return array{buffer:string, fileName:string, contentType:string} NZB data.
     * @throws RuntimeException When disabled, the payload is invalid, or the download fails.
     */
    public function downloadEasynewsNzb(string $payloadToken): array
    {
        if (!$this->isEasynewsEnabled()) {
            throw new RuntimeException('Easynews integration is disabled');
        }
        $decoded = $this->decodePayload($payloadToken);
        if ($decoded === null || empty($decoded['hash'])) {
            throw new RuntimeException('Invalid Easynews payload');
        }

        $hash = is_scalar($decoded['hash']) ? (string)$decoded['hash'] : '';
        $filename = isset($decoded['filename']) && is_scalar($decoded['filename']) ? (string)$decoded['filename'] : '';
        $ext = isset($decoded['ext']) && is_scalar($decoded['ext']) ? (string)$decoded['ext'] : '';
        $sig = $decoded['sig'] ?? null;
        $nzbTitle = isset($decoded['title']) && is_scalar($decoded['title']) ? (string)$decoded['title'] : null;

        $nzbEntries = $this->buildNzbPayload([
            [
                'hash' => $hash,
                'filename' => $filename,
                'ext' => $ext,
                'sig' => $sig,
            ],
        ], $nzbTitle !== null ? (string)$nzbTitle : null);

        $parts = [];
        foreach ($nzbEntries as [$key, $value]) {
            $parts[] = urlencode((string)$key) . '=' . urlencode((string)$value);
        }
        $form = implode('&', $parts);

        $authConfig = $this->buildAuthConfig();
        $headers = $authConfig['headers'] ?? [];
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        $response = $this->httpRequest('POST', '/2.0/api/dl-nzb', null, $form, null, self::EASYNEWS_NZB_DOWNLOAD_TIMEOUT_MS, false);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Easynews NZB download failed (' . $response['status'] . ')');
        }

        $defaultName = $filename !== '' ? $filename : 'download';
        $defaultName .= $ext !== '' ? $ext : '.nzb';
        $rawTitle = $nzbTitle ?? $defaultName;
        $safeTitle = preg_replace('/[^a-z0-9\s._-]+/i', '', $rawTitle);
        $safeTitle = trim((string)$safeTitle) !== '' ? trim((string)$safeTitle) : 'easynews';
        $fileName = str_ends_with(strtolower($safeTitle), '.nzb') ? $safeTitle : $safeTitle . '.nzb';

        return [
            'buffer' => $response['body'],
            'fileName' => $fileName,
            'contentType' => $response['contentType'] ?? 'application/x-nzb+xml',
        ];
    }

    /**
     * Download a video file from a search result to a local path.
     *
     * Streams the response directly to disk so very large files do not need
     * to be held in memory. Partial files are resumed via HTTP Range requests;
     * if the server ignores ranges the destination is overwritten from the
     * start. Missing parent directories are created automatically.
     *
     * @param array<string,mixed> $searchResult Normalized result containing directUrl.
     * @param string $destinationPath Full local path where the file should be written.
     * @return bool True when the download completes successfully.
     * @throws RuntimeException When disabled, the URL is missing, or the download fails.
     */
    public function downloadVideoFile(array $searchResult, string $destinationPath): bool
    {
        if (!$this->isEasynewsEnabled()) {
            throw new RuntimeException('Easynews integration is disabled');
        }

        $directUrl = isset($searchResult['directUrl']) && is_scalar($searchResult['directUrl']) ? (string)$searchResult['directUrl'] : '';
        if ($directUrl === '') {
            throw new RuntimeException('Search result is missing directUrl');
        }

        $dir = dirname($destinationPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create destination directory: ' . $dir);
            }
        }

        $resumeOffset = is_file($destinationPath) ? ((int)filesize($destinationPath)) : 0;

        $auth = $this->buildAuthConfig();
        $status = $this->streamVideoDownload($directUrl, $destinationPath, $resumeOffset, $auth);

        if ($status === 416 && $resumeOffset > 0) {
            return true;
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Easynews video download failed (' . $status . ')');
        }

        return true;
    }

    /**
     * Stream a video download to disk.
     *
     * Handles resume by sending a Range header when $resumeOffset is greater
     * than zero. If the server responds with 200 instead of 206 while resuming,
     * the destination file is truncated and the full body is written.
     *
     * @param string $directUrl Fully qualified video URL.
     * @param string $destinationPath Local path to write.
     * @param int $resumeOffset Number of bytes already present at the destination.
     * @param array<string,mixed> $auth Auth config from buildAuthConfig().
     * @return int HTTP status code of the final response.
     * @throws RuntimeException On cURL or filesystem errors.
     */
    private function streamVideoDownload(string $directUrl, string $destinationPath, int $resumeOffset, array $auth): int
    {
        $ch = curl_init($directUrl);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $mode = $resumeOffset > 0 ? 'ab' : 'wb';
        $fp = fopen($destinationPath, $mode);
        if ($fp === false) {
            throw new RuntimeException('Failed to open destination file: ' . $destinationPath);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($this->videoDownloadTimeoutMs > 0) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->videoDownloadTimeoutMs);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min($this->videoDownloadTimeoutMs, 5000));
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        }
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, (int)ceil($this->downloadLowSpeedTimeMs / 1000));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, $this->downloadLowSpeedLimitBps);
        curl_setopt($ch, CURLOPT_USERAGENT, 'UsenetStreamer-Easynews/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['auth']['username'] . ':' . $auth['auth']['password']);

        $headers = [];
        if ($resumeOffset > 0) {
            $headers[] = 'Range: bytes=' . $resumeOffset . '-';
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $status = 0;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $header) use (&$status,
            &$fp,
            $destinationPath,
            $resumeOffset
        ): int {
            $len = strlen($header);
            $trimmed = trim($header);
            if (str_starts_with($trimmed, 'HTTP/')) {
                $parts = explode(' ', $trimmed, 3);
                $status = isset($parts[1]) ? (int)$parts[1] : 0;
                if ($resumeOffset > 0 && $status === 200) {
                    fclose($fp);
                    $fp = fopen($destinationPath, 'wb');
                    if ($fp === false) {
                        throw new RuntimeException('Failed to reopen destination file: ' . $destinationPath);
                    }
                }
            }
            return $len;
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $data) use (&$fp): int {
            if ($fp === false) {
                return 0;
            }
            $written = fwrite($fp, $data);
            return $written === false ? 0 : (int)$written;
        });

        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (is_resource($fp)) {
            fclose($fp);
        }

        if ($result === false && $error !== '') {
            throw new RuntimeException('Easynews video download failed: ' . $error);
        }

        return $status;
    }

    /**
     * Verify a set of EasyNews credentials with a sample query.
     *
     * Performs a lightweight search for "dune" using the supplied credentials
     * and reports whether results were returned.
     *
     * @param array<string,string> $creds Credentials with username and password.
     * @return string Human-readable verification message.
     * @throws RuntimeException When credentials are missing or rejected.
     */
    public function testEasynewsCredentials(array $creds = []): string
    {
        $username = trim((string)($creds['username'] ?? ''));
        $password = trim((string)($creds['password'] ?? ''));
        if ($username === '' || $password === '') {
            throw new RuntimeException('Easynews username and password are required');
        }
        $data = $this->fetchSearchResults('dune', ['username' => $username, 'password' => $password]);
        $total = 0;
        if (is_array($data['data'] ?? null)) {
            $total = count($data['data']);
        } elseif (isset($data['total']) && is_numeric($data['total'])) {
            $total = (int)$data['total'];
        }
        if ($total > 0) {
            return 'Easynews login verified (sample query returned ' . $total . ' result' . ($total === 1 ? '' : 's') . ')';
        }
        return 'Easynews login verified, but sample query returned no results';
    }
}
