<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Security\PublicAddress;
use Arcturus\ModpackManager\Services\BaselineGuard;
use Arcturus\ModpackManager\Services\PropertiesMerge;
use Arcturus\ModpackManager\Services\RuntimeInspector;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$p = new UpdatePlanner;
$h = fn ($s) => hash('sha256', $s);
$base = ['mods/old.jar' => $h('old')];
$target = ['mods/renamed.jar' => $h('new')];
$ids = ['base' => ['mods/old.jar' => 'example'], 'target' => ['mods/renamed.jar' => 'example']];
$plan = $p->plan($base, [], $target, [], $ids);
check(collectAction($plan, 'mods/renamed.jar') === 'skip_disabled', 'Deleted mod reactivated after upstream rename');
$plan = $p->plan($base, ['mods/old.jar.disabled' => $h('old')], $target, [], $ids);
check(collectAction($plan, 'mods/renamed.jar') === 'skip_disabled', 'Disabled JAR was reactivated');
$plan = $p->plan($base, [], $target, [], ['base' => [], 'target' => []]);
check($plan['blocked'] > 0, 'Unidentified missing mod did not block ambiguous new JAR');
$plan = $p->plan($base, ['mods/renamed-local.jar' => $h('local')], $target, [], [...$ids, 'local' => ['mods/renamed-local.jar' => 'example']]);
check(collectAction($plan, 'mods/renamed.jar') === 'skip_disabled', 'Manually renamed mod gained a duplicate active upstream copy');
check($p->plan(['config/a.cfg' => strtoupper($h('same'))], ['config/a.cfg' => $h('same')], ['config/a.cfg' => $h('same')])['counts']['unchanged'] === 1, 'Hash case caused a false conflict');
foreach (['config/Paradigm/main.yml', 'config/Arcturus/config.json', 'run.sh', 'ops.txt'] as $path) {
    check(collectAction($p->plan([$path => $h('a')], [$path => $h('b')], [$path => $h('c')], [['pattern' => $path, 'mode' => 'replace']]), $path) === 'preserve', 'Protected path could be replaced');
}
$merge = new PropertiesMerge;
$result = $merge->merge("a=1\nb=1\n", "# local\na=2\nb=1\n", "a=1\nb=2\nc=3\n");
check($result['merged'] && str_contains($result['content'], "a=2\nb=2\nc=3"), 'Disjoint three-way properties merge failed');
check(! $merge->merge('a=1', 'a=2', 'a=3')['merged'], 'Conflicting scalar merge falsely succeeded');
rejects(fn () => $merge->merge('a=1', "a=2\na=3", 'a=4'), 'Duplicate configuration key accepted');
rejects(fn () => $merge->merge('a=1', 'a=hello\\n', 'a=4'), 'Escaped property accepted');
$guard = new BaselineGuard;
rejects(fn () => $guard->require(['schema' => 1, 'version' => 'v', 'files' => $base], 'ftb', '129', 'v'), 'Live-derived schema-1 manifest accepted as verified upstream');
$runtime = new RuntimeInspector;
$report = $runtime->inspect(['libraries/net/minecraftforge/forge/1.20.1-47.4.0/unix_args.txt'], [], 'java @unix_args.txt', 'java_17', 'Forge');
check($report['loader'] === 'forge' && $report['loader_version'] === '47.4.0' && $report['java'] === 17, 'Forge runtime detection failed');
$report = $runtime->inspect(['Crucible-1.7.10.jar'], [], 'bash startserver.sh', 'java_21', 'Custom');
check($report['loader'] === 'crucible' && $report['minecraft'] === '1.7.10', 'Legacy Crucible detection failed');
check(! $runtime->preflight($report, ['java' => 17, 'minecraft' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1'])['compatible'], 'Incompatible Java/loader accepted');
echo "Identity, deletion, protected paths, three-way merges, provenance and runtime tests passed\n";
function collectAction(array $plan, string $path): string
{
    foreach ($plan['changes'] as $c) {
        if ($c['path'] === $path) {
            return $c['action'];
        }
    } throw new RuntimeException('Missing path');
}
foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.169.254', '192.168.1.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', '2001:db8::1'] as $ip) {
    check(! PublicAddress::isPublic($ip), 'Private or special address accepted: '.$ip);
}
check(PublicAddress::isPublic('1.1.1.1'), 'Public address rejected');
