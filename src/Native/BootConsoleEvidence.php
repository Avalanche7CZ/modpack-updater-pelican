<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Throwable;

/** Read-only bounded polling during ONE accepted boot, anchored to the pre-start console boundary. */
final class BootConsoleEvidence
{
    private ?array $previous = null;
    private ?array $baseline = null;
    private StartupEvidence $evidence;
    private float $nextPoll = 0;
    private ?float $first = null;
    private ?float $last = null;

    public function __construct(private readonly Wings $files, ?array $baseline = null)
    {
        $this->evidence = new StartupEvidence;
        try { $this->previous = $baseline ?? $files->consoleTail(100); $this->baseline = $this->previous; }
        catch (Throwable) { } // Unavailable console must not break file-log startup verification.
    }

    public function poll(): ?array
    {
        if ($this->baseline === null || microtime(true) < $this->nextPoll) { return null; }
        $this->nextPoll = microtime(true) + 1;
        try { $lines = $this->files->consoleTail(100); }
        catch (Throwable) { return null; }
        if ($lines === $this->previous) { return null; }
        $overlap = min(count($this->previous), count($lines));
        while ($overlap > 0 && array_slice($this->previous, -$overlap) !== array_slice($lines, 0, $overlap)) { $overlap--; }
        // No overlap means the old tail rolled out or a new container log replaced it.
        // An identical pre-start tail never counts as fresh output.
        $new = array_slice($lines, $overlap); $this->previous = $lines;
        foreach ($new as $line) {
            if (strlen($line) > 262144) { continue; }
            $this->evidence->line($line); $this->first ??= microtime(true); $this->last = microtime(true);
        }
        return $this->proof();
    }

    public function proof(): array
    {
        return [...$this->evidence->proof(), 'source'=>'wings_console', 'baseline_sha256'=>hash('sha256', serialize($this->baseline)),
            'first_observed_at'=>$this->first, 'last_observed_at'=>$this->last];
    }
}
