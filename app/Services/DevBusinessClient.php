<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes funnel milestones into the Dev-Business hub (clients/projects/services/payments)
 * via its token-authenticated integration API. Best-effort: never throws.
 */
class DevBusinessClient
{
    public function isEnabled(): bool
    {
        return (bool) config('overcloud.devbusiness.enabled')
            && filled(config('overcloud.devbusiness.url'))
            && filled(config('overcloud.devbusiness.token'));
    }

    public function upsertClient(array $payload): ?array
    {
        return $this->post('/api/integration/clients/upsert', $payload);
    }

    public function upsertProject(array $payload): ?array
    {
        return $this->post('/api/integration/projects/upsert', $payload);
    }

    public function upsertService(array $payload): ?array
    {
        return $this->post('/api/integration/services/upsert', $payload);
    }

    public function createPayment(array $payload): ?array
    {
        return $this->post('/api/integration/payments', $payload);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('overcloud.devbusiness.url'), '/'))
            ->withToken(config('overcloud.devbusiness.token'))
            ->acceptJson()
            ->timeout(20);
    }

    private function post(string $path, array $payload): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = $this->client()->post($path, array_filter($payload, fn ($v) => $v !== null));

            if ($response->failed()) {
                Log::warning('Dev-Business sync failed', [
                    'path' => $path, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Dev-Business sync errored', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
