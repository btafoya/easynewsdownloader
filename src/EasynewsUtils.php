<?php

namespace EasyNewsDownloader\EasyNews;

use DateTime;
use DateTimeImmutable;
use Exception;
use Normalizer;

/**
 * Stateless string, date, and normalization utilities.
 *
 * Shared helpers used by EasynewsService and EasynewsQueryBuilder for
 * title normalization, duration parsing, date coercion, and array
 * inspection.
 */
class EasynewsUtils
{
    /** @var float Similarity threshold used by titleSimilarityCheck. */
    public const TITLE_SIMILARITY_THRESHOLD = 0.85;

    /** @var array<string,string> Manual umlaut to ASCII mappings. */
    private const UMLAUT_MAP = [
        'Ä' => 'Ae', 'ä' => 'ae',
        'Ö' => 'Oe', 'ö' => 'oe',
        'Ü' => 'Ue', 'ü' => 'ue',
        'ß' => 'ss',
    ];

    /**
     * Convert common truthy/falsy strings to a boolean.
     *
     * Recognizes values such as "true", "false", "yes", "no", "on", "off",
     * "1", "0" via filter_var(). Returns $default when $value cannot be
     * interpreted as a boolean.
     *
     * @param mixed $value Value to coerce.
     * @param bool $default Fallback when coercion fails or $value is null.
     * @return bool Coerced boolean value.
     */
    public static function toBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return $default;
        }
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $bool ?? $default;
    }

    /**
     * Remove trailing slashes from a URL or path string.
     *
     * @param string $raw Input URL or path.
     * @return string Trimmed string without a trailing slash.
     */
    public static function stripTrailingSlashes(string $raw): string
    {
        return rtrim($raw, '/');
    }

    /**
     * Fold accented and umlaut characters to ASCII.
     *
     * Example: Café becomes Cafe, Über becomes Ueber.
     *
     * @param string|null $text Input text.
     * @return string ASCII-folded string. Null input returns an empty string.
     */
    public static function foldAccents(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $step1 = strtr($text, self::UMLAUT_MAP);
        $step2 = Normalizer::normalize($step1, Normalizer::FORM_D);
        if ($step2 === false) {
            return $step1;
        }
        return preg_replace('/[\x{0300}-\x{036F}]/u', '', $step2) ?? $step1;
    }

    /**
     * Turn a human-readable title into an indexer-ready token string.
     *
     * Folds accents, replaces ampersands with "and", strips punctuation,
     * collapses whitespace, and trims the result.
     *
     * @param string|null $title Input title.
     * @return string Cleaned token string.
     */
    public static function cleanSearchTitle(?string $title): string
    {
        if ($title === null || $title === '') {
            return '';
        }
        $folded = self::foldAccents($title);
        $noApostrophes = preg_replace('/[\'\x{2018}\x{2019}\x{02BC}]/u', '', $folded) ?? $folded;
        $ampersand = str_replace('&', ' and ', (string)$noApostrophes);
        $punctToSpace = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $ampersand) ?? $ampersand;
        $collapsed = preg_replace('/\s+/u', ' ', (string)$punctToSpace) ?? $punctToSpace;
        return trim((string)$collapsed);
    }

    /**
     * Sanitize a phrase for strict token-boundary matching.
     *
     * Accents are folded, ampersands become "and", separators are replaced
     * with spaces, and non-alphanumeric characters are removed. The result is
     * lowercased and trimmed.
     *
     * @param string|null $text Input phrase.
     * @return string Sanitized, lowercased phrase.
     */
    public static function sanitizeStrictSearchPhrase(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $working = self::foldAccents($text);
        $working = str_replace('&', ' and ', $working);
        $working = preg_replace('/[\.\-_:\/\\\\]+/u', ' ', $working) ?? $working;
        $working = preg_replace('/[^\w\s]/u', '', $working) ?? $working;
        $working = preg_replace('/\s+/u', ' ', $working) ?? $working;
        return strtolower(trim($working));
    }

    /**
     * Strip all non-alphanumeric characters and lowercase the result.
     *
     * Useful for producing a comparable "fingerprint" of a title.
     *
     * @param string|null $text Input text.
     * @return string Normalized, lowercased string containing only letters and digits.
     */
    public static function normaliseTitle(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $folded = self::foldAccents(str_replace('&', 'and', $text));
        $stripped = preg_replace('/[^\p{L}\p{N}]/u', '', $folded);
        return strtolower(trim($stripped ?? ''));
    }

    /**
     * Calculate a similarity ratio from the Levenshtein distance.
     *
     * Returns a value between 0.0 and 1.0, where 1.0 means identical strings.
     * Empty strings are considered identical.
     *
     * @param string $a First string.
     * @param string $b Second string.
     * @return float Similarity ratio.
     */
    public static function levenshteinRatio(string $a, string $b): float
    {
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }
        $dist = levenshtein($a, $b);
        if ($dist < 0) {
            return 0.0;
        }
        return 1.0 - ($dist / $maxLen);
    }

    /**
     * Decide whether a candidate title is similar enough to a query title.
     *
     * Missing values are treated as a pass. Otherwise the normalized titles
     * are compared; if not equal, the Levenshtein ratio must be at least
     * TITLE_SIMILARITY_THRESHOLD.
     *
     * @param string|null $candidateParsedTitle Title parsed from a release.
     * @param string|null $queryParsedTitle Title derived from the search query.
     * @return bool True when the titles match or are sufficiently similar.
     */
    public static function titleSimilarityCheck(?string $candidateParsedTitle, ?string $queryParsedTitle): bool
    {
        if (!$candidateParsedTitle || !$queryParsedTitle) {
            return true;
        }
        $normCandidate = self::normaliseTitle($candidateParsedTitle);
        $normQuery = self::normaliseTitle($queryParsedTitle);
        if ($normCandidate === '' || $normQuery === '') {
            return true;
        }
        if ($normCandidate === $normQuery) {
            return true;
        }
        return self::levenshteinRatio($normCandidate, $normQuery) >= self::TITLE_SIMILARITY_THRESHOLD;
    }

    /**
     * Coerce a scalar value into a DateTime instance.
     *
     * Accepts DateTime, DateTimeImmutable, numeric timestamps (seconds or
     * milliseconds), and date strings. Numeric values larger than 1e12 are
     * treated as milliseconds and divided by 1000.
     *
     * @param mixed $value Value to coerce.
     * @return DateTime|null DateTime on success, null on failure.
     */
    public static function coerceDate(mixed $value): ?DateTime
    {
        if ($value === null || $value === '' || $value === false || $value === 0) {
            return null;
        }
        if ($value instanceof DateTime) {
            return $value;
        }
        if ($value instanceof DateTimeImmutable) {
            return new DateTime($value->format('c'));
        }
        if (is_numeric($value) && is_finite((float)$value)) {
            $asNumber = (float)$value;
            $ts = $asNumber > 1e12 ? (int)($asNumber / 1000) : (int)$asNumber;
            return new DateTime('@' . $ts);
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                return null;
            }
            if (ctype_digit($text)) {
                $asNumber = (int)$text;
                $ts = $asNumber > 1e12 ? (int)($asNumber / 1000) : $asNumber;
                return new DateTime('@' . $ts);
            }
            try {
                return new DateTime($text);
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Parse a duration value into seconds.
     *
     * Supports numeric seconds, "1h30m15s" style strings, and "HH:MM:SS"
     * or "MM:SS" time strings.
     *
     * @param mixed $raw Raw duration value.
     * @return int|null Duration in seconds, or null when parsing fails.
     */
    public static function parseDurationSeconds(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw) && is_finite((float)$raw) && (float)$raw > 0) {
            return (int)floor((float)$raw);
        }
        $text = strtolower(trim((string)(is_scalar($raw) ? $raw : json_encode($raw))));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $text)) {
            return (int)$text;
        }
        $total = 0;
        $matched = false;
        if (preg_match_all('/(\d+)\s*([hms])/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $matched = true;
                $value = (int)$match[1];
                switch (strtolower($match[2])) {
                    case 'h':
                        $total += $value * 3600;
                        break;
                    case 'm':
                        $total += $value * 60;
                        break;
                    case 's':
                        $total += $value;
                        break;
                }
            }
        }
        if ($matched && $total > 0) {
            return $total;
        }
        if (str_contains($text, ':')) {
            $parts = array_map('intval', explode(':', $text));
            $allFinite = true;
            foreach ($parts as $part) {
                if (!is_finite($part)) {
                    $allFinite = false;
                    break;
                }
            }
            if ($allFinite) {
                if (count($parts) === 3) {
                    return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
                }
                if (count($parts) === 2) {
                    return $parts[0] * 60 + $parts[1];
                }
            }
        }
        return null;
    }

    /**
     * Returns true when the array is a list (sequential integer keys from 0).
     *
     * @param array<mixed> $arr Array to inspect.
     * @return bool True for empty arrays and zero-indexed lists.
     */
    public static function isListArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
