<?php

declare(strict_types=1);

// Test double of the SMTP module of Symcon (module ID of the real module), which the SymconStubs do not contain.
// It records the calls of SMTP_SendMailMedia and SMTP_SendMailMediaEx; $GLOBALS['smtpResult'] sets the result.
class SMTP extends IPSModule
{
    public function SendMailMedia(string $Subject, string $Text, int $MediaID): bool
    {
        $GLOBALS['smtpCalls'][] = [$this->InstanceID, '', $Subject, $Text, $MediaID];
        return $GLOBALS['smtpResult'] ?? true;
    }

    public function SendMailMediaEx(string $Address, string $Subject, string $Text, int $MediaID): bool
    {
        $GLOBALS['smtpCalls'][] = [$this->InstanceID, $Address, $Subject, $Text, $MediaID];
        return $GLOBALS['smtpResult'] ?? true;
    }
}
