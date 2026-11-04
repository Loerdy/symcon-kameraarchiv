<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Tile state and image WebHook (phase 5a)
class TileTest extends TestCase
{
    private const MODULE = '{2AF53696-16BD-B081-D6C1-DCA8F3893CE4}';
    private const CAMERA_A = '653fd7e100601403e40003f3';
    private const CAMERA_B = '6900d73d01f57103e4808b14';

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
        [$id, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $token = $this->token($module);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        IPS_ApplyChanges($id);
        $this->assertSame($token, $this->token($module));
    }

    public function testHookDeliversStoredImage(): void
    {
        [, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $this->store($module, self::CAMERA_A, 3, 'image-1');

        $this->assertSame('image-1', $this->hook($module, ['token' => $this->token($module), 'camera' => self::CAMERA_A, 'slot' => '1', 'seq' => '1']));
    }

    public function testHookRejectsMissingOrWrongToken(): void
    {
        [, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $this->store($module, self::CAMERA_A, 3, 'image-1');
        $request = ['camera' => self::CAMERA_A, 'slot' => '1', 'seq' => '1'];

        $this->assertSame('Forbidden', $this->hook($module, $request));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => str_repeat('0', 32)]));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => '']));
        $this->assertSame('Forbidden', $this->hook($module, $request + ['token' => [$this->token($module)]]));
    }

    public function testHookRejectsOverwrittenImage(): void
    {
        [, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 1]]);
        $this->store($module, self::CAMERA_A, 1, 'image-1');
        $this->store($module, self::CAMERA_A, 1, 'image-2');
        $token = $this->token($module);

        // Slot 1 now holds image 2: the URL of image 1 must not return image 2
        $this->assertSame('Not Found', $this->hook($module, ['token' => $token, 'camera' => self::CAMERA_A, 'slot' => '1', 'seq' => '1']));
        $this->assertSame('image-2', $this->hook($module, ['token' => $token, 'camera' => self::CAMERA_A, 'slot' => '1', 'seq' => '2']));
        $this->assertSame('Not Found', $this->hook($module, ['token' => $token, 'camera' => self::CAMERA_B, 'slot' => '1', 'seq' => '2']));
    }

    public function testHookRejectsInvalidParameters(): void
    {
        [, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $token = $this->token($module);

        foreach ([
            ['camera' => '../media', 'slot' => '1', 'seq' => '1'],
            ['camera' => self::CAMERA_A, 'slot' => '0', 'seq' => '1'],
            ['camera' => self::CAMERA_A, 'slot' => '201', 'seq' => '1'],
            ['camera' => self::CAMERA_A, 'slot' => '1', 'seq' => 'x'],
            ['camera' => self::CAMERA_A, 'slot' => '1'],
            ['slot' => '1', 'seq' => '1']
        ] as $request) {
            $this->assertSame('Bad Request', $this->hook($module, $request + ['token' => $token]), json_encode($request));
        }
    }

    public function testStateListsImagesNewestFirstAndSkipsInactiveCamerasWithoutImages(): void
    {
        [, $module] = $this->createInstance([
            ['id' => self::CAMERA_A, 'images' => 3],
            ['id' => self::CAMERA_B, 'images' => 3, 'enabled' => false]
        ]);
        for ($i = 1; $i <= 4; $i++) {
            $this->store($module, self::CAMERA_A, 3, 'image-' . $i);
        }

        $state = $this->state($module);
        $this->assertFalse($state['indexDamaged']);
        $this->assertCount(1, $state['cameras']);
        $this->assertSame(self::CAMERA_A, $state['cameras'][0]['id']);
        $this->assertSame('Haustür', $state['cameras'][0]['name']);
        $this->assertSame([4, 3, 2], array_column($state['cameras'][0]['images'], 'seq'));
        $this->assertSame(['slot', 'seq', 'time', 'trigger', 'keep', 'width', 'height'], array_keys($state['cameras'][0]['images'][0]));
        $this->assertArrayNotHasKey('token', $state);
    }

    public function testInactiveCameraWithImagesStaysVisible(): void
    {
        [$id, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $this->store($module, self::CAMERA_A, 3, 'image-1');
        $this->setCameras($id, [['id' => self::CAMERA_A, 'images' => 3, 'enabled' => false]]);
        IPS_ApplyChanges($id);

        $this->assertSame([1], array_column($this->state($module)['cameras'][0]['images'], 'seq'));
    }

    public function testDamagedIndexIsReportedAndKept(): void
    {
        [$id, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3]]);
        $this->store($module, self::CAMERA_A, 3, 'image-1');
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        IPS_SetMediaContent($indexID, base64_encode('{broken'));

        $state = $this->state($module);
        $this->assertTrue($state['indexDamaged']);
        $this->assertSame([], $state['cameras'][0]['images']);
        $this->assertSame('Not Found', $this->hook($module, ['token' => $this->token($module), 'camera' => self::CAMERA_A, 'slot' => '1', 'seq' => '1']));
        $this->assertSame('{broken', base64_decode(IPS_GetMediaContent($indexID)));
    }

    public function testTileContainsStateAndEscapesCameraNames(): void
    {
        [, $module] = $this->createInstance([['id' => self::CAMERA_A, 'images' => 3, 'name' => '</script><img src=x>']]);
        $html = $module->GetVisualizationTile();

        $this->assertStringContainsString('function handleMessage', $html);
        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertSame(1, preg_match('/<script>handleMessage\((.*)\);<\/script>$/s', $html, $match));
        $state = json_decode($match[1], true);
        $this->assertSame('</script><img src=x>', $state['cameras'][0]['name']);
        $this->assertSame('/hook/camera-archive-' . $this->instanceID($module), $state['hook']);
        $this->assertSame($this->token($module), $state['token']);
    }

    private function createInstance(array $Cameras): array
    {
        $id = IPS_CreateInstance(self::MODULE);
        $this->setCameras($id, $Cameras);
        IPS_ApplyChanges($id);
        return [$id, IPS\InstanceManager::getInstanceInterface($id)];
    }

    private function setCameras(int $ID, array $Cameras): void
    {
        $rows = [];
        foreach ($Cameras as $camera) {
            $rows[] = [
                'Enabled'  => $camera['enabled'] ?? true,
                'Images'   => $camera['images'],
                'Name'     => $camera['name'] ?? 'Haustür',
                'Type'     => 'UVC G3',
                'State'    => 'CONNECTED',
                'FullHD'   => true,
                'CameraID' => $camera['id']
            ];
        }
        IPS_SetProperty($ID, 'Cameras', json_encode($rows));
    }

    private function store(object $Module, string $CameraID, int $Images, string $Data): void
    {
        $camera = ['Enabled' => true, 'Images' => $Images, 'Name' => 'Haustür', 'Type' => 'UVC G3', 'State' => 'CONNECTED', 'FullHD' => true, 'CameraID' => $CameraID];
        $result = (new ReflectionMethod($Module, 'StoreImage'))->invoke($Module, $camera, ['data' => $Data, 'width' => 16, 'height' => 8], 'manual');
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

    private function instanceID(object $Module): int
    {
        return (new ReflectionProperty($Module, 'InstanceID'))->getValue($Module);
    }
}
