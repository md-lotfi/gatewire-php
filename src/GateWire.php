<?php

declare(strict_types=1);

namespace GateWire;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GateWire\Exceptions\GateWireException;

class GateWire
{
    protected HttpClient $http;
    protected string $baseUrl;

    public function __construct(
        protected readonly string $apiKey,
        string $baseUrl = 'https://gatewire.raystate.com/api/v1',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        $this->http = new HttpClient([
            'base_uri' => $this->baseUrl . '/',
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'GateWire-PHP/1.1',
            ],
            'timeout' => 10,
        ]);
    }

    /**
     * Send an OTP to the given phone number.
     *
     * Calls POST /send-otp. The OTP message content is controlled server-side
     * through the template identified by $templateKey. If no template key is
     * provided the backend uses the account's default template.
     *
     * @param  string      $phone       Recipient phone number (e.g. +213555123456).
     * @param  string|null $templateKey Optional OTP message template key defined
     *                                  in your GateWire dashboard.
     * @return array{reference_id: string, status: string}
     *
     * @throws GateWireException 402 — Insufficient balance.
     * @throws GateWireException 429 — Daily / hourly OTP limit reached.
     * @throws GateWireException 503 — No available devices.
     * @throws GateWireException 500 — Unexpected server error.
     */
    public function dispatch(string $phone, ?string $templateKey = null): array
    {
        $payload = ['phone' => $phone];

        if ($templateKey !== null) {
            $payload['template_key'] = $templateKey;
        }

        return $this->request('POST', 'send-otp', $payload);
    }

    /**
     * Verify an OTP code against a previously issued reference.
     *
     * Calls POST /verify-otp.
     *
     * @param  string $referenceId The reference_id returned by dispatch().
     * @param  string $code        The code entered by the end-user.
     * @return array{status: string, message: string}
     *
     * @throws GateWireException 400 — Invalid, expired, cancelled, or already-used code.
     * @throws GateWireException 500 — Unexpected server error.
     */
    public function verifyOtp(string $referenceId, string $code): array
    {
        return $this->request('POST', 'verify-otp', [
            'reference_id' => $referenceId,
            'code'         => $code,
        ]);
    }

    /**
     * Check the delivery status of an OTP by its reference ID.
     *
     * Calls GET /status/{reference_id}.
     *
     * Possible status values:
     *   pending | dispatched | sent | verified | failed | expired | cancelled
     *
     * @param  string $referenceId The reference_id returned by dispatch().
     * @return array{reference_id: string, status: string, created_at: string}
     *
     * @throws GateWireException 500 — Unexpected server error.
     */
    public function status(string $referenceId): array
    {
        return $this->request('GET', "status/{$referenceId}");
    }

    /**
     * Execute an HTTP request and return the decoded JSON body.
     *
     * On any 4xx / 5xx response the "error" key from the JSON body is used as
     * the exception message. Falls back to the raw Guzzle message when the body
     * is absent or not valid JSON.
     *
     * @throws GateWireException On any API or network error.
     */
    protected function request(string $method, string $uri, array $data = []): array
    {
        try {
            $options = [];
            if ($data !== []) {
                $options['json'] = $data;
            }

            $response = $this->http->request($method, $uri, $options);
            $raw      = $response->getBody()->getContents();
            $body     = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new GateWireException(
                    'GateWire API returned non-JSON response: ' . json_last_error_msg(),
                );
            }

            return $body;

        } catch (GateWireException $e) {
            throw $e;

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $message    = $e->getMessage();

            if ($e->hasResponse()) {
                $raw       = $e->getResponse()->getBody()->getContents();
                $errorBody = json_decode($raw, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($errorBody['error'])) {
                    $message = $errorBody['error'];
                }
            }

            throw GateWireException::fromResponse($statusCode, $message);

        } catch (GuzzleException $e) {
            throw GateWireException::fromResponse($e->getCode(), $e->getMessage());
        }
    }
}
