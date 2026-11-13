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
    // Instance IDs of the rows in the order the form showed them. Symcon only saves the columns with an edit field,
    // so the saved rows carry no instance ID: row n belongs to the n-th instance of this list.
    private const FORM_ATTRIBUTE = 'FormInstances';
    // Saved rows of the last apply. Unchanged rows (restart, IPS_ApplyChanges without a change in the form) are not
    // processed again: the order of FormInstances may have changed since, because every opened form sets it.
    private const SAVED_ATTRIBUTE = 'Saved';

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
        $this->RegisterAttributeString(self::FORM_ATTRIBUTE, '[]');
        $this->RegisterAttributeString(self::SAVED_ATTRIBUTE, '[]');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Other instances are only changed once the kernel is ready
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $property = $this->ReadPropertyString('Cameras');
        if ($property === $this->ReadAttributeString(self::SAVED_ATTRIBUTE)) {
            return;
        }
        $this->WriteAttributeString(self::SAVED_ATTRIBUTE, $property);
        $saved = json_decode($property, true);
        $saved = is_array($saved) ? $saved : [];
        $instances = json_decode($this->ReadAttributeString(self::FORM_ATTRIBUTE), true);
        $instances = is_array($instances) ? $instances : [];
        if (count($saved) !== count($instances)) {
            // Rows and instances of the form do not match (should not happen): better write nothing
            if ($saved !== []) {
                $this->LogMessage($this->Translate('The rows do not match the cameras of the form. Nothing was changed, please open the form again.'), KL_WARNING);
            }
            return;
        }
        $rows = $this->ReadRows($saved, $instances);
        $applied = $this->ReadRows(json_decode($this->ReadAttributeString(self::APPLIED_ATTRIBUTE), true) ?: [], []);
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
        $rows = $this->BuildRows();
        $this->WriteAttributeString(self::FORM_ATTRIBUTE, (string) json_encode(array_column($rows, 'instanceID')));
        $this->PatchFormField($form['elements'], 'Cameras', 'values', $rows);
        return json_encode($form);
    }

    /**
     * Current values of all camera instances, sorted by name. The list itself does not sort, so that the saved
     * rows keep this order.
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
        usort($rows, fn (array $A, array $B): int => [mb_strtolower($A['name']), $A['instanceID']] <=> [mb_strtolower($B['name']), $B['instanceID']]);
        return $rows;
    }

    /**
     * Rows by instance ID, with values converted and limited. The ID comes from the row itself or, for rows saved
     * by the form, from $Instances at the same position. Rows without a valid ID are dropped.
     */
    private function ReadRows(array $Data, array $Instances): array
    {
        $rows = [];
        foreach (array_values($Data) as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $instanceID = $entry['instanceID'] ?? $Instances[$position] ?? null;
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
