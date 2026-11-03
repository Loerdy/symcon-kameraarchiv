<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Builds the request URL from the "Host" property, read from the debug output of a connection test.
// Port 1 is not listening, so every request fails quickly with "connection refused".
class HostTest extends TestCase
{
    private const MODULE = '{2AF53696-16BD-B081-D6C1-DCA8F3893CE4}';

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
    }

    public static function hostProvider(): array
    {
        return [
            'hostname'                 => ['localhost', 'localhost'],
            'hostname with port'       => ['localhost:1', 'localhost:1'],
            'IPv4'                     => ['127.0.0.1', '127.0.0.1'],
            'IPv4 with port'           => ['127.0.0.1:1', '127.0.0.1:1'],
            'scheme and slash removed' => ['https://127.0.0.1:1/', '127.0.0.1:1'],
            'IPv6 literal'             => ['::1', '[::1]'],
            'IPv6 with scheme'         => ['https://::1/', '[::1]'],
            'IPv6 with zone ID'        => ['fe80::1%eth0', '[fe80::1%25eth0]'],
            'IPv6 bracketed with port' => ['[::1]:1', '[::1]:1'],
            'IPv6 bracketed'           => ['[::1]', '[::1]']
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('hostProvider')]
    public function testRequestUrl(string $input, string $expectedHost): void
    {
        $id = IPS_CreateInstance(self::MODULE);
        IPS_SetProperty($id, 'Host', $input);
        IPS_SetProperty($id, 'APIKey', 'test');
        IPS_ApplyChanges($id);
        IPS\DebugServer::enableDebug($id, 600);

        ob_start();
        CAR_UITestConnection($id);
        ob_end_clean();

        $urls = [];
        foreach (IPS\DebugServer::getDebugMessages($id) as $message) {
            if ($message['Message'] === 'Request') {
                $urls[] = $message['Data'];
            }
        }
        $this->assertSame(['GET https://' . $expectedHost . '/proxy/protect/integration/v1/cameras'], $urls);
    }
}
