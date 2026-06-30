<?php

namespace EasyNewsDownloader\EasyNews\Tests;

use EasyNewsDownloader\EasyNews\EasynewsQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyNewsDownloader\EasyNews\EasynewsQueryBuilder
 */
final class EasynewsQueryBuilderTest extends TestCase
{
    public function testMovieQueryAppendsYear(): void
    {
        $params = [
            'type' => 'movie',
            'movieTitle' => 'Dune',
            'releaseYear' => 2024,
            'textQueryFallbackValue' => 'Dune',
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        // movieTitle is used only as a fallback and is not suffixed; the
        // textQueryFallbackValue receives the year suffix.
        $this->assertSame(['Dune'], $result['queries']);
        $this->assertSame('Dune', $result['fallbackQuery']);
        $this->assertSame(2024, $result['year']);
        $this->assertNull($result['season']);
        $this->assertNull($result['episode']);
        $this->assertFalse($result['strictMode']);
        $this->assertFalse($result['specialTextOnly']);
    }

    public function testSeriesQueryAppendsSeasonEpisode(): void
    {
        $params = [
            'type' => 'series',
            'tmdbTitles' => [
                ['title' => 'Severance', 'asciiTitle' => 'Severance', 'language' => 'en'],
            ],
            'releaseYear' => 2022,
            'seasonNum' => 1,
            'episodeNum' => 5,
            'textQueryFallbackValue' => 'Severance',
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        // tmdbTitles receive the season/episode suffix; textQueryFallbackValue
        // is added separately without a suffix.
        $this->assertSame(['Severance S01E05', 'Severance'], $result['queries']);
        $this->assertSame(2022, $result['year']);
        $this->assertSame(1, $result['season']);
        $this->assertSame(5, $result['episode']);
    }

    public function testEnglishTitlesArePreferred(): void
    {
        $params = [
            'type' => 'movie',
            'tmdbTitles' => [
                ['title' => '外国标题', 'asciiTitle' => '外国标题', 'language' => 'zh'],
                ['title' => 'Foreign Title', 'asciiTitle' => 'Foreign Title', 'language' => 'en'],
            ],
            'releaseYear' => 2020,
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        $this->assertSame('Foreign Title 2020', $result['queries'][0]);
    }

    public function testSpecialRequestUsesSpecialTitle(): void
    {
        $params = [
            'type' => 'series',
            'isSpecialRequest' => true,
            'specialMetadataTitle' => 'Holiday Special',
            'movieTitle' => 'Ignored Movie',
            'textQueryFallbackValue' => 'Fallback',
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        $this->assertContains('Holiday Special', $result['queries']);
        $this->assertTrue($result['specialTextOnly']);
    }

    public function testAnimeTitlesIncludedWhenRequested(): void
    {
        $params = [
            'type' => 'series',
            'isAnimeRequest' => true,
            'animeSearchableTitles' => [
                ['title' => 'Attack on Titan', 'asciiTitle' => 'Attack on Titan'],
            ],
            'seasonNum' => 1,
            'episodeNum' => 1,
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        $this->assertContains('Attack on Titan S01E01', $result['queries']);
    }

    public function testBaseIdentifierUsedAsLastResort(): void
    {
        $params = [
            'type' => 'movie',
            'baseIdentifier' => 'tt1160419',
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        $this->assertSame(['tt1160419'], $result['queries']);
        $this->assertSame('tt1160419', $result['fallbackQuery']);
    }

    public function testReturnsNullWhenNoQueriesCanBeBuilt(): void
    {
        $params = [
            'type' => 'movie',
        ];

        $this->assertNull(EasynewsQueryBuilder::buildEasynewsSearchParams($params));
    }

    public function testStrictModePropagated(): void
    {
        $params = [
            'type' => 'movie',
            'movieTitle' => 'Dune',
            'strictMode' => true,
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        $this->assertTrue($result['strictMode']);
    }

    public function testInvalidAsciiQueryIsRejected(): void
    {
        $params = [
            'type' => 'movie',
            'movieTitle' => '2024',
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNull($result);
    }

    public function testDuplicateQueriesAreDeduplicated(): void
    {
        $params = [
            'type' => 'movie',
            'movieTitle' => 'Dune',
            'textQueryFallbackValue' => 'Dune',
            'releaseYear' => 2024,
        ];

        $result = EasynewsQueryBuilder::buildEasynewsSearchParams($params);

        $this->assertNotNull($result);
        // movieTitle is used only as a fallback and is not suffixed, so the
        // duplicate is collapsed to a single unsuffixed query.
        $this->assertSame(['Dune'], $result['queries']);
    }
}
