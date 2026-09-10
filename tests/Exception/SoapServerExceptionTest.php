<?php

declare(strict_types=1);

namespace GoetasWebservices\SoapServices\SoapServer\Tests\Exception;

use GoetasWebservices\SoapServices\SoapServer\Exception\ServerException;
use GoetasWebservices\SoapServices\SoapServer\Exception\SoapServerException;
use PHPUnit\Framework\TestCase;

/**
 * In debug mode, to11Fault()/to12Fault() append the exception's stack trace to
 * the fault string/reason. A previous version passed the exception object
 * itself where a string was expected, which is a fatal TypeError under
 * declare(strict_types=1) regardless of whether the exception has a
 * __toString() method.
 */
class SoapServerExceptionTest extends TestCase
{
    public function testTo11FaultInDebugModeDoesNotThrow(): void
    {
        $e = new ServerException('something broke');

        $fault = SoapServerException::to11Fault($e, true);

        $this->assertStringContainsString('something broke', $fault->getBody()->getFault()->getString());
        $this->assertStringContainsString(__FUNCTION__, $fault->getBody()->getFault()->getString());
    }

    public function testTo12FaultInDebugModeDoesNotThrow(): void
    {
        $e = new ServerException('something broke');

        $fault = SoapServerException::to12Fault($e, true);

        $reason = implode("\n", $fault->getBody()->getFault()->getReason());
        $this->assertStringContainsString('something broke', $reason);
        $this->assertStringContainsString(__FUNCTION__, $reason);
    }
}
