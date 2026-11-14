<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Services\RuntimeInspector;

/** Retains parsed boot facts and a digest, never arbitrary console text or credentials. */
final class StartupEvidence
{
    private array $runtime = ['minecraft'=>null, 'loader'=>null, 'loader_version'=>null, 'java'=>null];
    private bool $crucible = false;
    private bool $valid = true;
    private bool $done = false;
    private int $lines = 0;
    private string $digest = '';

    public function line(string $line): void
    {
        $this->lines++; $this->digest = hash('sha256', $this->digest."\n".$line);
        // Parse a bounded line; control sequences are not evidence and never enter the receipt.
        $line = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $line);
        $line = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $line ?? '');
        $inspector = new RuntimeInspector;
        $observed = $inspector->fromStartupLog($line ?? '');
        $observed['java'] ??= $inspector->javaVersion($line ?? '');
        $crucible = stripos($line ?? '', 'Crucible') !== false;
        if ($crucible || array_filter($observed, fn ($v) => $v !== null)) { $this->done = false; }
        $this->crucible = $this->crucible || $crucible;
        foreach ($observed as $key => $value) {
            if ($value === null) { continue; }
            if (is_string($value) && strlen($value) > 128) { $this->valid = false; continue; }
            if ($this->runtime[$key] !== null && $this->runtime[$key] !== $value) { $this->valid = false; }
            $this->runtime[$key] = $value;
        }
        if (preg_match('/\bDone \([\d.]+s\)!/i', $line ?? '') === 1) { $this->done = true; }
    }

    public function proof(): array
    {
        $runtime = $this->runtime;
        if ($this->crucible && $runtime['minecraft'] === '1.7.10' && in_array($runtime['loader'], ['forge','crucible'], true)) {
            $runtime['loader'] = 'crucible'; $runtime['loader_version'] = null;
        }
        return ['runtime'=>$runtime, 'done'=>$this->done, 'valid'=>$this->valid, 'lines'=>$this->lines, 'sha256'=>$this->digest];
    }

    public static function matches(array $proof, array $target): bool
    {
        if (($proof['valid'] ?? false) !== true || ($proof['done'] ?? false) !== true || !preg_match('/^[a-f0-9]{64}$/D', $proof['sha256'] ?? '')) { return false; }
        foreach (['minecraft','loader','loader_version','java'] as $key) {
            if (!array_key_exists($key, $target) || !array_key_exists($key, $proof['runtime'] ?? []) || $proof['runtime'][$key] !== $target[$key]) { return false; }
        }
        return !empty($target['minecraft']) && !empty($target['loader']) && is_int($target['java']) && $target['java'] > 0;
    }
}
