# easynewsdownloader/easynews

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4?logo=php&logoColor=white)](https://php.net/)
[![Latest Stable Version](https://img.shields.io/packagist/v/easynewsdownloader/easynews.svg)](https://packagist.org/packages/easynewsdownloader/easynews)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-9-assertive.svg)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

PHP library for the EasyNews search and NZB download service.

This library provides a PSR-4, PHP 8.0+ wrapper around the EasyNews members API for searching Usenet video releases and downloading NZB and video files.

Source repository: https://github.com/btafoya/easynewsdownloader

## Requirements

- PHP `>= 8.0`
- `ext-curl`
- `ext-intl`

## Installation

```bash
composer require easynewsdownloader/easynews
```

## Configuration

The library reads configuration from environment variables by default. You may also pass an explicit config array to the constructor.

### Environment variables

| Variable | Description | Default |
|----------|-------------|---------|
| `EASYNEWS_ENABLED` | Enable or disable the integration | `false` |
| `EASYNEWS_USERNAME` | EasyNews account username | — |
| `EASYNEWS_PASSWORD` | EasyNews account password | — |
| `EASYNEWS_MIN_SIZE_MB` | Minimum release size in MB | `100` |
| `EASYNEWS_TEXT_MODE_ONLY` | Disable strict metadata matching | `false` |
| `ADDON_BASE_URL` | Base URL used for generated download links | — |
| `EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS` | Absolute cURL timeout for video downloads in milliseconds. `0` disables the absolute timeout. | `0` |
| `EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS` | Window for low-speed detection in milliseconds | `60000` |
| `EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS` | Minimum acceptable transfer speed in bytes per second during the low-speed window | `10240` |

You can also pass a shared array of values as the first argument to the constructor:

```php
$service = new EasyNewsDownloader\EasyNews\EasynewsService([
    'EASYNEWS_ENABLED'    => 'true',
    'EASYNEWS_USERNAME'   => 'myuser',
    'EASYNEWS_PASSWORD'   => 'mypass',
    'EASYNEWS_MIN_SIZE_MB'=> '200',
], [
    'sharedSecret' => 'my-secret',
]);
```

The second constructor argument is an optional runtime config array:

| Key | Description |
|-----|-------------|
| `addonBaseUrl` | Override `ADDON_BASE_URL` |
| `sharedSecret` | Secret segment added to generated download URLs |
| `videoDownloadTimeoutMs` | Override `EASYNEWS_VIDEO_DOWNLOAD_TIMEOUT_MS` |
| `downloadLowSpeedTimeMs` | Override `EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS` |
| `downloadLowSpeedLimitBps` | Override `EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS` |

## Quick start

```php
require 'vendor/autoload.php';

use EasyNewsDownloader\EasyNews\EasynewsService;

$service = new EasynewsService();

$results = $service->searchEasynews([
    'rawQuery' => 'Dune 2024',
]);

foreach ($results as $result) {
    echo $result['title'] . ' - ' . $result['directUrl'] . PHP_EOL;
}
```

## Searching

`EasynewsService::searchEasynews()` accepts an options array and returns a list of normalized results.

### Options

| Option | Type | Description |
|--------|------|-------------|
| `rawQuery` | string | Primary search query |
| `fallbackQuery` | string | Query to use if `rawQuery` is empty |
| `year` | int | Expected release year |
| `season` | int | Expected season number |
| `episode` | int | Expected episode number |
| `strictMode` | bool | Enable strict metadata matching |
| `specialTextOnly` | bool | Treat the request as a text-only special request |

### Returned result fields

Each result is an associative array:

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Release title |
| `downloadUrl` | string | URL that can be used to download the NZB |
| `guid` | string | Unique identifier |
| `indexer` | string | Always `Easynews` |
| `indexerId` | string | Always `easynews` |
| `size` | int | Size in bytes |
| `pubDate` | string\|null | ISO 8601 post date |
| `publishDateMs` | int | Post date as milliseconds since epoch |
| `ageDays` | int | Age in days |
| `release` | array | Contains `resolution` and `languages` |
| `poster` | string\|null | Usenet poster |
| `easynewsPayload` | string | Base64-encoded payload used for NZB download |
| `directUrl` | string | Direct EasyNews video download URL |
| `_sourceType` | string | Always `easynews` |
| `group` | string\|null | Usenet group |

## Building search queries

For applications that start with metadata rather than a raw query string, use `EasynewsQueryBuilder::buildEasynewsSearchParams()`. It converts structured metadata into a set of search strings.

```php
use EasyNewsDownloader\EasyNews\EasynewsQueryBuilder;

$params = EasynewsQueryBuilder::buildEasynewsSearchParams([
    'type'                => 'series',
    'tmdbTitles'          => [
        ['title' => 'Severance', 'asciiTitle' => 'Severance', 'language' => 'en'],
    ],
    'releaseYear'         => 2022,
    'seasonNum'           => 1,
    'episodeNum'          => 1,
    'textQueryFallbackValue' => 'Severance',
    'strictMode'          => true,
]);

// $params['queries']  => ['Severance S01E01']
```

### Query builder input

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `movie` or `series` |
| `releaseYear` | int\|null | Release year |
| `seasonNum` | int\|null | Season number |
| `episodeNum` | int\|null | Episode number |
| `tmdbTitles` | array | Array of title objects with `title`, `asciiTitle`, and `language` |
| `isAnimeRequest` | bool | Whether to include anime titles |
| `animeSearchableTitles` | array | Anime title objects |
| `textQueryFallbackValue` | string\|null | Fallback raw query |
| `movieTitle` | string\|null | Movie title |
| `baseIdentifier` | string\|null | Last-resort identifier |
| `isSpecialRequest` | bool | Special/text-only mode |
| `specialMetadataTitle` | string\|null | Title to use in special mode |
| `requestLacksIdentifiers` | bool | Force text-only behavior |
| `strictMode` | bool | Enable strict matching |
| `normalizeToAscii` | callable\|null | Optional callable for ASCII normalization |

### Query builder output

Returns `null` when no usable queries can be built, otherwise an array with:

| Key | Description |
|-----|-------------|
| `queries` | Array of search strings |
| `fallbackQuery` | Query to use as a fallback |
| `year` | Parsed year |
| `season` | Parsed season |
| `episode` | Parsed episode |
| `strictMode` | Strict mode flag |
| `specialTextOnly` | Text-only flag |

## Downloading NZBs

Use a result's `easynewsPayload` with `downloadEasynewsNzb()`:

```php
$result = $results[0] ?? null;
if ($result !== null) {
    $nzb = $service->downloadEasynewsNzb($result['easynewsPayload']);

    file_put_contents($nzb['fileName'], $nzb['buffer']);
}
```

The returned array contains:

| Field | Description |
|-------|-------------|
| `buffer` | The NZB contents |
| `fileName` | Suggested file name |
| `contentType` | Content type header |

## Downloading video files

Each search result includes a `directUrl` that points to the raw video file on EasyNews. Use `downloadVideoFile()` to stream it straight to disk. The method resumes partial files, creates missing parent directories, and throws `RuntimeException` on failure.

```php
$result = $results[0] ?? null;
if ($result !== null) {
    $service->downloadVideoFile($result, '/downloads/movie.mp4');
}
```

Behavior:

- Streams directly to disk; large files are not loaded into memory.
- Sends an HTTP `Range` request when the destination already exists so partial downloads resume.
- Auto-creates the destination directory tree.
- Returns `true` on success, throws `RuntimeException` on errors.
- By default there is **no absolute timeout** for video transfers, so large or slow downloads are allowed to run to completion as long as data keeps moving.
- Stalled transfers are aborted using cURL low-speed detection: if the average speed drops below `EASYNEWS_DOWNLOAD_LOW_SPEED_LIMIT_BPS` for `EASYNEWS_DOWNLOAD_LOW_SPEED_TIME_MS`, cURL kills the connection. Defaults are 10 KB/s over a 60-second window.

## Helper scripts

The `scripts/` directory contains convenience scripts for manual testing:

- `scripts/easynews_search.sh` — Run an EasyNews search and print JSON results. Reads credentials from `.env`.

  ```bash
  ./scripts/easynews_search.sh 'Dune+2024+2160p'
  ```

- `scripts/search_and_download.php` — Search for a specific release and download the first result to `/tmp/`. Reads credentials from `.env`.

  ```bash
  ./scripts/search_and_download.php
  ```

## Credential check

```php
echo $service->testEasynewsCredentials([
    'username' => 'myuser',
    'password' => 'mypass',
]);
```

## Utilities

`EasynewsUtils` exposes the stateless helpers used by the service and query builder.

```php
use EasyNewsDownloader\EasyNews\EasynewsUtils;

$ascii  = EasynewsUtils::foldAccents('Über');     // Ueber
$clean  = EasynewsUtils::cleanSearchTitle('Café'); // Cafe
$bool   = EasynewsUtils::toBoolean('yes', false);   // true
$date   = EasynewsUtils::coerceDate('2024-01-01');
$seconds = EasynewsUtils::parseDurationSeconds('1h30m'); // 5400
```

## Notes

- The service only returns results when `EASYNEWS_ENABLED` is truthy and both username and password are set.
- Strict mode performs title, year, season, and episode validation against parsed release metadata.
- The `downloadUrl` field assumes a downstream endpoint that accepts `easynewsPayload` and calls `downloadEasynewsNzb()`.
- The `directUrl` field is the raw EasyNews video URL and can be used directly with `downloadVideoFile()`.

## License

This project is released under the MIT License.
