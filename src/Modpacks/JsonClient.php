<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks;

use Arcturus\ModpackManager\Security\DownloadPolicy;
use Arcturus\ModpackManager\Security\LimitedStream;
use Arcturus\ModpackManager\Security\PublicAddress;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JsonClient
{
    public function get(string $url, array $headers = []): array
    {
        (new DownloadPolicy)->validate($url, array_merge(DownloadPolicy::HOSTS,
            config('arcturus-modpack-manager.allowed_custom_hosts', [])));
        $host = parse_url($url, PHP_URL_HOST);
        $address = PublicAddress::resolve($host);
        $pinned = str_contains($address, ':') ? '['.$address.']' : $address;
        $sink = new LimitedStream(Utils::streamFor(fopen('php://temp/maxmemory:2097152', 'w+b')), 16777216);
        try {
            $response = Http::acceptJson()->setHandler(new CurlHandler)->withHeaders($headers + [
                'User-Agent' => config('arcturus-modpack-manager.user_agent')])
                ->withOptions(['sink' => $sink, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]]])->withoutRedirecting()->connectTimeout(5)->timeout(30)->get($url);
            if (! $response->successful()) {
                throw new RuntimeException('Provider HTTP '.$response->status().
                    ($response->status() === 429 ? '; rate limited, retry after '.$response->header('Retry-After') : ''));
            }
            $stream = $response->toPsrResponse()->getBody();
            $stream->rewind();
            try {
                $body = '';
                while (! $stream->eof() && strlen($body) <= 16777216) {
                    $body .= $stream->read(65536);
                }
                if (strlen($body) > 16777216) {
                    throw new RuntimeException('Provider metadata exceeds 16 MiB');
                }
            } finally {
                $stream->close();
            }
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new RuntimeException('Provider metadata is not an object or list');
            }

            return $data;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Provider request failed; check credentials, connectivity and JSON format');
        } finally {
            $sink->close();
        }
    }
}
