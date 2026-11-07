<?php
/** Real TFG identities exercise the logical schema; production deployment stays gated. */
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\ModUpgradePlanner;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$directory = __DIR__.'/fixtures/tfg-0.13.15-to-0.13.17/';
$base = json_decode(file_get_contents($directory.'0.13.15.json'), true, flags: JSON_THROW_ON_ERROR);
$target = json_decode(file_get_contents($directory.'0.13.17.json'), true, flags: JSON_THROW_ON_ERROR);
$rules = (new ProtectionPolicy)->template('tfg_arcturus');
$planner = new UpdatePlanner;
$make = function (array $local, array $localMeta, array $policies = [], array $disabled = [], ?array $targetMeta = null) use ($base, $target, $planner, $rules): array {
    $ids = ['base' => $base['identities'], 'local' => [], 'target' => $target['identities']];
    foreach ($localMeta as $path => $meta) { $ids['local'][$path] = $meta['identity']; }
    return $planner->plan($base['files'], $local, $target['files'], [...$rules, ...$policies], $ids, $disabled, [], [],
        ['base' => $base['metadata'], 'local' => $localMeta, 'target' => $targetMeta ?? $target['metadata']]);
};
$rows = fn ($plan) => array_column(array_filter($plan['changes'], fn ($r) => ($r['kind'] ?? '') === 'mod_upgrade'), null, 'identity');
$plan = $make($base['files'], $base['metadata']);
check(count($plan['changes']) === 9 && $plan['blocked'] === 0 && ($plan['counts']['upgrade_mod'] ?? 0) === 9, 'Real TFG releases did not produce nine logical mod upgrades');
foreach ($plan['changes'] as $row) {
    check(count($row['mod_upgrade']['members']) === 2 && $row['classification'] === 'updated_upstream', 'Mod update reverted to unrelated add/remove review');
    check($row['mod_upgrade']['baseline']['sha256'] === $base['files'][$row['path']], 'Baseline evidence mismatched');
}
check(isset($rows($plan)['tfc+tfc_textile+tfcambiental+tfg'], $rows($plan)['voicechat+voicechat_api']), 'Bundled embedded IDs were split or guessed');

$optionalMetadata = $planner->plan($base['files'], $base['files'], $target['files'], $rules,
    ['base' => $base['identities'], 'target' => $target['identities']], [], [], [], ['target' => $target['metadata']]);
check($optionalMetadata['blocked'] === 0 && ($optionalMetadata['counts']['upgrade_mod'] ?? 0) === 9, 'Missing baseline loader metadata was treated as a loader migration');

$identity = 'ae2'; $old = array_search($identity, $base['identities'], true); $new = array_search($identity, $target['identities'], true);
$local = $base['files']; $metadata = $base['metadata'];
$local[$old] = hash('sha256', 'synthetic locally modified ae2'); $metadata[$old]['sha256'] = $local[$old];
$conflict = $make($local, $metadata);
check($conflict['blocked'] === 1 && $rows($conflict)[$identity]['action'] === 'mod_upgrade_conflict', 'A modified mod was hidden or counted as two conflicts');
$use = $make($local, $metadata, [['scope' => 'mod_id', 'pattern' => $identity, 'mode' => 'replace']]);
check($rows($use)[$identity]['action'] === 'upgrade_mod', 'Whole-mod upstream choice did not resolve the local conflict');
foreach ([$old, $new] as $path) {
    $keep = $make($local, $metadata, [['scope' => 'exact', 'pattern' => $path, 'mode' => 'protect']]);
    check($rows($keep)[$identity]['action'] === 'keep_mod' && ! $rows($keep)[$identity]['mod_upgrade']['members'], 'A protected member lost the installed JAR or added a second copy');
}
$review = $make($base['files'], $base['metadata'], [['scope' => 'exact', 'pattern' => $old, 'mode' => 'review']]);
check($rows($review)[$identity]['action'] === 'mod_upgrade_review', 'Explicit member review was bypassed');
$disabledLocal = $base['files']; $disabledMeta = $base['metadata'];
$disabledLocal[$old.'.disabled'] = $disabledLocal[$old]; $disabledMeta[$old.'.disabled'] = $disabledMeta[$old]; unset($disabledLocal[$old], $disabledMeta[$old]);
check($rows($make($disabledLocal, $disabledMeta))[$identity]['action'] === 'keep_mod_disabled', 'Disabled mod was re-enabled');
$absent = $base['files']; $absentMeta = $base['metadata']; unset($absent[$old], $absentMeta[$old]);
check($rows($make($absent, $absentMeta))[$identity]['action'] === 'keep_mod_disabled', 'Intentionally absent mod was re-enabled');
$renamed = $base['files']; $renamedMeta = $base['metadata']; $alias = 'mods/manually-renamed.jar';
$renamed[$alias] = $renamed[$old]; $renamedMeta[$alias] = $renamedMeta[$old]; unset($renamed[$old], $renamedMeta[$old]);
check($rows($make($renamed, $renamedMeta))[$identity]['mod_upgrade']['installed']['path'] === $alias, 'Installed identity was matched by filename instead of embedded IDs');
$duplicate = $base['files']; $duplicateMeta = $base['metadata']; $duplicate[$alias] = $duplicate[$old]; $duplicateMeta[$alias] = $duplicateMeta[$old];
check($rows($make($duplicate, $duplicateMeta))[$identity]['action'] === 'mod_identity_review', 'Duplicate active identities were accepted');
$unknownTarget = $target['metadata']; unset($unknownTarget[$new]['sha256']);
$unknown = $make($base['files'], $base['metadata'], [['scope' => 'exact', 'pattern' => $new, 'mode' => 'replace']], [], $unknownTarget);
check($unknown['blocked'] === 0 && $rows($unknown)[$identity]['action'] === 'upgrade_mod', 'Missing optional target metadata blocked a manifest-owned replacement');
$extra = $base['files']; $extraMeta = $base['metadata']; $custom = 'mods/Paradigm.jar'; $hash = hash('sha256', 'synthetic custom mod');
$extra[$custom] = $hash; $extraMeta[$custom] = ['identity' => 'paradigm', 'ids' => ['paradigm'], 'source' => 'META-INF/mods.toml', 'loader' => 'forge', 'sha256' => $hash];
$preserved = array_column($make($extra, $extraMeta)['changes'], null, 'path');
check($preserved[$custom]['action'] === 'preserve', 'Arcturus/Paradigm protection was lost');
echo "Real TFG nine logical upgrades, bundled IDs, local choices, protections, disabled/missing/duplicate identities and manifest-owned deployment passed\n";
