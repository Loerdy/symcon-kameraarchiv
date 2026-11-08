<?php

declare(strict_types=1);

/**
 * One camera of the Protect console: triggers, ring buffer of the images below the instance, tile and image WebHook.
 * Snapshots are requested from the console (parent) via the data flow.
 */
class CameraArchiveCamera extends IPSModuleStrict
{
    // Instance status codes, see "status" in form.json
    private const STATUS_CAMERA_MISSING = 201;
    private const STATUS_NO_CONSOLE = 202;

    // Data flow to the console. Packets of the console ({0EACC956-88F0-E151-304C-A22F37DDA6EA}) arrive in ReceiveData.
    private const DATA_TO_PARENT = '{9EC0ACA4-E5E8-FBE2-061E-944A1C1A13BB}';

    // Images kept in RAM
    private const IMAGES_DEFAULT = 5;
    private const IMAGES_MIN = 1;
    private const IMAGES_MAX = 200;

    // Ring buffer: image media below the instance, index in a document medium.
    // With the in-memory cache of the console (default) both are cached (IPS_SetMediaCached), so they live in RAM and
    // are only written at shutdown. Without it every image is written to the storage immediately.
    private const INDEX_IDENT = 'ImageIndex';
    private const INDEX_VERSION = 1;
    private const SLOT_IDENT_PREFIX = 'IMG_';
    private const STORAGE_LOCK_TIMEOUT = 5000;

    // Triggers: conditions of a trigger row
    private const CONDITION_CHANGE = 0;
    private const CONDITION_UPDATE = 1;
    private const CONDITION_CHANGE_TO = 2;
    private const CONDITION_UPDATE_WITH = 3;

    private const DELAY_MAX = 60;
    private const LOCKOUT_MAX = 3600;
    private const TRIGGER_LOCKOUT_DEFAULT = 30;
    private const CAMERA_LOCKOUT_DEFAULT = 5;

    // Queue of due snapshots, kept in a buffer (RAM only, no flash writes per trigger)
    private const QUEUE_TIMER = 'ProcessQueue';
    private const QUEUE_BUFFER = 'TriggerState';
    private const QUEUE_LOCK_TIMEOUT = 2000;

    // Images are delivered by the WebHook of the instance. WebHooks are reachable without authentication
    // (also via Connect), so every request needs the random token that only the tiles know.
    private const HOOK_PREFIX = 'camera-archive-';
    private const TOKEN_ATTRIBUTE = 'HookToken';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('CameraID', '');
        $this->RegisterPropertyInteger('Images', self::IMAGES_DEFAULT);
        $this->RegisterPropertyString('Triggers', '[]');
        $this->RegisterPropertyInteger('CameraLockout', self::CAMERA_LOCKOUT_DEFAULT);

        // Secret of the image WebHook, created once in ApplyChanges
        $this->RegisterAttributeString(self::TOKEN_ATTRIBUTE, '');

