<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\Providers\CurseForgeProvider;
use Arcturus\ModpackManager\Modpacks\Providers\FtbProvider;
use Arcturus\ModpackManager\Modpacks\Providers\GitHubProvider;
use Arcturus\ModpackManager\Modpacks\Providers\ModrinthProvider;
use Arcturus\ModpackManager\Modpacks\Release;

final class Fixtures extends JsonClient
{
    public array $responses = [];

    public array $requests = [];

    public function get(string $url, array $headers = []): array
    {
        $this->requests[] = $url;

        return $this->responses[$url] ?? throw new RuntimeException('Unmocked provider request: '.$url);
    }
}
$http = new Fixtures;
$github = new GitHubProvider($http);
$releaseUrl = 'https://api.github.com/repos/GTNewHorizons/GT-New-Horizons-Modpack/releases/tags/2.8.0';
$asset = ['name' => 'GTNH-2.8.0-Server-Java17.zip', 'browser_download_url' => 'https://github.com/GTNewHorizons/GT-New-Horizons-Modpack/releases/download/2.8.0/server.zip', 'size' => 123, 'digest' => 'sha256:'.str_repeat('a', 64)];
$http->responses[$releaseUrl] = ['tag_name' => '2.8.0', 'draft' => false, 'name' => 'GTNH', 'assets' => [$asset], 'body' => 'Migration notes'];
check(! $github->release('GTNewHorizons/GT-New-Horizons-Modpack', '2.8.0')->canPrepare(), 'Unselected GitHub asset must be blocked');
$http->responses['https://api.github.com/repos/GTNewHorizons/GT-New-Horizons-Modpack/releases/latest'] = ['tag_name' => '2.9.0-nightly', 'draft' => false, 'prerelease' => false];
$http->responses['https://api.github.com/repos/GTNewHorizons/GT-New-Horizons-Modpack/releases?per_page=100&page=1'] = [
    ['tag_name' => '2.9.0-nightly', 'draft' => false, 'prerelease' => false],
    ['tag_name' => '2.8.0', 'draft' => false, 'prerelease' => false],
];
check($github->latest('GTNewHorizons/GT-New-Horizons-Modpack', '*Server*Java17.zip')->version === '2.8.0', 'A nightly build was promoted to stable');
$r = $github->release('GTNewHorizons/GT-New-Horizons-Modpack', '2.8.0', '*Server*Java17.zip');
check($r->canPrepare() && $r->bytes() === 123, 'Exact server asset not resolved');
$http->responses[$releaseUrl]['assets'][] = $asset;
check(! $github->release('GTNewHorizons/GT-New-Horizons-Modpack', '2.8.0', '*Server*')->canPrepare(), 'Ambiguous GitHub assets accepted');
rejects(fn () => $github->project('../escape'), 'Invalid repository accepted');

$cf = new CurseForgeProvider($http);
rejects(fn () => $cf->versions('123'), 'Missing API credential accepted');
$GLOBALS['test_config']['arcturus-modpack-manager.curseforge_api_key'] = 'fixture-key';
$cfFile = ['id' => 20, 'modId' => 123, 'isAvailable' => true, 'downloadUrl' => 'https://edge.forgecdn.net/files/20/mod.jar', 'fileName' => 'mod.jar', 'fileLength' => 10, 'hashes' => [['algo' => 1, 'value' => str_repeat('b', 40)]]];
$http->responses['https://api.curseforge.com/v1/mods/123/files/20'] = ['data' => $cfFile];
check(count($cf->resolveManifest(['manifestType' => 'minecraftModpack', 'manifestVersion' => 1, 'files' => [['projectID' => 123, 'fileID' => 20, 'required' => true]]])) === 1, 'Required CF manifest did not resolve');
$http->responses['https://api.curseforge.com/v1/mods/123/files/20']['data']['downloadUrl'] = null;
rejects(fn () => $cf->resolveFile('123', '20'), 'Restricted redistribution incorrectly accepted');

$ftb = new FtbProvider($http, $cf);
$http->responses['https://api.feed-the-beast.com/v1/modpacks/modpack/129'] = ['versions' => [
    ['id' => 1, 'name' => '1.0', 'type' => 'release'], ['id' => 3, 'name' => '1.2', 'type' => 'release'], ['id' => 2, 'name' => '1.1', 'type' => 'release']]];
check($ftb->versions('129')[0]['id'] === '3', 'FTB version list was not sorted newest first');
$http->responses['https://api.feed-the-beast.com/v1/modpacks/modpack/129/3'] = ['status' => 'success', 'id' => 3, 'parent' => 129, 'targets' => [['name' => 'neoforge', 'version' => '21.1.180', 'type' => 'modloader']], 'files' => [
    ['name' => 'server.toml', 'path' => './config', 'url' => 'https://files.feed-the-beast.com/blob/server.toml', 'hashes' => ['sha256' => str_repeat('a', 64)], 'size' => 1],
    ['name' => 'client.jar', 'path' => './mods', 'clientonly' => true],
]];
$r = $ftb->release('129', '3');
check($r->canPrepare() && count($r->downloads) === 1 && $r->downloads[0]['path'] === 'config/server.toml', 'FTB server file selection failed');
$http->responses['https://api.feed-the-beast.com/v1/modpacks/modpack/129/3']['files'][] = ['name' => 'missing.jar', 'path' => './mods'];
check(! $ftb->release('129', '3')->canPrepare(), 'FTB required missing file accepted');

$mr = new ModrinthProvider($http);
$http->responses['https://api.modrinth.com/v2/project/testpack'] = ['id' => 'project1'];
$http->responses['https://api.modrinth.com/v2/version/ver1'] = ['id' => 'ver1', 'project_id' => 'project1', 'version_number' => '1.0', 'loaders' => ['fabric'], 'game_versions' => ['1.20.1'], 'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/data/test/pack.mrpack', 'hashes' => ['sha512' => str_repeat('c', 128)], 'size' => 30]]];
check($mr->release('testpack', 'ver1')->canPrepare(), 'Modrinth mrpack not resolved');
check($mr->release('testpack', 'ver1')->bytes() === null, 'mrpack index size mistaken for total download size');
$http->responses['https://api.modrinth.com/v2/version/ver1']['project_id'] = 'someone-else';
rejects(fn () => $mr->release('testpack', 'ver1'), 'Wrong project baseline accepted');

rejects(fn () => new Release('custom', 'x', 'v', 'v', [['url' => 'https://localhost/file', 'hashes' => ['sha256' => str_repeat('a', 64)], 'path' => '../bad']]), 'Unsafe custom release accepted');
rejects(fn () => new Release('custom', 'x', 'v', 'v', [['url' => 'https://github.com/file', 'hashes' => ['md5' => str_repeat('a', 32)], 'path' => 'mods/a.jar']]), 'Unsupported checksum accepted');
echo "Provider discovery, exact releases and complete-file resolution tests passed\n";
