<?php

declare(strict_types=1);

/**
 * Settings of all camera instances in one table. The cameras keep their own properties; this instance only shows
 * them and writes the rows changed in its form back to the cameras.
 */
class CameraArchiveSettings extends IPSModuleStrict
{
    private const CAMERA_MODULE = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    // Rows as written to the cameras the last time. Only rows that differ from it were changed in the form; all
    // other rows are left alone, so that a restart or an apply without changes never writes old values back.
    private const APPLIED_ATTRIBUTE = 'Applied';

    // Editable camera properties with type and limits (same limits as the camera module)
    private const FIELDS = [
        'DisplayName'         => ['type' => 'string'],
        'Images'              => ['type' => 'int', 'min' => 1, 'max' => 200],
        'CameraLockout'       => ['type' => 'int', 'min' => 0, 'max' => 3600],
        'LiveAutoStart'       => ['type' => 'bool'],
        'LiveDuration'        => ['type' => 'int', 'min' => 1, 'max' => 30],
        'LiveIntervalSeconds' => ['type' => 'int', 'min' => 1, 'max' => 30],
        'NASUpload'           => ['type' => 'bool'],
        'NASFolder'           => ['type' => 'string'],
        'NASRetention'        => ['type' => 'int', 'min' => 0, 'max' => 3650]
    ];

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Cameras', '[]');
        $this->RegisterAttributeString(self::APPLIED_ATTRIBUTE, '[]');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Other instances are only changed once the kernel is ready
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $rows = $this->ReadRows($this->ReadPropertyString('Cameras'));
        $applied = $this->ReadRows($this->ReadAttributeString(self::APPLIED_ATTRIBUTE));
        foreach ($rows as $instanceID => $row) {
            if (($applied[$instanceID] ?? null) !== $row) {
                $this->ApplyRow($instanceID, $row);
            }
        }
        $stored = [];
        foreach ($rows as $instanceID => $row) {
            $stored[] = ['instanceID' => $instanceID] + $row;
        }
        $this->WriteAttributeString(self::APPLIED_ATTRIBUTE, (string) json_encode($stored));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // The list uses loadValuesFromConfiguration = false: it always shows the current values of the cameras
        $this->PatchFormField($form['elements'], 'Cameras', 'values', $this->BuildRows());
        return json_encode($form);
    }

    /**
     * Current values of all camera instances.
     */
    private function BuildRows(): array
    {
        $rows = [];
        foreach (IPS_GetInstanceListByModuleID(self::CAMERA_MODULE) as $instanceID) {
            $row = ['instanceID' => $instanceID, 'name' => IPS_GetName($instanceID)];
            foreach (array_keys(self::FIELDS) as $field) {
                $row[$field] = IPS_GetProperty($instanceID, $field);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Rows of the list by instance ID, with values converted and limited. Rows without a valid ID are dropped.
     */
    private function ReadRows(string $JSON): array
    {
        $data = json_decode($JSON, true);
        $rows = [];
        foreach (is_array($data) ? $data : [] as $entry) {
            $instanceID = is_array($entry) ? ($entry['instanceID'] ?? null) : null;
            if (!is_int($instanceID) || $instanceID <= 0) {
                continue;
            }
            $row = [];
            foreach (self::FIELDS as $field => $definition) {
                $row[$field] = $this->ConvertValue($entry[$field] ?? null, $definition);
            }
            $rows[$instanceID] = $row;
        }
        return $rows;
    }

    private function ConvertValue(mixed $Value, array $Definition): string|int|bool
    {
        switch ($Definition['type']) {
            case 'bool':
                return $Value === true;
            case 'int':
                $number = is_int($Value) || is_float($Value) || (is_string($Value) && is_numeric($Value)) ? (int) $Value : $Definition['min'];
                return max($Definition['min'], min($Definition['max'], $number));
            default:
                return is_string($Value) ? trim($Value) : '';
        }
    }

    /**
     * Writes the values of a row that differ from the camera and applies the camera once.
     */
    private function ApplyRow(int $InstanceID, array $Row): void
    {
        if (!IPS_InstanceExists($InstanceID) || IPS_GetInstance($InstanceID)['ModuleInfo']['ModuleID'] !== self::CAMERA_MODULE) {
            return;
        }
        $changed = false;
        foreach ($Row as $field => $value) {
            if (IPS_GetProperty($InstanceID, $field) !== $value) {
                IPS_SetProperty($InstanceID, $field, $value);
                $changed = true;
            }
        }
        if (!$changed) {
            return;
        }
        $this->SendDebug('Apply', sprintf('%s (%d)', IPS_GetName($InstanceID), $InstanceID), 0);
        if (!@IPS_ApplyChanges($InstanceID)) {
            $this->LogMessage(sprintf($this->Translate('Settings of %s could not be applied.'), IPS_GetName($InstanceID)), KL_WARNING);
        }
    }

    /**
     * Sets a parameter of a named form field, also inside layout elements.
     */
    private function PatchFormField(array &$Items, string $Name, string $Parameter, mixed $Value): void
    {
        foreach ($Items as &$item) {
            if (($item['name'] ?? '') === $Name) {
                $item[$Parameter] = $Value;
            }
            if (isset($item['items']) && is_array($item['items'])) {
                $this->PatchFormField($item['items'], $Name, $Parameter, $Value);
            }
        }
        unset($item);
    }
}
