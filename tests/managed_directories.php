<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Modpacks\Release;
use Arcturus\ModpackManager\Security\ManagedPaths;
use Arcturus\ModpackManager\Services\ManagedPathDiagnostic;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$extras = ['DiscordIntegration-Data', 'tacz'];
check(! ManagedPaths::allowed('tacz/guns.json'), 'Global whitelist was broadened');
check(ManagedPaths::allowed('tacz/guns.json', $extras), 'Reviewed path was rejected');
foreach (['world', 'logs', 'backups', 'data', 'playerdata', 'DIM42', '../private', 'a/b'] as $name) {
    rejects(fn () => ManagedPaths::reviewedAdditions([$name]), 'Runtime or unsafe directory approved');
}
check(! ManagedPaths::allowed('tacz/backups/runtime.zip', $extras), 'Approved root allowed nested backups');
check(! ManagedPaths::allowed('tacz/region/r.0.0.mca', $extras), 'Approved root allowed world data');
$download = ['artifact_id' => 'fixture', 'path' => 'tacz/guns.json', 'hashes' => ['sha256' => str_repeat('a', 64)]];
$release = new Release('custom', 'fixture', '1', 'Fixture', [$download]);
check($release->canPrepare(), 'Unknown upstream recipe paths should be discovered without node approval');
check(! (new Release('custom', 'fixture', '1', 'Fixture', [$download], blockers: ['Independent integrity blocker']))->withManagedDirectories($extras)->canPrepare(), 'Reviewed scope removed unrelated blockers');
$hash = hash('sha256', 'fixture');
$plan = (new UpdatePlanner)->plan(['tacz/guns.json' => $hash], ['tacz/guns.json' => $hash], ['tacz/guns.json' => $hash]);
check($plan['counts']['unchanged'] === 1, 'Planner did not retain approved manifest paths');
$diagnostic = ManagedPathDiagnostic::normalize(['schema' => 1, 'code' => 'UNAPPROVED_MANAGED_DIRECTORY',
    'directories' => ['tacz', 'DiscordIntegration-Data', '/etc/private?token=secret'],
    'description' => 'https://private?token=secret', 'action' => 'Bearer secret']);
$text = json_encode($diagnostic);
check(! str_contains($text, '/etc') && ! str_contains($text, 'https') && ! str_contains($text, 'Bearer'), 'Unsafe worker text was displayed');
check(str_contains(ManagedPathDiagnostic::message($diagnostic), 'tacz'), 'Actionable directory context was lost');
echo "Reviewed managed roots preserve runtime exclusions, file recipes, planning and sanitized diagnostics\n";

$policy = new \Arcturus\ModpackManager\Services\DirectoryPolicy;
check($policy->normalize([['path' => 'tacz', 'mode' => 'manage']]) === [['path' => 'tacz', 'mode' => 'manage']], 'Directory decisions changed');
foreach (['world', 'backups', '.env', 'credentials'] as $root) {
    rejects(fn () => $policy->normalize([['path' => $root, 'mode' => 'manage']]), 'Protected directory decision accepted');
}
rejects(fn () => $policy->normalize([['path' => 'tacz', 'mode' => 'manage'], ['path' => 'tacz', 'mode' => 'keep']]), 'Duplicate directory decision accepted');
foreach (['config/backups/private.zip', 'scripts/playerdata/uuid.dat', 'kubejs/secrets/private.key', 'libraries/.env'] as $path) {
    check(! ManagedPaths::allowed($path, $extras), 'Nested protected path escaped invariants');
    $plan = (new UpdatePlanner)->plan([], [], [$path => $hash], [['pattern' => '**', 'mode' => 'replace']]);
    check(! in_array($plan['changes'][0]['action'], ['add', 'replace_with_override'], true), 'File rule overrode invariant');
}

check(ManagedPaths::allowed('kubejs/data/pack/recipes/item.json'), 'Pack datapack content was mistaken for runtime data');
