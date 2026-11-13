<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Settings instance: table of all cameras, only rows changed in the form are written back
class SettingsTest extends TestCase
{
    private const SETTINGS = '{4433B110-9053-C3A3-BBA2-AE61139B5CBA}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    public function testFormShowsCurrentValuesOfAllCameras(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        IPS_SetProperty($garden, 'DisplayName', 'Gartenhaus');
        IPS_SetProperty($garden, 'LiveIntervalSeconds', 5);
        IPS_ApplyChanges($garden);
        $settings = $this->createSettings();

        $rows = array_column($this->rows($settings), null, 'instanceID');
        $this->assertSame([$door, $garden], array_keys($rows));
        $this->assertSame('Haustür', $rows[$door]['name']);
        $this->assertSame('', $rows[$door]['DisplayName']);
        $this->assertSame(1, $rows[$door]['LiveIntervalSeconds']);
        $this->assertSame('Gartenhaus', $rows[$garden]['DisplayName']);
        $this->assertSame(5, $rows[$garden]['LiveIntervalSeconds']);
        $this->assertSame(
            ['instanceID', 'name', 'DisplayName', 'Images', 'CameraLockout', 'LiveAutoStart', 'LiveDuration', 'LiveIntervalSeconds', 'NASUpload', 'NASFolder', 'NASRetention'],
            array_keys($rows[$door])
        );
    }

    public function testChangedRowIsWrittenToTheCamera(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $rows[0]['DisplayName'] = ' Eingang ';
        $rows[0]['Images'] = 500;
        $rows[0]['LiveAutoStart'] = true;
        $rows[0]['LiveIntervalSeconds'] = 3;
        $rows[0]['NASUpload'] = true;
        $rows[0]['NASFolder'] = 'Tür';
        $rows[0]['NASRetention'] = 7;
        $this->save($settings, $rows);

        $this->assertSame('Eingang', IPS_GetProperty($door, 'DisplayName'));
        // Limited like in the camera
        $this->assertSame(200, IPS_GetProperty($door, 'Images'));
        $this->assertTrue(IPS_GetProperty($door, 'LiveAutoStart'));
        $this->assertSame(3, IPS_GetProperty($door, 'LiveIntervalSeconds'));
        $this->assertTrue(IPS_GetProperty($door, 'NASUpload'));
        $this->assertSame('Tür', IPS_GetProperty($door, 'NASFolder'));
        $this->assertSame(7, IPS_GetProperty($door, 'NASRetention'));
        $this->assertSame('', IPS_GetProperty($garden, 'DisplayName'));
    }

    public function testUnchangedRowsDoNotOverwriteLaterCameraChanges(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $rows[0]['DisplayName'] = 'Eingang';
        $this->save($settings, $rows);

        // Changed directly in the camera afterwards
        IPS_SetProperty($garden, 'LiveDuration', 10);
        IPS_ApplyChanges($garden);

        // A restart applies the settings instance again with its stored (now old) rows
        IPS_ApplyChanges($settings);
        $this->assertSame(10, IPS_GetProperty($garden, 'LiveDuration'));

        // The next change in the form starts from the current values of the cameras
        $rows = $this->rows($settings);
        $rows[0]['DisplayName'] = 'Tür';
        $this->save($settings, $rows);
        $this->assertSame('Tür', IPS_GetProperty($door, 'DisplayName'));
        $this->assertSame(10, IPS_GetProperty($garden, 'LiveDuration'));
    }

    public function testRowsOfOtherInstancesAreIgnored(): void
    {
        $door = $this->createCamera('Haustür');
        $settings = $this->createSettings();
        $other = IPS_CreateInstance(self::SETTINGS);

        $rows = $this->rows($settings);
        $rows[] = array_merge($rows[0], ['instanceID' => $other, 'DisplayName' => 'X']);
        $rows[] = array_merge($rows[0], ['instanceID' => 99999, 'DisplayName' => 'X']);
        $rows[0]['DisplayName'] = 'Eingang';
        $this->save($settings, $rows);

        $this->assertSame('Eingang', IPS_GetProperty($door, 'DisplayName'));
    }

    private function createCamera(string $Name): int
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_SetName($id, $Name);
        IPS_ApplyChanges($id);
        return $id;
    }

    private function createSettings(): int
    {
        $id = IPS_CreateInstance(self::SETTINGS);
        IPS_ApplyChanges($id);
        return $id;
    }

    private function rows(int $Settings): array
    {
        $form = json_decode(IPS_GetConfigurationForm($Settings), true);
        foreach ($form['elements'] as $element) {
            if (($element['name'] ?? '') === 'Cameras') {
                return $element['values'];
            }
        }
        $this->fail('List not found');
    }

    private function save(int $Settings, array $Rows): void
    {
        IPS_SetProperty($Settings, 'Cameras', json_encode($Rows));
        IPS_ApplyChanges($Settings);
    }
}
