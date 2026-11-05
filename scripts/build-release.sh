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
cp -R src config database resources bin examples "$ROOT/"

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

if find "$ROOT" -type l -print -quit | grep -q .; then
  echo "Refusing to package symlinks" >&2
  exit 1
fi

(cd dist && zip -qr arcturus-modpack-manager.zip arcturus-modpack-manager)

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

echo "Packaged Pelican plugin v$VERSION — SHA-256 $SHA256"
