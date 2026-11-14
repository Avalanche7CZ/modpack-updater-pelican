<?php

declare(strict_types=1);

/** Deterministic mixed configs and valid uncompressed JARs; no pack downloads or server data. */
function nativePayloads(int $count, bool $mixed): array
{
    $old = []; $new = []; $jarBodies = []; $jarCount = $mixed ? min(32, max(2, intdiv($count, 64))) : 0;
    for ($i = 0; $i < $count; $i++) {
        if ($i < $jarCount) {
            $size = $i % 4 === 0 ? 8 * 1048576 : 1048576;
            foreach (['old', 'new'] as $version) {
                if (! isset($jarBodies[$version][$size])) {
                    $temp = tempnam(sys_get_temp_dir(), 'native-jar-');
                    try {
                        $zip = new ZipArchive; check($zip->open($temp, ZipArchive::OVERWRITE) === true, 'Cannot create mixed JAR');
                        $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\nImplementation-Version: $version\r\n\r\n");
                        $zip->addFromString('fixture.bin', str_repeat($version[0], $size)); $zip->setCompressionName('fixture.bin', ZipArchive::CM_STORE); $zip->close();
                        $jarBodies[$version][$size] = file_get_contents($temp);
                    } finally { unlink($temp); }
                }
            }
            $path = 'mods/fixture-'.$i.'.jar'; $old[$path] = $jarBodies['old'][$size]; $new[$path] = $jarBodies['new'][$size];
        } else {
            $path = 'config/shared/f'.$i.'.cfg';
            $old[$path] = $mixed ? str_pad('old-'.$i, 128 + $i % 897, 'x') : 'old-'.$i;
            $new[$path] = $mixed ? str_pad('new-'.$i, 128 + $i % 897, 'x') : 'new-'.$i;
        }
    }
    return ['old' => $old, 'new' => $new, 'jars' => $jarCount, 'configs' => $count - $jarCount, 'upload_bytes' => array_sum(array_map('strlen', $new))];
}
