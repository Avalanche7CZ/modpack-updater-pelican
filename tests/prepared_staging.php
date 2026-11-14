<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Native\PreparedStaging;

$stage = sys_get_temp_dir().'/prepared-test-'.bin2hex(random_bytes(8)); mkdir($stage, 0700); mkdir($stage.'/config');
try {
    file_put_contents($stage.'/config/one.cfg', 'expected'); file_put_contents($stage.'/config/two.cfg', 'second');
    $plan = ['config/one.cfg' => hash('sha256', 'expected'), 'config/two.cfg' => hash('sha256', 'second')];
    $prepared = new PreparedStaging($stage, $plan); $prepared->requirePlan($plan, []);
    $bundle = $prepared->bundles[0];
    foreach (['corrupt', 'missing', 'extra', 'symlink'] as $fault) {
        $test = $stage.'/'.$fault.'.zip'; copy($bundle['local'], $test); $zip = new ZipArchive; $zip->open($test);
        if ($fault === 'corrupt') { $zip->addFromString('0', 'EXPECTED'); }
        if ($fault === 'missing') { $zip->deleteName('1'); }
        if ($fault === 'extra') { $zip->addFromString('../live.cfg', 'extra'); }
        if ($fault === 'symlink') { $zip->setExternalAttributesName('0', ZipArchive::OPSYS_UNIX, 0120777 << 16); }
        $zip->close(); rejects(fn () => PreparedStaging::verifyArchive($test, $bundle['members']), 'Unsafe ZIP accepted: '.$fault); unlink($test);
    }
    file_put_contents($stage.'/config/one.cfg', 'drifted');
    // Existing immutable bundles retain approved bytes; a newly prepared bundle must reject source drift.
    $prepared->requirePlan($plan, []);
    rejects(fn () => new PreparedStaging($stage, $plan), 'Source drift accepted');
    rejects(fn () => $prepared->requirePlan(array_reverse($plan, true), []), 'Different numeric mapping accepted');
    unlink($stage.'/config/one.cfg'); symlink('two.cfg', $stage.'/config/one.cfg');
    rejects(fn () => new PreparedStaging($stage, $plan), 'Source symlink accepted');
    echo "Prepared ZIPs: exact approved bytes, missing/extra/link/corruption and drift checks passed\n";
} finally {
    foreach (glob($stage.'/config/*') as $file) { unlink($file); } rmdir($stage.'/config'); rmdir($stage);
}
