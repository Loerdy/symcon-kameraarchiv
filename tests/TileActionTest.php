<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Actions of the tile: keep, delete, snapshot, download, forwarding by the console
class TileActionTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    private int $console;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $_GET = [];

        // Port 1 is not listening: snapshots fail quickly, but the configuration counts as complete
        $this->console = IPS_CreateInstance(self::CONSOLE);
        IPS_SetProperty($this->console, 'Host', '127.0.0.1:1');
        IPS_SetProperty($this->console, 'APIKey', 'test');
        IPS_ApplyChanges($this->console);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testKeepAndUnkeep(): void
    {
        [$id, $module] = $this->createCamera();
        $this->store($module, 'image-1');

        $module->RequestAction('TileKeep', json_encode(['camera' => $id, 'slot' => 1, 'seq' => 1, 'keep' => true]));
        $this->assertSame([true], array_column($this->images($module), 'keep'));

        $module->RequestAction('TileKeep', json_encode(['camera' => $id, 'slot' => 1, 'seq' => 1, 'keep' => false]));
        $this->assertSame([false], array_column($this->images($module), 'keep'));
    }

    public function testDeleteRemovesImageAndMedium(): void
    {
        [$id, $module] = $this->createCamera();
        $this->store($module, 'image-1');
        $this->store($module, 'image-2');

        $module->RequestAction('TileDelete', json_encode(['camera' => $id, 'slot' => 2, 'seq' => 2]));
        $this->assertSame([1], array_column($this->images($module), 'seq'));
        $this->assertFalse(@IPS_GetObjectIDByIdent('IMG_002', $id));
        $this->assertIsInt(IPS_GetObjectIDByIdent('IMG_001', $id));

        // The free slot is used again for the next image
        $this->store($module, 'image-3');
        $this->assertSame([3, 1], array_column($this->images($module), 'seq'));
        $this->assertSame([2, 1], array_column($this->images($module), 'slot'));
    }

    public function testKeptImageCanBeDeleted(): void
    {
        [$id, $module] = $this->createCamera();
        $this->store($module, 'image-1');
        $module->RequestAction('TileKeep', json_encode(['camera' => $id, 'slot' => 1, 'seq' => 1, 'keep' => true]));

        $module->RequestAction('TileDelete', json_encode(['camera' => $id, 'slot' => 1, 'seq' => 1]));
        $this->assertSame([], $this->images($module));
    }

    public function testOverwrittenOrInvalidImageIsNotChanged(): void
    {
        [$id, $module] = $this->createCamera();
        $this->store($module, 'image-1');

        foreach ([
            json_encode(['slot' => 1, 'seq' => 2, 'keep' => true]),
            json_encode(['slot' => 0, 'seq' => 1, 'keep' => true]),
            json_encode(['slot' => '1', 'seq' => 1, 'keep' => true]),
            json_encode(['slot' => 1, 'seq' => 1, 'keep' => 'yes']),
            json_encode(['slot' => 1, 'seq' => 1]),
            'no json',
            42
        ] as $value) {
            $module->RequestAction('TileKeep', $value);
        }
        foreach ([json_encode(['slot' => 1, 'seq' => 2]), json_encode(['slot' => 201, 'seq' => 1]), '[]'] as $value) {
            $module->RequestAction('TileDelete', $value);
        }
        $this->assertSame([['seq' => 1, 'keep' => false]], array_map(fn (array $Image): array => ['seq' => $Image['seq'], 'keep' => $Image['keep']], $this->images($module)));
    }

    public function testUnknownIdentThrows(): void
    {
        [, $module] = $this->createCamera();
        $this->expectException(Exception::class);
        $module->RequestAction('TileUnknown', '{}');
    }

    public function testSnapshotIsQueuedOnce(): void
    {
        [, $module] = $this->createCamera();

        $module->RequestAction('TileSnapshot', '{}');
        $module->RequestAction('TileSnapshot', '{}');
        $queue = $this->queue($module);
        $this->assertCount(1, $queue);
        $this->assertSame('manual', $queue[0]['trigger']);
        $this->assertTrue($queue[0]['tile']);
    }

    public function testFailedSnapshotIsShownAsNotice(): void
    {
        [, $module] = $this->createCamera();
        $this->assertNull($this->state($module)['notice']);

        $module->RequestAction('TileSnapshot', '{}');
        $module->RequestAction('ProcessQueue', null);

        $notice = $this->state($module)['notice'];
        $this->assertIsString($notice['id']);
        $this->assertNotSame('', $notice['text']);
        $this->assertStringNotContainsString('test', $notice['text']);
        $this->assertSame([], $this->queue($module));
    }

    public function testSnapshotWithoutConsoleShowsNotice(): void
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ApplyChanges($id);
        $module = IPS\InstanceManager::getInstanceInterface($id);

        $module->RequestAction('TileSnapshot', '{}');
        $this->assertSame([], $this->queue($module));
        $this->assertSame('Snapshot not possible: the camera is not set up completely.', $this->state($module)['notice']['text']);
    }

    public function testConsoleForwardsActionToItsCamera(): void
    {
        [$door, $doorModule] = $this->createCamera();
        [$garden, $gardenModule] = $this->createCamera();
        $this->store($doorModule, 'door');
        $this->store($gardenModule, 'garden');

        $consoleModule = IPS\InstanceManager::getInstanceInterface($this->console);
        $consoleModule->RequestAction('TileKeep', json_encode(['camera' => $garden, 'slot' => 1, 'seq' => 1, 'keep' => true]));
        $this->assertSame([false], array_column($this->images($doorModule), 'keep'));
        $this->assertSame([true], array_column($this->images($gardenModule), 'keep'));

        // A camera of another console is not reached
        $otherConsole = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($otherConsole);
        $other = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($other, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ConnectInstance($other, $otherConsole);
        IPS_ApplyChanges($other);
        $otherModule = IPS\InstanceManager::getInstanceInterface($other);
        $this->store($otherModule, 'other');
        $consoleModule->RequestAction('TileDelete', json_encode(['camera' => $other, 'slot' => 1, 'seq' => 1]));
        $this->assertCount(1, $this->images($otherModule));

        // Invalid camera value: nothing happens
        $consoleModule->RequestAction('TileDelete', json_encode(['camera' => (string) $door, 'slot' => 1, 'seq' => 1]));
        $this->assertCount(1, $this->images($doorModule));
    }

    public function testApplyChangesWhileConsoleIsNotReady(): void
    {
        [$id, $module] = $this->createCamera();
        $this->store($module, 'image-1');

        // Module update: the camera is loaded before the interface of the console exists
        $interfaces = new ReflectionProperty(IPS\InstanceManager::class, 'interfaces');
        $all = $interfaces->getValue();
        $withoutConsole = $all;
        unset($withoutConsole[$this->console]);
        $interfaces->setValue(null, $withoutConsole);
        try {
            IPS_ApplyChanges($id);
            $module->RequestAction('TileKeep', json_encode(['slot' => 1, 'seq' => 1, 'keep' => true]));
        } finally {
            $interfaces->setValue(null, $all);
        }
        $this->assertSame(102, IPS_GetInstance($id)['InstanceStatus']);
        $this->assertSame([true], array_column($this->images($module), 'keep'));
    }

    public function testLiveImageIsSharedForOneSecond(): void
    {
        [, $module] = $this->createCamera();
        $this->assertTrue($this->state($module)['live']);
        $setBuffer = new ReflectionMethod($module, 'SetBuffer');

        // A fresh image is delivered to further viewers without asking the console
        $setBuffer->invoke($module, 'LiveImage', json_encode(['time' => microtime(true), 'image' => base64_encode('frame-1')]));
        $this->assertSame('frame-1', $this->live($module));

        // Older than one second: the console is asked (not reachable here) and nothing old is delivered
        $setBuffer->invoke($module, 'LiveImage', json_encode(['time' => microtime(true) - 5, 'image' => base64_encode('frame-1')]));
        $this->assertSame('Service Unavailable', $this->live($module));
        $this->assertSame('', (new ReflectionMethod($module, 'GetBuffer'))->invoke($module, 'LiveImage'));
        // Live images are never stored
        $this->assertSame([], $this->images($module));
    }

    public function testLiveOptionsInTileState(): void
    {
        [$id, $module] = $this->createCamera();
        $state = $this->state($module);
        $this->assertFalse($state['liveAuto']);
        $this->assertSame(120, $state['liveMax']);

        IPS_SetProperty($id, 'LiveAutoStart', true);
        IPS_SetProperty($id, 'LiveDuration', 99);
        IPS_ApplyChanges($id);
        $state = $this->state($module);
        $this->assertTrue($state['liveAuto']);
        // Limited to 30 minutes
        $this->assertSame(1800, $state['liveMax']);
    }

    public function testLiveImageNeedsCompleteConfiguration(): void
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ApplyChanges($id);
        $module = IPS\InstanceManager::getInstanceInterface($id);

        $this->assertFalse($this->state($module)['live']);
        $this->assertSame('Not Found', $this->live($module));
    }

    public function testDownloadNameAndHook(): void
    {
        [$id, $module] = $this->createCamera();
        IPS_SetName($id, 'Haustür/Ost "1"');
        $this->store($module, 'image-1');
        $time = $this->images($module)[0]['time'];

        $disposition = (new ReflectionMethod($module, 'GetDownloadDisposition'))->invoke($module, $time);
        $file = 'Haustür_Ost _1_' . '_' . date('Y-m-d_His', $time) . '.jpg';
        $this->assertSame(
            sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', preg_replace('/[^A-Za-z0-9._-]+/', '_', $file), rawurlencode($file)),
            $disposition
        );
        $this->assertStringNotContainsString('"1"', $disposition);

        $token = (new ReflectionMethod($module, 'ReadAttributeString'))->invoke($module, 'HookToken');
        $_GET = ['token' => $token, 'slot' => '1', 'seq' => '1', 'download' => '1'];
        ob_start();
        (new ReflectionMethod($module, 'ProcessHookData'))->invoke($module);
        $this->assertSame('image-1', ob_get_clean());
    }

    private function createCamera(): array
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_SetProperty($id, 'Images', 3);
        IPS_ConnectInstance($id, $this->console);
        IPS_ApplyChanges($id);
        return [$id, IPS\InstanceManager::getInstanceInterface($id)];
    }

    private function store(object $Module, string $Data): void
    {
        $result = (new ReflectionMethod($Module, 'StoreImage'))->invoke($Module, ['data' => $Data, 'width' => 16, 'height' => 8], 'manual', true);
        $this->assertTrue($result['ok']);
    }

    private function live(object $Module): string
    {
        $_GET = ['token' => (new ReflectionMethod($Module, 'ReadAttributeString'))->invoke($Module, 'HookToken'), 'live' => '1'];
        ob_start();
        (new ReflectionMethod($Module, 'ProcessHookData'))->invoke($Module);
        $_GET = [];
        return (string) ob_get_clean();
    }

    private function state(object $Module): array
    {
        return (new ReflectionMethod($Module, 'BuildCameraState'))->invoke($Module);
    }

    private function images(object $Module): array
    {
        return $this->state($Module)['images'];
    }

    private function queue(object $Module): array
    {
        return (new ReflectionMethod($Module, 'LoadTriggerState'))->invoke($Module)['queue'];
    }
}
