<?php

declare(strict_types=1);

require __DIR__.'/fixtures/deployment-bootstrap.php';

use App\Models\User;
use Arcturus\ModpackManager\Models\{ArchiveTrust, ManagedServer};
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhProvider;
use Arcturus\ModpackManager\Services\{GtnhArchiveTrust, OperationTracker, PreparationException, PrepareRelease};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;

User::retrieved(function (User $user): void {
    $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([
        (new \App\Models\Role)->forceFill(['name' => \App\Models\Role::ROOT_ADMIN]),
    ]));
});
$versions = ['2.9.1', '3.0.0', '3.0.1', '3.0.2'];
$catalog = [];
foreach ($versions as $version) {
    $root = 'https://'.GtnhProvider::HOST.'/ServerPacks/GT_New_Horizons_'.$version.'_Server_Java_';
    $catalog[$version] = ['title' => 'Stable release', 'maxJavaVersion' => 26,
        'server' => ['java8Url' => $root.'8.zip', 'java17_2XUrl' => $root.'17-26.zip']];
}
app()->instance(JsonClient::class, new class($catalog) extends JsonClient {
    public function __construct(private array $catalog) {}
    public function get(string $url, array $headers = []): array
    {
        check($url === GtnhProvider::INDEX, 'Unexpected upstream metadata URL');
        return $this->catalog;
    }
});

$make = static function (string $version, string $mode = 'strict', string $variant = 'java17plus'): array {
    $uuid = (string) Str::uuid();
    config(['arcturus-modpack-manager.nodes.'.$uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('x', 64)]]);
    $server = ManagedServer::create(['pelican_server_uuid' => $uuid, 'label' => 'GTNH fixture',
        'provider' => 'gtnh', 'source' => 'gtnh', 'installed_version' => $version, 'transport' => 'wings',
        'asset_pattern' => $variant, 'gtnh_trust_mode' => $mode]);
    return [$server, app(OperationTracker::class)->create($server, 'baseline', ['version' => $version, 'provider' => 'gtnh', 'source' => 'gtnh'])];
};

$provider = app(GtnhProvider::class);
$trust = app(GtnhArchiveTrust::class);
$prepare = app(PrepareRelease::class);
$measurement = ['sha256' => str_repeat('a', 64), 'size' => 572227086];
$submissions = [];
$measurements = [];
$changed = false;
Http::fake(function (Request $request) use (&$submissions, &$measurements, &$changed, $measurement) {
    if (str_ends_with($request->url(), '/capabilities')) { return Http::response(['gtnh_archive_quarantine' => true, 'directory_policy' => 1]); }
    check(str_ends_with($request->url(), '/prepare'), 'Quarantine attempted to obtain a manifest or deploy');
    $data = $request->data();
    $recipe = $data['recipe'];
    $id = $data['operation_id'];
    $submissions[] = $data;
    if ($recipe['gtnh_quarantine'] ?? false) {
        check($recipe['downloads'] === [], 'Quarantine passed an empty checksum into trusted downloads');
        return Http::response(['operation_id' => $id, 'state' => 'archive_measured', 'measurement' => $measurements[$id] ?? $measurement]);
    }
    return Http::response(['operation_id' => $id, 'state' => $changed ? 'failed' : 'downloading',
        'diagnostic' => ['code' => 'GTNH_ARCHIVE_CHANGED', 'phase' => 'verifying']]);
});

[$server, $operation] = $make('3.0.0');
$strict = $provider->release('gtnh', '3.0.0', 'java17plus')->gtnhArtifact;
check($prepare->tick($server, $operation) && $operation->phase === 'archive_review_required'
    && ! $server->fresh()->base_manifest_path && count($submissions) === 1, 'Strict mode reached trusted preparation before approval');
