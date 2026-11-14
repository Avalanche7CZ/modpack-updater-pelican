<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Throwable;

/** Private, best-effort boot evidence. Never used to approve a boot or a restore. */
final class BootFailureEvidence
{
    private const MAX_BYTES = 16384;
    private ?string $baseline;
    private bool $running = false;
    private string $sample = '';

    public function __construct(private readonly Wings $files)
    {
        $before = $this->console();
        $this->baseline = $before === null ? null : self::fingerprint($before);
    }

    public function observedRunning(): void { $this->running = true; }

    public function sample(string $text): void { $this->sample = substr($text, -self::MAX_BYTES); }

    public function collect(): array
    {
        $after = $this->console();
        if ($after && ($this->baseline === null ? $this->running : $this->baseline !== self::fingerprint($after))) {
            $text = self::safeTail(implode("\n", $after));
            if (trim($text) !== '') { return ['source' => 'wings_console', 'text' => $text]; }
        }
        // start() retired latest.log before the start request. A replacement log may
        // contain the crash even when Minecraft exits before the first running sample.
        if ($this->sample === '') {
            try { $this->sample = $this->files->read('logs/latest.log', 4194304); }
            catch (Throwable) { /* Preserve the boot failure even when diagnostics are unavailable. */ }
        }
        $text = self::safeTail($this->sample);
        if (trim($text) !== '') { return ['source' => 'startup_log_sample', 'text' => $text]; }
        return ['source' => 'unavailable', 'text' => ''];
    }

    private function console(): ?array
    {
        try { return $this->files->consoleTail(); }
        catch (Throwable) { return null; } // Do not report remote response bodies.
    }

    private static function fingerprint(array $lines): string { return hash('sha256', serialize($lines)); }

    public static function safeTail(string $text): string
    {
        // Strip terminal/control characters, retain UTF-8 and never display as markup.
        $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', substr($text, -self::MAX_BYTES));
        $text = preg_replace('/(?![\n\t])[\p{Cc}\p{Cf}]/u', '', mb_scrub($text ?? '', 'UTF-8'));
        return mb_strcut($text ?? '', 0, self::MAX_BYTES, 'UTF-8');
    }
}
