<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\PreparationDiagnostic;

foreach ([502, 503, 504] as $status) {
    $detail = PreparationDiagnostic::normalize(['code' => 'HTTP_STATUS', 'phase' => 'queued', 'context' => ['http_status' => $status]]);
    check(str_contains($detail['description'], 'temporarily unavailable') && str_contains($detail['description'], (string) $status), 'Gateway failure lost actionable status');
}
$providerFailure = PreparationDiagnostic::fromException(new Arcturus\ModpackManager\Modpacks\ProviderHttpException(504));
check($providerFailure['code'] === 'HTTP_STATUS' && $providerFailure['context']['http_status'] === 504, 'Provider metadata error became an opaque Panel error');
$detail = PreparationDiagnostic::fromException(new RuntimeException('private?token=fixture-secret'));
check($detail['code'] === 'PANEL_ERROR' && ! str_contains(json_encode($detail), 'fixture-secret'), 'Unexpected exception leaked through public diagnostic');
echo "Preparation diagnostics distinguish gateway failures and keep private exception text out of UI\n";

$policy = PreparationDiagnostic::normalize(['code' => 'DOWNLOAD_POLICY', 'phase' => 'downloading', 'context' => ['reason' => 'REDIRECT_HOST_NOT_ALLOWED', 'host' => 'cdn.example.test', 'url' => 'https://cdn.example.test/file?secret=hidden']]);
check($policy['context'] === ['reason' => 'REDIRECT_HOST_NOT_ALLOWED', 'host' => 'cdn.example.test'] && str_contains($policy['description'], 'cdn.example.test') && ! str_contains(json_encode($policy), 'hidden'), 'Download diagnostic lost a safe hostname or leaked a URL');
