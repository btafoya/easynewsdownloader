<?php

namespace EasyNewsDownloader\EasyNews\Tests;

use EasyNewsDownloader\EasyNews\EasynewsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \EasyNewsDownloader\EasyNews\EasynewsService
 */
final class EasynewsServiceTest extends TestCase
{
    private function createService(): EasynewsService
    {
        return new EasynewsService([
            'EASYNEWS_ENABLED' => 'true',
            'EASYNEWS_USERNAME' => 'testuser',
            'EASYNEWS_PASSWORD' => 'testpass',
        ]);
    }

    public function testDisabledWhenCredentialsMissing(): void
    {
        $service = new EasynewsService([
            'EASYNEWS_ENABLED' => 'true',
            'EASYNEWS_USERNAME' => '',
            'EASYNEWS_PASSWORD' => '',
        ]);

        $this->assertFalse($service->isEasynewsEnabled());
        $this->assertSame([], $service->searchEasynews(['rawQuery' => 'Dune']));
    }

    public function testDisabledWhenNotEnabled(): void
    {
        $service = new EasynewsService([
            'EASYNEWS_ENABLED' => 'false',
            'EASYNEWS_USERNAME' => 'testuser',
            'EASYNEWS_PASSWORD' => 'testpass',
        ]);

        $this->assertFalse($service->isEasynewsEnabled());
    }

    public function testEnabledWhenConfigured(): void
    {
        $service = $this->createService();
        $this->assertTrue($service->isEasynewsEnabled());
    }

    public function testRequiresCinemetaMetadata(): void
    {
        $service = $this->createService();
        $this->assertTrue($service->requiresCinemetaMetadata(false));
        $this->assertFalse($service->requiresCinemetaMetadata(true));
    }

    public function testEncodePayloadIsUrlSafeAndReversible(): void
    {
        $service = $this->createService();
        $payload = [
            'hash' => 'abc123',
            'filename' => 'Dune.2024.2160p.BluRay',
            'ext' => '.mkv',
            'sig' => null,
            'title' => 'Dune 2024',
        ];

        $encoded = $service->encodePayload($payload);
        $this->assertSame($payload, $service->decodePayload($encoded));
    }

    public function testDecodePayloadReturnsNullForInvalidToken(): void
    {
        $service = $this->createService();
        $this->assertNull($service->decodePayload(null));
        $this->assertNull($service->decodePayload(''));
        $this->assertNull($service->decodePayload('not-valid-base64!!!'));
    }

    public function testBuildResultViaReflection(): void
    {
        $service = $this->createService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildResult');
        $method->setAccessible(true);

        $rawItem = [
            'hash' => 'abc123',
            'fileId' => '49b7',
            'filename' => 'Dune.2024.2160p',
            'ext' => '.mkv',
            'title' => 'Dune 2024',
            'size' => 5000000000,
            'poster' => 'poster@example.com',
            'posted' => new \DateTime('2024-01-15 12:00:00'),
            'group' => 'alt.binaries.example',
            'quality' => '4k',
            'downUrlBase' => 'https://members.easynews.com/dl',
            'dlFarm' => 'auto',
            'dlPort' => 443,
            'sid' => 'session-id-123',
            'sig' => 'signature-value',
        ];

        $result = $method->invoke($service, $rawItem);

        $this->assertSame('Dune 2024', $result['title']);
        $this->assertStringStartsWith('http://127.0.0.1:', $result['downloadUrl']);
        $this->assertStringContainsString('easynews/nzb?payload=', $result['downloadUrl']);
        $this->assertSame('https://members.easynews.com/dl/auto/443/abc12349b7.mkv/Dune.2024.2160p.mkv?sid=session-id-123&sig=signature-value', $result['directUrl']);
        $this->assertSame('easynews-abc123', $result['guid']);
        $this->assertSame('Easynews', $result['indexer']);
        $this->assertSame('easynews', $result['indexerId']);
        $this->assertSame(5000000000, $result['size']);
        $this->assertSame('2024-01-15T12:00:00', substr($result['pubDate'], 0, 19));
        $this->assertSame('easynews', $result['_sourceType']);
        $this->assertSame('4k', $result['release']['resolution']);
    }

    public function testParseTorrentTitleViaReflection(): void
    {
        $service = $this->createService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('parseTorrentTitle');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'Dune.2024.2160p.BluRay.REMUX.mkv');

