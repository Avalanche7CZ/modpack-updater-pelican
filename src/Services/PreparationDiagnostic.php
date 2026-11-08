<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** A fixed display vocabulary: exception messages and remote descriptions are never trusted. */
final class PreparationDiagnostic
{
    public const DEFINITIONS = [
        'SYMLINK_PATH' => ['A symbolic link was found in the selected managed scope.', 'Review the logical file ID and replace the link with reviewed regular content, or Keep local/Exclude its directory. Links are never followed.'],
        'FILE_TYPE' => ['A selected managed entry is not a regular file or directory.', 'Inspect the logical file ID for a socket, FIFO or changed directory. No partial inventory is accepted.'],
        'INVENTORY_FAILED' => ['Installation inventory failed with an unclassified filesystem validation error.', 'Inspect the retained comparison checkpoint and file ID. Retry installation comparison after correcting the entry; the verified upstream baseline is retained.'],
        'COMPARISON_FAILED' => ['Installation comparison did not complete.', 'Review the saved diagnostic and retry installation comparison. The verified upstream baseline is retained; no download is needed.'],
        'COMPARISON_STALE' => ['Server policy or baseline changed during comparison.', 'Retry installation comparison with the current directory decisions and protection rules.'],
        'WORLD_PATH_UNVERIFIED' => ['The custom world path could not be safely verified.', 'Resolve level-name in server.properties before retrying installation comparison. World protection cannot be bypassed.'],
        'WORKER_CONFIGURATION' => ['The preparation backend is missing configuration or filesystem access.', 'Open Local filesystem setup or test the configured remote worker; check permissions as the unprivileged queue account.'],
        'WORKER_HTTP' => ['The node worker rejected the request.', 'Check the matching server UUID/token and reverse proxy access rules. Never disable TLS verification.'],
        'CHECKSUM_MISSING' => ['An independently verified upstream checksum is missing.', 'Select a release with published checksums or an operator-reviewed verified recipe.'],
        'CHECKSUM_INVALID' => ['The declared upstream checksum is invalid.', 'Correct the reviewed release metadata; do not disable checksum verification.'],
        'CHECKSUM_MISMATCH' => ['Downloaded bytes do not match the upstream checksum.', 'Retain the partial stage and verify the exact upstream asset before an explicit retry.'],
        'NETWORK_DNS' => ['The download hostname could not be resolved.', 'Check DNS from the Panel/worker host.'],
        'NETWORK_TIMEOUT' => ['The upstream connection timed out.', 'Check outbound connectivity and upstream availability before retrying.'],
        'NETWORK_TLS' => ['The upstream HTTPS connection could not be verified.', 'Check certificates, system time and trust store. Keep TLS verification enabled.'],
        'NETWORK_IO' => ['The upstream connection was interrupted.', 'Check connectivity and retain the partial download before retrying.'],
        'HTTP_STATUS' => ['The download endpoint returned an HTTP error.', 'Up to three download attempts are made for 502/503/504 before writing an artifact. If they persist, check upstream availability; for other status codes check asset selection and permissions.'],
        'DOWNLOAD_POLICY' => ['The artifact or redirect violates the HTTPS/public-address/host policy.', 'Review the exact upstream CDN hosts in the operator configuration; do not allow private downloads.'],
        'DOWNLOAD_LIMIT' => ['The download exceeds its configured byte limit.', 'Review the expected release size and available staging space before adjusting operator limits.'],
        'TRANSFER_SIZE' => ['The transfer ended with a different byte count than declared.', 'Retain the partial download and check the exact upstream asset and connection.'],
        'ARCHIVE_INVALID' => ['The downloaded archive is corrupt or uses an unsupported format.', 'Verify the official server ZIP and retain the downloaded evidence.'],
        'ARCHIVE_UNSAFE' => ['An archive entry is unsafe, duplicated, encrypted or unsupported.', 'Inspect the numbered entry in the retained archive; use a reviewed normalized server pack.'],
        'ARCHIVE_LIMIT' => ['The archive exceeds file-count or expansion safety limits.', 'Review the archive and configured staging limits; do not bypass ZIP safety checks.'],
        'PERMISSION_DENIED' => ['A required file or directory could not be accessed.', 'The verified baseline is retained. Choose Keep local/Exclude for the affected directory, or ask the host operator to provision read access for this server. Retry installation comparison; the Panel cannot change POSIX ACLs.'],
        'DISK_FULL' => ['The staging filesystem has no available space or quota.', 'Free operator-reviewed staging space without deleting active evidence, or provision more capacity.'],
        'IO_ERROR' => ['A filesystem operation failed.', 'Check filesystem health, mount state, staging capacity and permissions.'],
        'MISSING_FILE' => ['A required file disappeared or was not present.', 'Inspect retained staging and release contents; do not substitute live files for upstream evidence.'],
        'MANAGED_PATH' => ['A file is outside the safe managed-pack scope.', 'Inspect the file/entry identifier in retained staging and use an explicitly reviewed normalized pack. World exclusions cannot be overridden.'],
        'FILES_CHANGED' => ['Managed files changed or disappeared during inventory.', 'Retry comparison when files are stable; no incomplete inventory is accepted.'],
        'INVENTORY_LIMIT' => ['The managed-file inventory exceeds a safety limit.', 'Review managed scope and configured file/byte limits.'],
        'METADATA_INVALID' => ['The release or operation metadata is invalid or incomplete.', 'Review the exact release recipe and retained operation state.'],
        'PREPARATION_FAILED' => ['Preparation failed without a recognized safe diagnostic.', 'Inspect retained evidence using the operation IDs. No automatic replay is performed.'],
        'CANCELLED' => ['Cancellation was confirmed; partial staging is retained.', 'Start a new operation only after reviewing the retained stage.'],
        'INTERRUPTED' => ['The preparation process stopped before completion; staging is retained.', 'Inspect process and operation state before an explicit new attempt.'],
        'LOCAL_TIMEOUT' => ['The local preparation subprocess exceeded its deadline.', 'Retain staging and inspect the operation before starting a new attempt.'],
        'LOCAL_INTERRUPTED' => ['The local helper stopped before returning a complete response.', 'Check Python/process availability and queue logs. Inspect retained stage state before retrying.'],
        'PANEL_ERROR' => ['Panel preparation or comparison failed.', 'Inspect sanitized operation evidence and Panel logs using the operation ID.'],
        'RELEASE_UNVERIFIED' => ['The selected release lacks complete independently verified preparation metadata.', 'Select the exact official asset with published checksums or a reviewed verified recipe.'],
        'REMOTE_UNAVAILABLE' => ['The node request was interrupted or rejected.', 'Check the configured worker service, credentials, HTTPS and connectivity; inspect saved state before retrying.'],
    ];
    public const PHASES = ['queued', 'downloading', 'verifying', 'extracting', 'hashing', 'comparing', 'awaiting_resolution'];

