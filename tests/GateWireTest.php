<?php

declare(strict_types=1);

namespace GateWire\Tests;

use GateWire\GateWire;
use GateWire\Exceptions\GateWireException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class GateWireTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a GateWire instance backed by a MockHandler.
     *
     * @param  list<Response|RequestException> $responses Responses to queue.
     * @param  list<array>                     $history   Populated with sent requests.
     */
    private function makeClient(array $responses, array &$history = []): GateWire
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new HttpClient([
            'handler'  => $stack,
            'base_uri' => 'https://gatewire.raystate.com/api/v1/',
        ]);

        return new GateWire('test_token', 'https://gatewire.raystate.com/api/v1', $http);
    }

    /** Decode the JSON body of a captured request. */
    private function requestBody(array $history, int $index = 0): array
    {
        $body = (string) $history[$index]['request']->getBody();
        return json_decode($body, true) ?? [];
    }

    // -------------------------------------------------------------------------
    // dispatch()
    // -------------------------------------------------------------------------

    public function test_dispatch_returns_reference_id_and_status(): void
    {
        $payload = ['reference_id' => 'wg_01HX_TEST', 'status' => 'pending'];
        $gw      = $this->makeClient([new Response(200, [], json_encode($payload))]);

        $result = $gw->dispatch('+213555123456');

        $this->assertSame('wg_01HX_TEST', $result['reference_id']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_dispatch_sends_phone_in_body(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"reference_id":"wg_1","status":"pending"}')],
            $history,
        );

        $gw->dispatch('+213555000001');

        $body = $this->requestBody($history);
        $this->assertSame('+213555000001', $body['phone']);
    }

    public function test_dispatch_sends_template_key_when_provided(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"reference_id":"wg_1","status":"pending"}')],
            $history,
        );

        $gw->dispatch('+213555000001', 'login_otp');

        $body = $this->requestBody($history);
        $this->assertSame('login_otp', $body['template_key']);
    }

    public function test_dispatch_omits_template_key_when_null(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"reference_id":"wg_1","status":"pending"}')],
            $history,
        );

        $gw->dispatch('+213555000001');

        $body = $this->requestBody($history);
        $this->assertArrayNotHasKey('template_key', $body);
    }

    public function test_dispatch_uses_post_send_otp_endpoint(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"reference_id":"wg_1","status":"pending"}')],
            $history,
        );

        $gw->dispatch('+213555000001');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/send-otp', (string) $request->getUri());
    }

    public function test_dispatch_throws_402_on_insufficient_balance(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/send-otp'),
                new Response(402, [], '{"error":"Insufficient balance. Please top up your account."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(402);
        $this->expectExceptionMessage('Insufficient balance. Please top up your account.');

        $gw->dispatch('+213555000001');
    }

    public function test_dispatch_throws_429_on_rate_limit(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/send-otp'),
                new Response(429, [], '{"error":"Daily OTP limit of 50 reached. Try again tomorrow."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(429);
        $this->expectExceptionMessage('Daily OTP limit of 50 reached. Try again tomorrow.');

        $gw->dispatch('+213555000001');
    }

    public function test_dispatch_throws_503_on_no_devices(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/send-otp'),
                new Response(503, [], '{"error":"No available devices."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('No available devices.');

        $gw->dispatch('+213555000001');
    }

    public function test_dispatch_throws_on_non_json_success_response(): void
    {
        $gw = $this->makeClient([new Response(200, [], 'not-json')]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionMessage('GateWire API returned non-JSON response');

        $gw->dispatch('+213555000001');
    }

    // -------------------------------------------------------------------------
    // verifyOtp()
    // -------------------------------------------------------------------------

    public function test_verify_otp_returns_status_and_message(): void
    {
        $payload = ['status' => 'verified', 'message' => 'Success'];
        $gw      = $this->makeClient([new Response(200, [], json_encode($payload))]);

        $result = $gw->verifyOtp('wg_01HX_TEST', '4827');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('Success', $result['message']);
    }

    public function test_verify_otp_sends_reference_id_and_code(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"status":"verified","message":"Success"}')],
            $history,
        );

        $gw->verifyOtp('wg_REF', '9999');

        $body = $this->requestBody($history);
        $this->assertSame('wg_REF', $body['reference_id']);
        $this->assertSame('9999', $body['code']);
    }

    public function test_verify_otp_uses_post_verify_otp_endpoint(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"status":"verified","message":"Success"}')],
            $history,
        );

        $gw->verifyOtp('wg_REF', '1234');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/verify-otp', (string) $request->getUri());
    }

    public function test_verify_otp_throws_400_on_invalid_code(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/verify-otp'),
                new Response(400, [], '{"error":"Invalid or expired code."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid or expired code.');

        $gw->verifyOtp('wg_REF', '0000');
    }

    public function test_verify_otp_throws_400_on_already_used(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/verify-otp'),
                new Response(400, [], '{"error":"This OTP has already been used."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('This OTP has already been used.');

        $gw->verifyOtp('wg_REF', '1234');
    }

    public function test_verify_otp_throws_400_on_expired(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Client error',
                new Request('POST', '/verify-otp'),
                new Response(400, [], '{"error":"This OTP has expired."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('This OTP has expired.');

        $gw->verifyOtp('wg_REF', '1234');
    }

    // -------------------------------------------------------------------------
    // status()
    // -------------------------------------------------------------------------

    public function test_status_returns_reference_id_status_and_created_at(): void
    {
        $payload = [
            'reference_id' => 'wg_01HX_TEST',
            'status'       => 'sent',
            'created_at'   => '2026-03-03T14:22:00Z',
        ];
        $gw = $this->makeClient([new Response(200, [], json_encode($payload))]);

        $result = $gw->status('wg_01HX_TEST');

        $this->assertSame('wg_01HX_TEST', $result['reference_id']);
        $this->assertSame('sent', $result['status']);
        $this->assertSame('2026-03-03T14:22:00Z', $result['created_at']);
    }

    public function test_status_uses_get_status_endpoint(): void
    {
        $history = [];
        $gw      = $this->makeClient(
            [new Response(200, [], '{"reference_id":"wg_1","status":"sent","created_at":"2026-03-03T00:00:00Z"}')],
            $history,
        );

        $gw->status('wg_01HX_TEST');

        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringEndsWith('/status/wg_01HX_TEST', (string) $request->getUri());
    }

    public function test_status_throws_on_error_response(): void
    {
        $gw = $this->makeClient([
            new RequestException(
                'Server error',
                new Request('GET', '/status/wg_BAD'),
                new Response(500, [], '{"error":"Unexpected error."}'),
            ),
        ]);

        $this->expectException(GateWireException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessage('Unexpected error.');

        $gw->status('wg_BAD');
    }
}
