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

        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->UpdateTile();
        }
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Host or API key may have changed
        $this->SetBuffer(self::CAMERA_BUFFER, '');

        if ($this->ReadPropertyBoolean('ResizeEnabled') && !$this->IsGDAvailable()) {
            $this->LogMessage($this->Translate('PHP extension GD is not available: images are stored without resizing.'), KL_WARNING);
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            // The cameras apply the cache option to their media; at startup they ask for it themselves
            $this->SendToChildren(['Command' => 'Settings', 'MemoryCache' => $this->ReadPropertyBoolean('MemoryCache')]);
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
            'Snapshot'     => $this->SnapshotForChild(is_string($request['CameraID'] ?? null) ? $request['CameraID'] : ''),
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
        $results = [];
        foreach ($this->SendDataToChildren((string) json_encode(['DataID' => self::DATA_TO_CHILDREN, 'Buffer' => bin2hex((string) json_encode($Request))])) as $result) {
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
    private function SnapshotForChild(string $CameraID): array
    {
        $result = $this->FetchSnapshot($CameraID);
        if (!$result['ok']) {
            return $result;
        }
        $image = $this->ResizeImage($result['image']);
        return [
            'ok'          => true,
            'message'     => '',
            'image'       => base64_encode($image['data']),
            'width'       => $image['width'],
            'height'      => $image['height'],
            'label'       => $result['label'],
            'memoryCache' => $this->ReadPropertyBoolean('MemoryCache')
        ];
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
