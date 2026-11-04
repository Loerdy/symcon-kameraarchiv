<?php

declare(strict_types=1);

class CameraArchive extends IPSModuleStrict
{
    // Instance status codes, see "status" in form.json
    private const STATUS_CONFIG_MISSING = 201;
    private const STATUS_CURL_MISSING = 202;
    private const STATUS_UNREACHABLE = 203;
    private const STATUS_AUTH_FAILED = 204;
    private const STATUS_TLS_FAILED = 205;
    private const STATUS_UNEXPECTED = 206;

    // UniFi Protect Integration API, see docs/KONZEPT.md
    private const API_BASE = '/proxy/protect/integration/v1';

    // HTTP timeouts in seconds
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT = 15;

    // Maximum number of body bytes written to the debug output
    private const DEBUG_BODY_LIMIT = 200;

    // libcurl error numbers caused by TLS/certificate problems:
    // 35 SSL_CONNECT_ERROR, 51 PEER_FAILED_VERIFICATION (old libcurl), 58 SSL_CERTPROBLEM,
    // 60 PEER_FAILED_VERIFICATION/SSL_CACERT, 77 SSL_CACERT_BADFILE, 83 SSL_ISSUER_ERROR
    private const CURL_TLS_ERRORS = [35, 51, 58, 60, 77, 83];

    // Images per camera (column "Images" of the camera list)
    private const IMAGES_DEFAULT = 30;
    private const IMAGES_MIN = 1;
    private const IMAGES_MAX = 200;

    // Ring buffer: image media below the instance, index in a document medium.
    // Both are cached (IPS_SetMediaCached), so they live in RAM and are only written at shutdown.
    private const INDEX_IDENT = 'ImageIndex';
    private const INDEX_VERSION = 1;
    private const SLOT_IDENT_PREFIX = 'IMG_';
    private const STORAGE_LOCK_TIMEOUT = 5000;

    // Triggers (phase 4): conditions of a trigger row
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

    // Tile (phase 5): images are delivered by the WebHook of the instance. WebHooks are reachable without
    // authentication (also via Connect), so every request needs the random token that only the tile knows.
    private const HOOK_PREFIX = 'camera-archive-';
    private const TOKEN_ATTRIBUTE = 'HookToken';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyBoolean('SkipCertificateCheck', false);
        $this->RegisterPropertyString('Cameras', '[]');
        $this->RegisterPropertyBoolean('ResizeEnabled', true);
        $this->RegisterPropertyInteger('ResizeWidth', 1280);
        $this->RegisterPropertyInteger('ResizeQuality', 75);
        $this->RegisterPropertyString('Triggers', '[]');
        $this->RegisterPropertyInteger('CameraLockout', self::CAMERA_LOCKOUT_DEFAULT);

        // Camera IDs for which Protect delivered no JPEG snapshot. This is runtime knowledge,
        // not user configuration, therefore an attribute and not a property.
        $this->RegisterAttributeString('NoSnapshotCameras', '[]');

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
        if ($Message === IPS_KERNELSTARTED) {
            $this->TrimAllCameras();
            $this->InitTriggerState();
            return;
        }
        if ($Message === VM_UPDATE) {
            // Only queue snapshots here; fetching happens in the timer (no blocking in MessageSink)
            $this->HandleVariableUpdate($SenderID);
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

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Forget snapshot failures of cameras that are no longer in the list
        $ids = array_column($this->GetCameraList(), 'CameraID');
        $noSnapshot = array_values(array_intersect($this->GetNoSnapshotCameras(), $ids));
        if ($noSnapshot !== $this->GetNoSnapshotCameras()) {
            $this->WriteAttributeString('NoSnapshotCameras', json_encode($noSnapshot));
        }

        if ($this->ReadPropertyBoolean('ResizeEnabled') && !$this->IsGDAvailable()) {
            $this->LogMessage($this->Translate('PHP extension GD is not available: images are stored without resizing.'), KL_WARNING);
        }

        if ($this->ReadAttributeString(self::TOKEN_ATTRIBUTE) === '') {
            $this->WriteAttributeString(self::TOKEN_ATTRIBUTE, bin2hex(random_bytes(16)));
        }

        $this->RegisterTriggerMessages();

        // Objects are only touched once the kernel is ready (best practice), otherwise IPS_KERNELSTARTED does it
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->TrimAllCameras();
            $this->InitTriggerState();
            // Camera names or image counts may have changed
            $this->UpdateTile();
        }

        // No network access here: ApplyChanges also runs during startup and must not block.
        $this->SetStatus($this->CheckConfiguration());
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // The list uses loadValuesFromConfiguration = false, so the saved cameras are filled in here
        // (store review guideline 13). This allows adding the computed columns.
        $cameras = $this->GetCameraList();
        $this->PatchFormField($form['elements'], 'Cameras', 'values', $this->BuildCameraRows($cameras));

        $this->PatchFormField($form['elements'], 'Triggers', 'values', $this->BuildTriggerRows($cameras));

        $this->PatchFormField($form['elements'], 'StorageInfo', 'caption', $this->GetStorageInfo());
        if ($this->ReadPropertyBoolean('ResizeEnabled') && !$this->IsGDAvailable()) {
            $this->PatchFormField($form['elements'], 'GDMissing', 'visible', true);
        }

        $options = [];
        foreach ($cameras as $camera) {
            if ($camera['Enabled']) {
                $options[] = ['caption' => $camera['Name'] !== '' ? $camera['Name'] : $camera['CameraID'], 'value' => $camera['CameraID']];
            }
        }
        if (count($options) > 0) {
            $this->PatchFormField($form['actions'], 'SnapshotCamera', 'options', $options);
        }