check($trust->evidence($strict)['level'] === 'pending', 'Strict measurement not recorded');
rejects(fn () => $trust->decide($strict, $measurement['sha256'], $measurement['size'], 999, true), 'Non-root archive approval');
rejects(fn () => $trust->decide($strict, $measurement['sha256'], $measurement['size'] + 1, 1, true), 'Approval accepted wrong size');
DB::table('servers')->insert(['uuid' => $server->pelican_server_uuid, 'name' => 'GTNH fixture']);
$managedPage = \Livewire\Livewire::test(\Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer::class, ['record' => $server->id]);
check(str_contains($managedPage->html(), 'Archive approval required'), 'Managed server hid the terminal GTNH quarantine operation');
$ui = \Livewire\Livewire::test(\Arcturus\ModpackManager\Livewire\PreparationStatus::class, ['managedServerId' => $server->id]);
check(str_contains($ui->html(), $measurement['sha256']) && str_contains($ui->html(), 'Archive approval required'), 'Trust UI omitted pending archive evidence');
$ui->call('mountAction', 'approveArchive', ['operation_id' => $operation->id, 'artifact_id' => $strict->id(),
    'filename' => $strict->filename, 'url' => $strict->url, ...$measurement])->call('callMountedAction');
check(str_contains($ui->html(), 'Administrator-approved HTTPS measurement'), 'Trust UI omitted approval');
check((new GtnhArchiveTrust)->evidence($strict)['level'] === 'admin_reviewed', 'Approval was not persistent');
$retry = $server->operations()->findOrFail($server->fresh()->active_operation_id);
check(DB::table('jobs')->count() === 1 && $retry->context['archive_approval']['review_id'] === $operation->id,
    'Approval did not enqueue one continuation for the reviewed operation');
rejects(fn () => app(\Arcturus\ModpackManager\Services\QueuePreparation::class)->decideArchive(
    $server->id, $operation->id, $strict->id(), $measurement['sha256'], $measurement['size'], true),
    'Duplicate approval enqueued another preparation');
check(DB::table('jobs')->count() === 1, 'Duplicate approval added a job');
check(! $prepare->tick($server->fresh(), $retry) && end($submissions)['recipe']['downloads'][0]['hashes']['sha256'] === $measurement['sha256']
    && end($submissions)['recipe']['gtnh_quarantine_source'] === $operation->node_operation_id,
    'Approved digest was not used in the pinned download recipe');

[$auto, $operation] = $make('3.0.1', 'tofu');
$autoArtifact = $provider->release('gtnh', '3.0.1', 'java17plus')->gtnhArtifact;
$quarantineId = $operation->node_operation_id;
check(! $prepare->tick($auto, $operation) && $operation->node_operation_id !== $quarantineId && $trust->evidence($autoArtifact)['level'] === 'tofu',
    'TOFU failed to retain measurement before creating distinct pinned recipe');
$pinnedId = $operation->node_operation_id;
check(! app(PrepareRelease::class)->tick($auto->fresh(), $operation->fresh())
    && end($submissions)['operation_id'] === $pinnedId && end($submissions)['recipe']['downloads'][0]['size'] === $measurement['size'],
    'Worker resume did not use the exact measured pin and stage ID');
[$racing, $raceOp] = $make('3.0.1', 'tofu');
$raceMeasurement = ['sha256' => str_repeat('b', 64), 'size' => $measurement['size']];
try {
    $trust->measure($autoArtifact, $raceMeasurement, $racing, $raceOp);
    throw new LogicException('Different concurrent first download overrode a pin');
} catch (PreparationException $error) {
    check($error->diagnostic['code'] === 'GTNH_ARCHIVE_CHANGED', 'Changed archive did not fail closed');
}
check(ArchiveTrust::whereKey($autoArtifact->id())->count() === 1 && $trust->evidence($autoArtifact)['sha256'] === $measurement['sha256'], 'Race changed authoritative pin');
$changed = true;
try {
    $prepare->tick($racing, $raceOp);
    throw new LogicException('Replaced upstream bytes reached preparation');
} catch (PreparationException $error) {
    check($error->diagnostic['code'] === 'GTNH_ARCHIVE_CHANGED', 'Worker change diagnostic was lost');
}
$changed = false;
[$strictServer, $strictOp] = $make('3.0.1');
$before = count($submissions);
check($prepare->tick($strictServer, $strictOp) && $strictOp->phase === 'archive_review_required' && count($submissions) === $before,
    'Strict server inherited another server’s TOFU opt-in');
$strictOp->update(['context' => array_diff_key($strictOp->context, ['recipe' => true, 'release_name' => true])]);
$continuation = app(\Arcturus\ModpackManager\Services\QueuePreparation::class)->decideArchive(
    $strictServer->id, $strictOp->id, $autoArtifact->id(), $measurement['sha256'], $measurement['size'], true);
