# GateWire PHP SDK

[![Latest Stable Version](https://poser.pugx.org/gatewire/client/v/stable)](https://packagist.org/packages/gatewire/client)
[![License](https://poser.pugx.org/gatewire/client/license)](https://packagist.org/packages/gatewire/client)

The official PHP library for the **[GateWire SMS Infrastructure](https://gatewire.raystate.com)**.

GateWire is a decentralized mesh network that lets developers send OTPs to North African carriers
(**Mobilis**, **Djezzy**, **Ooredoo**) reliably and at a fraction of the cost of international gateways.

---

## Requirements

- PHP **8.1** or higher
- [Composer](https://getcomposer.org/)
- `guzzlehttp/guzzle` — installed automatically by Composer

---

## Installation

```bash
composer require gatewire/client
```

---

## Quick Start

The typical OTP flow uses three methods in sequence.

### 1. Initialize the client

Obtain your API token from the [GateWire Dashboard](https://gatewire.raystate.com).

```php
require 'vendor/autoload.php';

use GateWire\GateWire;

$gw = new GateWire('YOUR_API_TOKEN');
```

### 2. Send an OTP

```php
use GateWire\Exceptions\GateWireException;

try {
    $res = $gw->dispatch('+213555123456', 'login_otp');
    // $res['reference_id'] => "wg_01HX..."
    // $res['status']       => "pending"

    $referenceId = $res['reference_id'];
} catch (GateWireException $e) {
    echo 'Error ' . $e->getCode() . ': ' . $e->getMessage();
}
```

The second argument (`template_key`) is optional. When omitted, the backend uses your account's
default OTP template. The message content is always controlled server-side — there is no message
body parameter.

### 3. Verify the OTP

Once the user submits the code they received, verify it:

```php
try {
    $result = $gw->verifyOtp($referenceId, $userEnteredCode);
    // $result['status']  => "verified"
    // $result['message'] => "Success"
} catch (GateWireException $e) {
    // code 400: invalid, expired, cancelled, or already used
    echo 'Verification failed: ' . $e->getMessage();
}
```

### 4. Check OTP status

Poll or webhook-verify the delivery status at any point:

```php
$info = $gw->status($referenceId);
// $info['reference_id'] => "wg_01HX..."
// $info['status']       => "sent"
// $info['created_at']   => "2026-03-03T14:22:00Z"
```

---

## OTP Lifecycle

```
pending → dispatched → sent → verified          (happy path)
                            → failed            (delivery failed)
                            → expired           (code TTL exceeded)
                            → cancelled         (manually cancelled)
```

| Status       | Meaning                                              |
|--------------|------------------------------------------------------|
| `pending`    | OTP queued, awaiting a device to pick it up          |
| `dispatched` | Assigned to a device, being sent                     |
| `sent`       | Delivered to the carrier                             |
| `verified`   | User entered the correct code                        |
| `failed`     | Delivery failed (device error, carrier rejection)    |
| `expired`    | Code TTL exceeded before verification                |
| `cancelled`  | OTP was cancelled before delivery                    |

---

## Laravel Integration

### Register a singleton in `app/Providers/AppServiceProvider.php`

```php
use GateWire\GateWire;

public function register(): void
{
    $this->app->singleton(GateWire::class, function () {
        return new GateWire(config('services.gatewire.key'));
    });
}
```

### Add credentials to `config/services.php`

```php
'gatewire' => [
    'key' => env('GATEWIRE_API_KEY'),
],
```

### Add the key to `.env`

```
GATEWIRE_API_KEY=your_api_token_here
```

### Use via dependency injection

```php
use GateWire\GateWire;
use GateWire\Exceptions\GateWireException;

class OtpController extends Controller
{
    public function send(Request $request, GateWire $gw): JsonResponse
    {
        try {
            $res = $gw->dispatch($request->input('phone'), 'login_otp');
            return response()->json(['reference_id' => $res['reference_id']]);
        } catch (GateWireException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function verify(Request $request, GateWire $gw): JsonResponse
    {
        try {
            $gw->verifyOtp($request->input('reference_id'), $request->input('code'));
            return response()->json(['verified' => true]);
        } catch (GateWireException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
```

---

## Error Handling

All API errors throw `GateWire\Exceptions\GateWireException`. The exception code matches the HTTP
status code returned by the server.

```php
use GateWire\Exceptions\GateWireException;

try {
    $res = $gw->dispatch('+213555123456', 'login_otp');
} catch (GateWireException $e) {
    match ($e->getCode()) {
        400 => /* Invalid or expired OTP code (during verifyOtp) */,
        402 => /* Insufficient balance — top up your account */,
        429 => /* Daily or hourly OTP limit reached */,
        503 => /* No available devices in the network right now */,
        default => /* Unexpected server error */,
    };
}
```

| Code | Meaning                                                          |
|------|------------------------------------------------------------------|
| 400  | Invalid, expired, cancelled, or already-used OTP code           |
| 402  | Insufficient balance                                             |
| 429  | Daily OTP limit or hourly bonus credit limit reached             |
| 503  | No available devices to dispatch the OTP                         |
| 500  | Unexpected server-side error                                     |

---

## API Reference

Base URL: `https://gatewire.raystate.com/api/v1`

| Method                                  | HTTP call                          | Description          |
|-----------------------------------------|------------------------------------|----------------------|
| `dispatch(phone, templateKey?)`         | `POST /send-otp`                   | Send an OTP          |
| `verifyOtp(referenceId, code)`          | `POST /verify-otp`                 | Verify an OTP code   |
| `status(referenceId)`                   | `GET  /status/{reference_id}`      | Check OTP status     |

---

## Testing

The SDK ships with a PHPUnit 10 test suite covering all public methods, request shapes, and error
paths. No network calls are made — responses are mocked with Guzzle's `MockHandler`.

Install dev dependencies and run the suite:

```bash
composer install
composer test
# or directly:
./vendor/bin/phpunit
```

Expected output:

```
PHPUnit 10.5.x by Sebastian Bergmann and contributors.

......................                                            22 / 22 (100%)

OK (22 tests, 45 assertions)
```

**Coverage:**

| Test file | What is tested |
|---|---|
| `tests/GateWireTest.php` | `dispatch()`, `verifyOtp()`, `status()` — success responses, request body shape, correct HTTP method + endpoint path, all documented error codes (400, 402, 429, 503, 500), non-JSON response guard |
| `tests/GateWireExceptionTest.php` | `GateWireException::fromResponse()` — message, code, instance type |

---

## Support

- **Issues:** [GitHub Issues](https://github.com/md-lotfi/gatewire-php/issues)
- **Email:** dev@raystate.com

## License

The GateWire PHP SDK is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