    public static function fromException(\Throwable $error, string $phase = 'queued'): array
    {
        if ($error instanceof PreparationException) { return $error->diagnostic; }
        if ($error instanceof \Arcturus\ModpackManager\Modpacks\ProviderHttpException) {
            return self::normalize(['code' => 'HTTP_STATUS', 'phase' => $phase, 'context' => ['http_status' => $error->status]]);
        }
        if ($error instanceof \Illuminate\Http\Client\RequestException) {
            return self::normalize(['code' => 'HTTP_STATUS', 'phase' => $phase, 'context' => ['http_status' => $error->response->status()]]);
        }
        return self::normalize(['code' => $error instanceof \Arcturus\ModpackManager\Remote\CapabilityUnavailable ? 'WORKER_CONFIGURATION' :
            ($error instanceof \Illuminate\Http\Client\ConnectionException ? 'NETWORK_IO' : 'PANEL_ERROR'), 'phase' => $phase]);
    }

    public static function normalize(mixed $value): array
    {
        if ($managed = ManagedPathDiagnostic::normalize($value)) {
            return $managed;
        }
        $code = is_array($value) && is_string($value['code'] ?? null) && isset(self::DEFINITIONS[$value['code']]) ? $value['code'] : 'PREPARATION_FAILED';
        $phase = is_array($value) && in_array($value['phase'] ?? null, self::PHASES, true) ? $value['phase'] : 'queued';
        [$description, $action] = self::DEFINITIONS[$code];
        $context = [];
        foreach (['artifact_index', 'entry_index', 'file_index', 'http_status', 'errno'] as $key) {
            $number = $value['context'][$key] ?? null;
            if (is_int($number) && $number >= 0 && $number <= 1000000) {
                $context[$key] = $number;
            }
        }
        $file = $value['context']['file'] ?? null;
        if (is_string($file) && strlen($file) <= 240 && preg_match('~^[A-Za-z0-9][A-Za-z0-9_. /+-]*$~D', $file) &&
            ! preg_match('~(?:^|/)(?:\.{1,2}|)(?:/|$)|[A-Za-z0-9_-]{40,}|token|secret|password|credential|bearer~i', $file)) {
            $context['file'] = $file;
        }
        $id = $value['context']['file_id'] ?? null;
        if (is_string($id) && preg_match('/^[a-f0-9]{12}$/D', $id)) {
            $context['file_id'] = $id;
        }
        if ($code === 'HTTP_STATUS' && in_array($context['http_status'] ?? null, [502, 503, 504], true)) {
            $description = 'The upstream service is temporarily unavailable (HTTP '.$context['http_status'].').';
            $action = 'Try preparation again later. If the problem persists, check the provider status and connectivity from the Panel and worker host.';
        }
        return ['schema' => 1, 'code' => $code, 'phase' => $phase, 'description' => $description, 'action' => $action, 'context' => $context];
    }

    public static function message(array $detail): string
    {
        $detail = self::normalize($detail);
        $context = $detail['context'] ?? [];
        $labels = [];
        foreach (['http_status' => 'HTTP ', 'artifact_index' => 'artifact #', 'entry_index' => 'archive entry #', 'errno' => 'OS error #'] as $key => $label) {
            if (isset($context[$key])) { $labels[] = $label.$context[$key]; }
        }
        if (isset($context['file'])) { $labels[] = $context['file']; }
        elseif (isset($context['file_id'])) { $labels[] = 'file ID '.$context['file_id']; }
        return '['.$detail['code'].'] '.$detail['description'].($labels ? ' ('.implode(' · ', $labels).')' : '').' '.$detail['action'];
    }
}
