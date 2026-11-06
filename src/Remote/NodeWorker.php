<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use Illuminate\Support\Facades\Http;
use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Services\PreparationException;

/** Requests are scoped by a per-server secret in operator-owned config. No shell operations. */
final class NodeWorker implements IntegrityWorker
{
    public function __construct(private readonly string $uuid, private readonly ?array $scope = null) {}

    public function request(string $operation, array $payload = []): array
    {
        if (! in_array($operation, ['capabilities', 'hashes', 'inventory', 'prepare', 'status', 'cancel', 'manifest', 'stage_file', 'requirements', 'resolve'], true)) {
            throw new CapabilityUnavailable('Unsupported node capability');
        }
        if ((! empty($this->scope['manage']) || ! empty($this->scope['exclude'])) && in_array($operation, ['inventory', 'hashes', 'prepare'], true) && ($this->request('capabilities')['directory_policy'] ?? null) !== 1) {
            throw new CapabilityUnavailable('Upgrade the remote worker for per-server directory discovery and policy support');
        }
        if ($this->scope !== null) { $payload['directory_scope'] = $this->scope; }
        $entry = config('arcturus-modpack-manager.nodes.'.$this->uuid);
        if (! is_array($entry) || empty($entry['url']) || strlen($entry['token'] ?? '') < 32) {
            throw new CapabilityUnavailable('Configure a server-scoped Arcturus node worker for remote SHA-256 inventory');
        }
        $p = parse_url($entry['url']);
        if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) ||
            isset($p['query']) || isset($p['fragment'])) {
            throw new CapabilityUnavailable('Node worker endpoint must use authenticated HTTPS');
        }
        $url = rtrim($entry['url'], '/').'/v1/servers/'.rawurlencode($this->uuid).'/'.$operation;
        // Never retry mutations: a lost response does not prove a failed request.
        try {
            $r = Http::withToken($entry['token'])->acceptJson()->withoutRedirecting()
                ->connectTimeout(5)->timeout(120)->withOptions(['stream' => true])->post($url, $payload);
            if (! $r->successful() && $r->status() !== 422) {
                throw new PreparationException(['code' => 'WORKER_HTTP', 'context' => ['http_status' => $r->status()]]);
            }
            $stream = $r->toPsrResponse()->getBody();
            try {
                $body = '';
                while (! $stream->eof() && strlen($body) <= 33554432) {
                    $body .= $stream->read(65536);
                }
                if (strlen($body) > 33554432) {
                    throw new CapabilityUnavailable('Node metadata exceeds the 32 MiB response limit; no partial result accepted');
                }
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data)) {
                    throw new CapabilityUnavailable('Node returned invalid metadata');
                }
                if (! $r->successful()) { throw new PreparationException($data['diagnostic'] ?? ['code' => 'WORKER_HTTP', 'context' => ['http_status' => $r->status()]]); }

                return $data;
            } finally {
                $stream->close();
            }
        } catch (PreparationException|CapabilityUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Laravel HTTP exception messages can contain credentials or signed URLs.
            throw new PreparationException(['code' => 'REMOTE_UNAVAILABLE']);
        }
    }
}
