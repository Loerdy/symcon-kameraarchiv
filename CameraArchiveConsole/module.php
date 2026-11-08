<?php

declare(strict_types=1);

/**
 * Connection to the UniFi Protect console. The camera instances (children) request camera list and snapshots via the
 * data flow; the console fetches them, downscales the images and returns them. Its tile shows all connected cameras.
 */
class CameraArchiveConsole extends IPSModuleStrict
{
    // Instance status codes, see "status" in form.json
    private const STATUS_CONFIG_MISSING = 201;
    private const STATUS_CURL_MISSING = 202;
    private const STATUS_UNREACHABLE = 203;
    private const STATUS_AUTH_FAILED = 204;
    private const STATUS_TLS_FAILED = 205;
    private const STATUS_UNEXPECTED = 206;

    // Data flow to the children. Packets of the children ({9EC0ACA4-E5E8-FBE2-061E-944A1C1A13BB}) arrive in ForwardData.
    private const DATA_TO_CHILDREN = '{0EACC956-88F0-E151-304C-A22F37DDA6EA}';

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

    // Camera properties of the last camera list (RAM only), needed for "highQuality" of the snapshot request
    private const CAMERA_BUFFER = 'Cameras';

    // The tile of the camera instances is reused for the overview of all cameras
    private const TILE_FILE = __DIR__ . '/../CameraArchiveCamera/module.html';
    // Actions of the tile, executed by the camera instances
    private const TILE_ACTIONS = ['TileSnapshot', 'TileKeep', 'TileDelete'];

    // NAS (WebDAV): queue of uploads and copies, processed by one timer. The queue itself is a small buffer; the
    // originals lie in a fixed pool of own media objects (ident NAS_001 …), cached like the images of the cameras.
    // Buffers cannot hold images: the kernel keeps only the last 512 KiB of a buffer and clears all buffers of an
    // instance above 1 MiB (see SymconStubs).
    private const UPLOAD_TIMER = 'ProcessUploads';
    private const UPLOAD_QUEUE_BUFFER = 'UploadQueue';
    private const UPLOAD_SLOT_PREFIX = 'NAS_';
    private const UPLOAD_LOCK_TIMEOUT = 2000;
    // Limits of the queue (RAM of the SymBox); when full, the oldest uploads are dropped
    private const UPLOAD_MAX_ITEMS = 100;
    private const UPLOAD_MAX_SLOTS = 30;
    private const UPLOAD_MAX_BYTES = 26214400;
    // Waiting time before the next attempt, by number of failed attempts (seconds)
    private const UPLOAD_RETRY_DELAYS = [60, 120, 300, 600, 1800];
    // Folders known to exist, retention per camera folder, date of the last rotation, error state
    private const NAS_STATE_BUFFER = 'NASState';
    private const DATE_FOLDER_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyBoolean('SkipCertificateCheck', false);
        $this->RegisterPropertyBoolean('ResizeEnabled', true);
        $this->RegisterPropertyInteger('ResizeWidth', 1280);
        $this->RegisterPropertyInteger('ResizeQuality', 75);
        $this->RegisterPropertyBoolean('MemoryCache', true);
        $this->RegisterPropertyString('NASURL', '');
        $this->RegisterPropertyString('NASUser', '');
        $this->RegisterPropertyString('NASPassword', '');
        $this->RegisterPropertyBoolean('NASSkipCertificateCheck', false);

