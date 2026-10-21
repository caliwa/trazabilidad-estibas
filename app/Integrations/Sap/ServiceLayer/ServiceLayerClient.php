<?php

namespace App\Integrations\Sap\ServiceLayer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * HTTP session with SAP Business One Service Layer.
 *
 * Logs in on first use (POST /Login) and keeps the session cookies for
 * this process; when SAP answers 401 (session expired) it logs in again
 * once and repeats the call. A write is never repeated after it reached
 * SAP: only the 401 path retries, and SAP did not process a 401.
 *
 * Credentials and session cookies are never logged or returned.
 */
class ServiceLayerClient
{
    /** @var array<string, string>|null */
    private ?array $cookies = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $companyDb,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 30,
    ) {
        if ($baseUrl === '' || $companyDb === '' || $username === '' || $password === '') {
            throw new LogicException('Faltan datos de conexión a SAP Service Layer (SAP_API_URL, SAP_COMPANY, SAP_USER o SAP_PASSWORD del ambiente).');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     * @throws ServiceLayerLoginFailed
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->url($path), $query));
    }

    /**
     * A full URL or a path relative to the Service Layer root, as SAP sends in nextLink.
     *
     * @throws ConnectionException
     * @throws ServiceLayerLoginFailed
     */
    public function getLink(string $link): Response
    {
        $url = str_starts_with($link, 'http') ? $link : $this->url($link);

        return $this->send(fn (PendingRequest $http) => $http->get($url));
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ConnectionException
     * @throws ServiceLayerLoginFailed
     */
    public function post(string $path, array $body): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->post($this->url($path), $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws ConnectionException
     * @throws ServiceLayerLoginFailed
     */
    private function send(callable $call): Response
    {
        $this->cookies ??= $this->login();
        $response = $call($this->http()->withHeaders(['Cookie' => $this->cookieHeader()]));

        if ($response->status() === 401) {
            $this->cookies = $this->login();
            $response = $call($this->http()->withHeaders(['Cookie' => $this->cookieHeader()]));
        }

        return $response;
    }

    /**
     * @return array<string, string>
     *
     * @throws ConnectionException
     * @throws ServiceLayerLoginFailed
     */
    private function login(): array
    {
        $response = $this->http()->post($this->url('Login'), [
            'CompanyDB' => $this->companyDb,
            'UserName' => $this->username,
            'Password' => $this->password,
        ]);

        if (! $response->successful()) {
            throw new ServiceLayerLoginFailed('SAP rechazó el inicio de sesión en Service Layer ('.$response->status().'): '.ServiceLayerError::message($response));
        }

        $cookies = [];
        foreach ($response->cookies()->toArray() as $cookie) {
            $cookies[(string) $cookie['Name']] = (string) $cookie['Value'];
        }

        // Some installations only return the session id in the body.
        $session = $response->json('SessionId');
        if (! isset($cookies['B1SESSION']) && is_string($session) && $session !== '') {
            $cookies['B1SESSION'] = $session;
        }

        if (! isset($cookies['B1SESSION'])) {
            throw new ServiceLayerLoginFailed('Service Layer no devolvió la sesión (B1SESSION) al iniciar sesión.');
        }

        return $cookies;
    }

    private function cookieHeader(): string
    {
        return implode('; ', array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($this->cookies ?? []), $this->cookies ?? []));
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(10, $this->timeoutSeconds))
            ->withOptions(['verify' => $this->verifyTls]);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