check(! $prepare->tick($strictServer->fresh(), $continuation)
    && end($submissions)['recipe']['downloads'][0]['hashes']['sha256'] === $measurement['sha256']
    && ! isset(end($submissions)['recipe']['gtnh_quarantine_source']),
    'A legacy review of a pin from another server did not continue against the exact pin');
[$otherVariant, $otherOp] = $make('3.0.1', 'tofu', 'java8');
rejects(fn () => $trust->measure($autoArtifact, $measurement, $otherVariant, $otherOp), 'Worker measurement crossed Java variants');

[$rejectServer, $rejectOp] = $make('3.0.2');
$prepare->tick($rejectServer, $rejectOp);
$rejectedArtifact = $provider->release('gtnh', '3.0.2', 'java17plus')->gtnhArtifact;
$trust->decide($rejectedArtifact, $measurement['sha256'], $measurement['size'], 1, false);
[$refused, $refusedOp] = $make('3.0.2', 'tofu');
$before = count($submissions);
try {
    $prepare->tick($refused, $refusedOp);
    throw new LogicException('Rejected archive reached worker');
} catch (PreparationException $error) {
    check($error->diagnostic['code'] === 'GTNH_ARCHIVE_REJECTED' && count($submissions) === $before, 'Rejected archive was not blocked before download');
}

[$legacy, $legacyOp] = $make('2.9.1');
$prepare->tick($legacy, $legacyOp);
check(! isset(end($submissions)['recipe']['gtnh_quarantine'])
    && end($submissions)['recipe']['downloads'][0]['hashes']['sha256'] === 'df20c7bb5423f1b5170047498d4278ad8342aef7632b0bacf7352e100296cae0', 'Bundled 2.9.1 pin no longer authoritative');
if (function_exists('pcntl_fork')) {
    $database = tempnam(sys_get_temp_dir(), 'arcturus-archive-race-');
    $gate = $database.'.start';
    config(['database.connections.archive-race' => [...config('database.connections.sqlite'),
        'database' => $database, 'busy_timeout' => 5000, 'transaction_mode' => 'IMMEDIATE']]);
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('archive-race');
    Schema::clearResolvedInstance('db.schema');
    try {
        Schema::create('webhook_configurations', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->json('events');
            $table->softDeletes();
        });
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) { (require $migration)->up(); }
        [$a, $aOp] = $make('3.0.0', 'tofu');
        [$b, $bOp] = $make('3.0.0', 'tofu');
        DB::purge('archive-race');
        $children = [];
        foreach ([[$a, $aOp, $measurement], [$b, $bOp, $raceMeasurement]] as [$candidate, $candidateOp, $digest]) {
            $pid = pcntl_fork();
            check($pid >= 0, 'Could not fork concurrent archive preparation');
            if ($pid === 0) {
                $GLOBALS['arcturus_integration_passed'] = true;
                $deadline = microtime(true) + 5;
                while (! is_file($gate) && microtime(true) < $deadline) { usleep(1000); }
                try {
                    $trust->measure($strict, $digest, $candidate, $candidateOp);
                    exit(0);
                } catch (PreparationException $error) {
                    exit($error->diagnostic['code'] === 'GTNH_ARCHIVE_CHANGED' ? 10 : 1);
                }
            }
            $children[$pid] = $digest['sha256'];
        }
        touch($gate);
        $statuses = [];
        $winner = null;
        foreach ($children as $pid => $candidateSha) {
            pcntl_waitpid($pid, $status);
            check(pcntl_wifexited($status), 'Archive trust race worker crashed');
            $code = pcntl_wexitstatus($status);
            $statuses[] = $code;
            if ($code === 0) { $winner = $candidateSha; }
        }
        sort($statuses);
        check($statuses === [0, 10] && ArchiveTrust::whereKey($strict->id())->count() === 1
            && (new GtnhArchiveTrust)->evidence($strict)['sha256'] === $winner, 'Concurrent processes resolved different pins or lost the winning measurement');
    } finally {
        DB::purge('archive-race');
        DB::setDefaultConnection($default);
        Schema::clearResolvedInstance('db.schema');
        if (is_file($gate)) { unlink($gate); }
        unlink($database);
    }
}
echo "GTNH trust: quarantine, exact approval/rejection, per-server TOFU, durable pin, changed bytes, variant fence and bundled pin passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
