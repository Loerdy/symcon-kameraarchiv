<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Configurator rows and the data flow between console, configurator and cameras
class ConfiguratorTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CONFIGURATOR = '{C1099428-0FB4-37B2-DC0C-63201BB4A5CF}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';
    private const DATA_TO_PARENT = '{9EC0ACA4-E5E8-FBE2-061E-944A1C1A13BB}';
    private const DOOR = '653fd7e100601403e40003f3';
    private const GARDEN = '6900d73d01f57103e4808b14';
    private const TAPO = '6900d73d01f57103e4808b99';
    private const OLD = '6900d73d01f57103e4808b00';

    private int $console;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $this->console = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($this->console);
    }

    public function testRowsForCamerasAndExistingInstances(): void
    {
        $configurator = $this->createConfigurator();
        $door = $this->createCamera(self::DOOR, $this->console);
        $old = $this->createCamera(self::OLD, $this->console);
        // Instance of another console is not listed
        $otherConsole = IPS_CreateInstance(self::CONSOLE);
        $this->createCamera(self::GARDEN, $otherConsole);

        $values = $this->values($configurator, [
            ['id' => self::DOOR, 'name' => 'Haustür', 'type' => 'UVC G4 Doorbell', 'state' => 'CONNECTED'],
            ['id' => self::GARDEN, 'name' => 'Garten', 'type' => 'UVC G3', 'state' => 'DISCONNECTED'],
            ['id' => self::TAPO, 'name' => 'Tapo', 'type' => 'Tapo C500', 'state' => 'CONNECTED']
        ]);
        $rows = array_column($values, null, 'cameraID');

        $this->assertSame($door, $rows[self::DOOR]['instanceID']);
        $this->assertSame(['moduleID' => self::CAMERA, 'configuration' => ['CameraID' => self::DOOR], 'name' => 'Haustür'], $rows[self::DOOR]['create']);
        $this->assertSame(0, $rows[self::GARDEN]['instanceID']);
        $this->assertSame('Garten', $rows[self::GARDEN]['create']['name']);
        // Third-party camera without an instance cannot be created
        $this->assertArrayNotHasKey('create', $rows[self::TAPO]);
        $this->assertSame('No snapshot via Protect', $rows[self::TAPO]['snapshot']);
        // Instance whose camera the console does not know: listed without "create"
        $this->assertSame($old, $rows[self::OLD]['instanceID']);
        $this->assertArrayNotHasKey('create', $rows[self::OLD]);
        $this->assertSame('Not found on the console', $rows[self::OLD]['state']);
        $this->assertCount(4, $values);
    }

    public function testFormWithoutConsoleOrWithUnreachableConsole(): void
    {
        $configurator = IPS_CreateInstance(self::CONFIGURATOR);
        IPS_ApplyChanges($configurator);
        $elements = array_column(json_decode(IPS_GetConfigurationForm($configurator), true)['elements'], null, 'name');
        $this->assertTrue($elements['Message']['visible']);
        $this->assertSame([], $elements['Cameras']['values']);

        // Console without host: the message of the console is shown
        IPS_ConnectInstance($configurator, $this->console);
        $elements = array_column(json_decode(IPS_GetConfigurationForm($configurator), true)['elements'], null, 'name');
        $this->assertTrue($elements['Message']['visible']);
        $this->assertStringContainsString('Protect console host and API key', $elements['Message']['caption']);
    }

    public function testConsoleAnswersSettingsAndRejectsInvalidPackets(): void
    {
        $module = IPS\InstanceManager::getInstanceInterface($this->console);
        $answer = json_decode($module->ForwardData(json_encode(['DataID' => self::DATA_TO_PARENT, 'Buffer' => bin2hex('{"Command":"GetSettings"}')])), true);
        $this->assertSame(['ok' => true, 'message' => '', 'memoryCache' => true], $answer);

        foreach (['', 'zz', 'abc', bin2hex('no json'), bin2hex('{"Command":"Unknown"}')] as $buffer) {
            $answer = json_decode($module->ForwardData(json_encode(['DataID' => self::DATA_TO_PARENT, 'Buffer' => $buffer])), true);
            $this->assertFalse($answer['ok'], $buffer);
        }
    }

    public function testSnapshotErrorOfTheConsoleReachesTheCamera(): void
    {
        $camera = $this->createCamera(self::DOOR, $this->console);
        ob_start();
        CAR_UITestSnapshot($camera);
        $message = (string) ob_get_clean();
        $this->assertStringContainsString('Protect console host and API key', $message);
        $this->assertSame(201, IPS_GetInstance($this->console)['InstanceStatus']);
    }

    private function createConfigurator(): int
    {
        $id = IPS_CreateInstance(self::CONFIGURATOR);
        IPS_ConnectInstance($id, $this->console);
        IPS_ApplyChanges($id);
        return $id;
    }

    private function createCamera(string $CameraID, int $ConsoleID): int
    {
        $id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($id, 'CameraID', $CameraID);
        IPS_ConnectInstance($id, $ConsoleID);
        IPS_ApplyChanges($id);
        return $id;
    }

    private function values(int $Configurator, array $Cameras): array
    {
        $module = IPS\InstanceManager::getInstanceInterface($Configurator);
        return (new ReflectionMethod($module, 'BuildValues'))->invoke($module, $Cameras, $this->console);
    }
}
