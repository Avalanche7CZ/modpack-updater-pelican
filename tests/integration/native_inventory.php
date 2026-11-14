<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
require __DIR__.'/fixtures/native_payloads.php';

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Native\Wings;
use Arcturus\ModpackManager\Native\Preparation;
use Arcturus\ModpackManager\Native\SymlinkPath;
use Arcturus\ModpackManager\Services\DirectoryPolicy;
use Arcturus\ModpackManager\Services\SafetyPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Arcturus\ModpackManager\Security\ManagedPaths;
use Illuminate\Support\Facades\Http;

$node = (new Node)->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture', 'daemon_token_id' => 'fixture']);
$server = (new Server)->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']); $server->setRelation('node', $node);
$managed = (new ManagedServer)->forceFill(['pelican_server_uuid' => $server->uuid]); $managed->setRelation('pelicanServer', $server);
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
$payloads = nativePayloads((int) (getenv('INVENTORY_BENCH_COUNT') ?: 128), true);
$data = [...$payloads['old'], 'server.properties' => "level-name=world\n", 'config/unselected.cfg' => 'never read', 'world/level.dat' => 'never read'];
$dirs = ['config' => true, 'config/shared' => true, 'mods' => true, 'world' => true];
$links = ['config/shared/linked.cfg' => false, 'config/linked-dir' => true];
$paths = [...array_keys($payloads['old']), 'config/shared/missing.cfg', 'config/missing-dir/child.cfg', 'config/shared/linked.cfg', 'config/linked-dir/a.cfg', 'config/linked-dir/deep/b.cfg', 'config/linked-dir', 'world/level.dat', 'unapproved/a.cfg'];
// Keep deliberately interleaved manifest order; batching must not alter the response or plan.
$paths = array_reverse($paths); $base = array_fill_keys($paths, hash('sha256', 'baseline')); $target = array_fill_keys($paths, hash('sha256', 'target'));
$counts = []; $bytes = 0; $reads = 0; $mode = ''; $listed = []; $readPaths = [];
$install = function () use (&$counts, &$bytes, &$reads, &$mode, &$listed, &$readPaths, &$data, &$dirs, &$links, $headers): void {
    $counts = ['list-directory' => 0, 'contents' => 0]; $bytes = 0; $reads = 0; $listed = []; $readPaths = [];
    Http::swap(new class extends \Illuminate\Http\Client\Factory { public function recordRequestResponsePair($request, $response) { return $this; } });
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$counts, &$bytes, &$reads, &$mode, &$listed, &$readPaths, &$data, &$dirs, &$links, $headers) {
        $route = basename(parse_url($request->url(), PHP_URL_PATH)); parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
        $counts[$route] = ($counts[$route] ?? 0) + 1;
        if ($route === 'list-directory') {
            $dir = trim($q['directory'], '/'); $listed[] = $dir;
            check(! str_starts_with($dir, 'world') && ! str_starts_with($dir, 'config/linked-dir'), 'Inventory traversed protected directory');
            if ($mode === 'denied' && $dir === 'config/shared') { return Http::response([], 403, $headers); }
            $prefix = $dir === '' ? '' : $dir.'/'; $rows = [];
            foreach ([...array_keys($data), ...array_keys($dirs), ...array_keys($links)] as $path) {
                if (! str_starts_with($path, $prefix)) { continue; } $name = substr($path, strlen($prefix));
                if ($name === '' || str_contains($name, '/')) { continue; }
                $isDir = isset($dirs[$path]) || ($links[$path] ?? false);
                $rows[$name] = ['name' => $name, 'file' => ! $isDir, 'directory' => $isDir, 'symlink' => array_key_exists($path, $links),
                    'size' => isset($data[$path]) ? strlen($data[$path]) : 0, 'modified' => 'stable', 'mode_bits' => '644'];
                if ($reads > 0 && $mode === 'parent_swap' && $path === 'config/shared') { $rows[$name]['modified'] = 'changed'; }
                if ($reads > 0 && $mode === 'parent_link' && $path === 'config/shared') { $rows[$name]['symlink'] = true; }
                if ($reads > 0 && $mode === 'link_swap' && $path === 'config/shared/linked.cfg') { $rows[$name]['symlink'] = false; }
                if ($reads > 0 && $mode === 'leaf_swap' && str_starts_with($path, 'config/shared/f')) { $rows[$name]['modified'] = 'changed'; }
            }
            if ($reads > 0 && $mode === 'appeared' && $dir === 'config/shared') { $rows['missing.cfg'] = ['name' => 'missing.cfg', 'file' => true, 'directory' => false, 'symlink' => false, 'size' => 1, 'modified' => 'new']; }
            return Http::response(array_values($rows), 200, $headers);
        }
        check($route === 'contents', 'Inventory attempted a mutation');
        $path = ltrim($q['file'], '/'); $readPaths[] = $path;
        check($path === 'server.properties' || (isset($data[$path]) && $path !== 'config/unselected.cfg' && ! str_starts_with($path, 'world/')), 'Inventory escaped explicit manifest');
        if ($path !== 'server.properties') { $reads++; }
        if ($mode === 'read_error' && $reads === 1) { return Http::response('', 500, $headers); }
        $body = $data[$path];
        if ($mode === 'truncated' && $path !== 'server.properties') { $body = substr($body, 1); }
        $bytes += strlen($body); return Http::response($body, 200, $headers);
    });
};
// Frozen serial inventory contract from main 6953188; no new batch behavior enters this side.
$serial = function () use ($managed, $paths): array {
    $files = new Wings($managed->pelicanServer, app(DaemonFileRepository::class));
    $scope = (new DirectoryPolicy)->scope($managed); $properties = $files->read('server.properties');
    $excluded = array_values(array_unique([...SafetyPaths::roots($properties), ...$scope['exclude']]));
    $result = []; $missing = []; $skipped = [];
    foreach ($paths as $path) {
        UpdatePlanner::assertPath($path);
        if (SafetyPaths::reason($path, $excluded) !== null || ! ManagedPaths::allowed($path, $scope['manage'])) { $excluded[] = $path; continue; }
        try { $hash = $files->hash($path); }
        catch (SymlinkPath $link) { $skipped[] = $link->path; $excluded[] = $link->path; continue; }
        if ($hash === null) { $missing[] = $path; } else { $result[$path] = $hash; }
    }
    ksort($result);
    return ['complete' => true, 'files' => $result, 'missing' => $missing, 'identities' => [], 'metadata' => [], 'scope' => 'manifest-delta-v1',
        'requested_paths' => $paths, 'excluded_roots' => array_values(array_unique($excluded)), 'skipped_symlinks' => array_values(array_unique($skipped)),
        'unreadable_paths' => [], 'world_path_unreadable' => false, 'properties_sha256' => hash('sha256', $properties),
        'inventory_summary' => ['files_hashed' => count($result), 'unreadable_files_skipped' => 0, 'unreadable_directories' => 0, 'unreadable_entries_unknown' => 0]];
};
if (getenv('INVENTORY_COMPARE_MAIN')) {
    // Optional immutable source comparison, safe in shallow CI where old history may be absent.
    foreach (['Files', 'BatchFiles', 'MutationBatches', 'SymlinkPath', 'TextChangedDuringInspection', 'WingsConnectionException', 'Wings', 'Preparation'] as $name) {
        $text = shell_exec('git show 6953188:src/Native/'.$name.'.php'); check(is_string($text) && str_contains($text, '<?php'), 'Fetch main baseline 6953188 first');
        $text = str_replace('namespace Arcturus\\ModpackManager\\Native;', 'namespace Arcturus\\ModpackManager\\InventoryOld;', $text); eval(substr($text, 5));
    }
    $serial = fn () => (new \Arcturus\ModpackManager\InventoryOld\Preparation($managed))->request('inventory', ['paths' => $paths]);
}
$results = []; $inventories = []; $plans = [];
foreach (['main' => $serial, 'current' => fn () => (new Preparation($managed))->request('inventory', ['paths' => $paths])] as $label => $inventory) {
    $install(); $start = hrtime(true); $inventories[$label] = $inventory(); $seconds = (hrtime(true) - $start) / 1e9;
    $plans[$label] = (new UpdatePlanner)->plan($base, $inventories[$label]['files'], $target, [], [], [], $inventories[$label]['excluded_roots']);
    $results[$label] = ['requests' => $counts, 'contents_bytes' => $bytes, 'inventory_seconds' => $seconds];
}
check($inventories['main'] === $inventories['current'], 'Batching changed the inventory response including missing/symlink classification');
check($plans['main'] === $plans['current'], 'Batching changed the approved-scope plan');
check($results['main']['requests']['contents'] === $results['current']['requests']['contents'], 'Inventory skipped SHA-256 reads');
check($results['current']['requests']['list-directory'] < $results['main']['requests']['list-directory'] / 5, 'Inventory failed to share fresh listings');
$files = new Wings($server, app(DaemonFileRepository::class));
$sample = ['config/shared/f'.($payloads['jars']).'.cfg', 'config/shared/missing.cfg', 'config/shared/linked.cfg', 'config/linked-dir/child.cfg'];
foreach (['denied', 'truncated', 'read_error', 'parent_swap', 'parent_link', 'link_swap', 'leaf_swap', 'appeared'] as $mode) {
    $install(); rejects(fn () => $files->inventoryBatch($sample), 'Inventory accepted unreadable/changed batch: '.$mode);
}
$mode = ''; $install(); $files->inventoryBatch($sample); $n = $counts['list-directory']; $files->inventoryBatch($sample);
check($counts['list-directory'] === 2 * $n, 'Inventory reused metadata between calls');
$pathsReordered = array_reverse($paths); // Every invocation must retain caller ordering too.
$other = (new Preparation($managed))->request('hashes', ['paths' => $pathsReordered]);
check($other['files'] === $inventories['current']['files'] && $other['requested_paths'] === $pathsReordered, 'Hashes operation changed inventory semantics');
$output = ['baseline' => '6953188', 'environment' => ['php' => PHP_VERSION, 'panel' => 'v1.0.0-rc.2', 'laravel' => \Composer\InstalledVersions::getPrettyVersion('laravel/framework'), 'guzzle' => \Composer\InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'), 'transport' => 'zero-latency HTTP fake'], 'candidates' => count($paths), 'configs' => $payloads['configs'], 'jars' => $payloads['jars'], 'results' => $results, 'identical_inventory_and_plan' => true];
if ($file = getenv('INVENTORY_BENCH_OUTPUT')) { file_put_contents($file, json_encode($output, JSON_PRETTY_PRINT)."\n"); }
echo json_encode($output, JSON_PRETTY_PRINT)."\n";
echo "Inventory plan equality, missing/symlink scope, read/change failures and fresh batches passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
