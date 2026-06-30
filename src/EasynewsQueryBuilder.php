<?php

namespace EasyNewsDownloader\EasyNews;

/**
 * Build EasyNews search queries from structured metadata.
 *
 * Takes metadata such as titles, year, season, and episode information and
 * produces a prioritized list of search strings ready for EasynewsService.
 */
class EasynewsQueryBuilder
{
    /**
     * Build the list of EasyNews search queries for a stream request.
     *
     * Accepts structured metadata and returns an array with search queries,
     * fallback query, and strict-mode flags. Returns null when no usable
     * queries can be generated.
     *
     * @param array<string,mixed> $params Input metadata:
     *        - type: string "movie" or "series"
     *        - releaseYear: int|null release year
     *        - seasonNum: int|null season number
     *        - episodeNum: int|null episode number
     *        - tmdbTitles: array<int,array{title?:string,asciiTitle?:string,language?:string}>|null
     *        - isAnimeRequest: bool
     *        - animeSearchableTitles: array<int,array{title?:string,asciiTitle?:string}>|null
     *        - textQueryFallbackValue: string|null fallback raw query
     *        - movieTitle: string|null movie title
     *        - baseIdentifier: string|null last-resort identifier
     *        - isSpecialRequest: bool special/text-only request
     *        - specialMetadataTitle: string|null title used in special mode
     *        - requestLacksIdentifiers: bool force text-only behavior
     *        - strictMode: bool enable strict matching
     *        - normalizeToAscii: callable|null optional ASCII normalization callable
     * @return array<string,mixed>|null Search parameters, or null when empty.
     */
    public static function buildEasynewsSearchParams(array $params): ?array
    {
        $type = isset($params['type']) && is_scalar($params['type']) ? (string)$params['type'] : null;
        $releaseYear = $params['releaseYear'] ?? null;
        $seasonNum = $params['seasonNum'] ?? null;
        $episodeNum = $params['episodeNum'] ?? null;
        $tmdbTitles = $params['tmdbTitles'] ?? null;
        $isAnimeRequestRaw = $params['isAnimeRequest'] ?? false;
        $isAnimeRequest = is_bool($isAnimeRequestRaw) ? $isAnimeRequestRaw : EasynewsUtils::toBoolean($isAnimeRequestRaw, false);
        $animeSearchableTitles = $params['animeSearchableTitles'] ?? null;
        $textQueryFallbackValue = isset($params['textQueryFallbackValue']) && is_scalar($params['textQueryFallbackValue']) ? (string)$params['textQueryFallbackValue'] : null;
        $movieTitle = isset($params['movieTitle']) && is_scalar($params['movieTitle']) ? (string)$params['movieTitle'] : null;
        $baseIdentifier = isset($params['baseIdentifier']) && is_scalar($params['baseIdentifier']) ? (string)$params['baseIdentifier'] : null;
        $isSpecialRequestRaw = $params['isSpecialRequest'] ?? false;
        $isSpecialRequest = is_bool($isSpecialRequestRaw) ? $isSpecialRequestRaw : EasynewsUtils::toBoolean($isSpecialRequestRaw, false);
        $specialMetadataTitle = isset($params['specialMetadataTitle']) && is_scalar($params['specialMetadataTitle']) ? (string)$params['specialMetadataTitle'] : null;
        $requestLacksIdentifiersRaw = $params['requestLacksIdentifiers'] ?? false;
        $requestLacksIdentifiers = is_bool($requestLacksIdentifiersRaw) ? $requestLacksIdentifiersRaw : EasynewsUtils::toBoolean($requestLacksIdentifiersRaw, false);
        $strictModeRaw = $params['strictMode'] ?? false;
        $strictMode = is_bool($strictModeRaw) ? $strictModeRaw : EasynewsUtils::toBoolean($strictModeRaw, false);
        $normalizeToAscii = $params['normalizeToAscii'] ?? null;

        $seenKeys = [];
        $queries = [];
        $suffixCtx = [
            'type' => $type,
            'releaseYear' => $releaseYear,
            'seasonNum' => $seasonNum,
            'episodeNum' => $episodeNum,
        ];

        /**
         * Normalize and add a title to the query list when valid and unique.
         *
         * @param string|null $rawTitle Title to add.
         * @param bool $alreadyHasSuffix Whether the title already includes year/episode suffix.
         * @param string|null $originalTitle Original unmodified title for length checks.
         */
        $tryAdd = function (
            ?string $rawTitle,
            bool $alreadyHasSuffix = false,
            ?string $originalTitle = null
        ) use (
            &$seenKeys,
            &$queries,
            $suffixCtx,
            $normalizeToAscii
        ): void {
            if ($rawTitle === null || $rawTitle === '') {
                return;
            }
            $normalized = EasynewsUtils::foldAccents(trim($rawTitle));
            if ($normalizeToAscii !== null && is_callable($normalizeToAscii)) {
                $normalized = (string)($normalizeToAscii($normalized));
            } else {
                $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
            }
            if ($normalized === '') {
                return;
            }
            $original = preg_replace('/\s+/', '', (string)($originalTitle ?? $rawTitle));
            $originalString = is_string($original) ? $original : '';
            if ($originalString !== '' && strlen($normalized) / strlen($originalString) < 0.8) {
                return;
            }
            $normalized = EasynewsUtils::cleanSearchTitle($normalized);
            if ($normalized === '') {
                return;
            }
            $withSuffix = $alreadyHasSuffix
                ? trim($normalized)
                : self::appendEpisodeSuffix($normalized, $suffixCtx);
            if (!self::isValidAsciiQuery($withSuffix)) {
                return;
            }
            $key = strtolower($withSuffix);
            if (isset($seenKeys[$key])) {
                return;
            }
            $seenKeys[$key] = true;
            $queries[] = $withSuffix;
        };

        if ($isSpecialRequest) {
            $tryAdd($specialMetadataTitle, true);
            $tryAdd($movieTitle);
            $tryAdd($textQueryFallbackValue, true);
        } else {
            if (is_array($tmdbTitles) && count($tmdbTitles) > 0) {
                $sorted = $tmdbTitles;
                usort($sorted, static function ($a, $b) {
                    if (!is_array($a) || !is_array($b)) {
                        return 0;
                    }
                    $aLang = isset($a['language']) && is_scalar($a['language']) ? (string)$a['language'] : '';
                    $bLang = isset($b['language']) && is_scalar($b['language']) ? (string)$b['language'] : '';
                    $aEn = str_starts_with(strtolower($aLang), 'en') ? 0 : 1;
                    $bEn = str_starts_with(strtolower($bLang), 'en') ? 0 : 1;
                    return $aEn <=> $bEn;
                });
                foreach ($sorted as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $asciiTitle = isset($t['asciiTitle']) && is_scalar($t['asciiTitle'])
                        ? (string)$t['asciiTitle']
                        : (isset($t['title']) && is_scalar($t['title']) ? (string)$t['title'] : null);
                    $originalTitle = isset($t['title']) && is_scalar($t['title']) ? (string)$t['title'] : null;
                    $tryAdd($asciiTitle, false, $originalTitle);
                }
            }

            if ($isAnimeRequest && is_array($animeSearchableTitles) && count($animeSearchableTitles) > 0) {
                foreach ($animeSearchableTitles as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $asciiTitle = isset($t['asciiTitle']) && is_scalar($t['asciiTitle']) ? (string)$t['asciiTitle'] : null;
                    $originalTitle = isset($t['title']) && is_scalar($t['title']) ? (string)$t['title'] : $asciiTitle;
                    $tryAdd($asciiTitle, false, $originalTitle);
                }
            }

            $tryAdd($textQueryFallbackValue, true);

            if ($queries === []) {
                $tryAdd($movieTitle);
            }
        }

        if ($queries === [] && $baseIdentifier !== null && $baseIdentifier !== '') {
            $queries[] = $baseIdentifier;
        }

        if ($queries === []) {
            return null;
        }

        return [
            'queries' => $queries,
            'fallbackQuery' => (string)($textQueryFallbackValue ?? ($baseIdentifier ?? ($movieTitle ?? ''))),
            'year' => $releaseYear !== null && is_scalar($releaseYear) && is_numeric($releaseYear) ? (int)$releaseYear : null,
            'season' => $type === 'series' && $seasonNum !== null && is_scalar($seasonNum) && is_numeric($seasonNum) ? (int)$seasonNum : null,
            'episode' => $type === 'series' && $episodeNum !== null && is_scalar($episodeNum) && is_numeric($episodeNum) ? (int)$episodeNum : null,
            'strictMode' => (bool)$strictMode,
            'specialTextOnly' => (bool)($isSpecialRequest || $requestLacksIdentifiers),
        ];
    }

