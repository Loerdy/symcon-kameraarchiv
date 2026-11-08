<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Notifications by e-mail and push after a stored trigger snapshot
class NotificationTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';
    private const VISU = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const SMTP = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    private int $id;
    private object $module;
    private int $smtp;
    private int $visu;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
        // The SymconStubs contain no SMTP module: a test double with its module ID records the calls
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/doubles/SMTP', '{E4713B63-4980-15EF-7247-819552E1629E}');
        $GLOBALS['smtpCalls'] = [];
        $GLOBALS['smtpResult'] = true;

        $console = IPS_CreateInstance(self::CONSOLE);
        IPS_ApplyChanges($console);
        $this->id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($this->id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ConnectInstance($this->id, $console);
        IPS_ApplyChanges($this->id);
        IPS_SetName($this->id, 'Haustür');
        $this->module = IPS\InstanceManager::getInstanceInterface($this->id);

        $this->smtp = IPS_CreateInstance(self::SMTP);
        $this->visu = IPS_CreateInstance(self::VISU);
    }

    public function testMailWithImageForMatchingTrigger(): void
    {
        $this->setNotifications([
            $this->mail(['Trigger' => 'Klingel']),
            $this->mail(['Trigger' => '', 'Recipient' => 'ich@example.org'])
        ]);
        $mediaID = $this->storeImage();

        $this->send('Klingel', $mediaID);
        $this->send('Türkontakt', $mediaID);

        $calls = $GLOBALS['smtpCalls'];
        $this->assertCount(3, $calls);
        $this->assertSame([$this->smtp, '', 'Haustür: Klingel'], array_slice($calls[0], 0, 3));
        $this->assertSame($mediaID, $calls[0][4]);
        $this->assertStringContainsString('Klingel', $calls[0][3]);
        $this->assertSame('ich@example.org', $calls[1][1]);
        $this->assertSame('Haustür: Türkontakt', $calls[2][2]);
    }

    public function testInactiveOrIncompleteNotificationIsNotSent(): void
    {
        $this->setNotifications([
            $this->mail(['Active' => false]),
            $this->mail(['SMTPID' => 0]),
            $this->mail(['SMTPID' => $this->visu]),
            $this->mail(['Recipient' => 'no address']),
            $this->push(['VisuID' => $this->smtp])
        ]);
        $this->send('Klingel', $this->storeImage());

        $this->assertSame([], $GLOBALS['smtpCalls']);
        $this->assertSame(0, $this->pushCount());
    }

    public function testConditionWithBooleanAndNumber(): void
    {
        $presence = $this->variable(VARIABLETYPE_BOOLEAN, true);
        $mode = $this->variable(VARIABLETYPE_INTEGER, 1);
        $this->setNotifications([
            $this->mail(['ConditionVariableID' => $presence, 'ConditionValueBool' => 'false']),
            $this->mail(['ConditionVariableID' => $mode, 'ConditionValue' => '2', 'Recipient' => 'mode@example.org'])
        ]);
        $mediaID = $this->storeImage();

        $this->send('Klingel', $mediaID);
        $this->assertSame([], $GLOBALS['smtpCalls']);

        SetValue($presence, false);
        SetValue($mode, 2);
        $this->send('Klingel', $mediaID);
        $this->assertSame(['', 'mode@example.org'], array_column($GLOBALS['smtpCalls'], 1));
    }

    public function testConditionWithoutValueOrMissingVariableIsNotSent(): void
    {
        $presence = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setNotifications([
            $this->mail(['ConditionVariableID' => $presence]),
            $this->mail(['ConditionVariableID' => 999999])
        ]);
        $this->send('Klingel', $this->storeImage());
        $this->assertSame([], $GLOBALS['smtpCalls']);

        $rows = $this->rows();
        $this->assertSame(['#FFC0C0', '#FFC0C0'], array_column($rows, 'rowColor'));
    }

    public function testPushNotification(): void
    {
        $this->setNotifications([$this->push(), $this->push(['Trigger' => 'Bewegung'])]);
        $this->send('Klingel', $this->storeImage());
        $this->assertSame(1, $this->pushCount());
    }

    public function testFailureIsReportedOnceUntilItWorksAgain(): void
    {
        $this->setNotifications([$this->mail()]);
        $mediaID = $this->storeImage();

        $GLOBALS['smtpResult'] = false;
        $this->send('Klingel', $mediaID);
        $this->assertSame(['0' => true], $this->failed());
        $this->send('Klingel', $mediaID);
        $this->assertSame(['0' => true], $this->failed());

        $GLOBALS['smtpResult'] = true;
        $this->send('Klingel', $mediaID);
        $this->assertSame([], $this->failed());
        $this->assertCount(3, $GLOBALS['smtpCalls']);
    }

    public function testRowsAndReferences(): void
    {
        $presence = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setNotifications([
            $this->mail(['Extra' => 'kept']),
            $this->push(['ConditionVariableID' => $presence, 'ConditionValueBool' => 'true']),
            $this->push(['VisuID' => $this->smtp])
        ]);

        $rows = $this->rows();
        $this->assertSame(['', '', '#FFC0C0'], array_column($rows, 'rowColor'));
        $this->assertSame('kept', $rows[0]['Extra']);

        $references = IPS\InstanceManager::getInstanceInterface($this->id)->GetReferenceList();
        foreach ([$this->smtp, $this->visu, $presence] as $objectID) {
            $this->assertContains($objectID, $references);
        }
    }

    public function testDialog(): void
    {
        IPS_SetProperty($this->id, 'Triggers', json_encode([
            ['Active' => true, 'Name' => 'Klingel', 'VariableID' => 0, 'Condition' => 0, 'Value' => '', 'Delay' => 0, 'Lockout' => 30],
            ['Active' => true, 'Name' => 'Klingel', 'VariableID' => 0, 'Condition' => 1, 'Value' => '', 'Delay' => 0, 'Lockout' => 30],
            ['Active' => true, 'Name' => 'Tür', 'VariableID' => 0, 'Condition' => 1, 'Value' => '', 'Delay' => 0, 'Lockout' => 30]
        ]));
        IPS_ApplyChanges($this->id);

        $dialog = array_column(json_decode(CAR_UINotificationForm($this->id, 0, 0), true), null, 'name');
        $this->assertTrue($dialog['SMTPID']['visible']);
        $this->assertTrue($dialog['Recipient']['visible']);
        $this->assertFalse($dialog['VisuID']['visible']);
        $this->assertFalse($dialog['ConditionValueBool']['visible']);
        $this->assertFalse($dialog['ConditionValue']['visible']);
        $this->assertSame(['', 'Klingel', 'Tür'], array_column($dialog['Trigger']['options'], 'value'));
        $this->assertSame([self::VISU], $dialog['VisuID']['validModules']);
        $this->assertSame([self::SMTP], $dialog['SMTPID']['validModules']);

        $dialog = array_column(json_decode(CAR_UINotificationForm($this->id, 1, $this->variable(VARIABLETYPE_BOOLEAN, false)), true), null, 'name');
        $this->assertFalse($dialog['SMTPID']['visible']);
        $this->assertTrue($dialog['VisuID']['visible']);
        $this->assertTrue($dialog['PushTargetID']['visible']);
        $this->assertTrue($dialog['ConditionValueBool']['visible']);

        $dialog = array_column(json_decode(CAR_UINotificationForm($this->id, 0, $this->variable(VARIABLETYPE_STRING, '')), true), null, 'name');
        $this->assertTrue($dialog['ConditionValue']['visible']);
        $this->assertSame('Value (text)', $dialog['ConditionValue']['caption']);
    }

    private function mail(array $Overrides = []): array
    {
        return $Overrides + ['Active' => true, 'Type' => 0, 'SMTPID' => $this->smtp, 'Recipient' => '', 'VisuID' => 0, 'PushTargetID' => 0, 'Trigger' => '', 'ConditionVariableID' => 0, 'ConditionValue' => '', 'ConditionValueBool' => ''];
    }

    private function push(array $Overrides = []): array
    {
        return $Overrides + ['Active' => true, 'Type' => 1, 'SMTPID' => 0, 'Recipient' => '', 'VisuID' => $this->visu, 'PushTargetID' => 0, 'Trigger' => '', 'ConditionVariableID' => 0, 'ConditionValue' => '', 'ConditionValueBool' => ''];
    }

    private function setNotifications(array $Notifications): void
    {
        IPS_SetProperty($this->id, 'Notifications', json_encode($Notifications));
        IPS_ApplyChanges($this->id);
    }

    private function variable(int $Type, mixed $Value): int
    {
        $id = IPS_CreateVariable($Type);
        SetValue($id, $Value);
        return $id;
    }

    private function storeImage(): int
    {
        $result = (new ReflectionMethod($this->module, 'StoreImage'))->invoke($this->module, ['data' => 'image', 'width' => 16, 'height' => 8], 'Klingel', true);
        $this->assertTrue($result['ok']);
        $this->assertTrue(IPS_MediaExists($result['mediaID']));
        return $result['mediaID'];
    }

    private function send(string $Trigger, int $MediaID): void
    {
        (new ReflectionMethod($this->module, 'SendNotifications'))->invoke($this->module, $Trigger, $MediaID, time());
    }

    private function rows(): array
    {
        $form = json_decode(IPS_GetConfigurationForm($this->id), true);
        return array_column($form['elements'], null, 'name')['Notifications']['values'];
    }

    private function failed(): array
    {
        $buffer = (new ReflectionMethod($this->module, 'GetBuffer'))->invoke($this->module, 'NotifyFailed');
        return $buffer === '' ? [] : json_decode($buffer, true);
    }

    private function pushCount(): int
    {
        $visu = IPS\InstanceManager::getInstanceInterface($this->visu);
        return (new ReflectionMethod($visu, 'ReadAttributeInteger'))->invoke($visu, 'LastNotificationsID');
    }
}
