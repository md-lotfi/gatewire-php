<?php

namespace GateWire;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GateWire\Exceptions\GateWireException;

class GateWire
{
    protected HttpClient $http;
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://gatewire.raystate.com/api/v1')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');

        $this->http = new HttpClient([
            'base_uri' => $this->baseUrl . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'GateWire-PHP/1.0',
            ],
            'timeout' => 10,
        ]);
    }

    /**
     * Send an SMS or OTP.
     *
     * @param string $to Recipient phone number (e.g., +213555000000)
     * @param string|null $message The message body (optional if using template)
     * @param array $options Additional options (template_key, priority, etc.)
     * @return array The API response
     * @throws GateWireException
     */
    public function dispatch(string $to, ?string $message = null, array $options = []): array
    {
        $payload = array_merge([
            'phone' => $to,
            'message' => $message,
        ], $options);

        // Remove null values (e.g. if message is null because template_key is used)
        $payload = array_filter($payload, fn($value) => !is_null($value));

        return $this->request('POST', 'dispatch', $payload);
    }

    /**
     * Check the status of a specific message.
     *
     * @param string $referenceId The UUID received from dispatch()
     * @return array
     * @throws GateWireException
     */
    public function getStatus(string $referenceId): array
    {
        return $this->request('GET', "status/{$referenceId}");
    }

    /**
     * Get your current account balance.
     *
     * @return float Balance in DZD (or account currency)
     * @throws GateWireException
     */
    public function getBalance(): float
    {
        $response = $this->request('GET', 'balance');
        return (float) ($response['balance'] ?? 0.0);
    }

    /**
     * Internal request handler.
     */
    protected function request(string $method, string $uri, array $data = []): array
    {
        try {
            $options = [];
            if (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->http->request($method, $uri, $options);
            $body = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new GateWireException('Invalid JSON response from GateWire API.');
            }

            return $body;

        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            $code = $e->getCode();

            if ($e->hasResponse()) {
                $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                $message = $errorBody['message'] ?? $errorBody['error'] ?? $message;
            }

            throw new GateWireException($message, $code);
        }
    }
}