    /**
     * Append a year or season/episode suffix to a title based on context.
     *
     * @param string|null $title Base title.
     * @param array<string,mixed> $ctx Context with type, releaseYear, seasonNum, and episodeNum.
     * @return string Title with suffix, or an empty string when the title is empty.
     */
    private static function appendEpisodeSuffix(?string $title, array $ctx): string
    {
        if ($title === null || $title === '') {
            return '';
        }
        $type = $ctx['type'] ?? null;
        $releaseYear = $ctx['releaseYear'] ?? null;
        $seasonNum = $ctx['seasonNum'] ?? null;
        $episodeNum = $ctx['episodeNum'] ?? null;

        if ($type === 'movie' && $releaseYear !== null && is_scalar($releaseYear) && is_numeric($releaseYear)) {
            return $title . ' ' . (int)$releaseYear;
        }
        if ($type === 'series' && $seasonNum !== null && is_scalar($seasonNum) && is_numeric($seasonNum) && $episodeNum !== null && is_scalar($episodeNum) && is_numeric($episodeNum)) {
            return $title . ' S' . str_pad((string)(int)$seasonNum, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)(int)$episodeNum, 2, '0', STR_PAD_LEFT);
        }
        return $title;
    }

    /**
     * Validate that a query string is safe to send to the indexer.
     *
     * A valid query contains only ASCII characters, at least two letters,
     * and is not just a season/episode pattern or a four-digit year.
     *
     * @param string|null $str Query candidate.
     * @return bool True when the query is valid.
     */
    private static function isValidAsciiQuery(?string $str): bool
    {
        if ($str === null || $str === '') {
            return false;
        }
        if (preg_match('/[^\x00-\x7F]/', $str)) {
            return false;
        }
        if (preg_match('/^s\d{2}e\d{2}$/i', $str) || preg_match('/^\d{4}$/', $str)) {
            return false;
        }
        $letters = preg_replace('/[^a-zA-Z]/', '', $str);
        return is_string($letters) && strlen($letters) >= 2;
    }
}