        // A single timer, always set to the next due snapshot of the queue (no polling)
        $this->RegisterTimer(self::QUEUE_TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::QUEUE_TIMER . '\', 0);');

        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML);
        // The hook is volatile and registered again on every start (see RegisterHook in the SDK documentation)
        $this->RegisterHook(self::HOOK_PREFIX . $this->InstanceID);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->Initialize();
                break;
            case VM_UPDATE:
                // Only queue snapshots here; fetching happens in the timer (no blocking in MessageSink)
                $this->HandleVariableUpdate($SenderID);
                break;
            case OM_CHANGENAME:
                // The name of the instance is the name of the camera button in the overview
                $this->UpdateTile();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->SetStatus($this->CheckConfiguration());
                break;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === self::QUEUE_TIMER) {
            $this->ProcessQueue();
            return;
        }
        throw new Exception(sprintf('Invalid ident %s', $Ident));
    }

    /**
     * Requests of the console. "GetTileState" returns the state of this camera for the overview tile.
     */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $buffer = is_array($data) && is_string($data['Buffer'] ?? null) ? $data['Buffer'] : '';
        if ($buffer === '' || strlen($buffer) % 2 !== 0 || !ctype_xdigit($buffer)) {
            return '';
        }
        $request = json_decode((string) hex2bin($buffer), true);
        if (!is_array($request)) {
            return '';
        }
        switch ($request['Command'] ?? '') {
            case 'GetTileState':
                return (string) json_encode($this->BuildCameraState() + ['position' => IPS_GetObject($this->InstanceID)['ObjectPosition']], JSON_INVALID_UTF8_SUBSTITUTE);
            case 'Settings':
                if (is_bool($request['MemoryCache'] ?? null)) {
                    $this->ApplyCacheMode($request['MemoryCache']);
                }
                return '';
        }
        return '';
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        if ($this->ReadAttributeString(self::TOKEN_ATTRIBUTE) === '') {
            $this->WriteAttributeString(self::TOKEN_ATTRIBUTE, bin2hex(random_bytes(16)));
        }

        $this->RegisterTriggerMessages();
        $this->RegisterMessage($this->InstanceID, OM_CHANGENAME);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        // Objects and other instances are only touched once the kernel is ready (best practice),
        // otherwise IPS_KERNELSTARTED does it
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Initialize();
        }

        // No network access here: ApplyChanges also runs during startup and must not block.
        $this->SetStatus($this->CheckConfiguration());
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // The list uses loadValuesFromConfiguration = false, so the saved rows are filled in here
        // (store review guideline 13). This allows the row colors.
        $this->PatchFormField($form['elements'], 'Triggers', 'values', $this->BuildTriggerRows());
        $this->PatchFormField($form['elements'], 'StorageInfo', 'caption', $this->GetStorageInfo());
        return json_encode($form);
    }

    /**
     * Tile of the HTML-SDK: static HTML plus the current state, which also carries the hook address and token.
     */
    public function GetVisualizationTile(): string
    {
        // Instance names are set by the user: escape everything that could end the script element
        $json = json_encode($this->BuildTileState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        return file_get_contents(__DIR__ . '/module.html') . '<script>handleMessage(' . $json . ');</script>';
    }

    /**
     * Fetches a snapshot of the camera and stores it in the ring buffer.
     */
    public function TakeSnapshot(): bool
    {
        $result = $this->SnapshotAndStore('manual');
        if (!$result['ok']) {
            $this->SendDebug('TakeSnapshot', $result['message'], 0);
        }
        return $result['ok'];
    }

    /**
     * Deletes all stored images of the camera, including images marked as "keep".
     */
    public function DeleteImages(): bool
    {
        $deleted = $this->DeleteAllImages();
        if ($deleted) {
            $this->UpdateTile();
        }
        return $deleted;
    }

    /**
     * Button "Test snapshot": Fetches a snapshot and shows the result.
     */
    public function UITestSnapshot(): void
    {
        $result = $this->SnapshotAndStore('manual');
        $this->UpdateFormField('StorageInfo', 'caption', $this->GetStorageInfo());
        echo $result['message'];
    }

    /**
     * Button "Delete all images".
     */
    public function UIDeleteImages(): void
    {
        $deleted = $this->DeleteImages();
        $this->UpdateFormField('StorageInfo', 'caption', $this->GetStorageInfo());
        echo $this->Translate($deleted ? 'All images deleted' : 'The images could not be deleted. Details in the debug output.');
    }

    /**
     * Edit dialog of a trigger row. Called by the PHP code of the list parameter "form", which runs outside the module.
     * The values of the row are filled in by the console via the element names.
     */
    public function UITriggerForm(int $VariableID, int $Condition): string
    {
        // SelectValue is not used: the dialog failed to load with it ("Unexpected end of JSON input").
        // Instead the dialog holds a selection for booleans and a text field for numbers and text; onChange shows the
        // field matching the type of the selected variable.
        $fields = $this->GetValueFieldState($VariableID, $Condition);
        $update = 'CAR_UITriggerUpdate($id, $VariableID, $Condition);';
        return json_encode([
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => $this->Translate('Active')],
            ['type' => 'ValidationTextBox', 'name' => 'Name', 'caption' => $this->Translate('Name (empty: name of the variable)')],
            ['type' => 'SelectVariable', 'name' => 'VariableID', 'caption' => $this->Translate('Variable'), 'onChange' => $update],
            ['type' => 'Select', 'name' => 'Condition', 'caption' => $this->Translate('Condition'), 'options' => $this->GetConditionOptions(), 'onChange' => $update],
            [
                'type'    => 'Select',
                'name'    => 'ValueBool',
                'caption' => $this->Translate('Value'),
                'options' => [
                    ['caption' => $this->Translate('true (on)'), 'value' => 'true'],
                    ['caption' => $this->Translate('false (off)'), 'value' => 'false']
                ],
                'visible' => $fields['ValueBool']
            ],
            ['type' => 'ValidationTextBox', 'name' => 'Value', 'caption' => $fields['caption'], 'visible' => $fields['Value']],
            ['type' => 'Label', 'name' => 'ValueHint', 'caption' => $this->Translate('Select a variable to set the value.'), 'visible' => $fields['ValueHint']],
            ['type' => 'NumberSpinner', 'name' => 'Delay', 'caption' => $this->Translate('Delay'), 'suffix' => 's', 'minimum' => 0, 'maximum' => self::DELAY_MAX],
            ['type' => 'NumberSpinner', 'name' => 'Lockout', 'caption' => $this->Translate('Lockout time of this trigger'), 'suffix' => 's', 'minimum' => 0, 'maximum' => self::LOCKOUT_MAX]
        ]);
    }

    /**
     * onChange of variable and condition in the trigger dialog: shows the value field matching the variable type.
     */
    public function UITriggerUpdate(int $VariableID, int $Condition): void
    {
        $fields = $this->GetValueFieldState($VariableID, $Condition);
        $this->UpdateFormField('ValueBool', 'visible', $fields['ValueBool']);
        $this->UpdateFormField('Value', 'caption', $fields['caption']);
        $this->UpdateFormField('Value', 'visible', $fields['Value']);
        $this->UpdateFormField('ValueHint', 'visible', $fields['ValueHint']);
    }

    /**
     * WebHook: delivers one stored image. Parameters: token, slot, seq.
     * The sequence number makes every URL unique per image, so a reused slot never returns a cached older image.
     */
    protected function ProcessHookData(): void
    {
        $token = $this->ReadAttributeString(self::TOKEN_ATTRIBUTE);
        $given = $_GET['token'] ?? null;
        if ($token === '' || !is_string($given) || !hash_equals($token, $given)) {
            $this->SendDebug('Hook', 'Request without valid token rejected', 0);
            $this->SendHookError(403, 'Forbidden');
            return;
        }

        $slot = filter_var($_GET['slot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::IMAGES_MAX]]);
        $seq = filter_var($_GET['seq'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($slot === false || $seq === false) {
            $this->SendHookError(400, 'Bad Request');
            return;
        }

        // Index and content are read under the lock, so the slot cannot be overwritten in between
        if (!$this->EnterStorageLock()) {
            $this->SendHookError(503, 'Service Unavailable');
            return;
        }
        try {
            $data = $this->ReadStoredImage($slot, $seq);
        } finally {
            $this->LeaveStorageLock();
        }
        if ($data === null) {
            $this->SendDebug('Hook', sprintf('Slot %d seq %d: not found', $slot, $seq), 0);
            $this->SendHookError(404, 'Not Found');
            return;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $data;
    }

    /**
     * Setup that needs the kernel to be ready: cache mode of the media, image count, trigger state, tiles.
     */
    private function Initialize(): void
    {
        $cached = $this->GetMemoryCacheSetting();
        if ($cached !== null) {
            $this->ApplyCacheMode($cached);
        }
        $this->TrimImages();
        $this->InitTriggerState();
        // Image count or name may have changed
        $this->UpdateTile();
    }

    /**
     * Returns IS_ACTIVE if all requirements are met, otherwise the matching error status.
     */
    private function CheckConfiguration(): int
    {
        if (!$this->IsValidCameraID($this->ReadPropertyString('CameraID'))) {
            return self::STATUS_CAMERA_MISSING;
        }
        if (!$this->HasConsole()) {
            return self::STATUS_NO_CONSOLE;
        }
        return IS_ACTIVE;
    }

    /**
     * True if the instance is connected to a console. Its status is not checked: after a failed request the console
     * shows an error status, but the next request must still be tried.
     */
    private function HasConsole(): bool
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $parentID > 0 && IPS_InstanceExists($parentID);
    }

    /**
     * Sends a request to the console. Result: decoded answer with at least ok and message.
     */
    private function SendToConsole(array $Request): array
    {
        if (!$this->HasConsole()) {
            return ['ok' => false, 'message' => $this->Translate('No Protect console connected')];
        }
        $answer = $this->SendDataToParent((string) json_encode(['DataID' => self::DATA_TO_PARENT, 'Buffer' => bin2hex((string) json_encode($Request))]));
        $result = json_decode($answer, true);
        if (!is_array($result) || !is_bool($result['ok'] ?? null)) {
            return ['ok' => false, 'message' => $this->Translate('Invalid answer of the Protect console')];
        }
        $result['message'] = is_string($result['message'] ?? null) ? $result['message'] : '';
        return $result;
    }

    /**
     * Option "Use in-memory cache" of the console, or null if no console is connected.
     */
    private function GetMemoryCacheSetting(): ?bool
    {
        if (!$this->HasConsole()) {
            return null;
        }
        $result = $this->SendToConsole(['Command' => 'GetSettings']);
        return $result['ok'] && is_bool($result['memoryCache'] ?? null) ? $result['memoryCache'] : null;
    }

    /**
     * Fetches a snapshot via the console and stores it. Result: ok, message.
     */
    private function SnapshotAndStore(string $Trigger): array
    {
        $cameraID = $this->ReadPropertyString('CameraID');
        if (!$this->IsValidCameraID($cameraID)) {
            return ['ok' => false, 'message' => $this->Translate('No camera selected. Create the instance with the configurator.')];
        }
        $result = $this->SendToConsole(['Command' => 'Snapshot', 'CameraID' => $cameraID]);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message']];
        }
        $data = is_string($result['image'] ?? null) ? base64_decode($result['image'], true) : false;
        if (!is_string($data) || $data === '') {
            return ['ok' => false, 'message' => $this->Translate('Invalid answer of the Protect console')];
        }
        $image = [
            'data'   => $data,
            'width'  => is_int($result['width'] ?? null) ? $result['width'] : 0,
            'height' => is_int($result['height'] ?? null) ? $result['height'] : 0
        ];

        // Without an answer about the cache, the media are cached (flash protection)
        $stored = $this->StoreImage($image, $Trigger, ($result['memoryCache'] ?? true) !== false);
        if (!$stored['ok']) {
            return $stored;
        }
        $this->UpdateTile();
        return ['ok' => true, 'message' => sprintf(
            $this->Translate('Snapshot stored: %d x %d, %d bytes'),
            $image['width'],
            $image['height'],
            strlen($image['data'])
        )];
    }

    /**
     * Saved trigger rows with validated values. "Index" is the position in the list (key for the lockout).
     */
    private function GetTriggerList(): array
    {
        $list = json_decode($this->ReadPropertyString('Triggers'), true);
        if (!is_array($list)) {
            return [];
        }
        $triggers = [];
        foreach (array_values(array_filter($list, 'is_array')) as $index => $row) {
            $condition = $row['Condition'] ?? self::CONDITION_CHANGE;
            $triggers[] = [
                'Index'      => $index,
                'Active'     => ($row['Active'] ?? true) === true,
                'Name'       => is_string($row['Name'] ?? null) ? trim($row['Name']) : '',
                'VariableID' => is_int($row['VariableID'] ?? null) ? $row['VariableID'] : 0,
                'Condition'  => is_int($condition) && $condition >= self::CONDITION_CHANGE && $condition <= self::CONDITION_UPDATE_WITH ? $condition : self::CONDITION_CHANGE,
                'Value'      => is_string($row['Value'] ?? null) ? $row['Value'] : '',
                'ValueBool'  => is_string($row['ValueBool'] ?? null) ? $row['ValueBool'] : '',
                'Delay'      => $this->ClampInteger($row['Delay'] ?? 0, 0, self::DELAY_MAX, 0),
                'Lockout'    => $this->ClampInteger($row['Lockout'] ?? self::TRIGGER_LOCKOUT_DEFAULT, 0, self::LOCKOUT_MAX, self::TRIGGER_LOCKOUT_DEFAULT)
            ];
        }
        return $triggers;
    }

    private function ClampInteger(mixed $Value, int $Minimum, int $Maximum, int $Default): int
    {
        return is_int($Value) ? max($Minimum, min($Maximum, $Value)) : $Default;
    }

    /**
     * Saved trigger rows plus row colors. All saved values are kept.
     */
    private function BuildTriggerRows(): array
    {
        $list = json_decode($this->ReadPropertyString('Triggers'), true);
        $rows = [];
        $triggers = $this->GetTriggerList();
        foreach (is_array($list) ? array_values(array_filter($list, 'is_array')) : [] as $position => $row) {
            $variableID = $triggers[$position]['VariableID'];
            $row['rowColor'] = $variableID === 0 || !IPS_VariableExists($variableID) ? '#FFC0C0' : '';
            // A development version saved "null" for rows without a value: shown as an empty field
            if (($row['Value'] ?? null) === 'null') {
                $row['Value'] = '';
            }
            // Former versions saved the value of booleans in "Value"
            if (!isset($row['ValueBool']) && in_array($row['Value'] ?? null, ['true', 'false'], true)) {
                $row['ValueBool'] = $row['Value'];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function GetConditionOptions(): array
    {
        return [
            ['caption' => $this->Translate('Any change'), 'value' => self::CONDITION_CHANGE],
            ['caption' => $this->Translate('Any update'), 'value' => self::CONDITION_UPDATE],
            ['caption' => $this->Translate('Changes to value'), 'value' => self::CONDITION_CHANGE_TO],
            ['caption' => $this->Translate('Is updated with value'), 'value' => self::CONDITION_UPDATE_WITH]
        ];
    }

    private function ConditionNeedsValue(int $Condition): bool
    {
        return $Condition === self::CONDITION_CHANGE_TO || $Condition === self::CONDITION_UPDATE_WITH;
    }

    /**
     * Visibility of the value fields in the trigger dialog and the caption of the text field.
     */
    private function GetValueFieldState(int $VariableID, int $Condition): array
    {
        $needsValue = $this->ConditionNeedsValue($Condition);
        $type = $VariableID > 0 && IPS_VariableExists($VariableID) ? IPS_GetVariable($VariableID)['VariableType'] : null;
        return [
            'ValueBool' => $needsValue && $type === VARIABLETYPE_BOOLEAN,
            'Value'     => $needsValue && $type !== null && $type !== VARIABLETYPE_BOOLEAN,
            'ValueHint' => $needsValue && $type === null,
            'caption'   => $this->Translate($type === VARIABLETYPE_STRING ? 'Value (text)' : 'Value (number)')
        ];
    }

    /**
     * Registers VM_UPDATE and a reference for every trigger variable; removes registrations of former variables.
     */
    private function RegisterTriggerMessages(): void
    {
        foreach ($this->GetMessageList() as $senderID => $messages) {
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($senderID, VM_UPDATE);
            }
        }
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        foreach ($this->GetTriggerList() as $trigger) {
            if ($trigger['VariableID'] > 0 && IPS_VariableExists($trigger['VariableID'])) {
                $this->RegisterMessage($trigger['VariableID'], VM_UPDATE);
                $this->RegisterReference($trigger['VariableID']);
            }
        }
    }

    /**
     * Current values of the trigger variables as starting point for "change" conditions. Resets the trigger lockouts
     * (the row positions may have changed). Changes during a restart are not caught up.
     */
    private function InitTriggerState(): void
    {
        if (!$this->EnterQueueLock()) {
            return;
        }
        try {
            $state = $this->LoadTriggerState();
            $state['values'] = [];
            foreach ($this->GetTriggerList() as $trigger) {
                if ($trigger['VariableID'] > 0 && IPS_VariableExists($trigger['VariableID'])) {
                    $state['values'][(string) $trigger['VariableID']] = GetValue($trigger['VariableID']);
                }
            }
            $state['triggerLast'] = [];
            $this->SaveTriggerState($state);
            $this->ScheduleQueue($state['queue']);
        } finally {
            $this->LeaveQueueLock();
        }
    }

    /**
     * VM_UPDATE of a trigger variable: checks conditions and lockouts and queues the snapshots.
     */
    private function HandleVariableUpdate(int $VariableID): void
    {
        if (!IPS_VariableExists($VariableID)) {
            return;
        }
        $value = GetValue($VariableID);
        $type = IPS_GetVariable($VariableID)['VariableType'];
        $configured = $this->CheckConfiguration() === IS_ACTIVE;
        $cameraLockout = max(0, $this->ReadPropertyInteger('CameraLockout'));

        if (!$this->EnterQueueLock()) {
            $this->SendDebug('Trigger', sprintf('Update of variable %d dropped, queue lock not available', $VariableID), 0);
            return;
        }
        try {
            $state = $this->LoadTriggerState();
            $key = (string) $VariableID;
            // Unknown previous value (e.g. directly after a restart) does not count as a change
            $changed = array_key_exists($key, $state['values']) && $state['values'][$key] !== $value;
            $state['values'][$key] = $value;
            $now = microtime(true);

            foreach ($this->GetTriggerList() as $trigger) {
                if ($trigger['VariableID'] !== $VariableID || !$this->IsConditionMet($trigger, $changed, $value, $type)) {
                    continue;
                }
                $name = $trigger['Name'] !== '' ? $trigger['Name'] : IPS_GetName($VariableID);
                if (!$trigger['Active']) {
                    $this->SendDebug('Trigger', sprintf('%s: inactive', $name), 0);
                    continue;
                }
                if (!$configured) {
                    $this->SendDebug('Trigger', sprintf('%s: configuration incomplete, no snapshot', $name), 0);
                    continue;
                }
                $last = $state['triggerLast'][(string) $trigger['Index']] ?? null;
                if ($last !== null && $now - $last < $trigger['Lockout']) {
                    $this->SendDebug('Trigger', sprintf('%s: lockout time running', $name), 0);
                    continue;
                }
                $state['triggerLast'][(string) $trigger['Index']] = $now;

                $due = $now + $trigger['Delay'];
                $lastCamera = is_float($state['cameraLast']) || is_int($state['cameraLast']) ? $state['cameraLast'] : null;
                if ($lastCamera !== null && abs($due - $lastCamera) < $cameraLockout) {
                    $this->SendDebug('Trigger', sprintf('%s: camera already has a snapshot within %d s', $name, $cameraLockout), 0);
                    continue;
                }
                $state['cameraLast'] = $due;
                $state['queue'][] = ['due' => $due, 'trigger' => $name];
                $this->SendDebug('Trigger', sprintf('%s: snapshot queued in %d s', $name, $trigger['Delay']), 0);
            }
            $this->SaveTriggerState($state);
            $this->ScheduleQueue($state['queue']);
        } finally {
            $this->LeaveQueueLock();
        }
    }

    private function IsConditionMet(array $Trigger, bool $Changed, mixed $Value, int $Type): bool
    {
        return match ($Trigger['Condition']) {
            self::CONDITION_CHANGE      => $Changed,
            self::CONDITION_UPDATE      => true,
            self::CONDITION_CHANGE_TO   => $Changed && $this->ValueEquals($this->GetExpectedValue($Trigger, $Type), $Value, $Type),
            self::CONDITION_UPDATE_WITH => $this->ValueEquals($this->GetExpectedValue($Trigger, $Type), $Value, $Type),
            default                     => false
        };
    }

    /**
     * Booleans use the selection "ValueBool"; rows of former versions without it have the value in "Value".
     */
    private function GetExpectedValue(array $Trigger, int $Type): string
    {
        if ($Type === VARIABLETYPE_BOOLEAN && $Trigger['ValueBool'] !== '') {
            return $Trigger['ValueBool'];
        }
        return $Trigger['Value'];
    }

    /**
     * Compares the value of the trigger with the current value of the variable, converted to the variable type.
     * Booleans are saved as "true"/"false", numbers as text (comma or point), strings as plain text.
     * Former versions saved strings JSON-encoded (SelectValue), these are still accepted. An empty value never matches.
     */
    private function ValueEquals(string $Expected, mixed $Value, int $Type): bool
    {
        if ($Expected === '') {
            return false;
        }
        $text = trim($Expected);
        if ($Type === VARIABLETYPE_STRING) {
            $decoded = json_decode($Expected, true);
            return $Expected === $Value || (is_string($decoded) && $decoded === $Value);
        }
        if ($Type === VARIABLETYPE_BOOLEAN) {
            return ($text === 'true' || $text === 'false') && ($text === 'true') === $Value;
        }
        $number = str_replace(',', '.', $text);
        if (!is_numeric($number)) {
            return false;
        }
        return match ($Type) {
            VARIABLETYPE_INTEGER => is_int($Value) && (float) $number === (float) $Value,
            VARIABLETYPE_FLOAT   => is_float($Value) && abs((float) $number - $Value) < 1e-9,
            default              => false
        };
    }

    /**
     * Timer: fetches and stores all due snapshots, then sets the timer to the next due entry.
     */
    private function ProcessQueue(): void
    {
        if (!$this->EnterQueueLock()) {
            // The timer keeps its interval and tries again
            return;
        }
        try {
            $state = $this->LoadTriggerState();
            $now = microtime(true);
            $due = [];
            $rest = [];
            foreach ($state['queue'] as $entry) {
                // Small tolerance, as the timer has a resolution of milliseconds
                if ($entry['due'] <= $now + 0.05) {
                    $due[] = $entry;
                } else {
                    $rest[] = $entry;
                }
            }
            $state['queue'] = $rest;
            $this->SaveTriggerState($state);
            $this->ScheduleQueue($rest);
        } finally {
            $this->LeaveQueueLock();
        }

        // Network access outside the lock; an error does not stop the other entries
        usort($due, fn (array $A, array $B): int => $A['due'] <=> $B['due']);
        foreach ($due as $entry) {
            $result = $this->SnapshotAndStore($entry['trigger']);
            $this->SendDebug('Queue', sprintf('%s: %s', $entry['trigger'], $result['ok'] ? 'stored' : $result['message']), 0);
        }
    }

    private function ScheduleQueue(array $Queue): void
    {
        if (count($Queue) === 0) {
            $this->SetTimerInterval(self::QUEUE_TIMER, 0);
            return;
        }
        $next = min(array_column($Queue, 'due'));
        $this->SetTimerInterval(self::QUEUE_TIMER, max(1, (int) ceil(($next - microtime(true)) * 1000)));
    }

    private function LoadTriggerState(): array
    {
        $state = json_decode($this->GetBuffer(self::QUEUE_BUFFER), true);
        $state = is_array($state) ? $state : [];
        foreach (['values', 'triggerLast', 'queue'] as $key) {
            if (!is_array($state[$key] ?? null)) {
                $state[$key] = [];
            }
        }
        $state['cameraLast'] ??= null;
        return $state;
    }

    private function SaveTriggerState(array $State): void
    {
        $this->SetBuffer(self::QUEUE_BUFFER, (string) json_encode($State));
    }

    private function EnterQueueLock(): bool
    {
        return IPS_SemaphoreEnter('CAR_Queue_' . $this->InstanceID, self::QUEUE_LOCK_TIMEOUT);
    }

    private function LeaveQueueLock(): void
    {
        IPS_SemaphoreLeave('CAR_Queue_' . $this->InstanceID);
    }

    private function IsValidCameraID(string $CameraID): bool
    {
        // Protect camera IDs are hex strings; they end up in URL paths
        return preg_match('/^[A-Za-z0-9]+$/', $CameraID) === 1;
    }

    private function GetImageLimit(): int
    {
        return max(self::IMAGES_MIN, min(self::IMAGES_MAX, $this->ReadPropertyInteger('Images')));
    }

    /**
     * Stores an image in the ring buffer. Result: ok, message.
     */
    private function StoreImage(array $Image, string $Trigger, bool $Cached): array
    {
        if (!$this->EnterStorageLock()) {
            return ['ok' => false, 'message' => $this->Translate('Storage is busy, please try again')];
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return ['ok' => false, 'message' => $this->Translate('The image index is damaged. Images are not stored.')];
            }

            $limit = $this->GetImageLimit();
            $entries = $index['images'];
            $trimmed = $this->TrimEntries($entries, $limit) > 0;

            $slot = $this->ChooseSlot($entries, $limit);
            if ($slot === 0) {
                if ($trimmed) {
                    $index['images'] = $entries;
                    $this->SaveIndex($index, $Cached);
                }
                $message = sprintf($this->Translate('All images of %s are marked as "keep": snapshot not stored'), IPS_GetName($this->InstanceID));
                $this->LogMessage($message, KL_WARNING);
                return ['ok' => false, 'message' => $message];
            }

            $ident = $this->SlotIdent($slot);
            $mediaID = $this->FindMedia($ident, MEDIATYPE_IMAGE);
            if ($mediaID < 0) {
                if ($trimmed) {
                    $index['images'] = $entries;
                    $this->SaveIndex($index, $Cached);
                }
                $this->SendDebug('Storage', sprintf('Ident %s is used by another object, slot skipped', $ident), 0);
                return ['ok' => false, 'message' => sprintf($this->Translate('Image slot %s is used by another object'), $ident)];
            }
            if ($mediaID === 0) {
                $mediaID = $this->CreateStorageMedia(
                    MEDIATYPE_IMAGE,
                    $ident,
                    sprintf('media/CameraArchive_%d_%03d.jpg', $this->InstanceID, $slot),
                    sprintf('%s %02d', $this->Translate('Image'), $slot),
                    $Cached
                );
            } else {
                $this->SyncCacheMode($mediaID, $Cached);
            }
            IPS_SetMediaContent($mediaID, base64_encode($Image['data']));

            $index['seq']++;
            $entries = array_values(array_filter($entries, fn (array $Entry): bool => $Entry['slot'] !== $slot));
            $entries[] = [
                'slot'    => $slot,
                'seq'     => $index['seq'],
                'time'    => time(),
                'trigger' => $Trigger,
                'keep'    => false,
                'size'    => strlen($Image['data']),
                'width'   => $Image['width'],
                'height'  => $Image['height']
            ];
            $index['images'] = $entries;
            if (!$this->SaveIndex($index, $Cached)) {
                return ['ok' => false, 'message' => $this->Translate('The image index could not be saved')];
            }
            $this->SendDebug('Storage', sprintf('Slot %d, %d bytes, %d images', $slot, strlen($Image['data']), count($entries)), 0);
            return ['ok' => true, 'message' => ''];
        } finally {
            $this->LeaveStorageLock();
        }
    }

    /**
     * Free slot number while the pool is not full, otherwise the slot of the oldest image not marked as "keep".
     * Returns 0 if no slot can be used.
     */
    private function ChooseSlot(array $Entries, int $Limit): int
    {
        if (count($Entries) < $Limit) {
            $used = array_column($Entries, 'slot');
            for ($slot = 1; $slot <= $Limit; $slot++) {
                if (!in_array($slot, $used, true)) {
                    return $slot;
                }
            }
        }
        $oldest = null;
        foreach ($Entries as $entry) {
            if (!$entry['keep'] && ($oldest === null || $entry['seq'] < $oldest['seq'])) {
                $oldest = $entry;
            }
        }
        return $oldest === null ? 0 : $oldest['slot'];
    }

    /**
     * Removes the oldest images not marked as "keep" while there are more images than allowed.
     * Images marked as "keep" are never removed here. Returns the number of removed images.
     */
    private function TrimEntries(array &$Entries, int $Limit): int
    {
        usort($Entries, fn (array $A, array $B): int => $A['seq'] <=> $B['seq']);
        $removed = 0;
        while (count($Entries) > $Limit) {
            $position = null;
            foreach ($Entries as $key => $entry) {
                if (!$entry['keep']) {
                    $position = $key;
                    break;
                }
            }
            if ($position === null) {
                break;
            }
            $this->DeleteSlotMedia($this->SlotIdent($Entries[$position]['slot']));
            array_splice($Entries, $position, 1);
            $removed++;
        }
        return $removed;
    }

    /**
     * Applies a reduced image count.
     */
    private function TrimImages(): void
    {
        if (!$this->EnterStorageLock()) {
            return;
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return;
            }
            $entries = $index['images'];
            $removed = $this->TrimEntries($entries, $this->GetImageLimit());
            if ($removed > 0) {
                $index['images'] = $entries;
                $this->SaveIndex($index, null);
                $this->SendDebug('Storage', sprintf('%d surplus images removed', $removed), 0);
            }
        } finally {
            $this->LeaveStorageLock();
        }
    }

    private function DeleteAllImages(): bool
    {
        if (!$this->EnterStorageLock()) {
            return false;
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return false;
            }
            $deleted = 0;
            // Also slots that are missing in the index
            foreach ($this->GetSlotIdents() as $ident) {
                if ($this->DeleteSlotMedia($ident)) {
                    $deleted++;
                }
            }
            $hadImages = count($index['images']) > 0;
            $index['images'] = [];
            if ($hadImages && !$this->SaveIndex($index, null)) {
                return false;
            }
            $this->SendDebug('DeleteImages', sprintf('%d images deleted', $deleted), 0);
            return true;
        } finally {
            $this->LeaveStorageLock();
        }
    }

    /**
     * Idents of the slot media below the instance.
     */
    private function GetSlotIdents(): array
    {
        $idents = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (preg_match('/^' . self::SLOT_IDENT_PREFIX . '\d+$/', $ident) === 1) {
                $idents[] = $ident;
            }
        }
        return $idents;
    }

    private function SlotIdent(int $Slot): string
    {
        return sprintf('%s%03d', self::SLOT_IDENT_PREFIX, $Slot);
    }

    /**
     * Deletes the image medium with the given ident. Media with child objects (added by the user) are kept.
     */
    private function DeleteSlotMedia(string $Ident): bool
    {
        $mediaID = $this->FindMedia($Ident, MEDIATYPE_IMAGE);
        if ($mediaID <= 0) {
            return false;
        }
        if (IPS_GetObject($mediaID)['HasChildren']) {
            $this->SendDebug('Storage', sprintf('%s has child objects and is not deleted', $Ident), 0);
            return false;
        }
        return IPS_DeleteMedia($mediaID, true);
    }

    /**
     * ID of the own medium with the ident: 0 if it does not exist, -1 if the ident belongs to another kind of object.
     */
    private function FindMedia(string $Ident, int $MediaType): int
    {
        // The warning for a missing ident is expected here, the result is checked
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($id === false) {
            return 0;
        }
        if (!IPS_MediaExists($id) || IPS_GetMedia($id)['MediaType'] !== $MediaType) {
            return -1;
        }
        return $id;
    }

    private function CreateStorageMedia(int $MediaType, string $Ident, string $File, string $Name, bool $Cached): int
    {
        $mediaID = IPS_CreateMedia($MediaType);
        IPS_SetMediaFile($mediaID, $File, false);
        // Cache before the first content is written: the content then stays in RAM until shutdown (flash protection)
        IPS_SetMediaCached($mediaID, $Cached);
        IPS_SetParent($mediaID, $this->InstanceID);
        IPS_SetIdent($mediaID, $Ident);
        // Name and visibility are only set once; afterwards they belong to the user
        IPS_SetName($mediaID, $Name);
        IPS_SetHidden($mediaID, true);
        return $mediaID;
    }

    /**
     * Caching of the own media follows the option "Use in-memory cache" of the console. It is part of the storage
     * concept of this module, not a user setting of the media. Switching it off writes the cached content to the file.
     */
    private function SyncCacheMode(int $MediaID, bool $Cached): void
    {
        if (IPS_GetMedia($MediaID)['MediaIsCached'] !== $Cached) {
            IPS_SetMediaCached($MediaID, $Cached);
        }
    }

    /**
     * Applies the option "Use in-memory cache" to all own image media and the index.
     */
    private function ApplyCacheMode(bool $Cached): void
    {
        if (!$this->EnterStorageLock()) {
            return;
        }
        try {
            foreach ($this->GetSlotIdents() as $ident) {
                $mediaID = $this->FindMedia($ident, MEDIATYPE_IMAGE);
                if ($mediaID > 0) {
                    $this->SyncCacheMode($mediaID, $Cached);
                }
            }
            $indexID = $this->FindMedia(self::INDEX_IDENT, MEDIATYPE_DOCUMENT);
            if ($indexID > 0) {
                $this->SyncCacheMode($indexID, $Cached);
            }
        } finally {
            $this->LeaveStorageLock();
        }
    }

    /**
     * Index of all stored images, or null if it is damaged. A damaged index is never overwritten.
     */
    private function LoadIndex(): ?array
    {
        $empty = ['version' => self::INDEX_VERSION, 'seq' => 0, 'images' => []];
        $mediaID = $this->FindMedia(self::INDEX_IDENT, MEDIATYPE_DOCUMENT);
        if ($mediaID === 0) {
            return $empty;
        }

        $index = null;
        if ($mediaID > 0) {
            $raw = $this->ReadMediaData($mediaID);
            if ($raw === null || $raw === '') {
                // No content at all (e.g. the file was not written before a restart): there is nothing to keep,
                // so a new index is started. Damaged content is handled below and never overwritten.
                if ($this->GetBuffer('IndexMissingReported') === '') {
                    $this->SetBuffer('IndexMissingReported', '1');
                    $this->LogMessage($this->Translate('The image index could not be read. A new index is started, older images are no longer listed.'), KL_WARNING);
                }
                return $empty;
            }
            $index = json_decode($raw, true);
        }
        if (!$this->IsValidIndex($index)) {
            // Report only once per runtime
            if ($this->GetBuffer('IndexErrorReported') === '') {
                $this->SetBuffer('IndexErrorReported', '1');
                $this->LogMessage($this->Translate('The image index is damaged. Images are not stored.'), KL_ERROR);
            }
            return null;
        }
        return $index;
    }

    private function IsValidIndex(mixed $Index): bool
    {
        if (!is_array($Index) || ($Index['version'] ?? null) !== self::INDEX_VERSION || !is_int($Index['seq'] ?? null)
            || !is_array($Index['images'] ?? null) || !array_is_list($Index['images'])) {
            return false;
        }
        foreach ($Index['images'] as $entry) {
            if (!is_array($entry)
                || !is_int($entry['slot'] ?? null) || $entry['slot'] < 1
                || !is_int($entry['seq'] ?? null) || !is_int($entry['time'] ?? null)
                || !is_string($entry['trigger'] ?? null) || !is_bool($entry['keep'] ?? null)
                || !is_int($entry['size'] ?? null) || !is_int($entry['width'] ?? null) || !is_int($entry['height'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Saves the index. $Cached: cache mode for a newly created index medium, null = cached (flash protection).
     */
    private function SaveIndex(array $Index, ?bool $Cached): bool
    {
        $json = json_encode($Index);
        if ($json === false) {
            $this->SendDebug('Storage', 'Index could not be encoded: ' . json_last_error_msg(), 0);
            return false;
        }
        $mediaID = $this->FindMedia(self::INDEX_IDENT, MEDIATYPE_DOCUMENT);
        if ($mediaID < 0) {
            $this->SendDebug('Storage', sprintf('Ident %s is used by another object', self::INDEX_IDENT), 0);
            return false;
        }
        if ($mediaID === 0) {
            $mediaID = $this->CreateStorageMedia(
                MEDIATYPE_DOCUMENT,
                self::INDEX_IDENT,
                sprintf('media/CameraArchive_%d_index.json', $this->InstanceID),
                $this->Translate('Image index'),
                $Cached ?? true
            );
        } elseif ($Cached !== null) {
            $this->SyncCacheMode($mediaID, $Cached);
        }
        return IPS_SetMediaContent($mediaID, base64_encode($json));
    }

    private function GetStorageInfo(): string
    {
        $index = $this->LoadIndex();
        if ($index === null) {
            return $this->Translate('The image index is damaged. Images are not stored.');
        }
        return sprintf(
            $this->Translate('Stored images: %d, %.1f MB'),
            count($index['images']),
            array_sum(array_column($index['images'], 'size')) / 1048576
        );
    }

    private function EnterStorageLock(): bool
    {
        if (IPS_SemaphoreEnter('CAR_Storage_' . $this->InstanceID, self::STORAGE_LOCK_TIMEOUT)) {
            return true;
        }
        $this->SendDebug('Storage', 'Storage lock not available', 0);
        return false;
    }

    private function LeaveStorageLock(): void
    {
        IPS_SemaphoreLeave('CAR_Storage_' . $this->InstanceID);
    }

    /**
     * Content of the image in the slot, if the slot still holds the image with this sequence number.
     */
    private function ReadStoredImage(int $Slot, int $Seq): ?string
    {
        $found = false;
        foreach ($this->LoadIndex()['images'] ?? [] as $entry) {
            if ($entry['slot'] === $Slot && $entry['seq'] === $Seq) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return null;
        }
        $mediaID = $this->FindMedia($this->SlotIdent($Slot), MEDIATYPE_IMAGE);
        if ($mediaID <= 0) {
            return null;
        }
        $data = $this->ReadMediaData($mediaID);
        return $data !== '' ? $data : null;
    }

    /**
     * Decoded content of a medium, or null if it cannot be read. If the file of the medium does not exist,
     * IPS_GetMediaContent returns false with a warning (the test stubs throw an exception instead).
     */
    private function ReadMediaData(int $MediaID): ?string
    {
        try {
            $content = @IPS_GetMediaContent($MediaID);
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_string($content)) {
            $this->SendDebug('Storage', sprintf('Content of medium %d not available', $MediaID), 0);
            return null;
        }
        $data = base64_decode($content, true);
        return is_string($data) ? $data : null;
    }

    private function SendHookError(int $Code, string $Text): void
    {
        header(sprintf('HTTP/1.1 %d %s', $Code, $Text));
        header('Content-Type: text/plain');
        echo $Text;
    }

    /**
     * State of this camera for the tiles: name of the instance, image WebHook with token, images newest first.
     */
    private function BuildCameraState(): array
    {
        $index = $this->LoadIndex();
        $entries = $index['images'] ?? [];
        usort($entries, fn (array $A, array $B): int => $B['seq'] <=> $A['seq']);
        $images = [];
        foreach ($entries as $entry) {
            $images[] = [
                'slot'    => $entry['slot'],
                'seq'     => $entry['seq'],
                'time'    => $entry['time'],
                'trigger' => $entry['trigger'],
                'keep'    => $entry['keep'],
                'width'   => $entry['width'],
                'height'  => $entry['height']
            ];
        }
        return [
            'id'           => $this->InstanceID,
            'name'         => IPS_GetName($this->InstanceID),
            'hook'         => '/hook/' . self::HOOK_PREFIX . $this->InstanceID,
            'token'        => $this->ReadAttributeString(self::TOKEN_ATTRIBUTE),
            'images'       => $images,
            'indexDamaged' => $index === null
        ];
    }

    private function BuildTileState(): array
    {
        return ['cameras' => [$this->BuildCameraState()], 'overview' => false];
    }

    /**
     * Sends the current state to open tiles of this instance and informs the console for its overview tile.
     * Only metadata, the images are loaded via the WebHook.
     */
    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue((string) json_encode($this->BuildTileState(), JSON_INVALID_UTF8_SUBSTITUTE));
        if ($this->HasConsole()) {
            $this->SendToConsole(['Command' => 'StateChanged']);
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
