<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Tests {
    /** Process-local clock for synchronous HTTP fakes; real transport deadlines stay real. */
    final class FixtureClock
    {
        private static float $offset = 0;

        public static function now(): float { return \microtime(true) + self::$offset; }

        public static function advance(float $seconds): void { self::$offset += $seconds; }

        public static function install(): void
        {
            // Keep real elapsed work too: freezing Carbon at the last virtual sleep makes
            // audit timestamps lag behind Wings uptime on slower CI runners.
            \Illuminate\Support\Facades\Date::setTestNow(
                static fn (\Carbon\CarbonInterface $realNow): \Carbon\CarbonInterface =>
                    $realNow->avoidMutation()->addMicroseconds((int) round(self::$offset * 1000000)),
            );
        }
    }

    FixtureClock::install();
}

namespace Arcturus\ModpackManager\Native {
    function microtime(bool $asFloat = false): float|string
    {
        $now = \Arcturus\ModpackManager\Tests\FixtureClock::now();
        return $asFloat ? $now : sprintf('%.8f %d', $now - floor($now), (int)$now);
    }
    function time(): int { return (int) \Arcturus\ModpackManager\Tests\FixtureClock::now(); }
    function sleep(int $seconds): int { \Arcturus\ModpackManager\Tests\FixtureClock::advance($seconds); return 0; }
    function usleep(int $microseconds): void { \Arcturus\ModpackManager\Tests\FixtureClock::advance($microseconds / 1000000); }
}

namespace Arcturus\ModpackManager\Services {
    function microtime(bool $asFloat = false): float|string { return \Arcturus\ModpackManager\Native\microtime($asFloat); }
    function time(): int { return \Arcturus\ModpackManager\Native\time(); }
    function sleep(int $seconds): int { return \Arcturus\ModpackManager\Native\sleep($seconds); }
    function usleep(int $microseconds): void { \Arcturus\ModpackManager\Native\usleep($microseconds); }
}
