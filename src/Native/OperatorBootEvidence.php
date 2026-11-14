<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use DateTimeImmutable;
use RuntimeException;

/** Explicit CLI-only root-administrator attestation. PHP never invokes Docker or opens its socket. */
final class OperatorBootEvidence
{
    public static function timestamp(string $value): float
    {
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?Z$/D', $value)) { throw new RuntimeException('Docker evidence requires UTC RFC3339 timestamps.'); }
        // PHP accepts microseconds; retain Docker nanosecond strings in the audited identity as well.
        $value = preg_replace('/(\.\d{6})\d+Z$/', '$1Z', $value);
        try { $date = new DateTimeImmutable($value); }
        catch (\Exception) { throw new RuntimeException('Invalid Docker evidence timestamp.'); }
        if (DateTimeImmutable::getLastErrors() !== false) { throw new RuntimeException('Invalid Docker evidence timestamp.'); }
        return (float)$date->format('U.u');
    }

    private static function open(string $path)
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) { throw new RuntimeException('Operator Docker evidence is available only from a local operator CLI.'); }
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1 || !in_array($stat['uid'], [0,posix_geteuid()], true)) { throw new RuntimeException('Evidence files must be private regular operator-owned files (0600), without links.'); }
        $file = @fopen($path, 'rb'); $opened = $file ? fstat($file) : false;
        if (!$opened || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']) { if ($file) { fclose($file); } throw new RuntimeException('Evidence file changed while opening.'); }
        return $file;
    }

    public static function read(string $directory, string $operation, string $server, array $instance, array $target, ?float $verificationStartedAt = null, bool $auditedApply = false): array
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || realpath($directory) !== rtrim($directory, '/') || is_link($directory)) { throw new RuntimeException('Use an absolute canonical private evidence directory from the operator CLI.'); }
        $stat = @lstat($directory);
        if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0077) !== 0 || !in_array($stat['uid'], [0,posix_geteuid()], true)) { throw new RuntimeException('Docker evidence directory must be private and operator-owned (0700).'); }
        $metadata = self::open($directory.'/metadata.json');
        try { $json = stream_get_contents($metadata, 16385); }
        finally { fclose($metadata); }
        if (strlen($json) > 16384) { throw new RuntimeException('Docker evidence metadata is too large.'); }
        try { $meta = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Invalid Docker evidence metadata JSON.'); }
        if (($meta['schema'] ?? null) !== 1 || ($meta['operation_id'] ?? '') !== $operation || ($meta['server_uuid'] ?? '') !== $server) { throw new RuntimeException('Docker evidence belongs to a different operation/server or schema.'); }
        $before = $meta['inspect_before'] ?? []; $after = $meta['inspect_after'] ?? [];
        foreach (['Id','Name','State','RestartCount'] as $key) {
            if (!array_key_exists($key,$before) || ($before[$key] !== ($after[$key] ?? null))) { throw new RuntimeException('Docker container identity changed during evidence collection.'); }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $before['Id'] ?? '') || ($before['Name'] ?? '') !== '/'.$server || ($before['State']['Running'] ?? false) !== true || !is_int($before['State']['Pid'] ?? null) || $before['State']['Pid'] < 1 || !is_int($before['RestartCount']) || $before['RestartCount'] < 0) { throw new RuntimeException('Docker evidence lacks the exact running server container identity.'); }
        $started = self::timestamp($before['State']['StartedAt'] ?? ''); $collected = self::timestamp($meta['collected_at'] ?? '');
        if ($collected < $started || $collected > microtime(true)+5 || (!$auditedApply && ($verificationStartedAt ?? microtime(true))-$collected > 300)) { throw new RuntimeException('Docker container inspection is stale or has an inconsistent clock; collect fresh evidence.'); }
        $identity = RunningInstance::same($instance, ['min'=>$started-0.002,'max'=>$started+0.002]);
        $file = self::open($directory.'/console.log'); $beforeStat = fstat($file);
        $digest = hash_init('sha256'); $evidence = new StartupEvidence; $startupProof = null; $bytes = 0;
        try {
            while (($line = fgets($file, 262146)) !== false) {
                $bytes += strlen($line); if ($bytes > 536870912 || strlen($line) > 262144 || !str_ends_with($line,"\n")) { throw new RuntimeException('Docker evidence log exceeds limits or has a truncated line.'); }
                hash_update($digest,$line);
                if (!preg_match('/^(\S+) (.*)\n$/sD',$line,$match)) { throw new RuntimeException('Docker evidence log must include a timestamp on every line.'); }
                $at = self::timestamp($match[1]);
                if ($at < $started-0.000002 || $at > $collected+0.000002) { throw new RuntimeException('Docker log contains output outside this container start/collection window.'); }
                $evidence->line($match[2]);
                if ($startupProof === null) {
                    $candidate = $evidence->proof();
                    if (StartupEvidence::matches($candidate, $target)) { $startupProof = $candidate; }
                }
                // Keep reading and validating the entire log after the first verified startup.
            }
            $afterStat = fstat($file);
            foreach (['dev','ino','size','mtime','ctime','mode','uid','nlink'] as $key) {
                if ($beforeStat[$key] !== $afterStat[$key]) { throw new RuntimeException('Docker evidence log changed during inspection.'); }
            }
            if (!feof($file)) { throw new RuntimeException('Docker evidence log read did not complete.'); }
        } finally { fclose($file); }
        $sha = hash_final($digest);
        if (!is_string($meta['log_sha256'] ?? null) || !hash_equals($sha, $meta['log_sha256'])) { throw new RuntimeException('Docker log SHA-256 does not match the evidence manifest.'); }
        if ($startupProof === null) { throw new RuntimeException('Docker log lacks valid original-runtime startup evidence with a fresh Done marker.'); }
        return ['source'=>'operator_docker', 'bundle_sha256'=>hash('sha256',$json."\n".$sha), 'log_sha256'=>$sha,
            'operator_uid'=>posix_geteuid(), 'container_id'=>$before['Id'], 'started_at'=>$before['State']['StartedAt'], 'collected_at'=>$collected,
            'instance'=>$identity, ...$startupProof];
    }
}
