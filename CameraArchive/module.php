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

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyBoolean('SkipCertificateCheck', false);
        $this->RegisterPropertyString('Cameras', '[]');

        // Camera IDs for which Protect delivered no JPEG snapshot. This is runtime knowledge,
        // not user configuration, therefore an attribute and not a property.
        $this->RegisterAttributeString('NoSnapshotCameras', '[]');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Forget snapshot failures of cameras that are no longer in the list
        $ids = array_column($this->GetCameraList(), 'CameraID');
        $noSnapshot = array_values(array_intersect($this->GetNoSnapshotCameras(), $ids));
        $this->WriteAttributeString('NoSnapshotCameras', json_encode($noSnapshot));

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
     * Fetches a snapshot of the given camera.
     * Phase 2: The image is only reported via debug output and not stored yet.
     */
    public function TakeSnapshot(string $CameraID): bool
    {
        $result = $this->FetchSnapshot($CameraID);
        if (!$result['ok']) {
            $this->SendDebug('TakeSnapshot', $result['message'], 0);
        }
        return $result['ok'];
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

        // Keep the selection of cameras that are already known
        $enabled = [];
        foreach ($this->GetCameraList() as $camera) {
            $enabled[$camera['CameraID']] = $camera['Enabled'];
        }

        $cameras = [];
        foreach ($result['cameras'] as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id']) || $entry['id'] === '') {
                continue;
            }
            $flags = isset($entry['featureFlags']) && is_array($entry['featureFlags']) ? $entry['featureFlags'] : [];
            $cameras[] = [
                'Enabled'  => $enabled[$entry['id']] ?? false,
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
        $result = $this->FetchSnapshot($CameraID);
        $markedAfter = in_array($CameraID, $this->GetNoSnapshotCameras(), true);

        // Show a changed "Snapshot" column in the open form
        if ($markedBefore !== $markedAfter) {
            $this->UpdateFormField('Cameras', 'values', json_encode($this->BuildCameraRows($this->GetCameraList())));
        }

        echo $result['message'];
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
     * Requests a snapshot. Result: ok, message.
     */
    private function FetchSnapshot(string $CameraID): array
    {
        $message = $this->CheckRequestPossible();
        if ($message !== '') {
            return ['ok' => false, 'message' => $message];
        }

        // Protect camera IDs are hex strings; anything else must not end up in the URL path
        if (preg_match('/^[A-Za-z0-9]+$/', $CameraID) !== 1) {
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
            // Phase 3: store the image in the RAM ring buffer
            return ['ok' => true, 'message' => sprintf($this->Translate('Snapshot received: %s, %d bytes'), $label, strlen($body))];
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
            $cameras[] = [
                'Enabled'  => ($entry['Enabled'] ?? false) === true,
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
            $rows[] = $camera + [
                'FullHDText' => $this->Translate($camera['FullHD'] ? 'Yes' : 'No'),
                'Snapshot'   => $this->Translate($supported ? 'OK' : 'No snapshot via Protect'),
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
