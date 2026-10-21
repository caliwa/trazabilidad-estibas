<?php

namespace App\Integrations\Sap\ServiceLayer;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Reads SAP's error from a Service Layer answer. Version 1 sends
 * {"error": {"code": -10, "message": {"lang": "en-us", "value": "..."}}};
 * version 2 sends the message as a plain string.
 */
final class ServiceLayerError
{
    public static function code(Response $response): string
    {
        $code = $response->json('error.code');

        return is_scalar($code) && (string) $code !== '' ? 'SAP_'.$code : 'HTTP_'.$response->status();
    }

    public static function message(Response $response): string
    {
        $message = $response->json('error.message.value') ?? $response->json('error.message');

        if (! is_string($message) || trim($message) === '') {
            $message = 'Respuesta HTTP '.$response->status().' sin mensaje de SAP.';
        }

        return Str::limit(trim($message), 500);
    }
}
