<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class CameraArchiveValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateCameraArchiveModule(): void
    {
        $this->validateModule(__DIR__ . '/../CameraArchive');
    }
}
