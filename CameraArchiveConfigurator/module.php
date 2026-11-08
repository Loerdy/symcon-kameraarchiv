<?php

declare(strict_types=1);

/**
 * Lists the cameras of the connected console and creates one camera instance per camera.
 */
class CameraArchiveConfigurator extends IPSModuleStrict
{
    private const DATA_TO_PARENT = '{9EC0ACA4-E5E8-FBE2-061E-944A1C1A13BB}';
    private const CAMERA_MODULE = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();
    }

    /**
     * The console also sends its packets for the cameras to the configurator: nothing to do here.
     */
    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $cameras = null;
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            $this->PatchFormField($form['elements'], 'Message', 'caption', $this->Translate('Not connected to a Camera Archive Console.'));
            $this->PatchFormField($form['elements'], 'Message', 'visible', true);
        } else {
            // A console that is not created yet returns false with a warning; the warning would break the form
            try {
                $raw = @$this->SendDataToParent((string) json_encode([
                    'DataID' => self::DATA_TO_PARENT,
                    'Buffer' => bin2hex((string) json_encode(['Command' => 'GetCameras']))
                ]));
            } catch (Throwable $exception) {
                $raw = null;
            }
            $answer = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($answer) && ($answer['ok'] ?? false) === true && is_array($answer['cameras'] ?? null)) {
                $cameras = $answer['cameras'];
            } else {
                $message = is_array($answer) && is_string($answer['message'] ?? null) ? $answer['message'] : '';
                $this->PatchFormField($form['elements'], 'Message', 'caption', sprintf($this->Translate('The cameras could not be fetched: %s'), $message));
                $this->PatchFormField($form['elements'], 'Message', 'visible', true);
            }
        }
        $this->PatchFormField($form['elements'], 'Cameras', 'values', $this->BuildValues($cameras, $parentID));
        return json_encode($form);
    }

    /**
     * Rows of the configurator: cameras of the console, then camera instances of this console that the console
     * does not know (anymore). $Cameras is null if the list could not be fetched.
     */
    private function BuildValues(?array $Cameras, int $ParentID): array
    {
        // Camera instances of this console by camera ID
        $instances = [];
        foreach (IPS_GetInstanceListByModuleID(self::CAMERA_MODULE) as $instanceID) {
            if ($ParentID > 0 && IPS_GetInstance($instanceID)['ConnectionID'] === $ParentID) {
                $instances[(string) IPS_GetProperty($instanceID, 'CameraID')] = $instanceID;
            }
        }

        $values = [];
        foreach ($Cameras ?? [] as $camera) {
            if (!is_array($camera) || !is_string($camera['id'] ?? null) || $camera['id'] === '') {
                continue;
            }
            $type = is_string($camera['type'] ?? null) ? $camera['type'] : '';
            // Third-party cameras have a type without "UVC" prefix; Protect delivers no snapshots for them
            $supported = str_starts_with($type, 'UVC');
            $instanceID = $instances[$camera['id']] ?? 0;
            unset($instances[$camera['id']]);
            $name = is_string($camera['name'] ?? null) && $camera['name'] !== '' ? $camera['name'] : $camera['id'];
            $row = [
                'name'       => $name,
                'cameraType' => $type,
                'state'      => is_string($camera['state'] ?? null) ? $camera['state'] : '',
                'snapshot'   => $this->Translate($supported ? 'OK' : 'No snapshot via Protect'),
                'cameraID'   => $camera['id'],
                'instanceID' => $instanceID
            ];
            if ($supported || $instanceID > 0) {
                $row['create'] = [
                    'moduleID'      => self::CAMERA_MODULE,
                    'configuration' => ['CameraID' => $camera['id']],
                    'name'          => $name
                ];
            }
            $values[] = $row;
        }

        // Instances whose camera is unknown to the console are shown in red (no "create")
        foreach ($instances as $cameraID => $instanceID) {
            $values[] = [
                'name'       => IPS_GetName($instanceID),
                'cameraType' => '',
                'state'      => $Cameras === null ? '' : $this->Translate('Not found on the console'),
                'snapshot'   => '',
                'cameraID'   => (string) $cameraID,
                'instanceID' => $instanceID
            ];
        }
        return $values;
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
