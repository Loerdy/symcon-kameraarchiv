<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Triggers, lockouts and the snapshot queue of a camera instance.
// The stubs do not dispatch messages, so the tests call MessageSink like the kernel would.
class TriggerTest extends TestCase
{
    private const CONSOLE = '{1D21BC5C-4D67-637A-F881-25186E07F675}';
    private const CAMERA = '{9640A1DD-2C7F-8953-58B8-30A09B2DEE85}';

    private int $id;
    private object $module;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

        // Port 1 is not listening: snapshots fail quickly, but the configuration counts as complete
        $console = IPS_CreateInstance(self::CONSOLE);
        IPS_SetProperty($console, 'Host', '127.0.0.1:1');
        IPS_SetProperty($console, 'APIKey', 'test');
        IPS_ApplyChanges($console);

        $this->id = IPS_CreateInstance(self::CAMERA);
        IPS_SetProperty($this->id, 'CameraID', '653fd7e100601403e40003f3');
        IPS_ConnectInstance($this->id, $console);
        IPS_ApplyChanges($this->id);
        $this->module = IPS\InstanceManager::getInstanceInterface($this->id);
    }

    public function testAnyChange(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 0, ['Lockout' => 0])], 0);

        $this->update($variable, false);
        $this->assertCount(0, $this->queue());
        $this->update($variable, true);
        $this->assertCount(1, $this->queue());
        $this->update($variable, false);
        $this->assertCount(2, $this->queue());
    }

    public function testAnyUpdate(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 1, ['Lockout' => 0])], 0);

        $this->update($variable, false);
        $this->update($variable, false);
        $this->assertCount(2, $this->queue());
    }

    public function testChangesToValue(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 2, ['Lockout' => 0, 'Value' => 'true'])], 0);

        $this->update($variable, true);
        $this->update($variable, true);
        $this->update($variable, false);
        $this->assertCount(1, $this->queue());
    }

    public function testIsUpdatedWithValue(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, true);
        $this->setTriggers([$this->trigger($variable, 3, ['Lockout' => 0, 'Value' => 'true'])], 0);

        $this->update($variable, true);
        $this->update($variable, true);
        $this->update($variable, false);
        $this->assertCount(2, $this->queue());
    }

    public function testValueComparisonUsesVariableType(): void
    {
        $integer = $this->variable(VARIABLETYPE_INTEGER, 0);
        $float = $this->variable(VARIABLETYPE_FLOAT, 0.0);
        $string = $this->variable(VARIABLETYPE_STRING, '');
        $this->setTriggers([
            $this->trigger($integer, 3, ['Lockout' => 0, 'Value' => '3']),
            $this->trigger($float, 3, ['Lockout' => 0, 'Value' => '2.5']),
            $this->trigger($string, 3, ['Lockout' => 0, 'Value' => '"ring"'])
        ], 0);

        $this->update($integer, 2);
        $this->update($integer, 3);
        $this->update($float, 2.5);
        $this->update($string, 'Ring');
        $this->update($string, 'ring');
        $this->assertCount(3, $this->queue());
    }

    public function testTriggerLockout(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 1, ['Lockout' => 30])], 0);

        $this->update($variable, true);
        $this->update($variable, true);
        $this->assertCount(1, $this->queue());
    }

    public function testCameraLockoutAcrossTriggers(): void
    {
        $doorbell = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $contact = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($doorbell, 1, ['Name' => 'Klingel']), $this->trigger($contact, 1, ['Name' => 'Tür'])], 5);

        $this->update($doorbell, true);
        $this->update($contact, true);
        $queue = $this->queue();
        $this->assertCount(1, $queue);
        $this->assertSame('Klingel', $queue[0]['trigger']);

        // Without camera lockout both triggers queue a snapshot
        $this->setTriggers([$this->trigger($doorbell, 1, ['Name' => 'Klingel']), $this->trigger($contact, 1, ['Name' => 'Tür'])], 0);
        $this->clearQueue();
        $this->update($doorbell, true);
        $this->update($contact, true);
        $this->assertCount(2, $this->queue());
    }

    public function testInactiveTriggerIsSkipped(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 1, ['Active' => false])], 0);

        $this->update($variable, true);
        $this->assertCount(0, $this->queue());
    }

    public function testCameraWithoutConsoleQueuesNothing(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 1)], 0);
        IPS_DisconnectInstance($this->id);
        IPS_ApplyChanges($this->id);
        $this->assertSame(202, IPS_GetInstance($this->id)['InstanceStatus']);

        $this->update($variable, true);
        $this->assertCount(0, $this->queue());
        $this->assertFalse(CAR_TakeSnapshot($this->id));
    }

    public function testDefaultNameIsVariableName(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        IPS_SetName($variable, 'Türkontakt');
        $this->setTriggers([$this->trigger($variable, 1, ['Name' => ''])], 0);

        $this->update($variable, true);
        $this->assertSame('Türkontakt', $this->queue()[0]['trigger']);
    }

    public function testDelaySetsTimerToNextDueSnapshot(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 1, ['Delay' => 10])], 0);
        $this->assertSame(0, $this->timerInterval());

        $this->update($variable, true);
        $interval = $this->timerInterval();
        $this->assertGreaterThan(9000, $interval);
        $this->assertLessThanOrEqual(10000, $interval);
    }

    public function testProcessQueueRemovesDueEntriesAndKeepsLaterOnes(): void
    {
        $now = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $later = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($now, 1), $this->trigger($later, 1, ['Delay' => 60])], 0);
        $this->update($now, true);
        $this->update($later, true);
        $this->assertCount(2, $this->queue());

        // The console is not reachable: the due entry is removed anyway, the later one stays scheduled
        IPS_RequestAction($this->id, 'ProcessQueue', 0);
        $queue = $this->queue();
        $this->assertCount(1, $queue);
        $this->assertGreaterThan(55000, $this->timerInterval());
    }

    public function testMessagesAndReferencesFollowTriggerList(): void
    {
        $first = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $second = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($first, 1)], 0);
        $this->assertSame([VM_UPDATE], $this->messages()[$first] ?? []);
        $this->assertContains($first, $this->module->GetReferenceList());

        $this->setTriggers([$this->trigger($second, 1)], 0);
        $this->assertArrayNotHasKey($first, $this->messages());
        $this->assertSame([VM_UPDATE], $this->messages()[$second] ?? []);
        $this->assertSame([$second], array_values($this->module->GetReferenceList()));
        // The registration for the kernel start stays
        $this->assertContains(IPS_KERNELSTARTED, $this->messages()[0] ?? []);
    }

    public function testTriggerDialogAndRows(): void
    {
        $variable = $this->variable(VARIABLETYPE_BOOLEAN, false);
        $this->setTriggers([$this->trigger($variable, 0, ['Extra' => true])], 0);

        $dialog = array_column(json_decode(CAR_UITriggerForm($this->id, $variable, 0), true), null, 'name');
        $this->assertFalse($dialog['Value']['visible']);
        $dialog = array_column(json_decode(CAR_UITriggerForm($this->id, $variable, 2), true), null, 'name');
        $this->assertTrue($dialog['Value']['visible']);
        $this->assertSame($variable, $dialog['Value']['variableID']);
        $this->assertArrayNotHasKey('ValueHint', $dialog);

        // New row or deleted variable: SelectValue needs an existing variable, a hint is shown instead
        foreach ([0, 999999] as $missing) {
            $dialog = array_column(json_decode(CAR_UITriggerForm($this->id, $missing, 2), true), null, 'name');
            $this->assertArrayNotHasKey('Value', $dialog);
            $this->assertTrue($dialog['ValueHint']['visible']);
            $this->assertStringEndsWith('$Condition, false);', $dialog['VariableID']['onChange']);
        }

        $form = json_decode(IPS_GetConfigurationForm($this->id), true);
        $elements = array_column($form['elements'], null, 'name');
        // All saved values of the row are kept for saving, including values of former versions
        $this->assertSame('Test', $elements['Triggers']['values'][0]['Name']);
        $this->assertTrue($elements['Triggers']['values'][0]['Extra']);
        $this->assertSame('', $elements['Triggers']['values'][0]['rowColor']);
        // SelectValue cannot load an empty string, so a row without a chosen value is shown with "null"
        $this->assertSame('null', $elements['Triggers']['values'][0]['Value']);
    }

    private function variable(int $Type, mixed $Value): int
    {
        $id = IPS_CreateVariable($Type);
        SetValue($id, $Value);
        return $id;
    }

    private function trigger(int $VariableID, int $Condition, array $Overrides = []): array
    {
        return $Overrides + [
            'Active'                   => true,
            'Name'                     => 'Test',
            'VariableID'               => $VariableID,
            'Condition'                => $Condition,
            'Value'                    => '',
            'Delay'                    => 0,
            'Lockout'                  => 30
        ];
    }

    private function setTriggers(array $Triggers, int $CameraLockout): void
    {
        IPS_SetProperty($this->id, 'Triggers', json_encode($Triggers));
        IPS_SetProperty($this->id, 'CameraLockout', $CameraLockout);
        IPS_ApplyChanges($this->id);
    }

    private function update(int $VariableID, mixed $Value): void
    {
        SetValue($VariableID, $Value);
        $this->module->MessageSink(0, $VariableID, VM_UPDATE, []);
    }

    private function queue(): array
    {
        return (new ReflectionMethod($this->module, 'LoadTriggerState'))->invoke($this->module)['queue'];
    }

    private function clearQueue(): void
    {
        $state = (new ReflectionMethod($this->module, 'LoadTriggerState'))->invoke($this->module);
        $state['queue'] = [];
        $state['cameraLast'] = null;
        (new ReflectionMethod($this->module, 'SaveTriggerState'))->invoke($this->module, $state);
    }

    private function timerInterval(): int
    {
        return (new ReflectionMethod($this->module, 'GetTimerInterval'))->invoke($this->module, 'ProcessQueue');
    }

    private function messages(): array
    {
        return (new ReflectionMethod($this->module, 'GetMessageList'))->invoke($this->module);
    }
}
