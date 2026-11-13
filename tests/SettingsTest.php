<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Settings instance: table of all cameras, only rows changed in the form are written back
class SettingsTest extends TestCase
{
    private const SETTINGS = '{4433B110-9053-C3A3-BBA2-AE61139B5CBA}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';
    // Symcon only saves the columns with an edit field (seen on the live system)
    private const EDIT_COLUMNS = ['DisplayName', 'Images', 'CameraLockout', 'LiveAutoStart', 'LiveDuration', 'LiveIntervalSeconds', 'NASUpload', 'NASFolder', 'NASRetention'];

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    public function testFormShowsCurrentValuesOfAllCamerasSortedByName(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        IPS_SetProperty($garden, 'DisplayName', 'Gartenhaus');
        IPS_SetProperty($garden, 'LiveIntervalSeconds', 5);
        IPS_ApplyChanges($garden);
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $this->assertSame([$garden, $door], array_column($rows, 'instanceID'));
        $this->assertSame('Gartenhaus', $rows[0]['DisplayName']);
        $this->assertSame(5, $rows[0]['LiveIntervalSeconds']);
        $this->assertSame('Haustür', $rows[1]['name']);
        $this->assertSame('', $rows[1]['DisplayName']);
        $this->assertSame(1, $rows[1]['LiveIntervalSeconds']);
        $this->assertSame(array_merge(['instanceID', 'name'], self::EDIT_COLUMNS), array_keys($rows[1]));
    }

    public function testChangedRowIsWrittenToTheCamera(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $rows[1]['DisplayName'] = ' Eingang ';
        $rows[1]['Images'] = 500;
        $rows[1]['LiveAutoStart'] = true;
        $rows[1]['LiveIntervalSeconds'] = 3;
        $rows[1]['NASUpload'] = true;
        $rows[1]['NASFolder'] = 'Tür';
        $rows[1]['NASRetention'] = 7;
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
        $rows[1]['DisplayName'] = 'Eingang';
        $this->save($settings, $rows);

        // Changed directly in the camera afterwards
        IPS_SetProperty($garden, 'LiveDuration', 10);
        IPS_ApplyChanges($garden);

        // A restart applies the settings instance again with its stored (now old) rows
        IPS_ApplyChanges($settings);
        $this->assertSame(10, IPS_GetProperty($garden, 'LiveDuration'));

        // The next change in the form starts from the current values of the cameras
        $rows = $this->rows($settings);
        $rows[1]['DisplayName'] = 'Tür';
        $this->save($settings, $rows);
        $this->assertSame('Tür', IPS_GetProperty($door, 'DisplayName'));
        $this->assertSame(10, IPS_GetProperty($garden, 'LiveDuration'));
    }

    public function testRestartAfterReorderedFormWritesNothing(): void
    {
        $door = $this->createCamera('Haustür');
        $garden = $this->createCamera('Garten');
        IPS_SetProperty($garden, 'Images', 50);
        IPS_ApplyChanges($garden);
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $rows[1]['DisplayName'] = 'Eingang';
        $this->save($settings, $rows);

        // Renamed: the next opened form has another order, but is not saved
        IPS_SetName($door, 'Auffahrt');
        $this->assertSame([$door, $garden], array_column($this->rows($settings), 'instanceID'));
        IPS_ApplyChanges($settings);

        $this->assertSame(5, IPS_GetProperty($door, 'Images'));
        $this->assertSame(50, IPS_GetProperty($garden, 'Images'));
        $this->assertSame('', IPS_GetProperty($garden, 'DisplayName'));
    }

    public function testRowsThatDoNotMatchTheFormAreNotWritten(): void
    {
        $door = $this->createCamera('Haustür');
        $settings = $this->createSettings();

        $rows = $this->rows($settings);
        $rows[0]['DisplayName'] = 'Eingang';
        $rows[] = $rows[0];
        $this->save($settings, $rows);

        $this->assertSame('', IPS_GetProperty($door, 'DisplayName'));
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

    // Saves the rows like the form does: only the columns with an edit field
    private function save(int $Settings, array $Rows): void
    {
        $saved = array_map(fn (array $Row): array => array_intersect_key($Row, array_flip(self::EDIT_COLUMNS)), $Rows);
        IPS_SetProperty($Settings, 'Cameras', json_encode($saved));
        IPS_ApplyChanges($Settings);
    }
}
