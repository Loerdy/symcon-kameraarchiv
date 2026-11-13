<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class CameraArchiveValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateConsoleModule(): void
    {
        $this->validateModule(__DIR__ . '/../CameraArchiveConsole');
    }

    public function testValidateConfiguratorModule(): void
    {
        $this->validateModule(__DIR__ . '/../CameraArchiveConfigurator');
    }

    public function testValidateCameraModule(): void
    {
        $this->validateModule(__DIR__ . '/../CameraArchiveCamera');
    }

    public function testValidateSettingsModule(): void
    {
        $this->validateModule(__DIR__ . '/../CameraArchiveSettings');
    }
}
