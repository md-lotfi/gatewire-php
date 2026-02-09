# GateWire PHP SDK

[![Latest Stable Version](https://poser.pugx.org/gatewire/client/v/stable)](https://packagist.org/packages/gatewire/client)
[![License](https://poser.pugx.org/gatewire/client/license)](https://packagist.org/packages/gatewire/client)

The official PHP library for the **[GateWire SMS Infrastructure](https://gatewire.raystate.com)**.

GateWire is a decentralized mesh network that allows developers to send OTPs, notifications, and alerts to North African carriers (**Mobilis**, **Djezzy**, **Ooredoo**) reliably and at a fraction of the cost of international gateways.

## Features

* 🚀 **Decentralized Routing:** Bypasses international aggregators for lower latency.
* 💰 **Local Pricing:** Pay in **DZD** (Algerian Dinar) without Forex fees.
* 🔒 **Secure:** All traffic is encrypted via TLS 1.3.
* ⚡ **Async Support:** Fire-and-forget dispatch with webhook delivery reports.

## Requirements

* PHP 8.1 or higher
* [Composer](https://getcomposer.org/)
* `guzzlehttp/guzzle` (Installed automatically)

---

## Installation

Install the package via Composer:

```bash
composer require gatewire/clientQuick Start
```
---

### 1. Initialize the Client
First, obtain your API Key from the GateWire Dashboard.

```php
require 'vendor/autoload.php';

use GateWire\GateWire;

// Initialize with your API Key
$gatewire = new GateWire('sk_live_YOUR_API_KEY_HERE');
```

### 2. Send an SMS (Standard Route)
Send a simple text message to any Algerian number (+213).

```php
try {
    $response = $gatewire->dispatch(
        to: '+213555123456', 
        message: 'Your verification code is 849201'
    );

    echo "Success! Reference ID: " . $response['reference_id'];

} catch (\GateWire\Exceptions\GateWireException $e) {
    echo "Error: " . $e->getMessage();
}
```

### 3. Send via Template (Priority Route)
For OTPs and 2FA, we highly recommend using Templates. This ensures your message skips the standard queue and uses the High Priority lane.

```php
$response = $gatewire->dispatch(
    to: '+213555123456',
    options: [
        'template_key' => 'login_otp', // Defined in your dashboard
        'priority'     => 'high'       // Force high priority routing
    ]
);
```
### 4. Check Account Balance
Retrieve your current remaining credit balance in DZD.

```php
$balance = $gatewire->getBalance();
echo "Current Balance: " . $balance . " DZD";
```
## Laravel Integration
If you are using Laravel, you can register the client as a singleton in your AppServiceProvider.

```php
app/Providers/AppServiceProvider.php
use GateWire\GateWire;

public function register()
{
    $this->app->singleton(GateWire::class, function ($app) {
        return new GateWire(config('services.gatewire.key'));
    });
}
config/services.php
```

```php
'gatewire' => [
    'key' => env('GATEWIRE_API_KEY'),
],
```

## Usage in Controller:

```php
use GateWire\GateWire;

public function sendOtp(Request $request, GateWire $gatewire)
{
    $gatewire->dispatch($request->phone, 'Your code is 123456');
    return response()->json(['status' => 'sent']);
}
```

## Error Handling
The SDK throws GateWire\Exceptions\GateWireException for any API error (4xx or 5xx). You should always wrap your calls in a try-catch block.

```php
use GateWire\Exceptions\GateWireException;

try {
    $gatewire->dispatch('+213000000000', 'Hello');
} catch (GateWireException $e) {
    // Handle specific errors
    if ($e->getCode() === 402) {
        // "Insufficient Balance"
    }
    if ($e->getCode() === 503) {
        // "No active devices available in the network"
    }
}
```

## Support & Community
### Documentation: Read the full docs

### Issues: Report a bug

Email: dev@raystate.com

## License
The GateWire PHP SDK is open-sourced software licensed under the MIT license.