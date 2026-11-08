<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// NAS upload via WebDAV: queue, folders, keep copy, rotation. Uses a small WebDAV server (tests/webdav/router.php)
// started with the built-in web server of PHP.
class NASTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    private static $server = null;
    private static int $port = 0;
    private static string $root = '';

    private int $console;
    private object $module;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/car-webdav-' . getmypid();
        @mkdir(self::$root);
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            return;
        }
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $env = getenv();
        $env['WEBDAV_ROOT'] = self::$root;
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/webdav/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env
        );
        // Wait until the server accepts connections
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }
        self::$port = 0;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::deleteTree(self::$root);
    }

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        foreach (scandir(self::$root) as $name) {
            if ($name !== '.' && $name !== '..') {
                self::deleteTree(self::$root . '/' . $name);
            }
        }
        $this->console = IPS_CreateInstance(self::CONSOLE);
        $this->module = IPS\InstanceManager::getInstanceInterface($this->console);
    }

    public function testUploadCreatesFoldersAndFile(): void
    {
        $this->configure();
        $path = $this->queueUpload('Haustür', 'Klingel/Tür', "\xFF\xD8image\x00-1\xFF\xD9");
        $this->assertMatchesRegularExpression('#^Haustür/\d{4}-\d{2}-\d{2}/\d{6}_Klingel_Tür\.jpg$#u', $path);
        $this->assertSame(1, $this->timer());

        $this->module->RequestAction('ProcessUploads', 0);
        $this->assertSame("\xFF\xD8image\x00-1\xFF\xD9", file_get_contents(self::$root . '/' . $path));
        $this->assertSame([], $this->queue());
        $this->assertSame(0, $this->timer());
        // The slot is emptied after the upload
        $this->assertSame(0, IPS_GetMedia(IPS_GetObjectIDByIdent('NAS_001', $this->console))['MediaSize']);
    }

    public function testLargeImageArrivesComplete(): void
    {
        $this->configure();
        // Larger than the 512 KiB a buffer can hold: the cause of truncated files with the first version
        $data = "\xFF\xD8" . random_bytes(1500000) . "\xFF\xD9";
        $path = $this->queueUpload('Haustür', 'Klingel', $data);

        $this->module->RequestAction('ProcessUploads', 0);
        $this->assertSame(md5($data), md5_file(self::$root . '/' . $path));
    }

    public function testIncompleteUploadIsRetried(): void
    {
        $this->configure();
        $data = "\xFF\xD8" . str_repeat('x', 1500000) . "\xFF\xD9";
        $this->queueUpload('Haustür', 'Truncate', $data);

        $this->module->RequestAction('ProcessUploads', 0);
        $queue = $this->queue();
        $this->assertCount(1, $queue);
        $this->assertSame(1, $queue[0]['attempts']);
        $this->assertStringContainsString(sprintf('Upload incomplete (%d of %d bytes)', strlen($data) - 10, strlen($data)), $this->nasInfo());
    }

    public function testKeepCopyGoesToFolderForKeptImages(): void
    {
        $this->configure();
        $path = $this->queueUpload('Garten', 'Bewegung', 'image-2');
        $result = (new ReflectionMethod($this->module, 'QueueKeepCopy'))->invoke($this->module, $path);
        $this->assertTrue($result['ok']);

        // Upload and copy in one run, in this order
        $this->module->RequestAction('ProcessUploads', 0);
        $keep = (new ReflectionMethod($this->module, 'KeepFolder'))->invoke($this->module, 'Garten');
        $this->assertSame('image-2', file_get_contents(self::$root . '/' . $keep . '/' . basename($path)));
        $this->assertSame([], $this->queue());
    }

    public function testInvalidKeepPathIsRejected(): void
    {
        $this->configure();
        foreach (['../x/2026-01-01/a.jpg', 'Garten/Kept/a.jpg', 'Garten/2026-01-01', 'a/b/c/d.jpg'] as $path) {
            $result = (new ReflectionMethod($this->module, 'QueueKeepCopy'))->invoke($this->module, $path);
            $this->assertFalse($result['ok'], $path);
        }
        $this->assertSame([], $this->queue());
    }

    public function testWrongPasswordKeepsImageForLaterRetry(): void
    {
        $this->configure('wrong');
        $path = $this->queueUpload('Haustür', 'Klingel', 'image-3');

        $this->module->RequestAction('ProcessUploads', 0);
        $queue = $this->queue();
        $this->assertCount(1, $queue);
        $this->assertSame(1, $queue[0]['attempts']);
        $this->assertGreaterThan(microtime(true) + 30, $queue[0]['next']);
        $this->assertGreaterThan(30000, $this->timer());
        $this->assertStringContainsString('NAS user or password invalid', $this->nasInfo());
        $this->assertStringNotContainsString('wrong', $this->nasInfo());

        // Password corrected: the waiting image is uploaded at the next attempt
        $this->configure();
        $queue[0]['next'] = 0.0;
        (new ReflectionMethod($this->module, 'SaveUploadQueue'))->invoke($this->module, $queue);
        $this->module->RequestAction('ProcessUploads', 0);
        $this->assertSame('image-3', file_get_contents(self::$root . '/' . $path));
        $this->assertStringNotContainsString('error', $this->nasInfo());
    }

    public function testUnreachableNASKeepsQueue(): void
    {
        $this->configure('secret', 'http://127.0.0.1:1/archiv');
        $this->queueUpload('Haustür', 'Klingel', 'a');
        $this->queueUpload('Haustür', 'Klingel', 'b');

        $this->module->RequestAction('ProcessUploads', 0);
        $queue = $this->queue();
        $this->assertCount(2, $queue);
        // Only the first entry was tried, the NAS is not asked for every waiting image
        $this->assertSame([1, 0], array_column($queue, 'attempts'));
    }

    public function testRotationDeletesOldDateFoldersOnly(): void
    {
        $this->configure();
        foreach (['Haustür', 'Haustür/2000-01-01', 'Haustür/' . date('Y-m-d', strtotime('-1 day')), 'Haustür/Behalten', 'Haustür/Kept', 'Garten', 'Garten/2000-01-01'] as $folder) {
            mkdir(self::$root . '/' . $folder);
        }
        $this->queueUpload('Haustür', 'Klingel', 'x', 2);
        $this->queueUpload('Garten', 'Klingel', 'y', 0);

        $this->module->RequestAction('ProcessUploads', 0);
        $this->assertDirectoryDoesNotExist(self::$root . '/Haustür/2000-01-01');
        $this->assertDirectoryExists(self::$root . '/Haustür/' . date('Y-m-d', strtotime('-1 day')));
        $this->assertDirectoryExists(self::$root . '/Haustür/' . date('Y-m-d'));
        $this->assertDirectoryExists(self::$root . '/Haustür/Behalten');
        $this->assertDirectoryExists(self::$root . '/Haustür/Kept');
        // Retention 0: nothing is deleted
        $this->assertDirectoryExists(self::$root . '/Garten/2000-01-01');
    }

    public function testQueueLimitDropsOldestUploads(): void
    {
        $this->configure('secret', 'http://127.0.0.1:1/archiv');
        for ($i = 1; $i <= 31; $i++) {
            $this->queueUpload('Haustür', 'T' . $i, 'image-' . $i);
        }
        // 30 slots: the oldest upload gave way, its slot holds the newest image
        $queue = $this->queue();
        $this->assertCount(30, $queue);
        $this->assertStringEndsWith('_T2.jpg', $queue[0]['path']);
        $this->assertSame(1, end($queue)['slot']);
        $this->assertSame('image-31', base64_decode(IPS_GetMediaContent(IPS_GetObjectIDByIdent('NAS_001', $this->console))));
        $this->assertCount(30, IPS_GetChildrenIDs($this->console));

        // Copies need no slot, they are limited by count
        for ($i = 1; $i <= 80; $i++) {
            (new ReflectionMethod($this->module, 'QueueKeepCopy'))->invoke($this->module, 'Haustür/2026-11-08/12000' . ($i % 10) . '_K.jpg');
        }
        $this->assertCount(100, $this->queue());

        $big = str_repeat('x', 15 * 1048576);
        $this->queueUpload('Haustür', 'Big1', $big);
        $this->queueUpload('Haustür', 'Big2', $big);
        $this->assertLessThanOrEqual(26214400, array_sum(array_column($this->queue(), 'size')));
        $queue = $this->queue();
        $this->assertStringEndsWith('_Big2.jpg', end($queue)['path']);
    }

    public function testArchiveListsDaysAndFiles(): void
    {
        $this->configure();
        $this->createArchive();

        $days = $this->listArchive('Haustür', '');
        $this->assertTrue($days['ok']);
        $this->assertSame(['2026-11-08', '2026-11-07'], $days['items']);

        $files = $this->listArchive('Haustür', '2026-11-07');
        $this->assertTrue($files['ok']);
        $this->assertSame(['101500_Klingel.jpg', '093000_Tür.jpg'], array_column($files['items'], 'file'));
        $this->assertSame(['Klingel', 'Tür'], array_column($files['items'], 'trigger'));
        $this->assertSame(mktime(10, 15, 0, 11, 7, 2026), $files['items'][0]['time']);

        // Camera without images on the NAS yet
        $this->assertSame(['ok' => true, 'message' => '', 'items' => []], $this->listArchive('Garten', ''));
        $this->assertFalse($this->listArchive('Haustür', '../2026-11-07')['ok']);
    }

    public function testArchiveImageUsesThumbnailOrOriginal(): void
    {
        $this->configure();
        $this->createArchive();
        $get = new ReflectionMethod($this->module, 'GetArchiveImage');

        $this->assertSame("\xFF\xD8thumb", base64_decode($get->invoke($this->module, 'Haustür', '2026-11-07', '101500_Klingel.jpg', true)['image']));
        $this->assertSame("\xFF\xD8original", base64_decode($get->invoke($this->module, 'Haustür', '2026-11-07', '101500_Klingel.jpg', false)['image']));
        // No thumbnail (e.g. uploaded without GD): the original is delivered
        $this->assertSame("\xFF\xD8door", base64_decode($get->invoke($this->module, 'Haustür', '2026-11-07', '093000_Tür.jpg', true)['image']));
        foreach ([['2026-11-07', '../101500_Klingel.jpg'], ['2026-11-07', 'notes.txt'], ['../x', '101500_Klingel.jpg'], ['2026-11-07', '120000_Missing.jpg']] as [$date, $file]) {
            $this->assertFalse($get->invoke($this->module, 'Haustür', $date, $file, false)['ok'], $file);
        }
    }

    public function testCameraHookDeliversArchiveImage(): void
    {
        $this->configure();
        $this->createArchive();
        [$camera, $cameraModule] = $this->createCamera(true);
        $token = (new ReflectionMethod($cameraModule, 'ReadAttributeString'))->invoke($cameraModule, 'HookToken');

        $request = ['token' => $token, 'archive' => '1', 'date' => '2026-11-07', 'file' => '101500_Klingel.jpg'];
        $this->assertSame("\xFF\xD8thumb", $this->hook($cameraModule, $request + ['thumb' => '1']));
        $this->assertSame("\xFF\xD8original", $this->hook($cameraModule, $request + ['download' => '1']));
        $this->assertSame('Bad Request', $this->hook($cameraModule, ['file' => '../../secret.jpg'] + $request));
        $this->assertSame('Bad Request', $this->hook($cameraModule, ['date' => '2026-11-07/..'] + $request));
        $this->assertSame('Forbidden', $this->hook($cameraModule, ['token' => 'x'] + $request));

        // Upload switched off: no archive
        IPS_SetProperty($camera, 'NASUpload', false);
        IPS_ApplyChanges($camera);
        $this->assertSame('Not Found', $this->hook($cameraModule, $request));
        $this->assertFalse((new ReflectionMethod($cameraModule, 'BuildCameraState'))->invoke($cameraModule)['nas']);
    }

    public function testOverviewAsksCameraForItsFolder(): void
    {
        $this->configure();
        [$door] = $this->createCamera(true);
        [$garden] = $this->createCamera(false);
        $send = new ReflectionMethod($this->module, 'SendToChildren');

        $this->assertSame([['folder' => 'Haustür']], $send->invoke($this->module, ['Command' => 'GetArchiveFolder', 'InstanceID' => $door]));
        $this->assertSame([], $send->invoke($this->module, ['Command' => 'GetArchiveFolder', 'InstanceID' => $garden]));
    }

    public function testUnusedSlotsAreEmptiedAfterRestart(): void
    {
        $this->configure('secret', 'http://127.0.0.1:1/archiv');
        $this->queueUpload('Haustür', 'Klingel', 'a');
        $this->queueUpload('Haustür', 'Klingel', 'b');
        $slot = IPS_GetObjectIDByIdent('NAS_001', $this->console);
        $this->assertGreaterThan(0, IPS_GetMedia($slot)['MediaSize']);

        // Restart: the queue in RAM is gone, the cached media still hold the images
        (new ReflectionMethod($this->module, 'SaveUploadQueue'))->invoke($this->module, []);
        $this->module->MessageSink(0, 0, IPS_KERNELSTARTED, []);
        $this->assertSame(0, IPS_GetMedia($slot)['MediaSize']);
        $this->assertSame(0, IPS_GetMedia(IPS_GetObjectIDByIdent('NAS_002', $this->console))['MediaSize']);
    }

    public function testWithoutAddressNothingIsQueued(): void
    {
        $this->configure('secret', 'ftp://nas/archiv');
        $this->assertSame('', $this->queueUpload('Haustür', 'Klingel', 'x'));
        $this->assertSame([], $this->queue());
        $this->assertSame('NAS: not set up', $this->nasInfo());
    }

    public function testParseDateFoldersOfSynologyAnswer(): void
    {
        $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
            . '<d:response><d:href>/kamera-archiv/Haust%C3%BCr/</d:href></d:response>'
            . '<d:response><d:href>https://nas:5006/kamera-archiv/Haust%C3%BCr/2026-11-01/</d:href></d:response>'
            . '<d:response><d:href>/kamera-archiv/Haust%C3%BCr/Behalten/</d:href></d:response>'
            . '<d:response><d:href>/kamera-archiv/Haust%C3%BCr/2026-11-02</d:href></d:response>'
            . '<d:response><d:href>/kamera-archiv/Andere/2026-11-03/</d:href></d:response>'
            . '</d:multistatus>';
        $dates = (new ReflectionMethod($this->module, 'ParseDateFolders'))->invoke($this->module, $body, 'Haustür');
        $this->assertSame(['2026-11-01', '2026-11-02'], $dates);
    }

    public function testURLEncodesEverySegment(): void
    {
        $this->configure('secret', 'https://[fd00::1]:5006/kamera-archiv/');
        $url = (new ReflectionMethod($this->module, 'NASURL'))->invoke($this->module, 'Haus tür/2026-11-08/');
        $this->assertSame('https://[fd00::1]:5006/kamera-archiv/Haus%20t%C3%BCr/2026-11-08/', $url);
    }

    public function testKeepInCameraQueuesCopyOnConsole(): void
    {
        $this->configure('secret', 'http://127.0.0.1:1/archiv');
        $camera = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($camera, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ConnectInstance($camera, $this->console);
        IPS_ApplyChanges($camera);
        $cameraModule = IPS\InstanceManager::getInstanceInterface($camera);
        $store = new ReflectionMethod($cameraModule, 'StoreImage');
        $this->assertTrue($store->invoke($cameraModule, ['data' => 'a', 'width' => 1, 'height' => 1, 'nas' => 'Haustür/2026-11-08/120000_Klingel.jpg'], 'Klingel', true)['ok']);
        $this->assertTrue($store->invoke($cameraModule, ['data' => 'b', 'width' => 1, 'height' => 1, 'nas' => ''], 'Klingel', true)['ok']);

        $cameraModule->RequestAction('TileKeep', json_encode(['slot' => 1, 'seq' => 1, 'keep' => true]));
        $cameraModule->RequestAction('TileKeep', json_encode(['slot' => 2, 'seq' => 2, 'keep' => true]));
        // Removing the mark does not copy again
        $cameraModule->RequestAction('TileKeep', json_encode(['slot' => 1, 'seq' => 1, 'keep' => false]));

        $queue = $this->queue();
        $this->assertCount(1, $queue);
        $this->assertSame(['copy', 'Haustür/2026-11-08/120000_Klingel.jpg'], [$queue[0]['kind'], $queue[0]['path']]);
    }

    private function configure(string $Password = 'secret', string $URL = ''): void
    {
        if ($URL === '' && self::$port === 0) {
            $this->markTestSkipped('The test WebDAV server could not be started');
        }
        IPS_SetProperty($this->console, 'NASURL', $URL !== '' ? $URL : 'http://127.0.0.1:' . self::$port . '/archiv');
        IPS_SetProperty($this->console, 'NASUser', 'cam');
        IPS_SetProperty($this->console, 'NASPassword', $Password);
        IPS_ApplyChanges($this->console);
    }

    private function createArchive(): void
    {
        foreach (['Haustür', 'Haustür/2026-11-07', 'Haustür/2026-11-07/thumbs', 'Haustür/2026-11-08', 'Haustür/Behalten'] as $folder) {
            mkdir(self::$root . '/' . $folder);
        }
        file_put_contents(self::$root . '/Haustür/2026-11-07/101500_Klingel.jpg', "\xFF\xD8original");
        file_put_contents(self::$root . '/Haustür/2026-11-07/thumbs/101500_Klingel.jpg', "\xFF\xD8thumb");
        file_put_contents(self::$root . '/Haustür/2026-11-07/093000_Tür.jpg', "\xFF\xD8door");
        file_put_contents(self::$root . '/Haustür/2026-11-07/notes.txt', 'x');
    }

    private function createCamera(bool $Upload): array
    {
        $camera = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($camera, 'CameraID', '653fd7e100601403e40003f3');
        IPS_SetProperty($camera, 'NASUpload', $Upload);
        IPS_SetProperty($camera, 'NASFolder', 'Haustür');
        IPS_ConnectInstance($camera, $this->console);
        IPS_ApplyChanges($camera);
        return [$camera, IPS\InstanceManager::getInstanceInterface($camera)];
    }

    private function listArchive(string $Folder, string $Date): array
    {
        return (new ReflectionMethod($this->module, 'ListArchive'))->invoke($this->module, $Folder, $Date);
    }

    private function hook(object $Module, array $Get): string
    {
        $_GET = $Get;
        ob_start();
        (new ReflectionMethod($Module, 'ProcessHookData'))->invoke($Module);
        $_GET = [];
        return (string) ob_get_clean();
    }

    private function queueUpload(string $Folder, string $Trigger, string $Data, int $Retention = 30): string
    {
        return (new ReflectionMethod($this->module, 'QueueUpload'))->invoke($this->module, ['folder' => $Folder, 'trigger' => $Trigger, 'retention' => $Retention], $Data);
    }

    private function queue(): array
    {
        return (new ReflectionMethod($this->module, 'LoadUploadQueue'))->invoke($this->module);
    }

    private function timer(): int
    {
        return (new ReflectionMethod($this->module, 'GetTimerInterval'))->invoke($this->module, 'ProcessUploads');
    }

    private function nasInfo(): string
    {
        return (new ReflectionMethod($this->module, 'GetNASInfo'))->invoke($this->module);
    }

    private static function deleteTree(string $Path): void
    {
        if (is_dir($Path)) {
            foreach (scandir($Path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::deleteTree($Path . '/' . $name);
                }
            }
            @rmdir($Path);
        } elseif (is_file($Path)) {
            @unlink($Path);
        }
    }
}