        $this->assertNotNull($result);
        $this->assertSame('Dune', $result['title']);
        $this->assertSame(2024, $result['year']);
        $this->assertSame('4k', $result['resolution']);
        $this->assertSame('mkv', $result['container']);
    }

    public function testParseTorrentTitleSeriesViaReflection(): void
    {
        $service = $this->createService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('parseTorrentTitle');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'Severance.S01E05.1080p.WEB-DL.mkv');

        $this->assertNotNull($result);
        $this->assertSame('Severance', $result['title']);
        $this->assertSame([1], $result['seasons']);
        $this->assertSame([5], $result['episodes']);
        $this->assertSame('1080p', $result['resolution']);
    }

    public function testBuildTitleViaReflection(): void
    {
        $service = $this->createService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildTitle');
        $method->setAccessible(true);

        $this->assertSame('Dune.2024.2160p.mkv', $method->invoke($service, [
            'displayFn' => 'Dune 2024 2160p',
            'ext' => '.mkv',
        ]));

        $this->assertSame('subject title', $method->invoke($service, [
            'subject' => 'subject title',
        ]));

        $this->assertSame('Untitled', $method->invoke($service, []));
    }

    public function testResolveDownloadBaseUsesLocalhostFallback(): void
    {
        $service = $this->createService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveDownloadBase');
        $method->setAccessible(true);

        $this->assertStringStartsWith('http://127.0.0.1:', $method->invoke($service, ''));
    }

    /** @var string|null */
    private static $mockServerUrl = null;

    /** @var resource|null */
    private static $mockServerProc = null;

    public static function setUpBeforeClass(): void
    {
        $port = self::findFreePort();
        $router = __DIR__ . '/mock_video_server.php';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        self::$mockServerProc = proc_open(
            'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router),
            $descriptors,
            $pipes
        );
        if (self::$mockServerProc === false) {
            throw new \RuntimeException('Failed to start mock video server');
        }
        self::$mockServerUrl = 'http://127.0.0.1:' . $port;
        self::waitForServer(self::$mockServerUrl);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$mockServerProc !== null) {
            proc_terminate(self::$mockServerProc);
            proc_close(self::$mockServerProc);
        }
        self::$mockServerProc = null;
        self::$mockServerUrl = null;
    }

    private static function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new \RuntimeException('Unable to create socket');
        }
        if (!socket_bind($socket, '127.0.0.1', 0)) {
            throw new \RuntimeException('Unable to bind socket');
        }
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);
        return $port;
    }

    private static function waitForServer(string $url): void
    {
        $ch = curl_init($url . '/fixtures/video.mp4');
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        for ($i = 0; $i < 50; $i++) {
            curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401) {
                curl_close($ch);
                return;
            }
            usleep(100000);
        }
        curl_close($ch);
        throw new \RuntimeException('Mock video server did not start');
    }

    public function testDownloadVideoFileSuccess(): void
    {
        $service = $this->createService();
        $destination = sys_get_temp_dir() . '/easynews_download_test_' . uniqid() . '/video.mp4';
        $result = ['directUrl' => self::$mockServerUrl . '/fixtures/video.mp4'];

        $this->assertTrue($service->downloadVideoFile($result, $destination));
        $this->assertStringEqualsFile($destination, file_get_contents(__DIR__ . '/fixtures/video.mp4'));
        unlink($destination);
    }

    public function testDownloadVideoFileResumesPartialFile(): void
    {
        $service = $this->createService();
        $destination = sys_get_temp_dir() . '/easynews_resume_test_' . uniqid() . '/video.mp4';
        $fullContent = file_get_contents(__DIR__ . '/fixtures/video.mp4');
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($destination, substr($fullContent, 0, 5));

        $result = ['directUrl' => self::$mockServerUrl . '/fixtures/video.mp4'];

        $this->assertTrue($service->downloadVideoFile($result, $destination));
        $this->assertStringEqualsFile($destination, $fullContent);
        unlink($destination);
    }

    public function testDownloadVideoFileResumesCompletedFile(): void
    {
        $service = $this->createService();
        $destination = sys_get_temp_dir() . '/easynews_complete_test_' . uniqid() . '/video.mp4';
        $fullContent = file_get_contents(__DIR__ . '/fixtures/video.mp4');
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($destination, $fullContent);

        $result = ['directUrl' => self::$mockServerUrl . '/fixtures/video.mp4'];

        $this->assertTrue($service->downloadVideoFile($result, $destination));
        $this->assertStringEqualsFile($destination, $fullContent);
        unlink($destination);
    }

    public function testDownloadVideoFileCreatesParentDirectories(): void
    {
        $service = $this->createService();
        $destination = sys_get_temp_dir() . '/easynews_mkdir_test_' . uniqid() . '/nested/dir/video.mp4';
        $result = ['directUrl' => self::$mockServerUrl . '/fixtures/video.mp4'];

        $this->assertTrue($service->downloadVideoFile($result, $destination));
        $this->assertFileExists($destination);
        unlink($destination);
    }

    public function testDownloadVideoFileMissingDirectUrlThrows(): void
    {
        $service = $this->createService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Search result is missing directUrl');
        $service->downloadVideoFile([], sys_get_temp_dir() . '/should_not_be_created.mp4');
    }

    public function testDownloadVideoFileThrowsWhenDisabled(): void
    {
        $service = new EasynewsService([
            'EASYNEWS_ENABLED' => 'false',
            'EASYNEWS_USERNAME' => 'testuser',
            'EASYNEWS_PASSWORD' => 'testpass',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Easynews integration is disabled');
        $service->downloadVideoFile(['directUrl' => 'http://example.com/file.mp4'], sys_get_temp_dir() . '/should_not_be_created.mp4');
    }
}