        // Only active while uploads are waiting
        $this->RegisterTimer(self::UPLOAD_TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'' . self::UPLOAD_TIMER . '\', 0);');

        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ClearUnusedUploadSlots();
            $this->UpdateTile();
        }
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Host or API key may have changed
        $this->SetBuffer(self::CAMERA_BUFFER, '');
        // NAS address may have changed: folders are checked again, waiting uploads stay
        $state = $this->LoadNASState();
        $state['folders'] = [];
        $this->SaveNASState($state);
        $this->ScheduleUploads();

        if ($this->ReadPropertyBoolean('ResizeEnabled') && !$this->IsGDAvailable()) {
            $this->LogMessage($this->Translate('PHP extension GD is not available: images are stored without resizing.'), KL_WARNING);
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            // The cameras apply the cache option to their media; at startup they ask for it themselves
            $this->SendToChildren(['Command' => 'Settings', 'MemoryCache' => $this->ReadPropertyBoolean('MemoryCache')]);
            $this->ClearUnusedUploadSlots();
            $this->UpdateTile();
        }

        // No network access here: ApplyChanges also runs during startup and must not block.
        $this->SetStatus($this->CheckConfiguration());
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if ($this->ReadPropertyBoolean('ResizeEnabled') && !$this->IsGDAvailable()) {
            $this->PatchFormField($form['elements'], 'GDMissing', 'visible', true);
        }
        $this->PatchFormField($form['elements'], 'NASInfo', 'caption', $this->GetNASInfo());
        return json_encode($form);
    }

    /**
     * Requests of the camera instances and the configurator. Result: JSON object with at least "ok" and "message".
     */
    public function ForwardData(string $JSONString): string
    {
        $request = $this->DecodePacket($JSONString);
        $command = is_array($request) && is_string($request['Command'] ?? null) ? $request['Command'] : '';
        $result = match ($command) {
            'GetCameras'   => $this->GetCamerasForChild(),
            'Snapshot'     => $this->SnapshotForChild(is_string($request['CameraID'] ?? null) ? $request['CameraID'] : '', is_array($request['NAS'] ?? null) ? $request['NAS'] : null),
            'NASKeep'      => $this->QueueKeepCopy(is_string($request['Path'] ?? null) ? $request['Path'] : ''),
            'GetSettings'  => ['ok' => true, 'message' => '', 'memoryCache' => $this->ReadPropertyBoolean('MemoryCache')],
            'StateChanged' => $this->HandleStateChanged(),
            default        => ['ok' => false, 'message' => 'Invalid request']
        };
        return (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Actions of the overview tile. The value names the camera instance ("camera"); the console only forwards it to
     * its own camera instances, which validate and execute the action.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === self::UPLOAD_TIMER) {
            $this->ProcessUploads();
            return;
        }
        if (!in_array($Ident, self::TILE_ACTIONS, true)) {
            throw new Exception(sprintf('Invalid ident %s', $Ident));
        }
        $data = is_string($Value) ? json_decode($Value, true) : null;
        $cameraID = is_array($data) ? ($data['camera'] ?? null) : null;
        if (!is_int($cameraID) || $cameraID <= 0) {
            $this->SendDebug('Tile', sprintf('%s: invalid camera', $Ident), 0);
            return;
        }
        $this->SendToChildren(['Command' => 'TileAction', 'InstanceID' => $cameraID, 'Action' => $Ident, 'Value' => $Value]);
    }

    /**
     * Overview tile: all connected cameras. The state comes from the camera instances and carries the address and
     * token of their image WebHooks, so the images are loaded directly from the camera instances.
     */
    public function GetVisualizationTile(): string
    {
        $json = json_encode($this->BuildOverviewState(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        return file_get_contents(self::TILE_FILE) . '<script>handleMessage(' . $json . ');</script>';
    }

    /**
     * Button "Test connection": Requests the camera list and shows the result.
     */
    public function UITestConnection(): void
    {
        echo $this->FetchCameras()['message'];
    }

    /**
     * Button "Test NAS connection": reads the archive folder (PROPFIND) and shows the result.
     */
    public function UITestNAS(): void
    {
        if ($this->GetNASBaseURL() === '') {
            echo $this->Translate('Please enter a valid WebDAV address (http:// or https://) and click "Apply" first.');
            return;
        }
        $response = $this->WebDAVRequest('PROPFIND', '', null, ['Depth: 0']);
        echo $this->DescribeNASResponse($response, [207]);
    }

    private function DecodePacket(string $JSONString): ?array
    {
        $data = json_decode($JSONString, true);
        $buffer = is_array($data) && is_string($data['Buffer'] ?? null) ? $data['Buffer'] : '';
        if ($buffer === '' || strlen($buffer) % 2 !== 0 || !ctype_xdigit($buffer)) {
            return null;
        }
        $request = json_decode((string) hex2bin($buffer), true);
        return is_array($request) ? $request : null;
    }

    private function SendToChildren(array $Request): array
    {
        // While Symcon loads the instances (start, module update), single cameras may not be created yet.
        // A missing answer must not stop the console, the cameras update themselves once they are ready.
        try {
            $answers = @$this->SendDataToChildren((string) json_encode(['DataID' => self::DATA_TO_CHILDREN, 'Buffer' => bin2hex((string) json_encode($Request))]));
        } catch (Throwable $exception) {
            $this->SendDebug('Children', sprintf('%s: %s', $Request['Command'] ?? '', $exception->getMessage()), 0);
            return [];
        }
        $results = [];
        foreach (is_array($answers) ? $answers : [] as $result) {
            $decoded = is_string($result) ? json_decode($result, true) : null;
            if (is_array($decoded)) {
                $results[] = $decoded;
            }
        }
        return $results;
    }

    private function HandleStateChanged(): array
    {
        $this->UpdateTile();
        return ['ok' => true, 'message' => ''];
    }

    /**
     * State of the overview tile: cameras in the order of the object tree, then by name.
     */
    private function BuildOverviewState(): array
    {
        $cameras = [];
        foreach ($this->SendToChildren(['Command' => 'GetTileState']) as $camera) {
            if (is_int($camera['id'] ?? null) && is_string($camera['name'] ?? null) && is_array($camera['images'] ?? null)) {
                $cameras[] = $camera;
            }
        }
        usort($cameras, fn (array $A, array $B): int => [$A['position'] ?? 0, $A['name']] <=> [$B['position'] ?? 0, $B['name']]);
        foreach ($cameras as &$camera) {
            unset($camera['position']);
        }
        unset($camera);
        return ['cameras' => $cameras, 'overview' => true];
    }

    private function UpdateTile(): void
    {
        $this->UpdateVisualizationValue((string) json_encode($this->BuildOverviewState(), JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Camera list for the configurator, reduced to the fields the module uses.
     */
    private function GetCamerasForChild(): array
    {
        $result = $this->FetchCameras();
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'cameras' => []];
        }
        return ['ok' => true, 'message' => $result['message'], 'cameras' => array_values($this->NormalizeCameras($result['cameras']))];
    }

    /**
     * Cameras of the Protect answer with valid ID, keyed by the ID.
     */
    private function NormalizeCameras(array $List): array
    {
        $cameras = [];
        foreach ($List as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !$this->IsValidCameraID($entry['id'])) {
                continue;
            }
            $flags = is_array($entry['featureFlags'] ?? null) ? $entry['featureFlags'] : [];
            $cameras[$entry['id']] = [
                'id'     => $entry['id'],
                'name'   => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                'type'   => is_string($entry['type'] ?? null) ? $entry['type'] : '',
                'state'  => is_string($entry['state'] ?? null) ? $entry['state'] : '',
                'fullHD' => ($flags['supportFullHdSnapshot'] ?? false) === true
            ];
        }
        return $cameras;
    }

    /**
     * Fetches and downscales a snapshot. Result: ok, message; on success also image (base64), width, height, label
     * and memoryCache (option of the console, the camera applies it to its media).
     */
    private function SnapshotForChild(string $CameraID, ?array $NAS): array
    {
        $result = $this->FetchSnapshot($CameraID);
        if (!$result['ok']) {
            return $result;
        }
        // The NAS gets the original in full resolution, the camera the downscaled image
        $nasPath = $NAS === null ? '' : $this->QueueUpload($NAS, $result['image']);
        $image = $this->ResizeImage($result['image']);
        return [
            'ok'          => true,
            'message'     => '',
            'image'       => base64_encode($image['data']),
            'width'       => $image['width'],
            'height'      => $image['height'],
            'label'       => $result['label'],
            'memoryCache' => $this->ReadPropertyBoolean('MemoryCache'),
            'nasPath'     => $nasPath
        ];
    }

    /**
     * WebDAV address without trailing slash, or '' if none or no valid http(s) address is set.
     */
    private function GetNASBaseURL(): string
    {
        $url = rtrim(trim($this->ReadPropertyString('NASURL')), '/');
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return '';
        }
        return $url;
    }

    /**
     * Folder or file name for the NAS: characters not allowed on the NAS are replaced, umlauts stay (UTF-8).
     */
    private function SafeName(string $Name, string $Fallback): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $Name));
        $name = trim($name, '. ');
        return $name !== '' ? $name : $Fallback;
    }

    /**
     * Queues the original of a snapshot. $NAS: folder, trigger, retention (days, 0 = keep). Returns the path relative
     * to the WebDAV address ("Folder/2026-11-08/143005_Trigger.jpg"), or '' if nothing was queued.
     */
    private function QueueUpload(array $NAS, string $Data): string
    {
        if ($this->GetNASBaseURL() === '' || !function_exists('curl_init')) {
            $this->SendDebug('NAS', 'No valid WebDAV address, upload skipped', 0);
            return '';
        }
        $folder = $this->SafeName(is_string($NAS['folder'] ?? null) ? $NAS['folder'] : '', 'Camera');
        $trigger = $this->SafeName(is_string($NAS['trigger'] ?? null) ? $NAS['trigger'] : '', 'manual');
        $retention = is_int($NAS['retention'] ?? null) ? max(0, $NAS['retention']) : 0;
        $time = time();
        $path = $folder . '/' . date('Y-m-d', $time) . '/' . date('His', $time) . '_' . $trigger . '.jpg';

        if (!$this->EnterUploadLock()) {
            $this->SendDebug('NAS', 'Queue lock not available, upload skipped', 0);
            return '';
        }
        try {
            $state = $this->LoadNASState();
            $state['retention'][$folder] = $retention;
            $this->SaveNASState($state);

            $queue = $this->LoadUploadQueue();
            $used = array_column(array_filter($queue, fn (array $Entry): bool => $Entry['kind'] === 'put'), 'slot');
            $slot = 0;
            for ($candidate = 1; $candidate <= self::UPLOAD_MAX_SLOTS; $candidate++) {
                if (!in_array($candidate, $used, true)) {
                    $slot = $candidate;
                    break;
                }
            }
            if ($slot === 0) {
                // All slots wait for the NAS: the oldest upload gives way
                foreach ($queue as $position => $entry) {
                    if ($entry['kind'] === 'put') {
                        $slot = $entry['slot'];
                        array_splice($queue, $position, 1);
                        $this->LogMessage(sprintf($this->Translate('NAS upload queue full: %d images were not uploaded.'), 1), KL_WARNING);
                        break;
                    }
                }
            }
            if (!$this->WriteUploadSlot($slot, $Data)) {
                $this->SaveUploadQueue($queue);
                return '';
            }
            $id = (int) $this->GetBuffer('UploadNextID') + 1;
            $this->SetBuffer('UploadNextID', (string) $id);
            $queue[] = ['id' => $id, 'kind' => 'put', 'path' => $path, 'size' => strlen($Data), 'attempts' => 0, 'next' => 0.0, 'slot' => $slot];
            $queue = $this->LimitUploadQueue($queue);
            $this->SaveUploadQueue($queue);
        } finally {
            $this->LeaveUploadLock();
        }
        $this->SendDebug('NAS', sprintf('Queued %s (%d bytes)', $path, strlen($Data)), 0);
        $this->ScheduleUploads();
        return $path;
    }

    /**
     * "Keep" in a tile: copies the uploaded image into the folder for kept images of its camera, which the
     * rotation never deletes. The copy runs after a still waiting upload of the image (queue order).
     */
    private function QueueKeepCopy(string $Path): array
    {
        $segments = explode('/', $Path);
        if ($this->GetNASBaseURL() === '' || count($segments) !== 3 || preg_match(self::DATE_FOLDER_PATTERN, $segments[1]) !== 1
            || $this->SafeName($segments[0], '') !== $segments[0] || $this->SafeName($segments[2], '') !== $segments[2]) {
            return ['ok' => false, 'message' => 'Invalid path'];
        }
        if (!$this->EnterUploadLock()) {
            return ['ok' => false, 'message' => 'Queue busy'];
        }
        try {
            $queue = $this->LoadUploadQueue();
            $id = (int) $this->GetBuffer('UploadNextID') + 1;
            $this->SetBuffer('UploadNextID', (string) $id);
            $queue[] = ['id' => $id, 'kind' => 'copy', 'path' => $Path, 'size' => 0, 'attempts' => 0, 'next' => 0.0];
            $this->SaveUploadQueue($this->LimitUploadQueue($queue));
        } finally {
            $this->LeaveUploadLock();
        }
        $this->ScheduleUploads();
        return ['ok' => true, 'message' => ''];
    }

    private function KeepFolder(string $CameraFolder): string
    {
        return $CameraFolder . '/' . $this->Translate('Kept');
    }

    /**
     * Drops the oldest uploads while the queue is too large. Copies need no memory and are only dropped by count.
     */
    private function LimitUploadQueue(array $Queue): array
    {
        $dropped = 0;
        while (count($Queue) > self::UPLOAD_MAX_ITEMS || array_sum(array_column($Queue, 'size')) > self::UPLOAD_MAX_BYTES) {
            $entry = array_shift($Queue);
            if ($entry['kind'] === 'put') {
                $this->ClearUploadSlot($entry['slot']);
            }
            $dropped++;
        }
        if ($dropped > 0) {
            $this->LogMessage(sprintf($this->Translate('NAS upload queue full: %d images were not uploaded.'), $dropped), KL_WARNING);
        }
        return $Queue;
    }

    /**
     * Timer: processes the due entries in order. If the NAS fails, processing stops and is retried later, so an
     * unreachable NAS is not asked for every waiting image. Network access never happens under the lock.
     */
    private function ProcessUploads(): void
    {
        if ($this->GetNASBaseURL() === '') {
            // Address removed: waiting uploads are discarded
            $this->ClearUploadQueue();
            return;
        }
        if (!$this->EnterUploadLock()) {
            return;
        }
        try {
            $queue = $this->LoadUploadQueue();
        } finally {
            $this->LeaveUploadLock();
        }

        $now = microtime(true);
        foreach ($queue as $entry) {
            if ($entry['next'] > $now) {
                break;
            }
            $result = $this->ExecuteUpload($entry);
            if (!$this->EnterUploadLock()) {
                break;
            }
            try {
                $current = $this->LoadUploadQueue();
                foreach ($current as $position => $item) {
                    if ($item['id'] !== $entry['id']) {
                        continue;
                    }
                    if ($result === 'retry') {
                        $delays = self::UPLOAD_RETRY_DELAYS;
                        $current[$position]['attempts']++;
                        $current[$position]['next'] = microtime(true) + $delays[min($current[$position]['attempts'], count($delays)) - 1];
                    } else {
                        if ($item['kind'] === 'put') {
                            $this->ClearUploadSlot($item['slot']);
                        }
                        array_splice($current, $position, 1);
                    }
                    break;
                }
                $this->SaveUploadQueue($current);
            } finally {
                $this->LeaveUploadLock();
            }
            if ($result === 'retry') {
                break;
            }
        }

        $this->ScheduleUploads();
        if (count($this->LoadUploadQueue()) === 0) {
            $this->RotateNAS();
        }
    }

    /**
     * Uploads or copies one entry. Result: "done", "retry" (NAS not reachable, no permission, folder missing …)
     * or "drop" (the entry cannot succeed, e.g. the source of a copy no longer exists).
     */
    private function ExecuteUpload(array $Entry): string
    {
        $segments = explode('/', $Entry['path']);
        if ($Entry['kind'] === 'copy') {
            $target = $this->KeepFolder($segments[0]);
            if (!$this->EnsureNASFolder($target)) {
                return 'retry';
            }
            $response = $this->WebDAVRequest('COPY', $Entry['path'], null, [
                'Destination: ' . $this->NASURL($target . '/' . $segments[2]),
                'Overwrite: T'
            ]);
            if ($response['errno'] === 0 && $response['code'] === 404) {
                $this->SendDebug('NAS', sprintf('%s: not on the NAS, copy dropped', $Entry['path']), 0);
                return 'drop';
            }
            return $this->HandleNASResult($response, [201, 204], $Entry['path']);
        }

        $data = $this->ReadUploadSlot($Entry['slot']);
        if ($data === null) {
            $this->SendDebug('NAS', sprintf('%s: data missing, upload dropped', $Entry['path']), 0);
            return 'drop';
        }
        if (!$this->EnsureNASFolder($segments[0] . '/' . $segments[1])) {
            return 'retry';
        }
        $response = $this->WebDAVRequest('PUT', $Entry['path'], $data, ['Content-Type: image/jpeg']);
        $result = $this->HandleNASResult($response, [200, 201, 204], $Entry['path']);
        if ($result !== 'done') {
            return $result;
        }
        // The NAS confirmed the upload: its size is checked, as an incomplete file would stay unnoticed otherwise.
        // Without an answer or size the upload counts as done (the check must not block working uploads).
        $head = $this->WebDAVRequest('HEAD', $Entry['path']);
        if ($head['errno'] === 0 && $head['code'] === 200 && $head['length'] >= 0 && $head['length'] !== strlen($data)) {
            $message = sprintf($this->Translate('Upload incomplete (%d of %d bytes)'), $head['length'], strlen($data));
            return $this->HandleNASResult(['errno' => 0, 'error' => '', 'code' => 0, 'body' => '', 'length' => -1, 'message' => $message], [200], $Entry['path']);
        }
        return 'done';
    }

    /**
     * Result of a NAS request for the queue; reports the first error of a series once and its end.
     */
    private function HandleNASResult(array $Response, array $Success, string $Path): string
    {
        $state = $this->LoadNASState();
        if ($Response['errno'] === 0 && in_array($Response['code'], $Success, true)) {
            if ($state['error'] !== '') {
                $state['error'] = '';
                $this->SaveNASState($state);
                $this->LogMessage($this->Translate('NAS reachable again, waiting images are uploaded.'), KL_NOTIFY);
            }
            $this->SendDebug('NAS', sprintf('%s: HTTP %d', $Path, $Response['code']), 0);
            return 'done';
        }
        $message = $this->DescribeNASResponse($Response, $Success);
        $this->SendDebug('NAS', sprintf('%s: %s', $Path, $message), 0);
        // Other client errors (e.g. 400, 415) cannot be solved by trying again
        $permanent = $Response['errno'] === 0 && $Response['code'] >= 400 && $Response['code'] < 500
            && !in_array($Response['code'], [401, 403, 404, 408, 409, 423, 429], true);
        if ($state['error'] === '') {
            $this->LogMessage(sprintf($this->Translate('NAS upload failed: %s. Images are kept in the queue and uploaded later.'), $message), KL_WARNING);
        }
        $state['error'] = $message;
        $state['folders'] = [];
        $this->SaveNASState($state);
        return $permanent ? 'drop' : 'retry';
    }

    private function DescribeNASResponse(array $Response, array $Success): string
    {
        if (is_string($Response['message'] ?? null)) {
            return $Response['message'];
        }
        if ($Response['errno'] !== 0) {
            $this->SendDebug('NAS cURL error', sprintf('%d: %s', $Response['errno'], $Response['error']), 0);
            if (in_array($Response['errno'], self::CURL_TLS_ERRORS, true)) {
                return $this->Translate('The certificate of the NAS could not be verified');
            }
            return $this->Translate('NAS not reachable');
        }
        if (in_array($Response['code'], $Success, true)) {
            return $this->Translate('Connection to the NAS OK');
        }
        return match ($Response['code']) {
            401, 403 => $this->Translate('NAS user or password invalid, or no permission for the folder'),
            404, 409 => $this->Translate('Folder not found on the NAS'),
            507      => $this->Translate('NAS storage full'),
            default  => sprintf($this->Translate('Unexpected answer of the NAS (HTTP %d)'), $Response['code'])
        };
    }

    /**
     * Creates the folder and its parents (MKCOL) if they are not known to exist yet.
     */
    private function EnsureNASFolder(string $Folder): bool
    {
        $state = $this->LoadNASState();
        $path = '';
        foreach (explode('/', $Folder) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            if (isset($state['folders'][$path])) {
                continue;
            }
            $response = $this->WebDAVRequest('MKCOL', $path . '/');
            // 201 created, 405 already exists (RFC 4918)
            if ($response['errno'] !== 0 || !in_array($response['code'], [201, 405], true)) {
                $this->HandleNASResult($response, [201, 405], $path);
                return false;
            }
            $state['folders'][$path] = true;
        }
        $this->SaveNASState($state);
        return true;
    }

    /**
     * Once a day after the queue is empty: deletes the date folders of every camera folder that are older than its
     * retention. Other folders (kept images) are never touched.
     */
    private function RotateNAS(): void
    {
        $state = $this->LoadNASState();
        $today = date('Y-m-d');
        if ($state['rotation'] === $today) {
            return;
        }
        $complete = true;
        foreach ($state['retention'] as $folder => $days) {
            if (!is_int($days) || $days <= 0) {
                continue;
            }
            $limit = date('Y-m-d', strtotime(sprintf('-%d days', $days)));
            $response = $this->WebDAVRequest('PROPFIND', $folder . '/', null, ['Depth: 1']);
            if ($response['errno'] !== 0 || $response['code'] !== 207) {
                $this->SendDebug('Rotation', sprintf('%s: %s', $folder, $this->DescribeNASResponse($response, [207])), 0);
                $complete = false;
                continue;
            }
            foreach ($this->ParseDateFolders($response['body'], (string) $folder) as $date) {
                if ($date >= $limit) {
                    continue;
                }
                $delete = $this->WebDAVRequest('DELETE', $folder . '/' . $date . '/');
                $this->SendDebug('Rotation', sprintf('%s/%s: HTTP %d', $folder, $date, $delete['code']), 0);
                if ($delete['errno'] !== 0 || !in_array($delete['code'], [200, 204, 404], true)) {
                    $complete = false;
                }
            }
        }
        if ($complete) {
            $state = $this->LoadNASState();
            $state['rotation'] = $today;
            $this->SaveNASState($state);
        }
    }

    /**
     * Names of the date folders directly below the camera folder from a PROPFIND answer (Depth 1).
     */
    private function ParseDateFolders(string $Body, string $Folder): array
    {
        preg_match_all('#<(?:[A-Za-z][\w.-]*:)?href>\s*([^<]+?)\s*</(?:[A-Za-z][\w.-]*:)?href>#i', $Body, $matches);
        $dates = [];
        foreach ($matches[1] as $href) {
            $path = rawurldecode((string) parse_url(html_entity_decode($href, ENT_QUOTES | ENT_XML1), PHP_URL_PATH));
            $segments = explode('/', trim($path, '/'));
            $name = end($segments);
            $parent = prev($segments);
            if (is_string($name) && $parent === $Folder && preg_match(self::DATE_FOLDER_PATTERN, $name) === 1) {
                $dates[] = $name;
            }
        }
        return array_values(array_unique($dates));
    }

    private function NASURL(string $Path): string
    {
        $segments = $Path === '' ? [] : explode('/', rtrim($Path, '/'));
        $url = $this->GetNASBaseURL() . ($segments === [] ? '' : '/' . implode('/', array_map('rawurlencode', $segments)));
        return str_ends_with($Path, '/') ? $url . '/' : $url;
    }

    /**
     * WebDAV request. User and password are sent by curl (basic authentication) and never written to the debug output.
     */
    private function WebDAVRequest(string $Method, string $Path, ?string $Body = null, array $Headers = []): array
    {
        $url = $this->NASURL($Path);
        $this->SendDebug('WebDAV', $Method . ' ' . $url, 0);
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $Method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER     => $Headers
        ];
        if ($Method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($this->ReadPropertyString('NASUser') !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $this->ReadPropertyString('NASUser') . ':' . $this->ReadPropertyString('NASPassword');
        }
        if ($Body !== null) {
            $options[CURLOPT_POSTFIELDS] = $Body;
        }
        if ($this->ReadPropertyBoolean('NASSkipCertificateCheck')) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $response = [
            'errno'  => curl_errno($ch),
            'error'  => curl_error($ch),
            'code'   => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body'   => is_string($body) ? $body : '',
            // Content-Length of the answer, -1 if unknown
            'length' => (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T)
        ];
        unset($ch);
        return $response;
    }

    /**
     * Stores an original in its slot medium; the medium is created on first use and reused afterwards.
     * Caching follows the option "Use in-memory cache" like the images of the cameras.
     */
    private function WriteUploadSlot(int $Slot, string $Data): bool
    {
        $ident = sprintf('%s%03d', self::UPLOAD_SLOT_PREFIX, $Slot);
        $cached = $this->ReadPropertyBoolean('MemoryCache');
        $mediaID = $this->FindUploadSlot($Slot);
        if ($mediaID < 0) {
            $this->SendDebug('NAS', sprintf('Ident %s is used by another object, upload skipped', $ident), 0);
            return false;
        }
        if ($mediaID === 0) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetMediaFile($mediaID, sprintf('media/CameraArchiveUpload_%d_%03d.jpg', $this->InstanceID, $Slot), false);
            // Cache before the first content is written (flash protection)
            IPS_SetMediaCached($mediaID, $cached);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            // Name and visibility are only set once; afterwards they belong to the user
            IPS_SetName($mediaID, sprintf('%s %02d', $this->Translate('NAS upload'), $Slot));
            IPS_SetHidden($mediaID, true);
        } elseif (IPS_GetMedia($mediaID)['MediaIsCached'] !== $cached) {
            IPS_SetMediaCached($mediaID, $cached);
        }
        return IPS_SetMediaContent($mediaID, base64_encode($Data));
    }

    private function ReadUploadSlot(int $Slot): ?string
    {
        $mediaID = $this->FindUploadSlot($Slot);
        if ($mediaID <= 0) {
            return null;
        }
        try {
            $content = @IPS_GetMediaContent($mediaID);
        } catch (Throwable $exception) {
            return null;
        }
        $data = is_string($content) ? base64_decode($content, true) : false;
        return is_string($data) && $data !== '' ? $data : null;
    }

    /**
     * Empties a slot after the upload, so the original does not occupy RAM or get written at shutdown.
     */
    private function ClearUploadSlot(int $Slot): void
    {
        $mediaID = $this->FindUploadSlot($Slot);
        if ($mediaID > 0 && IPS_GetMedia($mediaID)['MediaSize'] > 0) {
            IPS_SetMediaContent($mediaID, '');
        }
    }

    /**
     * Empties all slots that no waiting upload refers to, e.g. after a restart (the queue lies in RAM only).
     */
    private function ClearUnusedUploadSlots(): void
    {
        $used = array_column(array_filter($this->LoadUploadQueue(), fn (array $Entry): bool => $Entry['kind'] === 'put'), 'slot');
        for ($slot = 1; $slot <= self::UPLOAD_MAX_SLOTS; $slot++) {
            if (!in_array($slot, $used, true)) {
                $this->ClearUploadSlot($slot);
            }
        }
    }

    /**
     * ID of the slot medium: 0 if it does not exist, -1 if the ident belongs to another kind of object.
     */
    private function FindUploadSlot(int $Slot): int
    {
        // The warning for a missing ident is expected here, the result is checked
        $id = @IPS_GetObjectIDByIdent(sprintf('%s%03d', self::UPLOAD_SLOT_PREFIX, $Slot), $this->InstanceID);
        if ($id === false) {
            return 0;
        }
        return IPS_MediaExists($id) && IPS_GetMedia($id)['MediaType'] === MEDIATYPE_IMAGE ? $id : -1;
    }

    private function LoadUploadQueue(): array
    {
        $queue = json_decode($this->GetBuffer(self::UPLOAD_QUEUE_BUFFER), true);
        return is_array($queue) ? array_values(array_filter($queue, fn (mixed $Entry): bool => is_array($Entry) && is_int($Entry['id'] ?? null)
            && in_array($Entry['kind'] ?? null, ['put', 'copy'], true) && is_string($Entry['path'] ?? null)
            && is_int($Entry['size'] ?? null) && is_int($Entry['attempts'] ?? null) && (is_float($Entry['next'] ?? null) || is_int($Entry['next'] ?? null))
            && ($Entry['kind'] === 'copy' || (is_int($Entry['slot'] ?? null) && $Entry['slot'] >= 1 && $Entry['slot'] <= self::UPLOAD_MAX_SLOTS)))) : [];
    }

    private function SaveUploadQueue(array $Queue): void
    {
        $this->SetBuffer(self::UPLOAD_QUEUE_BUFFER, (string) json_encode(array_values($Queue)));
    }

    private function ClearUploadQueue(): void
    {
        if (!$this->EnterUploadLock()) {
            return;
        }
        try {
            $this->SaveUploadQueue([]);
            $this->ClearUnusedUploadSlots();
        } finally {
            $this->LeaveUploadLock();
        }
        $this->SetTimerInterval(self::UPLOAD_TIMER, 0);
    }

    /**
     * Sets the timer to the next due entry, or stops it if the queue is empty.
     */
    private function ScheduleUploads(): void
    {
        $queue = $this->LoadUploadQueue();
        if (count($queue) === 0 || $this->GetNASBaseURL() === '') {
            $this->SetTimerInterval(self::UPLOAD_TIMER, count($queue) === 0 ? 0 : 1);
            return;
        }
        $next = min(array_map(fn (array $Entry): float => (float) $Entry['next'], $queue));
        $this->SetTimerInterval(self::UPLOAD_TIMER, max(1, (int) ceil(($next - microtime(true)) * 1000)));
    }

    private function LoadNASState(): array
    {
        $state = json_decode($this->GetBuffer(self::NAS_STATE_BUFFER), true);
        $state = is_array($state) ? $state : [];
        return [
            'folders'   => is_array($state['folders'] ?? null) ? $state['folders'] : [],
            'retention' => is_array($state['retention'] ?? null) ? $state['retention'] : [],
            'rotation'  => is_string($state['rotation'] ?? null) ? $state['rotation'] : '',
            'error'     => is_string($state['error'] ?? null) ? $state['error'] : ''
        ];
    }

    private function SaveNASState(array $State): void
    {
        $json = (string) json_encode($State);
        if ($this->GetBuffer(self::NAS_STATE_BUFFER) !== $json) {
            $this->SetBuffer(self::NAS_STATE_BUFFER, $json);
        }
    }

    private function EnterUploadLock(): bool
    {
        return IPS_SemaphoreEnter('CARC_Upload_' . $this->InstanceID, self::UPLOAD_LOCK_TIMEOUT);
    }

    private function LeaveUploadLock(): void
    {
        IPS_SemaphoreLeave('CARC_Upload_' . $this->InstanceID);
    }

    private function GetNASInfo(): string
    {
        if ($this->GetNASBaseURL() === '') {
            return $this->Translate('NAS: not set up');
        }
        $queue = $this->LoadUploadQueue();
        $info = sprintf($this->Translate('NAS: %d waiting, %.1f MB'), count($queue), array_sum(array_column($queue, 'size')) / 1048576);
        $error = $this->LoadNASState()['error'];
        return $error === '' ? $info : $info . ' – ' . sprintf($this->Translate('last error: %s'), $error);
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
     * Requests the camera list and remembers the camera properties. Result: ok, message, cameras (raw).
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
        $this->SetBuffer(self::CAMERA_BUFFER, (string) json_encode($this->NormalizeCameras($data)));
        $this->SendDebug('Cameras', sprintf('%d cameras received', count($data)), 0);
        return ['ok' => true, 'message' => sprintf($this->Translate('Connection OK, %d cameras found'), count($data)), 'cameras' => $data];
    }

    /**
     * Properties of a camera from the last camera list; the list is requested if the camera is unknown.
     */
    private function GetCamera(string $CameraID): ?array
    {
        $cameras = json_decode($this->GetBuffer(self::CAMERA_BUFFER), true);
        if (!is_array($cameras) || !isset($cameras[$CameraID])) {
            if (!$this->FetchCameras()['ok']) {
                return null;
            }
            $cameras = json_decode($this->GetBuffer(self::CAMERA_BUFFER), true);
        }
        return is_array($cameras[$CameraID] ?? null) ? $cameras[$CameraID] : null;
    }

    /**
     * Requests a snapshot. Result: ok, message; on success also image (JPEG bytes) and label.
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

        $camera = $this->GetCamera($CameraID);
        if ($camera === null) {
            return ['ok' => false, 'message' => sprintf($this->Translate('Camera %s was not found on the Protect console'), $CameraID)];
        }
        $label = $camera['name'] !== '' ? $camera['name'] : $CameraID;

        // highQuality only for cameras that support Full HD snapshots, see docs/KONZEPT.md
        $path = '/cameras/' . $CameraID . '/snapshot' . ($camera['fullHD'] ? '?highQuality=true' : '');
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
            $this->SendDebug('Snapshot', sprintf(
                '%s: %d bytes, Content-Type %s, %.2f s, highQuality %s',
                $label,
                strlen($body),
                $response['type'],
                $response['time'],
                $camera['fullHD'] ? 'yes' : 'no'
            ), 0);
            return ['ok' => true, 'message' => '', 'image' => $body, 'label' => $label];
        }

        // No JPEG: The console answered, but cannot deliver a snapshot for this camera (e.g. third-party cameras)
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
        return ['ok' => false, 'message' => sprintf($this->Translate('No snapshot received from %s (HTTP %d). Details in the debug output of the console.'), $label, $response['code'])];
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

    private function IsValidCameraID(string $CameraID): bool
    {
        // Protect camera IDs are hex strings; they end up in URL paths
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
