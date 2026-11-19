<?php

namespace App\Support\Analytics;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mengirim event outbox ke Analytics Platform secara berkelompok.
 *
 * Kontrak HTTP: POST {endpoint} dengan JSON {source, sent_at, events: [{id, type, occurred_at,
 * business_id, outlet_id, data}]}. Header `Authorization: Bearer {token}` (bila diatur) dan
 * `X-Pabalu-Signature: sha256={HMAC body}` (bila signing secret diatur). Respons 2xx = seluruh
 * batch diterima; selain itu batch dicoba ulang dengan jeda bertahap (2, 4, 8 … maks. 60 menit).
 */
class OutboxDispatcher
{
    /** @return array{sent: int, failed: int, error: ?string} */
    public function dispatch(?int $limit = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'error' => null];

        if (! Outbox::enabled()) {
            return $result;
        }

        $events = OutboxEvent::due()->orderBy('id')->limit($limit ?? max(1, (int) config('services.analytics.batch_size')))->get();

        if ($events->isEmpty()) {
            return $result;
        }

        $error = $this->send($events->map->toEnvelope()->all());

        if ($error === null) {
            OutboxEvent::whereIn('id', $events->modelKeys())->update(['dispatched_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
            $result['sent'] = $events->count();

            return $result;
        }

        foreach ($events as $event) {
            $attempts = $event->attempts + 1;
            $event->forceFill([
                'attempts' => $attempts,
                'next_attempt_at' => now()->addMinutes(min(60, 2 ** min($attempts, 6))),
                'last_error' => mb_substr($error, 0, 255),
            ])->save();
        }

        return ['sent' => 0, 'failed' => $events->count(), 'error' => $error];
    }

    /**
     * @param  list<array<string, mixed>>  $envelopes
     * @return string|null pesan galat, null bila berhasil
     */
    private function send(array $envelopes): ?string
    {
        $body = json_encode([
            'source' => 'pabalu-fnb',
            'sent_at' => now()->toIso8601String(),
            'events' => $envelopes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['Accept' => 'application/json'];

        if (filled($secret = config('services.analytics.signing_secret'))) {
            $headers['X-Pabalu-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        try {
            $request = Http::timeout(max(1, (int) config('services.analytics.timeout')))->withHeaders($headers);

            if (filled($token = config('services.analytics.token'))) {
                $request = $request->withToken($token);
            }

            $response = $request->withBody($body, 'application/json')->post(config('services.analytics.endpoint'));

            return $response->successful() ? null : 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 200);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
