<?php

namespace EasyNewsDownloader\EasyNews\Tests;

use DateTime;
use EasyNewsDownloader\EasyNews\EasynewsUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyNewsDownloader\EasyNews\EasynewsUtils
 */
final class EasynewsUtilsTest extends TestCase
{
    public function testToBoolean(): void
    {
        $this->assertTrue(EasynewsUtils::toBoolean(true, false));
        $this->assertFalse(EasynewsUtils::toBoolean(false, true));
        $this->assertTrue(EasynewsUtils::toBoolean('true', false));
        $this->assertFalse(EasynewsUtils::toBoolean('false', true));
        $this->assertTrue(EasynewsUtils::toBoolean('yes', false));
        $this->assertFalse(EasynewsUtils::toBoolean('no', true));
        $this->assertTrue(EasynewsUtils::toBoolean('1', false));
        $this->assertFalse(EasynewsUtils::toBoolean('0', true));
        $this->assertTrue(EasynewsUtils::toBoolean(null, true));
        $this->assertFalse(EasynewsUtils::toBoolean(null, false));
        $this->assertTrue(EasynewsUtils::toBoolean('invalid', true));
    }

    public function testStripTrailingSlashes(): void
    {
        $this->assertSame('https://example.com', EasynewsUtils::stripTrailingSlashes('https://example.com/'));
        $this->assertSame('https://example.com', EasynewsUtils::stripTrailingSlashes('https://example.com///'));
        $this->assertSame('', EasynewsUtils::stripTrailingSlashes('/'));
        $this->assertSame('https://example.com/path', EasynewsUtils::stripTrailingSlashes('https://example.com/path'));
    }

    public function testFoldAccents(): void
    {
        $this->assertSame('', EasynewsUtils::foldAccents(null));
        $this->assertSame('', EasynewsUtils::foldAccents(''));
        $this->assertSame('Cafe', EasynewsUtils::foldAccents('Café'));
        $this->assertSame('Ueber', EasynewsUtils::foldAccents('Über'));
        $this->assertSame('Ae', EasynewsUtils::foldAccents('Ä'));
        $this->assertSame('ss', EasynewsUtils::foldAccents('ß'));
    }

    public function testCleanSearchTitle(): void
    {
        $this->assertSame('', EasynewsUtils::cleanSearchTitle(null));
        $this->assertSame('', EasynewsUtils::cleanSearchTitle(''));
        $this->assertSame('Tom and Jerrys', EasynewsUtils::cleanSearchTitle("Tom & Jerry's"));
        $this->assertSame('Dune 2024 2160p BluRay', EasynewsUtils::cleanSearchTitle('Dune.2024.2160p.BluRay'));
    }

    public function testSanitizeStrictSearchPhrase(): void
    {
        $this->assertSame('', EasynewsUtils::sanitizeStrictSearchPhrase(null));
        $this->assertSame('', EasynewsUtils::sanitizeStrictSearchPhrase(''));
        $this->assertSame('tom and jerry', EasynewsUtils::sanitizeStrictSearchPhrase('Tom & Jerry'));
        $this->assertSame('dune 2024 2160p', EasynewsUtils::sanitizeStrictSearchPhrase('Dune.2024.2160p'));
        $this->assertSame('tom and jerry', EasynewsUtils::sanitizeStrictSearchPhrase('Tom  &  Jerry'));
    }

    public function testNormaliseTitle(): void
    {
        $this->assertSame('', EasynewsUtils::normaliseTitle(null));
        $this->assertSame('tomandjerry', EasynewsUtils::normaliseTitle('Tom & Jerry'));
        $this->assertSame('dune2024', EasynewsUtils::normaliseTitle('Dune 2024!'));
    }

    public function testLevenshteinRatio(): void
    {
        $this->assertSame(1.0, EasynewsUtils::levenshteinRatio('', ''));
        $this->assertSame(1.0, EasynewsUtils::levenshteinRatio('dune', 'dune'));
        $this->assertGreaterThan(0.5, EasynewsUtils::levenshteinRatio('dune', 'dunes'));
        $this->assertLessThan(0.5, EasynewsUtils::levenshteinRatio('dune', 'arrakis'));
    }

