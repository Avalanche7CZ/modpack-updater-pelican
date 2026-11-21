<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Models\{ManagedServer, Operation};
use Arcturus\ModpackManager\Services\{SameHostDeployment, UpdatePlanner};
use RuntimeException;

/** Only reviewed, byte-exact local text config differences may supplement a deployed receipt. */
final class DeploymentConfigChanges
{
    public const TEXT_LIMIT = 1048576;

    public function scope(Operation $op): array
    {
        $context = $op->context;
        $expected = $context['native_after'] ?? null;
        if (! is_array($expected)) { throw new RuntimeException('Missing native_after deployed-file receipt.'); }
        if (! array_key_exists('server.properties', $expected) && isset($context['native_world_properties_sha256'])) { $expected['server.properties'] = $context['native_world_properties_sha256']; }
        foreach ($context['native_target_runtime_plan']['dependency_hashes'] ?? [] as $path => $sha) {
            if (array_key_exists($path, $expected) && $expected[$path] !== $sha) { throw new RuntimeException('Conflicting approved deployed/runtime file digest: '.$path); }
            $expected[$path] = $sha;
        }
        ksort($expected); return $expected;
    }

    private function restriction(array $context, array $target, string $path, ?string $expected, ?string $actual, ?string $error, bool $configurationSet = false): ?string
    {
        if ($error !== null) { return $error; }
        if ($expected === null) { return 'Expected removal: the path must remain absent.'; }
        if ($actual === null) { return 'Expected deployed file is missing.'; }
        if (array_key_exists($path, $context['native_target_runtime_plan']['dependency_hashes'] ?? [])) { return 'Runtime dependency: strict hash required.'; }
        if (array_key_exists($path, $context['native_target_runtime_plan']['retained_launcher_files'] ?? [])) { return 'Retained runtime launcher/argument: strict hash required.'; }
        if ($configurationSet && $path === 'server.properties') { return null; }
        $recipeConfig = $configurationSet && in_array($path, ['config/opencomputers/default.recipes', 'config/opencomputers/gregtech.recipes'], true);
        if (! str_starts_with($path, 'config/') || (! $recipeConfig && ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['cfg', 'conf', 'properties', 'json', 'json5', 'toml', 'yaml', 'yml', 'ini', 'txt', 'xml', 'hocon', 'snbt'], true))) {
            return 'Only text configuration files under config/ may be accepted; JARs and other deployed files remain strict.';
        }
        if (($target['files'][$path] ?? null) !== $expected) { return 'The deployed digest is not the authenticated target manifest digest.'; }
        return null;
    }

    private function accepted(string $operationId, array $acceptances, string $targetSha, string $path, ?string $expected, ?string $actual, string $scopeSha): ?array
    {
        $receipt = $acceptances[$path] ?? null;
        if (! is_array($receipt) || ($receipt['operation_id'] ?? null) !== $operationId || ($receipt['target_manifest_sha256'] ?? null) !== $targetSha
            || ($receipt['path'] ?? null) !== $path || ($receipt['scope_sha256'] ?? null) !== $scopeSha || ($receipt['expected_sha256'] ?? null) !== $expected || ($receipt['actual_sha256'] ?? null) !== $actual || empty($receipt['review_event_id']) || empty($receipt['actor_id'])) { return null; }
        return $receipt;
    }

    public function report(Operation $op, array $target, string $targetSha, Wings $files, bool $configurationSet = false): array
    {
        $context = $op->context;
        $acceptances = $context['native_admin_config_acceptances'] ?? [];
        $expected = $this->scope($op);
        if ($configurationSet) {
            // Include locally retained target configs too; never replace a deployed removal receipt.
            foreach ($target['files'] as $path => $sha) { if (str_starts_with($path, 'config/') && ! array_key_exists($path, $expected)) { $expected[$path] = $sha; } }
            ksort($expected);
        }
        $scopeSha = hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)); $differences = []; $accepted = [];
        foreach (array_chunk($expected, 32, true) as $batch) {
            $errors = []; $textErrors = []; $readIndividually = false;
            $inspect = $configurationSet ? function (string $path, string $actual, int $size, $stream) use ($context, $target, $batch, &$textErrors): void {
                if ($actual === $batch[$path] || $this->restriction($context, $target, $path, $batch[$path], $actual, null, true) !== null) { return; }
                if ($size > self::TEXT_LIMIT || ! $stream->isSeekable()) {
                    $textErrors[$path] = 'Required configuration is too large or could not be safely read.';
                    return;
                }
                $stream->rewind();
                $text = $stream->read($size + 1);
                if (strlen($text) !== $size || ! $this->validText($text)) {
                    $textErrors[$path] = 'Only complete UTF-8 text configurations can be accepted.';
                }
            } : null;
            try { $hashes = $files->hashBatch(array_keys($batch), $inspect); }
            catch (CheckpointChanged $error) { throw $error; }
            catch (\Throwable) {
                $readIndividually = true;
                $textErrors = [];
                // GET-only recovery of a failed read batch; preserve every unreadable/symlink path as a blocker.
                $hashes = [];
                foreach ($batch as $path => $_) {
                    try { $hashes[$path] = $files->hash($path); }
                    catch (CheckpointChanged $error) { throw $error; }
                    catch (\Throwable $error) { $hashes[$path] = null; $errors[$path] = $error instanceof RuntimeException ? $error->getMessage() : 'Deployed path could not be safely read.'; }
                }
            }
            foreach ($batch as $path => $sha) {
                if (! isset($errors[$path]) && $hashes[$path] === $sha) { continue; }
                $reason = $this->restriction($context, $target, $path, $sha, $hashes[$path], $errors[$path] ?? $textErrors[$path] ?? null, $configurationSet);
                if ($reason === null && $configurationSet && $readIndividually) {
                    try { $this->currentText($files, $path, $hashes[$path]); }
                    catch (CheckpointChanged $error) { throw $error; }
                    catch (RuntimeException $error) { $reason = $error->getMessage(); }
                }
                $receipt = $reason === null ? $this->accepted($op->id, $acceptances, $targetSha, $path, $sha, $hashes[$path], $scopeSha) : null;
                if ($configurationSet && $reason === null) {
                    $accepted[$path] = ['operation_id' => $op->id, 'target_manifest_sha256' => $targetSha, 'path' => $path,
                        'scope_sha256' => $scopeSha, 'expected_sha256' => $sha, 'actual_sha256' => $hashes[$path], 'approval' => 'configuration_set'];
                } elseif ($receipt !== null) { $accepted[$path] = $receipt; }
                $differences[] = ['path' => $path, 'expected_sha256' => $sha, 'actual_sha256' => $hashes[$path],
                    'reviewable' => $reason === null, 'reason' => $reason, 'accepted' => $receipt !== null];
            }
        }
        return ['differences' => $differences, 'accepted' => $accepted,
            'can_confirm' => ! array_filter($differences, fn ($row) => $configurationSet ? $row['reason'] !== null : ! $row['accepted']),
            'scope_sha256' => hash('sha256', json_encode(['expected' => $expected, 'accepted' => $accepted], JSON_THROW_ON_ERROR))];
    }

    private function currentText(Wings $files, string $path, string $sha): void
    {
        $text = $files->read($path, self::TEXT_LIMIT);
        if (hash('sha256', $text) !== $sha) { throw new RuntimeException('Config changed while reading confirmed list: '.$path); }
        if (! $this->validText($text)) { throw new RuntimeException('Only UTF-8 text configs can be accepted: '.$path); }
    }

    private function validText(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text);
    }

    public function review(Operation $op, ManagedServer $managed, array $target, string $targetSha, Wings $files, string $path, string $actualSha): array
    {
        UpdatePlanner::assertPath($path);
        if (! preg_match('/^[a-f0-9]{64}$/D', $actualSha)) { throw new RuntimeException('An exact currently observed SHA-256 is required for '.$path); }
        $scope = $this->scope($op);
        if (! array_key_exists($path, $scope)) { throw new RuntimeException('Path is outside this exact deployed scope: '.$path); }
        $actual = $files->hash($path);
        if ($actual !== $actualSha) { throw new RuntimeException('Local config changed: '.$path.'; requested '.$actualSha.', observed '.($actual ?? '[absent]').'. Refresh the differences.'); }
        if ($scope[$path] === $actual) { throw new RuntimeException('No deployed difference to accept: '.$path); }
        $reason = $this->restriction($op->context, $target, $path, $scope[$path], $actual, null);
        if ($reason !== null) { throw new RuntimeException($path.': '.$reason); }
        $local = $files->read($path, self::TEXT_LIMIT);
        if (hash('sha256', $local) !== $actualSha) { throw new RuntimeException('Local config changed during text read: '.$path); }
        $stage = app(Qualification::class)->stage($managed);
        $upstream = app(SameHostDeployment::class)->read($stage, $path, self::TEXT_LIMIT);
        if (hash('sha256', $upstream) !== $scope[$path]) { throw new RuntimeException('Verified target bytes no longer match '.$path); }
        foreach ([$upstream, $local] as $text) {
            if (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) { throw new RuntimeException('Only UTF-8 text configs without binary/control bytes can be accepted: '.$path); }
        }
        if ($files->hash($path) !== $actualSha) { throw new RuntimeException('Local config changed while preparing diff: '.$path); }
        $diff = $this->diff($upstream, $local);
        return ['operation_id' => $op->id, 'path' => $path, 'target_manifest_sha256' => $targetSha,
            'expected_sha256' => $scope[$path], 'actual_sha256' => $actualSha, 'diff_sha256' => hash('sha256', $diff), 'diff' => $diff];
    }

    private function diff(string $target, string $local): string
    {
        $a = explode("\n", str_replace(["\r\n", "\r"], "\n", $target)); $b = explode("\n", str_replace(["\r\n", "\r"], "\n", $local));
        $start = 0; $endA = count($a); $endB = count($b);
        while ($start < min($endA, $endB) && $a[$start] === $b[$start]) { $start++; }
        $out = ['--- Verified target', '+++ Current local config'];
        if ($start === $endA && $start === $endB) { return implode("\n", [...$out, 'Only line endings differ; the exact raw-byte hashes above remain authoritative.']); }
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $endA--; $endB--; }
        $out[] = '@@ changed region starting at line '.($start + 1).' @@';
        foreach (array_slice($a, max(0, $start - 3), min(3, $start)) as $line) { $out[] = ' '.$line; }
        foreach ([['-', $a, $endA], ['+', $b, $endB]] as [$prefix, $lines, $end]) { for ($i = $start; $i < $end; $i++) { $out[] = $prefix.$lines[$i]; } }
        foreach (array_slice($a, $endA, 3) as $line) { $out[] = ' '.$line; }
        return implode("\n", $out);
    }
}