        return json_encode($form);
    }

    /**
     * Tile of the HTML-SDK: static HTML plus the current state, which also carries the hook address and token.
     * Later changes reach the tile via UpdateVisualizationValue (without the token).
     */
    public function GetVisualizationTile(): string
    {
        $state = $this->BuildTileState();
        $state['hook'] = '/hook/' . self::HOOK_PREFIX . $this->InstanceID;
        $state['token'] = $this->ReadAttributeString(self::TOKEN_ATTRIBUTE);
        // Camera names come from the console: escape everything that could end the script element
        $json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        return file_get_contents(__DIR__ . '/module.html') . '<script>handleMessage(' . $json . ');</script>';
    }

    /**
     * Fetches a snapshot of the given camera and stores it in the ring buffer of the camera.
     */
    public function TakeSnapshot(string $CameraID): bool
    {
        $result = $this->SnapshotAndStore($CameraID, 'manual');
        if (!$result['ok']) {
            $this->SendDebug('TakeSnapshot', $result['message'], 0);
        }
        return $result['ok'];
    }

    /**
     * Deletes all stored images of the given camera, including images marked as "keep".
     */
    public function DeleteImages(string $CameraID): bool
    {
        $deleted = $this->DeleteImagesLocked($CameraID);
        if ($deleted) {
            $this->UpdateTile();
        }
        return $deleted;
    }

    /**
     * Button "Delete images of removed cameras": Deletes the images of cameras that are no longer in the camera list.
     */
    public function UIDeleteRemovedCameraImages(): void
    {
        $known = array_column($this->GetCameraList(), 'CameraID');
        $removed = array_diff($this->GetStoredCameraIDs(), $known);

        $count = 0;
        foreach ($removed as $cameraID) {
            if ($this->DeleteImages($cameraID)) {
                $count++;
            }
        }
        $this->UpdateFormField('StorageInfo', 'caption', $this->GetStorageInfo());
        echo sprintf($this->Translate('Images of %d removed cameras deleted'), $count);
    }

    /**
     * Edit dialog of a trigger row. Called by the PHP code of the list parameter "form", which runs outside the module.
     * The values of the row are filled in by the console via the element names.
     */
    public function UITriggerForm(int $VariableID, int $Condition): string
    {
        // SelectValue loads type and profile of its variable, so it needs an existing variable.
        // A new row has no variable yet: then a hint is shown instead (value is set when editing the row again).
        $hasValueField = $VariableID > 0 && IPS_VariableExists($VariableID);
        $update = 'CAR_UITriggerUpdate($id, $VariableID, $Condition, ' . ($hasValueField ? 'true' : 'false') . ');';
        $elements = [
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => $this->Translate('Active')],
            ['type' => 'ValidationTextBox', 'name' => 'Name', 'caption' => $this->Translate('Name (empty: name of the variable)')],
            ['type' => 'SelectVariable', 'name' => 'VariableID', 'caption' => $this->Translate('Variable'), 'onChange' => $update],
            ['type' => 'Select', 'name' => 'Condition', 'caption' => $this->Translate('Condition'), 'options' => $this->GetConditionOptions(), 'onChange' => $update]
        ];
        if ($hasValueField) {
            $elements[] = [
                'type'       => 'SelectValue',
                'name'       => 'Value',
                'caption'    => $this->Translate('Value'),
                'variableID' => $VariableID,
                'visible'    => $this->ConditionNeedsValue($Condition)
            ];
        } else {
            $elements[] = [
                'type'    => 'Label',
                'name'    => 'ValueHint',
                'caption' => $this->Translate('Select the variable and confirm with OK, then open the trigger again to set the value.'),
                'visible' => $this->ConditionNeedsValue($Condition)
            ];
        }
        $elements[] = ['type' => 'Label', 'caption' => $this->Translate('Cameras'), 'bold' => true];
        $cameras = $this->GetCameraList();
        foreach ($cameras as $camera) {
            $caption = $camera['Name'] !== '' ? $camera['Name'] : $camera['CameraID'];
            if (!$camera['Enabled']) {
                $caption .= ' (' . $this->Translate('inactive') . ')';
            }
            $elements[] = ['type' => 'CheckBox', 'name' => 'Camera_' . $camera['CameraID'], 'caption' => $caption];
        }
        if (count($cameras) === 0) {
            $elements[] = ['type' => 'Label', 'caption' => $this->Translate('The camera list is empty. Fetch the cameras first.')];
        }
        $elements[] = ['type' => 'NumberSpinner', 'name' => 'Delay', 'caption' => $this->Translate('Delay'), 'suffix' => 's', 'minimum' => 0, 'maximum' => self::DELAY_MAX];
        $elements[] = ['type' => 'NumberSpinner', 'name' => 'Lockout', 'caption' => $this->Translate('Lockout time of this trigger'), 'suffix' => 's', 'minimum' => 0, 'maximum' => self::LOCKOUT_MAX];
        return json_encode($elements);
    }

    /**
     * onChange of variable and condition in the trigger dialog: adapts the value field.
     */
    public function UITriggerUpdate(int $VariableID, int $Condition, bool $HasValueField): void
    {
        $needsValue = $this->ConditionNeedsValue($Condition);
        if (!$HasValueField) {
            $this->UpdateFormField('ValueHint', 'visible', $needsValue);
            return;
        }
        if ($VariableID > 0 && IPS_VariableExists($VariableID)) {
            $this->UpdateFormField('Value', 'variableID', $VariableID);
        }
        $this->UpdateFormField('Value', 'visible', $needsValue);
    }

    /**
     * Button "Test connection": Requests the camera list and shows the result.
     */
    public function UITestConnection(): void
    {
        $result = $this->FetchCameras();
        echo $result['message'];
    }

    /**
     * Button "Fetch cameras": Suggests the cameras of the console in the camera list.
     * The user confirms the suggestion with "Apply" (no IPS_SetProperty, store review guideline 13).
     */
    public function UIFetchCameras(): void
    {
        $result = $this->FetchCameras();
        if (!$result['ok']) {
            echo $result['message'];
            return;
        }

        // Keep the settings of cameras that are already known
        $known = [];
        foreach ($this->GetCameraList() as $camera) {
            $known[$camera['CameraID']] = $camera;
        }

        $cameras = [];
        foreach ($result['cameras'] as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id']) || $entry['id'] === '') {
                continue;
            }
            $flags = isset($entry['featureFlags']) && is_array($entry['featureFlags']) ? $entry['featureFlags'] : [];
            $cameras[] = [
                'Enabled'  => $known[$entry['id']]['Enabled'] ?? false,
                'Images'   => $known[$entry['id']]['Images'] ?? self::IMAGES_DEFAULT,
                'Name'     => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                'Type'     => is_string($entry['type'] ?? null) ? $entry['type'] : '',
                'State'    => is_string($entry['state'] ?? null) ? $entry['state'] : '',
                'FullHD'   => ($flags['supportFullHdSnapshot'] ?? false) === true,
                'CameraID' => $entry['id']
            ];
        }

        $this->UpdateFormField('Cameras', 'values', json_encode($this->BuildCameraRows($cameras)));
        echo sprintf($this->Translate('%d cameras found. Select the cameras and click "Apply".'), count($cameras));
    }

    /**
     * Button "Test snapshot": Fetches a snapshot of the selected camera and shows the result.
     */
    public function UITestSnapshot(string $CameraID): void
    {
        if ($CameraID === '') {
            echo $this->Translate('Please activate a camera in the list and click "Apply" first.');
            return;
        }

        $markedBefore = in_array($CameraID, $this->GetNoSnapshotCameras(), true);
        $result = $this->SnapshotAndStore($CameraID, 'manual');
        $markedAfter = in_array($CameraID, $this->GetNoSnapshotCameras(), true);

        // Show a changed "Snapshot" column in the open form
        if ($markedBefore !== $markedAfter) {
            $this->UpdateFormField('Cameras', 'values', json_encode($this->BuildCameraRows($this->GetCameraList())));
        }
        // Only the label: updating the list would mark the configuration as changed
        $this->UpdateFormField('StorageInfo', 'caption', $this->GetStorageInfo());

        echo $result['message'];
    }

    /**
     * WebHook: delivers one stored image. Parameters: token, camera, slot, seq.
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

        $cameraID = $_GET['camera'] ?? null;
        $slot = filter_var($_GET['slot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::IMAGES_MAX]]);
        $seq = filter_var($_GET['seq'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($cameraID) || !$this->IsValidCameraID($cameraID) || $slot === false || $seq === false) {
            $this->SendHookError(400, 'Bad Request');
            return;
        }

        // Index and content are read under the lock, so the slot cannot be overwritten in between
        if (!$this->EnterStorageLock()) {
            $this->SendHookError(503, 'Service Unavailable');
            return;
        }
        try {
            $data = $this->ReadStoredImage($cameraID, $slot, $seq);
        } finally {
            $this->LeaveStorageLock();
        }
        if ($data === null) {
            $this->SendDebug('Hook', sprintf('%s slot %d seq %d: not found', $cameraID, $slot, $seq), 0);
            $this->SendHookError(404, 'Not Found');
            return;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $data;
    }

    private function DeleteImagesLocked(string $CameraID): bool
    {
        if (!$this->IsValidCameraID($CameraID)) {
            $this->SendDebug('DeleteImages', 'Invalid camera ID', 0);
            return false;
        }
        if (!$this->EnterStorageLock()) {
            return false;
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return false;
            }
            $inIndex = isset($index['cameras'][$CameraID]);
            $deleted = $this->DeleteCameraImages($index, $CameraID);
            if ($inIndex && !$this->SaveIndex($index)) {
                return false;
            }
            $this->SendDebug('DeleteImages', sprintf('%s: %d images deleted', $CameraID, $deleted), 0);
            return true;
        } finally {
            $this->LeaveStorageLock();
        }
    }

    /**
     * Fetches a snapshot and stores it. Result: ok, message.
     */
    private function SnapshotAndStore(string $CameraID, string $Trigger): array
    {
        $result = $this->FetchSnapshot($CameraID);
        if (!$result['ok']) {
            return $result;
        }

        $image = $this->ResizeImage($result['image']);
        $stored = $this->StoreImage($result['camera'], $image, $Trigger);
        if (!$stored['ok']) {
            return ['ok' => false, 'message' => $stored['message']];
        }
        $this->UpdateTile();
        return ['ok' => true, 'message' => sprintf(
            $this->Translate('Snapshot stored: %s, %d x %d, %d bytes'),
            $result['label'],
            $image['width'],
            $image['height'],
            strlen($image['data'])
        )];
    }

    /**
     * Returns IS_ACTIVE if all requirements are met, otherwise the matching error status.
     */
    private function CheckConfiguration(): int
    {
        if (!function_exists('curl_init')) {
            return self::STATUS_CURL_MISSING;
        }
        if ($this->GetHost() === '' || $this->ReadPropertyString('APIKey') === '') {
            return self::STATUS_CONFIG_MISSING;
        }
        return IS_ACTIVE;
    }

    /**
     * Checks the configuration before a request. Returns an empty string if a request is possible,
     * otherwise a message for the user.
     */
    private function CheckRequestPossible(): string
    {
        $status = $this->CheckConfiguration();
        if ($status === IS_ACTIVE) {
            return '';
        }
        $this->UpdateStatus($status);
        if ($status === self::STATUS_CURL_MISSING) {
            return $this->Translate('PHP extension cURL is not available');
        }
        return $this->Translate('Please enter the Protect console host and API key and click "Apply" first.');
    }

    /**
     * Requests the camera list. Result: ok, message, cameras.
     */
    private function FetchCameras(): array
    {
        $message = $this->CheckRequestPossible();
        if ($message !== '') {
            return ['ok' => false, 'message' => $message, 'cameras' => []];
        }

        $response = $this->Request('/cameras');
        if ($response['errno'] !== 0) {
            return ['ok' => false, 'message' => $this->HandleTransportError($response), 'cameras' => []];
        }
        if ($response['code'] === 401 || $response['code'] === 403) {
            return ['ok' => false, 'message' => $this->HandleAuthError($response), 'cameras' => []];
        }

        $data = null;
        if ($response['code'] === 200 && str_starts_with(ltrim($response['body']), '[')) {
            $data = json_decode($response['body'], true);
        }
        if (!is_array($data) || !array_is_list($data)) {
            $this->UpdateStatus(self::STATUS_UNEXPECTED);
            $this->DebugBody('Cameras', $response);
            return ['ok' => false, 'message' => sprintf($this->Translate('Unexpected response (HTTP %d)'), $response['code']), 'cameras' => []];
        }

        $this->UpdateStatus(IS_ACTIVE);
        $this->SendDebug('Cameras', sprintf('%d cameras received', count($data)), 0);
        return ['ok' => true, 'message' => sprintf($this->Translate('Connection OK, %d cameras found'), count($data)), 'cameras' => $data];
    }

    /**
     * Requests a snapshot. Result: ok, message; on success also image (JPEG bytes), camera, label.
     */
    private function FetchSnapshot(string $CameraID): array
    {
        $message = $this->CheckRequestPossible();
        if ($message !== '') {
            return ['ok' => false, 'message' => $message];
        }

        if (!$this->IsValidCameraID($CameraID)) {
            return ['ok' => false, 'message' => $this->Translate('Invalid camera ID')];
        }

        $camera = null;
        foreach ($this->GetCameraList() as $entry) {
            if ($entry['CameraID'] === $CameraID) {
                $camera = $entry;
                break;
            }
        }
        if ($camera === null) {
            return ['ok' => false, 'message' => sprintf($this->Translate('Camera %s is not in the camera list'), $CameraID)];
        }
        $label = $camera['Name'] !== '' ? $camera['Name'] : $CameraID;
        if (!$camera['Enabled']) {
            return ['ok' => false, 'message' => sprintf($this->Translate('Camera %s is not active'), $label)];
        }

        // highQuality only for cameras that support Full HD snapshots, see docs/KONZEPT.md
        $path = '/cameras/' . $CameraID . '/snapshot' . ($camera['FullHD'] ? '?highQuality=true' : '');
        $response = $this->Request($path);
        if ($response['errno'] !== 0) {
            return ['ok' => false, 'message' => $this->HandleTransportError($response)];
        }
        if ($response['code'] === 401 || $response['code'] === 403) {
            return ['ok' => false, 'message' => $this->HandleAuthError($response)];
        }

        $body = $response['body'];
        if ($response['code'] === 200 && str_starts_with($body, "\xFF\xD8")) {
            $this->UpdateStatus(IS_ACTIVE);
            $this->SetNoSnapshot($CameraID, false);
            $this->SendDebug('Snapshot', sprintf(
                '%s: %d bytes, Content-Type %s, %.2f s, highQuality %s',
                $label,
                strlen($body),
                $response['type'],
                $response['time'],
                $camera['FullHD'] ? 'yes' : 'no'
            ), 0);
            $this->SendDebug('Snapshot (first bytes)', substr($body, 0, 16), 1);
            return ['ok' => true, 'message' => '', 'image' => $body, 'camera' => $camera, 'label' => $label];
        }

        // No JPEG: The console answered, but cannot deliver a snapshot for this camera
        // (e.g. third-party cameras). Mark the camera in the list.
        $this->SetNoSnapshot($CameraID, true);
        $this->SendDebug('Snapshot', sprintf(
            '%s: no JPEG, HTTP %d, %d bytes, Content-Type %s',
            $label,
            $response['code'],
            strlen($body),
            $response['type']
        ), 0);
        $error = json_decode($body, true);
        if (is_array($error) && (isset($error['name']) || isset($error['error']))) {
            $this->SendDebug('Snapshot', sprintf('Protect error: %s (%s)', (string) ($error['error'] ?? ''), (string) ($error['name'] ?? '')), 0);
        } else {
            $this->DebugBody('Snapshot', $response);
        }
        return ['ok' => false, 'message' => sprintf($this->Translate('No snapshot received from %s (HTTP %d). Details in the debug output.'), $label, $response['code'])];
    }

    /**
     * Performs a GET request against the Protect Integration API.
     * The API key is only sent as header and never written to the debug output.
     */
    private function Request(string $Path): array
    {
        $url = 'https://' . $this->GetHost() . self::API_BASE . $Path;
        $this->SendDebug('Request', 'GET ' . $url, 0);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $this->ReadPropertyString('APIKey')]
        ];
        if ($this->ReadPropertyBoolean('SkipCertificateCheck')) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $response = [
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'code'  => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'type'  => is_string($type) ? $type : '',
            'time'  => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            'body'  => is_string($body) ? $body : ''
        ];
        unset($ch);

        if ($response['errno'] === 0) {
            $this->SendDebug('Response', sprintf('HTTP %d, %d bytes, %.2f s', $response['code'], strlen($response['body']), $response['time']), 0);
        }
        return $response;
    }

    private function HandleTransportError(array $Response): string
    {
        $this->SendDebug('cURL error', sprintf('%d: %s', $Response['errno'], $Response['error']), 0);
        if (in_array($Response['errno'], self::CURL_TLS_ERRORS, true)) {
            $this->UpdateStatus(self::STATUS_TLS_FAILED);
            return $this->Translate('The certificate of the Protect console could not be verified. For a self-signed certificate enable "Do not verify certificate".');
        }
        $this->UpdateStatus(self::STATUS_UNREACHABLE);
        return $this->Translate('Protect console not reachable');
    }

    private function HandleAuthError(array $Response): string
    {
        $this->UpdateStatus(self::STATUS_AUTH_FAILED);
        $this->DebugBody('Authentication', $Response);
        return $this->Translate('API key invalid or without permission');
    }

    /**
     * Writes a shortened response body to the debug output.
     */
    private function DebugBody(string $Title, array $Response): void
    {
        $this->SendDebug($Title, sprintf(
            'HTTP %d, Content-Type %s, body: %s',
            $Response['code'],
            $Response['type'],
            substr($Response['body'], 0, self::DEBUG_BODY_LIMIT)
        ), 0);
    }

    private function UpdateStatus(int $Status): void
    {
        if ($this->GetStatus() !== $Status) {
            $this->SetStatus($Status);
        }
    }

    /**
     * Host part of the URL: without scheme and trailing slash, IPv6 literals in brackets.
     * Hostnames, IPv4 and already bracketed IPv6 (optionally with port, e.g. "[fd00::1]:443") stay unchanged.
     */
    private function GetHost(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $host = (string) preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');

        // A bare IPv6 literal cannot carry a port, so the whole input is the address.
        // A zone ID (e.g. "fe80::1%eth0") has to be encoded as "%25" in URLs (RFC 6874).
        $parts = explode('%', $host, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $parts[0] . (isset($parts[1]) ? '%25' . $parts[1] : '') . ']';
        }
        return $host;
    }

    /**
     * Saved camera list with all expected keys.
     */
    private function GetCameraList(): array
    {
        $list = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($list)) {
            return [];
        }
        $cameras = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_string($entry['CameraID'] ?? null) || $entry['CameraID'] === '') {
                continue;
            }
            // Rows saved before the column "Images" existed get the default
            $images = $entry['Images'] ?? self::IMAGES_DEFAULT;
            $images = is_int($images) ? max(self::IMAGES_MIN, min(self::IMAGES_MAX, $images)) : self::IMAGES_DEFAULT;
            $cameras[] = [
                'Enabled'  => ($entry['Enabled'] ?? false) === true,
                'Images'   => $images,
                'Name'     => (string) ($entry['Name'] ?? ''),
                'Type'     => (string) ($entry['Type'] ?? ''),
                'State'    => (string) ($entry['State'] ?? ''),
                'FullHD'   => ($entry['FullHD'] ?? false) === true,
                'CameraID' => $entry['CameraID']
            ];
        }
        return $cameras;
    }

    /**
     * Rows for the camera list including the computed columns and row colors.
     */
    private function BuildCameraRows(array $Cameras): array
    {
        $noSnapshot = $this->GetNoSnapshotCameras();
        $statistics = $this->GetStorageStatistics();
        $rows = [];
        foreach ($Cameras as $camera) {
            // Third-party cameras have a type without "UVC" prefix. Fallback: a snapshot request returned no JPEG.
            $supported = str_starts_with($camera['Type'], 'UVC') && !in_array($camera['CameraID'], $noSnapshot, true);
            $rowColor = '';
            if (!$supported) {
                $rowColor = '#FFC0C0';
            } elseif ($camera['State'] !== 'CONNECTED') {
                $rowColor = '#FFFFC0';
            }
            $stored = '?';
            if ($statistics !== null) {
                $cameraStatistics = $statistics[$camera['CameraID']] ?? ['count' => 0, 'bytes' => 0];
                $stored = sprintf('%d (%s)', $cameraStatistics['count'], $this->FormatBytes($cameraStatistics['bytes']));
            }
            $rows[] = $camera + [
                'FullHDText' => $this->Translate($camera['FullHD'] ? 'Yes' : 'No'),
                'Snapshot'   => $this->Translate($supported ? 'OK' : 'No snapshot via Protect'),
                'Stored'     => $stored,
                'rowColor'   => $rowColor
            ];
        }
        return $rows;
    }

    private function GetNoSnapshotCameras(): array
    {
        $ids = json_decode($this->ReadAttributeString('NoSnapshotCameras'), true);
        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function SetNoSnapshot(string $CameraID, bool $NoSnapshot): void
    {
        $ids = $this->GetNoSnapshotCameras();
        $known = in_array($CameraID, $ids, true);
        if ($NoSnapshot && !$known) {
            $ids[] = $CameraID;
        } elseif (!$NoSnapshot && $known) {
            $ids = array_values(array_diff($ids, [$CameraID]));
        } else {
            return;
        }
        $this->WriteAttributeString('NoSnapshotCameras', json_encode($ids));
    }

    /**
     * Saved trigger rows with validated values. "Index" is the position in the list (key for the lockout).
     * The cameras of a row are stored as values "Camera_<id>" = true (one CheckBox per camera in the dialog).
     */
    private function GetTriggerList(): array
    {
        $list = json_decode($this->ReadPropertyString('Triggers'), true);
        if (!is_array($list)) {
            return [];
        }
        $triggers = [];
        foreach (array_values(array_filter($list, 'is_array')) as $index => $row) {
            $cameras = [];
            foreach ($row as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'Camera_') && $value === true && $this->IsValidCameraID(substr($key, 7))) {
                    $cameras[] = substr($key, 7);
                }
            }
            $condition = $row['Condition'] ?? self::CONDITION_CHANGE;
            $triggers[] = [
                'Index'      => $index,
                'Active'     => ($row['Active'] ?? true) === true,
                'Name'       => is_string($row['Name'] ?? null) ? trim($row['Name']) : '',
                'VariableID' => is_int($row['VariableID'] ?? null) ? $row['VariableID'] : 0,
                'Condition'  => is_int($condition) && $condition >= self::CONDITION_CHANGE && $condition <= self::CONDITION_UPDATE_WITH ? $condition : self::CONDITION_CHANGE,
                'Value'      => is_string($row['Value'] ?? null) ? $row['Value'] : '',
                'Delay'      => $this->ClampInteger($row['Delay'] ?? 0, 0, self::DELAY_MAX, 0),
                'Lockout'    => $this->ClampInteger($row['Lockout'] ?? self::TRIGGER_LOCKOUT_DEFAULT, 0, self::LOCKOUT_MAX, self::TRIGGER_LOCKOUT_DEFAULT),
                'Cameras'    => $cameras
            ];
        }
        return $triggers;
    }

    private function ClampInteger(mixed $Value, int $Minimum, int $Maximum, int $Default): int
    {
        return is_int($Value) ? max($Minimum, min($Maximum, $Value)) : $Default;
    }

    /**
     * Saved trigger rows plus the computed column "CameraNames" and row colors. All saved values are kept.
     */
    private function BuildTriggerRows(array $Cameras): array
    {
        $names = [];
        foreach ($Cameras as $camera) {
            $names[$camera['CameraID']] = $camera['Name'] !== '' ? $camera['Name'] : $camera['CameraID'];
        }
        $list = json_decode($this->ReadPropertyString('Triggers'), true);
        $rows = [];
        $triggers = $this->GetTriggerList();
        foreach (is_array($list) ? array_values(array_filter($list, 'is_array')) : [] as $position => $row) {
            $trigger = $triggers[$position];
            $cameraNames = array_map(fn (string $ID): string => $names[$ID] ?? $ID, $trigger['Cameras']);
            $row['CameraNames'] = count($cameraNames) > 0 ? implode(', ', $cameraNames) : $this->Translate('none');
            $row['rowColor'] = '';
            if ($trigger['VariableID'] === 0 || !IPS_VariableExists($trigger['VariableID'])) {
                $row['rowColor'] = '#FFC0C0';
            } elseif (count($trigger['Cameras']) === 0) {
                $row['rowColor'] = '#FFFFC0';
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
        $cameras = array_column($this->GetCameraList(), null, 'CameraID');
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

                foreach ($trigger['Cameras'] as $cameraID) {
                    if (!($cameras[$cameraID]['Enabled'] ?? false)) {
                        $this->SendDebug('Trigger', sprintf('%s: camera %s is not active', $name, $cameraID), 0);
                        continue;
                    }
                    $due = $now + $trigger['Delay'];
                    $lastCamera = $state['cameraLast'][$cameraID] ?? null;
                    if ($lastCamera !== null && abs($due - $lastCamera) < $cameraLockout) {
                        $this->SendDebug('Trigger', sprintf('%s: camera %s already has a snapshot within %d s', $name, $cameraID, $cameraLockout), 0);
                        continue;
                    }
                    $state['cameraLast'][$cameraID] = $due;
                    $state['queue'][] = ['due' => $due, 'camera' => $cameraID, 'trigger' => $name];
                    $this->SendDebug('Trigger', sprintf('%s: snapshot of %s queued in %d s', $name, $cameraID, $trigger['Delay']), 0);
                }
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
            self::CONDITION_CHANGE_TO   => $Changed && $this->ValueEquals($Trigger['Value'], $Value, $Type),
            self::CONDITION_UPDATE_WITH => $this->ValueEquals($Trigger['Value'], $Value, $Type),
            default                     => false
        };
    }

    /**
     * Compares the JSON-encoded value of SelectValue with the current value, converted to the variable type.
     */
    private function ValueEquals(string $Json, mixed $Value, int $Type): bool
    {
        $expected = $Json === '' ? null : json_decode($Json, true);
        if ($expected === null || is_array($expected)) {
            return false;
        }
        return match ($Type) {
            VARIABLETYPE_BOOLEAN => (bool) $expected === $Value,
            VARIABLETYPE_INTEGER => (int) $expected === $Value,
            VARIABLETYPE_FLOAT   => is_float($Value) && abs((float) $expected - $Value) < 1e-9,
            VARIABLETYPE_STRING  => (string) $expected === $Value,
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

        // Network access outside the lock, one camera after the other; an error does not stop the others
        usort($due, fn (array $A, array $B): int => $A['due'] <=> $B['due']);
        foreach ($due as $entry) {
            $result = $this->SnapshotAndStore($entry['camera'], $entry['trigger']);
            $this->SendDebug('Queue', sprintf('%s / %s: %s', $entry['trigger'], $entry['camera'], $result['ok'] ? 'stored' : $result['message']), 0);
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
        foreach (['values', 'triggerLast', 'cameraLast', 'queue'] as $key) {
            if (!is_array($state[$key] ?? null)) {
                $state[$key] = [];
            }
        }
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
        // Protect camera IDs are hex strings; they end up in URL paths and idents
        return preg_match('/^[A-Za-z0-9]+$/', $CameraID) === 1;
    }

    private function IsGDAvailable(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagescale') && function_exists('imagejpeg');
    }

    /**
     * Downscales the JPEG to the configured width if enabled and necessary. Result: data, width, height.
     * Images are never enlarged. On any problem the original image is kept.
     */
    private function ResizeImage(string $Jpeg): array
    {
        $size = getimagesizefromstring($Jpeg);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        $original = ['data' => $Jpeg, 'width' => $width, 'height' => $height];

        $maxWidth = $this->ReadPropertyInteger('ResizeWidth');
        if (!$this->ReadPropertyBoolean('ResizeEnabled') || $maxWidth <= 0 || $width <= $maxWidth || $height <= 0) {
            return $original;
        }
        if (!$this->IsGDAvailable()) {
            $this->SendDebug('Resize', 'GD not available, image stored without resizing', 0);
            return $original;
        }

        // Decoded images need about 4 bytes per pixel (source and target), plus some headroom
        $targetHeight = (int) ceil($height * $maxWidth / $width);
        if (!$this->HasMemoryFor(($width * $height + $maxWidth * $targetHeight) * 5)) {
            $this->SendDebug('Resize', sprintf('Not enough PHP memory for %d x %d, image stored without resizing', $width, $height), 0);
            return $original;
        }

        // The return value is checked, a failure is reported in the debug output
        $source = @imagecreatefromstring($Jpeg);
        if ($source === false) {
            $this->SendDebug('Resize', 'Image could not be decoded, stored without resizing', 0);
            return $original;
        }
        $target = imagescale($source, $maxWidth);
        unset($source);
        if ($target === false) {
            $this->SendDebug('Resize', 'Image could not be scaled, stored without resizing', 0);
            return $original;
        }

        $quality = max(1, min(100, $this->ReadPropertyInteger('ResizeQuality')));
        ob_start();
        $ok = imagejpeg($target, null, $quality);
        $data = (string) ob_get_clean();
        if (!$ok || $data === '') {
            $this->SendDebug('Resize', 'Image could not be encoded, stored without resizing', 0);
            return $original;
        }

        $resized = ['data' => $data, 'width' => imagesx($target), 'height' => imagesy($target)];
        $this->SendDebug('Resize', sprintf(
            '%d x %d, %d bytes -> %d x %d, %d bytes',
            $width,
            $height,
            strlen($Jpeg),
            $resized['width'],
            $resized['height'],
            strlen($data)
        ), 0);
        return $resized;
    }

    private function HasMemoryFor(int $Bytes): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $exponent = ['K' => 1, 'M' => 2, 'G' => 3][strtoupper(substr($limit, -1))] ?? 0;
        return memory_get_usage() + $Bytes < (int) $limit * (1024 ** $exponent);
    }

    /**
     * Stores an image in the ring buffer of the camera. Result: ok, message.
     */
    private function StoreImage(array $Camera, array $Image, string $Trigger): array
    {
        if (!$this->EnterStorageLock()) {
            return ['ok' => false, 'message' => $this->Translate('Storage is busy, please try again')];
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return ['ok' => false, 'message' => $this->Translate('The image index is damaged. Images are not stored.')];
            }

            $cameraID = $Camera['CameraID'];
            $label = $Camera['Name'] !== '' ? $Camera['Name'] : $cameraID;
            $entries = $index['cameras'][$cameraID] ?? [];
            $trimmed = $this->TrimEntries($entries, $cameraID, $Camera['Images']) > 0;

            $slot = $this->ChooseSlot($entries, $Camera['Images']);
            if ($slot === 0) {
                if ($trimmed) {
                    $index['cameras'][$cameraID] = $entries;
                    $this->SaveIndex($index);
                }
                $message = sprintf($this->Translate('All images of %s are marked as "keep": snapshot not stored'), $label);
                $this->LogMessage($message, KL_WARNING);
                return ['ok' => false, 'message' => $message];
            }

            $ident = $this->SlotIdent($cameraID, $slot);
            $mediaID = $this->FindMedia($ident, MEDIATYPE_IMAGE);
            if ($mediaID < 0) {
                if ($trimmed) {
                    $index['cameras'][$cameraID] = $entries;
                    $this->SaveIndex($index);
                }
                $this->SendDebug('Storage', sprintf('Ident %s is used by another object, slot skipped', $ident), 0);
                return ['ok' => false, 'message' => sprintf($this->Translate('Image slot %s is used by another object'), $ident)];
            }
            if ($mediaID === 0) {
                $mediaID = $this->CreateCachedMedia(
                    MEDIATYPE_IMAGE,
                    $ident,
                    sprintf('media/CameraArchive_%d_%s_%03d.jpg', $this->InstanceID, $cameraID, $slot),
                    sprintf('%s %02d', $label, $slot)
                );
            } elseif (!IPS_GetMedia($mediaID)['MediaIsCached']) {
                // Caching is the flash protection of this module, not a user setting
                IPS_SetMediaCached($mediaID, true);
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
            $index['cameras'][$cameraID] = $entries;
            if (!$this->SaveIndex($index)) {
                return ['ok' => false, 'message' => $this->Translate('The image index could not be saved')];
            }
            $this->SendDebug('Storage', sprintf('%s: slot %d, %d bytes, %d images', $label, $slot, strlen($Image['data']), count($entries)), 0);
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
     * Removes the oldest images not marked as "keep" while the camera has more images than allowed.
     * Images marked as "keep" are never removed here. Returns the number of removed images.
     */
    private function TrimEntries(array &$Entries, string $CameraID, int $Limit): int
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
            $this->DeleteSlotMedia($this->SlotIdent($CameraID, $Entries[$position]['slot']));
            array_splice($Entries, $position, 1);
            $removed++;
        }
        return $removed;
    }

    /**
     * Applies reduced image counts of all cameras in the list.
     */
    private function TrimAllCameras(): void
    {
        if (!$this->EnterStorageLock()) {
            return;
        }
        try {
            $index = $this->LoadIndex();
            if ($index === null) {
                return;
            }
            $removed = 0;
            foreach ($this->GetCameraList() as $camera) {
                if (!isset($index['cameras'][$camera['CameraID']])) {
                    continue;
                }
                $entries = $index['cameras'][$camera['CameraID']];
                $count = $this->TrimEntries($entries, $camera['CameraID'], $camera['Images']);
                if ($count > 0) {
                    $index['cameras'][$camera['CameraID']] = $entries;
                    $removed += $count;
                }
            }
            if ($removed > 0) {
                $this->SaveIndex($index);
                $this->SendDebug('Storage', sprintf('%d surplus images removed', $removed), 0);
            }
        } finally {
            $this->LeaveStorageLock();
        }
    }

    /**
     * Deletes all images of a camera from the index and below the instance (also slots missing in the index).
     * Returns the number of deleted media.
     */
    private function DeleteCameraImages(array &$Index, string $CameraID): int
    {
        $deleted = 0;
        foreach ($this->GetSlotMedia() as $slot) {
            if ($slot['camera'] === $CameraID && $this->DeleteSlotMedia($slot['ident'])) {
                $deleted++;
            }
        }
        unset($Index['cameras'][$CameraID]);
        return $deleted;
    }

    /**
     * Camera IDs with stored images, from the index and from the slot media below the instance.
     */
    private function GetStoredCameraIDs(): array
    {
        $ids = array_column($this->GetSlotMedia(), 'camera');
        $index = $this->LoadIndex();
        if ($index !== null) {
            $ids = array_merge($ids, array_map('strval', array_keys($index['cameras'])));
        }
        return array_values(array_unique($ids));
    }

    /**
     * Slot media below the instance: ident, camera, slot.
     */
    private function GetSlotMedia(): array
    {
        $slots = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (preg_match('/^' . self::SLOT_IDENT_PREFIX . '([A-Za-z0-9]+)_(\d+)$/', $ident, $match) === 1) {
                $slots[] = ['ident' => $ident, 'camera' => $match[1], 'slot' => (int) $match[2]];
            }
        }
        return $slots;
    }

    private function SlotIdent(string $CameraID, int $Slot): string
    {
        return sprintf('%s%s_%03d', self::SLOT_IDENT_PREFIX, $CameraID, $Slot);
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

    private function CreateCachedMedia(int $MediaType, string $Ident, string $File, string $Name): int
    {
        $mediaID = IPS_CreateMedia($MediaType);
        IPS_SetMediaFile($mediaID, $File, false);
        // Cache before the first content is written: the content then stays in RAM until shutdown (flash protection)
        IPS_SetMediaCached($mediaID, true);
        IPS_SetParent($mediaID, $this->InstanceID);
        IPS_SetIdent($mediaID, $Ident);
        // Name and visibility are only set once; afterwards they belong to the user
        IPS_SetName($mediaID, $Name);
        IPS_SetHidden($mediaID, true);
        return $mediaID;
    }

    /**
     * Index of all stored images, or null if it is damaged. A damaged index is never overwritten.
     */
    private function LoadIndex(): ?array
    {
        $empty = ['version' => self::INDEX_VERSION, 'seq' => 0, 'cameras' => []];
        $mediaID = $this->FindMedia(self::INDEX_IDENT, MEDIATYPE_DOCUMENT);
        if ($mediaID === 0) {
            return $empty;
        }

        $index = null;
        if ($mediaID > 0) {
            $raw = base64_decode(IPS_GetMediaContent($mediaID), true);
            $index = is_string($raw) ? json_decode($raw, true) : null;
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
        if (!is_array($Index) || ($Index['version'] ?? null) !== self::INDEX_VERSION || !is_int($Index['seq'] ?? null) || !is_array($Index['cameras'] ?? null)) {
            return false;
        }
        foreach ($Index['cameras'] as $cameraID => $entries) {
            if (!$this->IsValidCameraID((string) $cameraID) || !is_array($entries) || !array_is_list($entries)) {
                return false;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)
                    || !is_int($entry['slot'] ?? null) || $entry['slot'] < 1
                    || !is_int($entry['seq'] ?? null) || !is_int($entry['time'] ?? null)
                    || !is_string($entry['trigger'] ?? null) || !is_bool($entry['keep'] ?? null)
                    || !is_int($entry['size'] ?? null) || !is_int($entry['width'] ?? null) || !is_int($entry['height'] ?? null)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function SaveIndex(array $Index): bool
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
            $mediaID = $this->CreateCachedMedia(
                MEDIATYPE_DOCUMENT,
                self::INDEX_IDENT,
                sprintf('media/CameraArchive_%d_index.json', $this->InstanceID),
                $this->Translate('Image index')
            );
        }
        return IPS_SetMediaContent($mediaID, base64_encode($json));
    }

    /**
     * Number of images and bytes per camera, or null if the index is damaged.
     */
    private function GetStorageStatistics(): ?array
    {
        $index = $this->LoadIndex();
        if ($index === null) {
            return null;
        }
        $statistics = [];
        foreach ($index['cameras'] as $cameraID => $entries) {
            $statistics[(string) $cameraID] = ['count' => count($entries), 'bytes' => array_sum(array_column($entries, 'size'))];
        }
        return $statistics;
    }

    private function GetStorageInfo(): string
    {
        $statistics = $this->GetStorageStatistics();
        if ($statistics === null) {
            return $this->Translate('The image index is damaged. Images are not stored.');
        }
        return sprintf(
            $this->Translate('Stored images: %d, %s in RAM'),
            array_sum(array_column($statistics, 'count')),
            $this->FormatBytes(array_sum(array_column($statistics, 'bytes')))
        );
    }

    private function FormatBytes(int $Bytes): string
    {
        return sprintf('%.1f MB', $Bytes / 1048576);
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
    private function ReadStoredImage(string $CameraID, int $Slot, int $Seq): ?string
    {
        $index = $this->LoadIndex();
        $found = false;
        foreach ($index['cameras'][$CameraID] ?? [] as $entry) {
            if ($entry['slot'] === $Slot && $entry['seq'] === $Seq) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return null;
        }
        $mediaID = $this->FindMedia($this->SlotIdent($CameraID, $Slot), MEDIATYPE_IMAGE);
        if ($mediaID <= 0) {
            return null;
        }
        $data = base64_decode(IPS_GetMediaContent($mediaID), true);
        return is_string($data) && $data !== '' ? $data : null;
    }

    private function SendHookError(int $Code, string $Text): void
    {
        header(sprintf('HTTP/1.1 %d %s', $Code, $Text));
        header('Content-Type: text/plain');
        echo $Text;
    }

    /**
     * State of the tile: cameras (active or with images) in the order of the camera list, images newest first.
     */
    private function BuildTileState(): array
    {
        $index = $this->LoadIndex();
        $cameras = [];
        foreach ($this->GetCameraList() as $camera) {
            $entries = $index['cameras'][$camera['CameraID']] ?? [];
            if (!$camera['Enabled'] && count($entries) === 0) {
                continue;
            }
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
            $cameras[] = [
                'id'     => $camera['CameraID'],
                'name'   => $camera['Name'] !== '' ? $camera['Name'] : $camera['CameraID'],
                'images' => $images
            ];
        }
        return ['cameras' => $cameras, 'indexDamaged' => $index === null];
    }

    /**
     * Sends the current state to open tiles. Only metadata, the images are loaded via the WebHook.
     */
    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue((string) json_encode($this->BuildTileState(), JSON_INVALID_UTF8_SUBSTITUTE));
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