    public function testTitleSimilarityCheck(): void
    {
        $this->assertTrue(EasynewsUtils::titleSimilarityCheck(null, 'dune'));
        $this->assertTrue(EasynewsUtils::titleSimilarityCheck('dune', null));
        $this->assertTrue(EasynewsUtils::titleSimilarityCheck('Dune', 'Dune'));
        $this->assertTrue(EasynewsUtils::titleSimilarityCheck('The Matrix', 'The Matrx'));
        $this->assertTrue(EasynewsUtils::titleSimilarityCheck('The Matrix Reloaded', 'The Matrix Relaoded'));
        $this->assertFalse(EasynewsUtils::titleSimilarityCheck('Dune', 'Dunes'));
        $this->assertFalse(EasynewsUtils::titleSimilarityCheck('Dune 2024', 'Dune'));
        $this->assertFalse(EasynewsUtils::titleSimilarityCheck('Dune', 'Arrakis'));
    }

    public function testCoerceDate(): void
    {
        $this->assertNull(EasynewsUtils::coerceDate(null));
        $this->assertNull(EasynewsUtils::coerceDate(''));
        $this->assertNull(EasynewsUtils::coerceDate(0));

        $date = new DateTime('2024-01-15');
        $this->assertSame($date->getTimestamp(), EasynewsUtils::coerceDate($date)->getTimestamp());

        $immutable = new \DateTimeImmutable('2024-01-15');
        $this->assertSame($immutable->getTimestamp(), EasynewsUtils::coerceDate($immutable)->getTimestamp());

        $numeric = EasynewsUtils::coerceDate(1705276800);
        $this->assertInstanceOf(DateTime::class, $numeric);
        $this->assertSame('2024-01-15', $numeric->format('Y-m-d'));

        $milliseconds = EasynewsUtils::coerceDate(1705276800000);
        $this->assertInstanceOf(DateTime::class, $milliseconds);
        $this->assertSame('2024-01-15', $milliseconds->format('Y-m-d'));

        $string = EasynewsUtils::coerceDate('2024-01-15');
        $this->assertInstanceOf(DateTime::class, $string);
        $this->assertSame('2024-01-15', $string->format('Y-m-d'));

        $digit = EasynewsUtils::coerceDate('1705276800');
        $this->assertInstanceOf(DateTime::class, $digit);
        $this->assertSame('2024-01-15', $digit->format('Y-m-d'));

        $this->assertNull(EasynewsUtils::coerceDate('not a date'));
    }

    public function testParseDurationSeconds(): void
    {
        $this->assertNull(EasynewsUtils::parseDurationSeconds(null));
        $this->assertNull(EasynewsUtils::parseDurationSeconds(''));
        $this->assertSame(125, EasynewsUtils::parseDurationSeconds(125));
        $this->assertSame(125, EasynewsUtils::parseDurationSeconds('125'));
        $this->assertSame(5415, EasynewsUtils::parseDurationSeconds('1h30m15s'));
        $this->assertSame(5415, EasynewsUtils::parseDurationSeconds('1H 30M 15S'));
        $this->assertSame(90, EasynewsUtils::parseDurationSeconds('1:30'));
        $this->assertSame(3661, EasynewsUtils::parseDurationSeconds('1:01:01'));
        $this->assertNull(EasynewsUtils::parseDurationSeconds('not a duration'));
    }

    public function testIsListArray(): void
    {
        $this->assertTrue(EasynewsUtils::isListArray([]));
        $this->assertTrue(EasynewsUtils::isListArray(['a', 'b', 'c']));
        $this->assertFalse(EasynewsUtils::isListArray(['a' => 'b']));
        $this->assertFalse(EasynewsUtils::isListArray([1 => 'a']));
    }
}
