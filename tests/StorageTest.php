<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Ring buffer of cached image media and the index medium (phase 3)
class StorageTest extends TestCase
{
    private const MODULE = '{2AF53696-16BD-B081-D6C1-DCA8F3893CE4}';
    private const CAMERA_A = '653fd7e100601403e40003f3';
    private const CAMERA_B = '6900d73d01f57103e4808b14';

    // 16 x 8 pixel JPEG, so the ring buffer tests do not need GD
    private const TINY_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNTAK/9sAQwAQCwwODAoQDg0OEhEQExgoGhgWFhgxIyUdKDozPTw5Mzg3QEhcTkBEV0U3OFBtUVdfYmdoZz5NcXlwZHhcZWdj/9sAQwEREhIYFRgvGhovY0I4QmNjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj/8AAEQgACAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8AoUUUV7h55//Z';

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    public function testRingBufferOverwritesOldestSlot(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($this->store($module, self::CAMERA_A, 3, $this->jpeg($i))['ok']);
        }

        $this->assertSame(['IMG_' . self::CAMERA_A . '_001', 'IMG_' . self::CAMERA_A . '_002', 'IMG_' . self::CAMERA_A . '_003'], $this->slotIdents($id));
        $entries = $this->index($id)['cameras'][self::CAMERA_A];
        $this->assertCount(3, $entries);
        // Image 4 replaced slot 1, image 5 replaced slot 2
        $bySlot = array_column($entries, 'seq', 'slot');
        $this->assertSame([1 => 4, 2 => 5, 3 => 3], [1 => $bySlot[1], 2 => $bySlot[2], 3 => $bySlot[3]]);
        $this->assertSame($this->jpeg(4), $this->content($id, 'IMG_' . self::CAMERA_A . '_001'));
        $this->assertSame('manual', $entries[0]['trigger']);
        $this->assertSame(16, $entries[0]['width']);
    }

    public function testMediaAreCachedHiddenAndNamedOnce(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(1));

        $mediaID = IPS_GetObjectIDByIdent('IMG_' . self::CAMERA_A . '_001', $id);
        $media = IPS_GetMedia($mediaID);
        $this->assertSame(MEDIATYPE_IMAGE, $media['MediaType']);
        $this->assertTrue($media['MediaIsCached']);
        $this->assertSame(sprintf('media/CameraArchive_%d_%s_001.jpg', $id, self::CAMERA_A), $media['MediaFile']);
        $this->assertTrue(IPS_GetObject($mediaID)['ObjectIsHidden']);
        $this->assertSame('Haustür 01', IPS_GetObject($mediaID)['ObjectName']);

        // Name and visibility belong to the user after creation
        IPS_SetName($mediaID, 'Mein Bild');
        IPS_SetHidden($mediaID, false);
        $this->store($module, self::CAMERA_A, 1, $this->jpeg(2));
        $this->assertSame('Mein Bild', IPS_GetObject($mediaID)['ObjectName']);
        $this->assertFalse(IPS_GetObject($mediaID)['ObjectIsHidden']);

        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        $this->assertSame(MEDIATYPE_DOCUMENT, IPS_GetMedia($indexID)['MediaType']);
        $this->assertTrue(IPS_GetMedia($indexID)['MediaIsCached']);
    }

    public function testMemoryCacheOptionAppliesToNewAndExistingMedia(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(1));
        $slotID = IPS_GetObjectIDByIdent('IMG_' . self::CAMERA_A . '_001', $id);
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);

        // Switched off: existing media are no longer cached, new media are created without cache
        IPS_SetProperty($id, 'MemoryCache', false);
        IPS_ApplyChanges($id);
        $this->assertFalse(IPS_GetMedia($slotID)['MediaIsCached']);
        $this->assertFalse(IPS_GetMedia($indexID)['MediaIsCached']);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(2));
        $this->assertFalse(IPS_GetMedia(IPS_GetObjectIDByIdent('IMG_' . self::CAMERA_A . '_002', $id))['MediaIsCached']);
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_' . self::CAMERA_A . '_001'));
        $this->assertStringNotContainsString('RAM', (new ReflectionMethod($module, 'GetStorageInfo'))->invoke($module));

        // Switched on again: all media are cached
        IPS_SetProperty($id, 'MemoryCache', true);
        IPS_ApplyChanges($id);
        foreach (['IMG_' . self::CAMERA_A . '_001', 'IMG_' . self::CAMERA_A . '_002', 'ImageIndex'] as $ident) {
            $this->assertTrue(IPS_GetMedia(IPS_GetObjectIDByIdent($ident, $id))['MediaIsCached'], $ident);
        }
        $this->assertSame(2, count($this->index($id)['cameras'][self::CAMERA_A]));
    }

    public function testKeptImagesAreNotOverwritten(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 2]);
        $this->store($module, self::CAMERA_A, 2, $this->jpeg(1));
        $this->store($module, self::CAMERA_A, 2, $this->jpeg(2));
        $this->setKeep($id, self::CAMERA_A, 1, true);

        $this->assertTrue($this->store($module, self::CAMERA_A, 2, $this->jpeg(3))['ok']);
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_' . self::CAMERA_A . '_001'));
        $this->assertSame($this->jpeg(3), $this->content($id, 'IMG_' . self::CAMERA_A . '_002'));

        // All images kept: nothing is overwritten
        $this->setKeep($id, self::CAMERA_A, 2, true);
        $this->assertFalse($this->store($module, self::CAMERA_A, 2, $this->jpeg(4))['ok']);
        $this->assertSame($this->jpeg(3), $this->content($id, 'IMG_' . self::CAMERA_A . '_002'));
    }

    public function testReducedImageCountRemovesOldestNotKeptImages(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 5]);
        for ($i = 1; $i <= 5; $i++) {
            $this->store($module, self::CAMERA_A, 5, $this->jpeg($i));
        }
        $this->setKeep($id, self::CAMERA_A, 1, true);

        $this->setCameras($id, [self::CAMERA_A => 2]);
        IPS_ApplyChanges($id);

        $entries = $this->index($id)['cameras'][self::CAMERA_A];
        $this->assertSame([1, 5], array_column($entries, 'seq'));
        $this->assertSame(['IMG_' . self::CAMERA_A . '_001', 'IMG_' . self::CAMERA_A . '_005'], $this->slotIdents($id));
    }

    public function testDamagedIndexIsNeverOverwritten(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(1));
        $indexID = IPS_GetObjectIDByIdent('ImageIndex', $id);
        IPS_SetMediaContent($indexID, base64_encode('{"version":1,"seq":"broken"}'));

        $this->assertFalse($this->store($module, self::CAMERA_A, 3, $this->jpeg(2))['ok']);
        $this->assertSame('{"version":1,"seq":"broken"}', base64_decode(IPS_GetMediaContent($indexID)));
        $this->assertSame(['IMG_' . self::CAMERA_A . '_001'], $this->slotIdents($id));
        $this->assertSame($this->jpeg(1), $this->content($id, 'IMG_' . self::CAMERA_A . '_001'));
    }

    public function testDeleteImagesOfRemovedCameras(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3, self::CAMERA_B => 3]);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(1));
        $this->store($module, self::CAMERA_B, 3, $this->jpeg(2));
        $this->store($module, self::CAMERA_B, 3, $this->jpeg(3));

        $this->setCameras($id, [self::CAMERA_A => 3]);
        IPS_ApplyChanges($id);
        ob_start();
        CAR_UIDeleteRemovedCameraImages($id);
        ob_end_clean();

        $this->assertSame(['IMG_' . self::CAMERA_A . '_001'], $this->slotIdents($id));
        $this->assertSame([self::CAMERA_A], array_map('strval', array_keys($this->index($id)['cameras'])));

        $this->assertTrue(CAR_DeleteImages($id, self::CAMERA_A));
        $this->assertSame([], $this->slotIdents($id));
        $this->assertSame([], $this->index($id)['cameras']);
        $this->assertFalse(CAR_DeleteImages($id, '../x'));
    }

    public function testFormShowsStorage(): void
    {
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(1));
        $this->store($module, self::CAMERA_A, 3, $this->jpeg(2));

        $form = json_decode(IPS_GetConfigurationForm($id), true);
        $elements = array_column($form['elements'], null, 'name');
        $this->assertStringStartsWith('Stored images: 2, ', $elements['StorageInfo']['caption']);
        $this->assertSame(3, $elements['Cameras']['values'][0]['Images']);
        $this->assertStringStartsWith('2 (', $elements['Cameras']['values'][0]['Stored']);
    }

    public function testResizeOnlyDownscales(): void
    {
        if (!function_exists('imagescale')) {
            $this->markTestSkipped('GD not available');
        }
        [$id, $module] = $this->createInstance([self::CAMERA_A => 3]);
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
        foreach ($Cameras as $cameraID => $images) {
            $rows[] = ['Enabled' => true, 'Images' => $images, 'Name' => 'Haustür', 'Type' => 'UVC G3', 'State' => 'CONNECTED', 'FullHD' => true, 'CameraID' => $cameraID];
        }
        IPS_SetProperty($ID, 'Cameras', json_encode($rows));
    }

    private function store(object $Module, string $CameraID, int $Images, string $Jpeg): array
    {
        $camera = ['Enabled' => true, 'Images' => $Images, 'Name' => 'Haustür', 'Type' => 'UVC G3', 'State' => 'CONNECTED', 'FullHD' => true, 'CameraID' => $CameraID];
        $image = ['data' => $Jpeg, 'width' => 16, 'height' => 8];
        return (new ReflectionMethod($Module, 'StoreImage'))->invoke($Module, $camera, $image, 'manual');
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

    private function setKeep(int $ID, string $CameraID, int $Slot, bool $Keep): void
    {
        $index = $this->index($ID);
        foreach ($index['cameras'][$CameraID] as &$entry) {
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
