<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;

/** Strict single-line key=value files. Escapes, continuations and duplicates require manual review. */
final class PropertiesMerge
{
    private function parse(string $text): array
    {
        if (strlen($text) > 1048576 || str_contains($text, "\0")) {
            throw new RuntimeException('Configuration exceeds merge limits');
        }
        $values = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (trim($line) === '' || preg_match('/^\s*[#!]/', $line)) {
                continue;
            }
            if (str_contains($line, '\\') || ! preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=\s*(.*?)\s*$/D', $line, $m) || array_key_exists($m[1], $values)) {
                throw new RuntimeException('Unsupported or ambiguous properties syntax; manual merge required');
            }
            $values[$m[1]] = $m[2];
        }

        return $values;
    }

    public function merge(string $base, string $local, string $target): array
    {
        $old = $this->parse($base);
        $current = $this->parse($local);
        $new = $this->parse($target);
        $merged = $current;
        $conflicts = [];
        $same = fn ($a, $b, $k) => array_key_exists($k, $a) === array_key_exists($k, $b) && ($a[$k] ?? null) === ($b[$k] ?? null);
        foreach (array_unique(array_merge(array_keys($old), array_keys($current), array_keys($new))) as $key) {
            if ($same($current, $new, $key) || $same($old, $new, $key)) {
                continue;
            }
            if ($same($old, $current, $key)) {
                if (array_key_exists($key, $new)) {
                    $merged[$key] = $new[$key];
                } else {
                    unset($merged[$key]);
                }
            } else {
                $conflicts[] = $key;
            }
        }
        if ($conflicts) {
            return ['merged' => false, 'conflicts' => $conflicts, 'content' => null];
        }
        $output = [];
        $written = [];
        foreach (preg_split('/\r?\n/', rtrim($local, "\r\n")) as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=/', $line, $m)) {
                $key = $m[1];
                if (! array_key_exists($key, $merged)) {
                    continue;
                }
                $output[] = array_key_exists($key, $current) && $current[$key] === $merged[$key] ? $line : $key.'='.$merged[$key];
                $written[$key] = true;
            } else {
                $output[] = $line;
            }
        }
        foreach ($merged as $key => $value) {
            if (! isset($written[$key])) {
                $output[] = $key.'='.$value;
            }
        }

        return ['merged' => true, 'conflicts' => [], 'content' => implode("\n", $output)."\n"];
    }
}
