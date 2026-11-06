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
check(! $release->canPrepare() && $release->withManagedDirectories($extras)->canPrepare(), 'File recipes did not bind reviewed scope');
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
