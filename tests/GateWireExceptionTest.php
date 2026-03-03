<?php

declare(strict_types=1);

namespace GateWire\Tests;

use GateWire\Exceptions\GateWireException;
use PHPUnit\Framework\TestCase;

class GateWireExceptionTest extends TestCase
{
    public function test_from_response_sets_message(): void
    {
        $e = GateWireException::fromResponse(402, 'Insufficient balance.');

        $this->assertSame('Insufficient balance.', $e->getMessage());
    }

    public function test_from_response_sets_code(): void
    {
        $e = GateWireException::fromResponse(429, 'Daily OTP limit reached.');

        $this->assertSame(429, $e->getCode());
    }

    public function test_from_response_returns_instance_of_gatewire_exception(): void
    {
        $e = GateWireException::fromResponse(503, 'No available devices.');

        $this->assertInstanceOf(GateWireException::class, $e);
    }

    public function test_is_subclass_of_exception(): void
    {
        $e = GateWireException::fromResponse(500, 'Server error.');

        $this->assertInstanceOf(\Exception::class, $e);
    }
}
