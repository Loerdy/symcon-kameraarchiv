<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Ring buffer of cached image media and the index medium of a camera instance
class StorageTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';
    private const CAMERA_ID = '653fd7e100601403e40003f3';

    // 16 x 8 pixel JPEG, so the ring buffer tests do not need GD
    private const TINY_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNTAK/9sAQwAQCwwODAoQDg0OEhEQExgoGhgWFhgxIyUdKDozPTw5Mzg3QEhcTkBEV0U3OFBtUVdfYmdoZz5NcXlwZHhcZWdj/9sAQwEREhIYFRgvGhovY0I4QmNjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj/8AAEQgACAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AoUUUV7h55//Z';

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    public function testDefaultIsFiveImages(): void
    {
        [$id, $module] = $this->createCamera(null);
        for ($i = 1; $i <= 7; $i++) {
            $this->store($module, $this->jpeg($i));
        }
        $this->assertSame(['IMG_001', 'IMG_002', 'IMG_003', 'IMG_004', 'IMG_005'], $this->slotIdents($id));
        $this->assertSame([3, 4, 5, 6, 7], $this->seqs($id));
    }

    public function testRingBufferOverwritesOldestSlot(): void
    {
        [$id, $module] = $this->createCamera(3);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($this->store($module, $this->jpeg($i))['ok']);
        }

        $this->assertSame(['IMG_001', 'IMG_002', 'IMG_003'], $this->slotIdents($id));
        $entries = $this->index($id)['images'];
        $this->assertCount(3, $entries);
        // Image 4 replaced slot 1, image 5 replaced slot 2
        $bySlot = array_column($entries, 'seq', 'slot');
        $this->assertSame([1 => 4, 2 => 5, 3 => 3], [1 => $bySlot[1], 2 => $bySlot[2], 3 => $bySlot[3]]);
        $this->assertSame($this->jpeg(4), $this->content($id, 'IMG_001'));
        $this->assertSame('manual', $entries[0]['trigger']);
        $this->assertSame(16, $entries[0]['width']);
    }

    public function testMediaAreCachedHiddenAndNamedOnce(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, $this->jpeg(1));

        $mediaID = IPS_GetObjectIDByIdent('IMG_001', $id);
        $media = IPS_GetMedia($mediaID);
        $this->assertSame(MEDIATYPE_IMAGE, $media['MediaType']);
        $this->assertTrue($media['MediaIsCached']);
        $this->assertSame(sprintf('media/CameraArchive_%d_001.jpg', $id), $media['MediaFile']);
        $this->assertTrue(IPS_GetObject($mediaID)['ObjectIsHidden']);
        $this->assertSame('Image 01', IPS_GetObject($mediaID)['ObjectName']);

        // Name and visibility belong to the user after creation
        IPS_SetName($mediaID, 'Mein Bild');
        IPS_SetHidden($mediaID, false);
        IPS_SetProperty($id, 'Images', 1);
        IPS_ApplyChanges($id);
        $this->store($module, $this->jpeg(2));
        $this->assertSame('Mein Bild', IPS_GetObject($mediaID)['ObjectName']);
        $this->assertFalse(IPS_GetObject($mediaID)['ObjectIsHidden']);

        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        $this->assertSame(MEDIATYPE_DOCUMENT, IPS_GetMedia($indexID)['MediaType']);
        $this->assertTrue(IPS_GetMedia($indexID)['MediaIsCached']);
    }

    public function testCacheOptionOfTheConsoleAppliesToNewAndExistingMedia(): void
    {
        $console = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($console);
        [$id, $module] = $this->createCamera(3, $console);
        $this->store($module, $this->jpeg(1));
        $slotID = IPS_GetObjectIDByIdent('IMG_001', $id);
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);

        // Switched off in the console: existing media are no longer cached, new media are created without cache
        IPS_SetProperty($console, 'MemoryCache', false);
        IPS_ApplyChanges($console);
        $this->assertFalse(IPS_GetMedia($slotID)['MediaIsCached']);
        $this->assertFalse(IPS_GetMedia($indexID)['MediaIsCached']);
        $this->store($module, $this->jpeg(2), false);
        $this->assertFalse(IPS_GetMedia(IPS_GetObjectIDByIdent('IMG_002', $id))['MediaIsCached']);
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_001'));

        // Switched on again: all media are cached, also when the camera starts and asks the console
        IPS_SetProperty($console, 'MemoryCache', true);
        IPS_ApplyChanges($console);
        IPS_SetMediaCached($slotID, false);
        IPS_ApplyChanges($id);
        foreach (['IMG_001', 'IMG_002', 'ImageIndex'] as $ident) {
            $this->assertTrue(IPS_GetMedia(IPS_GetObjectIDByIdent($ident, $id))['MediaIsCached'], $ident);
        }
        $this->assertCount(2, $this->index($id)['images']);
    }

    public function testKeptImagesAreNotOverwritten(): void
    {
        [$id, $module] = $this->createCamera(2);
        $this->store($module, $this->jpeg(1));
        $this->store($module, $this->jpeg(2));
        $this->setKeep($id, 1, true);

        $this->assertTrue($this->store($module, $this->jpeg(3))['ok']);
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_001'));
        $this->assertSame($this->jpeg(3), $this->content($id, 'IMG_002'));

        // All images kept: nothing is overwritten
        $this->setKeep($id, 2, true);
        $this->assertFalse($this->store($module, $this->jpeg(4))['ok']);
        $this->assertSame($this->jpeg(3), $this->content($id, 'IMG_002'));
    }

    public function testReducedImageCountRemovesOldestNotKeptImages(): void
    {
        [$id, $module] = $this->createCamera(5);
        for ($i = 1; $i <= 5; $i++) {
            $this->store($module, $this->jpeg($i));
        }
        $this->setKeep($id, 1, true);

        IPS_SetProperty($id, 'Images', 2);
        IPS_ApplyChanges($id);

        $this->assertSame([1, 5], $this->seqs($id));
        $this->assertSame(['IMG_001', 'IMG_005'], $this->slotIdents($id));
    }

    public function testDamagedIndexIsNeverOverwritten(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, $this->jpeg(1));
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        IPS_SetMediaContent($indexID, base64_encode('{"version":1,"seq":"broken"}'));

        $this->assertFalse($this->store($module, $this->jpeg(2))['ok']);
        $this->assertFalse(CAR_DeleteImages($id));
        $this->assertSame('{"version":1,"seq":"broken"}', base64_decode(IPS_GetMediaContent($indexID)));
        $this->assertSame(['IMG_001'], $this->slotIdents($id));
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_001'));
    }

    public function testDeleteImages(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, $this->jpeg(1));
        $this->store($module, $this->jpeg(2));
        $this->setKeep($id, 1, true);

        $this->assertTrue(CAR_DeleteImages($id));
        $this->assertSame([], $this->slotIdents($id));
        $this->assertSame([], $this->index($id)['images']);
        // Numbering continues, so URLs of deleted images never point to new images
        $this->store($module, $this->jpeg(3));
        $this->assertSame([3], $this->seqs($id));
    }

    public function testFormShowsStorage(): void
    {
        [$id, $module] = $this->createCamera(3);
        $this->store($module, $this->jpeg(1));
        $this->store($module, $this->jpeg(2));

        $form = json_decode(IPS_GetConfigurationForm($id), true);
        $elements = array_column($form['elements'], null, 'name');
        $this->assertStringStartsWith('Stored images: 2, ', $elements['StorageInfo']['caption']);
    }

    public function testResizeOnlyDownscales(): void
    {
        if (!function_exists('imagescale')) {
            $this->markTestSkipped('GD not available');
        }
        $id = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($id);
        $module = IPS\InstanceManager::getInstanceInterface($id);
        $resize = new ReflectionMethod($module, 'ResizeImage');

        $large = $this->gdJpeg(2000, 1000);
        $result = $resize->invoke($module, $large);
        $this->assertSame([1280, 640], [$result['width'], $result['height']]);
        $this->assertSame([1280, 640], array_slice(getimagesizefromstring($result['data']), 0, 2));

        // Small images stay byte-identical
        $small = $this->gdJpeg(640, 480);
        $this->assertSame($small, $resize->invoke($module, $small)['data']);

        IPS_SetProperty($id, 'ResizeEnabled', false);
        IPS_ApplyChanges($id);
        $this->assertSame($large, $resize->invoke($module, $large)['data']);
    }

    private function createCamera(?int $Images, int $ConsoleID = 0): array
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', self::CAMERA_ID);
        if ($Images !== null) {
            IPS_SetProperty($id, 'Images', $Images);
        }
        if ($ConsoleID > 0) {
            IPS_ConnectInstance($id, $ConsoleID);
        }
        IPS_ApplyChanges($id);
        return [$id, IPS\InstanceManager::getInstanceInterface($id)];
    }

    private function store(object $Module, string $Jpeg, bool $Cached = true): array
    {
        $image = ['data' => $Jpeg, 'width' => 16, 'height' => 8];
        return (new ReflectionMethod($Module, 'StoreImage'))->invoke($Module, $image, 'manual', $Cached);
    }

    // Distinguishable images: the tiny JPEG plus a different number of trailing bytes
    private function jpeg(int $Number): string
    {
        return base64_decode(self::TINY_JPEG) . str_repeat("\0", $Number);
    }

    private function gdJpeg(int $Width, int $Height): string
    {
        $image = imagecreatetruecolor($Width, $Height);
        ob_start();
        imagejpeg($image, null, 80);
        return (string) ob_get_clean();
    }

    private function index(int $ID): array
    {
        return json_decode(base64_decode(IPS_GetMediaContent(IPS_GetObjectIDByIdent('ImageIndex', $ID))), true);
    }

    private function seqs(int $ID): array
    {
        $seqs = array_column($this->index($ID)['images'], 'seq');
        sort($seqs);
        return $seqs;
    }

    private function setKeep(int $ID, int $Slot, bool $Keep): void
    {
        $index = $this->index($ID);
        foreach ($index['images'] as &$entry) {
            if ($entry['slot'] === $Slot) {
                $entry['keep'] = $Keep;
            }
        }
        unset($entry);
        IPS_SetMediaContent(IPS_GetObjectIDByIdent('ImageIndex', $ID), base64_encode(json_encode($index)));
    }

    private function content(int $ID, string $Ident): string
    {
        return base64_decode(IPS_GetMediaContent(IPS_GetObjectIDByIdent($Ident, $ID)));
    }

    private function slotIdents(int $ID): array
    {
        $idents = [];
        foreach (IPS_GetChildrenIDs($ID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (str_starts_with($ident, 'IMG_')) {
                $idents[] = $ident;
            }
        }
        sort($idents);
        return $idents;
    }
}
