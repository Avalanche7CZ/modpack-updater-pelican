#!/usr/bin/env bash
# Assemble an installable Pelican plugin and matching one-click update feed.
# Usage: bash scripts/build-release.sh <version> <tag> <owner/repo>
set -euo pipefail
VERSION="$1"
TAG="$2"
REPOSITORY="$3"

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Invalid version: $VERSION" >&2; exit 2; }
[[ "$TAG" == "v$VERSION" ]] || { echo "Tag and version disagree" >&2; exit 2; }
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo "Invalid repository" >&2; exit 2; }

ROOT="dist/arcturus-modpack-manager"
rm -rf dist
mkdir -p "$ROOT"
cp README.md "$ROOT/"
cp -R src config database resources bin examples node-worker docs "$ROOT/"

php -r '
  $file = json_decode(file_get_contents("plugin.json"), true, 512, JSON_THROW_ON_ERROR);
  if (($file["id"] ?? "") !== "arcturus-modpack-manager") {
      fwrite(STDERR, "Unexpected plugin ID\n"); exit(1);
  }
  $file["version"] = $argv[1];
  $file["update_url"] = "https://github.com/" . $argv[2] . "/releases/latest/download/update.json";
  unset($file["meta"]);
  file_put_contents($argv[3], json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$VERSION" "$REPOSITORY" "$ROOT/plugin.json"

find "$ROOT" -type d -name '__pycache__' -prune -exec rm -rf {} +
if find "$ROOT" -type l -print -quit | grep -q .; then
  echo "Refusing to package symlinks" >&2
  exit 1
fi

php -r '
  $zip = new ZipArchive();
  if ($zip->open("dist/arcturus-modpack-manager.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit(1);
  $root = "dist/arcturus-modpack-manager";
  $zip->addEmptyDir("arcturus-modpack-manager");
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
  foreach ($files as $file) {
      $name = "arcturus-modpack-manager/" . substr($file->getPathname(), strlen($root) + 1);
      $ok = $file->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($file->getPathname(), $name);
      if (!$ok) { fwrite(STDERR, "Cannot add package entry\n"); exit(1); }
  }
  if (!$zip->close()) exit(1);
'

php -r '
  $archive = new ZipArchive();
  if ($archive->open("dist/arcturus-modpack-manager.zip") !== true) {
      fwrite(STDERR, "Cannot read ZIP\n"); exit(1);
  }
  $required = [
      "arcturus-modpack-manager/plugin.json",
      "arcturus-modpack-manager/src/ModpackManagerPlugin.php",
      "arcturus-modpack-manager/src/Providers/ModpackManagerServiceProvider.php",
      "arcturus-modpack-manager/resources/views/overview.blade.php",
      "arcturus-modpack-manager/resources/views/managed-server.blade.php",
      "arcturus-modpack-manager/src/Filament/Server/Pages/ArcturusModpack.php",
      "arcturus-modpack-manager/node-worker/worker.py",
      "arcturus-modpack-manager/node-worker/local.py",
      "arcturus-modpack-manager/node-worker/setup-local-node.py",
      "arcturus-modpack-manager/src/Local/LocalNode.php",
      "arcturus-modpack-manager/src/Filament/Admin/Pages/LocalFilesystem.php",
      "arcturus-modpack-manager/docs/local-filesystem.md",
      "arcturus-modpack-manager/src/Local/LocalWorker.php",
      "arcturus-modpack-manager/docs/capabilities.md",
      "arcturus-modpack-manager/src/Services/ProtectionPolicy.php",
      "arcturus-modpack-manager/src/Services/DiscordNotifier.php",
      "arcturus-modpack-manager/src/Jobs/SendDiscordNotification.php",
      "arcturus-modpack-manager/src/Filament/Forms/DiscordFields.php",
      "arcturus-modpack-manager/database/migrations/2026_11_06_010000_add_discord_notifications.php",
      "arcturus-modpack-manager/src/Services/ServerPolicyService.php",
      "arcturus-modpack-manager/src/Filament/Forms/PolicyFields.php",
      "arcturus-modpack-manager/resources/views/adoption-submit.blade.php",
      "arcturus-modpack-manager/resources/views/adoption-review.blade.php",
      "arcturus-modpack-manager/database/migrations/2026_11_06_000000_add_server_policy_inventory.php",
      "arcturus-modpack-manager/database/migrations/2026_11_05_000000_create_arcturus_modpack_tables.php"
  ];
  foreach ($required as $file) {
      if ($archive->locateName($file) === false) {
          fwrite(STDERR, "Missing ZIP entry: $file\n"); exit(1);
      }
  }
  for ($i = 0; $i < $archive->numFiles; ++$i) {
      $name = $archive->getNameIndex($i);
      if (!str_starts_with($name, "arcturus-modpack-manager/") || str_contains($name, "../")) {
          fwrite(STDERR, "Invalid ZIP entry: $name\n"); exit(1);
      }
  }
  $data = json_decode($archive->getFromName("arcturus-modpack-manager/plugin.json"), true, 512, JSON_THROW_ON_ERROR);
  if (($data["version"] ?? "") !== $argv[1] || ($data["id"] ?? "") !== "arcturus-modpack-manager") {
      fwrite(STDERR, "Invalid embedded plugin metadata\n"); exit(1);
  }
  $archive->close();
' "$VERSION"

SHA256="$(sha256sum dist/arcturus-modpack-manager.zip | awk '{print $1}')"
printf '%s  arcturus-modpack-manager.zip\n' "$SHA256" > dist/SHA256SUMS.txt

php -r '
  $json = ["*" => [
      "version" => $argv[1],
      "download_url" => "https://github.com/" . $argv[2] . "/releases/download/" . $argv[3] . "/arcturus-modpack-manager.zip",
      "sha256" => $argv[4],
  ]];
  file_put_contents("dist/update.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$VERSION" "$REPOSITORY" "$TAG" "$SHA256"

php -r '
  $feed = json_decode(file_get_contents("dist/update.json"), true, 512, JSON_THROW_ON_ERROR);
  $sha = hash_file("sha256", "dist/arcturus-modpack-manager.zip");
  if (($feed["*"]["version"] ?? "") !== $argv[1] || !hash_equals($sha, $feed["*"]["sha256"] ?? "")) {
      fwrite(STDERR, "Update feed is out of sync with ZIP\n"); exit(1);
  }
' "$VERSION"

(cd dist && sha256sum update.json >> SHA256SUMS.txt)

echo "Packaged Pelican plugin v$VERSION — SHA-256 $SHA256"
