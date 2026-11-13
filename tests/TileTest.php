<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Tile state of a camera, image WebHook and overview tile of the console
class TileTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testTokenIsCreatedOnceAndKept(): void
    {
        [$id, $module] = $this->createCamera(3);
        $token = $this->token($module);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        IPS_ApplyChanges($id);
        $this->assertSame($token, $this->token($module));
    }

    public function testHookDeliversStoredImage(): void
    {
        [, $module] = $this->createCamera(3);
        $this->store($module, 'image-1');

        $this->assertSame('image-1', $this->hook($module, ['token' => $this->token($module), 'slot' => '1', 'seq' => '1']));
    }

    public function testHookRejectsMissingOrWrongToken(): void
    {
        [, $module] = $this->createCamera(3);
        $this->store($module, 'image-1');
        $request = ['slot' => '1', 'seq' => '1'];

        $this->assertSame('Forbidden', $this->hook($module, $request));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => str_repeat('0', 32)]));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => '']));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => [$this->token($module)]]));
    }

    public function testHookRejectsTokenOfAnotherCamera(): void
    {
        [, $first] = $this->createCamera(3);
        [, $second] = $this->createCamera(3);
        $this->store($first, 'image-1');

        $this->assertSame('Forbidden', $this->hook($first, ['token' => $this->token($second), 'slot' => '1', 'seq' => '1']));
    }

    public function testHookRejectsOverwrittenImage(): void
    {
        [, $module] = $this->createCamera(1);
        $this->store($module, 'image-1');
        $this->store($module, 'image-2');
        $token = $this->token($module);

        // Slot 1 now holds image 2: the URL of image 1 must not return image 2
        $this->assertSame('Not Found', $this->hook($module, ['token' => $token, 'slot' => '1', 'seq' => '1']));
        $this->assertSame('image-2', $this->hook($module, ['token' => $token, 'slot' => '1', 'seq' => '2']));
    }

    public function testHookRejectsInvalidParameters(): void
    {
        [, $module] = $this->createCamera(3);
        $token = $this->token($module);

        foreach ([
            ['slot' => '0', 'seq' => '1'],
            ['slot' => '201', 'seq' => '1'],
            ['slot' => '1', 'seq' => 'x'],
            ['slot' => '1'],
            ['seq' => '1']
        ] as $request) {
            $this->assertSame('Bad Request', $this->hook($module, $request + ['token' => $token]), json_encode($request));
        }
    }

    public function testStateListsImagesNewestFirst(): void
    {
        [$id, $module] = $this->createCamera(3);
        IPS_SetName($id, 'Haustür');
        for ($i = 1; $i <= 4; $i++) {
            $this->store($module, 'image-' . $i);
        }

        $state = $this->state($module);
        $this->assertFalse($state['overview']);
        $this->assertCount(1, $state['cameras']);
        $camera = $state['cameras'][0];
        $this->assertSame($id, $camera['id']);
        $this->assertSame('Haustür', $camera['name']);
        $this->assertFalse($camera['indexDamaged']);
        $this->assertSame('/hook/camera-archive-' . $id, $camera['hook']);
        $this->assertSame($this->token($module), $camera['token']);
        $this->assertSame([4, 3, 2], array_column($camera['images'], 'seq'));
        $this->assertSame(['slot', 'seq', 'time', 'trigger', 'keep', 'width', 'height'], array_keys($camera['images'][0]));
    }

    public function testDisplayNameReplacesInstanceName(): void
    {
        [$id, $module] = $this->createCamera(3);
        IPS_SetName($id, 'G4 Doorbell Pro');
        IPS_SetProperty($id, 'DisplayName', '  Haustür  ');
        IPS_ApplyChanges($id);
        $this->assertSame('Haustür', $this->state($module)['cameras'][0]['name']);

        // Only spaces: the name of the instance is used
        IPS_SetProperty($id, 'DisplayName', '   ');
        IPS_ApplyChanges($id);
        $this->assertSame('G4 Doorbell Pro', $this->state($module)['cameras'][0]['name']);
    }

    public function testDamagedIndexIsReportedAndKept(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, 'image-1');
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        IPS_SetMediaContent($indexID, base64_encode('{broken'));

        $camera = $this->state($module)['cameras'][0];
        $this->assertTrue($camera['indexDamaged']);
        $this->assertSame([], $camera['images']);
        $this->assertSame('Not Found', $this->hook($module, ['token' => $this->token($module), 'slot' => '1', 'seq' => '1']));
        $this->assertSame('{broken', base64_decode(IPS_GetMediaContent($indexID)));
    }

    public function testMissingIndexFileStartsNewIndex(): void
    {
        // After a restart the medium can exist without its file (content not readable)
        [$id, $module] = $this->createCamera(3);
        $indexID = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
        IPS_SetMediaFile($indexID, 'media/missing_index.json', false);
        IPS_SetParent($indexID, $id);
        IPS_SetIdent($indexID, 'ImageIndex');

        $camera = $this->state($module)['cameras'][0];
        $this->assertFalse($camera['indexDamaged']);
        $this->assertSame([], $camera['images']);
        $this->assertStringContainsString('function handleMessage', $module->GetVisualizationTile());

        // Storing works again and uses the existing medium
        $this->store($module, 'image-1');
        $this->assertSame($indexID, IPS_GetObjectIDByIdent('ImageIndex', $id));
        $this->assertSame([1], array_column($this->state($module)['cameras'][0]['images'], 'seq'));
    }

    public function testHookReportsMissingImageFile(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, 'image-1');
        // Slot 2 is listed in the index, but its medium has no readable content
        $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetMediaFile($mediaID, 'media/missing_slot.jpg', false);
        IPS_SetParent($mediaID, $id);
        IPS_SetIdent($mediaID, 'IMG_002');
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        $index = json_decode(base64_decode(IPS_GetMediaContent($indexID)), true);
        $index['images'][] = ['slot' => 2, 'seq' => 7, 'time' => 1, 'trigger' => 'manual', 'keep' => false, 'size' => 1, 'width' => 1, 'height' => 1];
        IPS_SetMediaContent($indexID, base64_encode(json_encode($index)));

        $this->assertSame('Not Found', $this->hook($module, ['token' => $this->token($module), 'slot' => '2', 'seq' => '7']));
    }

    public function testTileContainsStateAndEscapesInstanceNames(): void
    {
        [$id, $module] = $this->createCamera(3);
        IPS_SetName($id, '</script><img src=x>');
        $html = $module->GetVisualizationTile();

        $this->assertStringContainsString('function handleMessage', $html);
        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertSame(1, preg_match('/<script>handleMessage\((.*)\);<\/script>$/s', $html, $match));
        $state = json_decode($match[1], true);
        $this->assertSame('</script><img src=x>', $state['cameras'][0]['name']);
        $this->assertSame($this->token($module), $state['cameras'][0]['token']);
    }

    public function testOverviewListsConnectedCamerasInTreeOrder(): void
    {
        $console = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($console);
        [$garden, $gardenModule] = $this->createCamera(3, $console);
        [$door, $doorModule] = $this->createCamera(3, $console);
        [$other] = $this->createCamera(3);
        IPS_SetName($garden, 'Garten');
        IPS_SetName($door, 'Haustür');
        IPS_SetName($other, 'Andere Konsole');
        IPS_SetPosition($garden, 2);
        IPS_SetPosition($door, 1);
        IPS_SetProperty($door, 'DisplayName', 'Eingang');
        IPS_ApplyChanges($door);
        $this->store($doorModule, 'image-1');

        $consoleModule = IPS\InstanceManager::getInstanceInterface($console);
        $html = $consoleModule->GetVisualizationTile();
        $this->assertSame(1, preg_match('/<script>handleMessage\((.*)\);<\/script>$/s', $html, $match));
        $state = json_decode($match[1], true);

        $this->assertTrue($state['overview']);
        // The buttons of the overview use the display name if one is set
        $this->assertSame(['Eingang', 'Garten'], array_column($state['cameras'], 'name'));
        $this->assertSame([$door, $garden], array_column($state['cameras'], 'id'));
        $this->assertSame($this->token($doorModule), $state['cameras'][0]['token']);
        $this->assertSame($this->token($gardenModule), $state['cameras'][1]['token']);
        $this->assertSame([1], array_column($state['cameras'][0]['images'], 'seq'));
        $this->assertArrayNotHasKey('position', $state['cameras'][0]);
    }

    private function createCamera(int $Images, int $ConsoleID = 0): array
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_SetProperty($id, 'Images', $Images);
        if ($ConsoleID > 0) {
            IPS_ConnectInstance($id, $ConsoleID);
        }
        IPS_ApplyChanges($id);
        return [$id, IPS\InstanceManager::getInstanceInterface($id)];
    }

    private function store(object $Module, string $Data): void
    {
        $result = (new ReflectionMethod($Module, 'StoreImage'))->invoke($Module, ['data' => $Data, 'width' => 16, 'height' => 8], 'manual', true);
        $this->assertTrue($result['ok']);
    }

    private function hook(object $Module, array $Get): string
    {
        $_GET = $Get;
        ob_start();
        (new ReflectionMethod($Module, 'ProcessHookData'))->invoke($Module);
        return (string) ob_get_clean();
    }

    private function state(object $Module): array
    {
        return (new ReflectionMethod($Module, 'BuildTileState'))->invoke($Module);
    }

    private function token(object $Module): string
    {
        return (new ReflectionMethod($Module, 'ReadAttributeString'))->invoke($Module, 'HookToken');
    }
}
