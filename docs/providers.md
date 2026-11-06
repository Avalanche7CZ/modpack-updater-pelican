# Providers and upstream provenance

Use the provider's immutable release ID, not an inferred display version. Read-only search/project/version adapters are separate from preparation and deployment capability. Rate limits, missing keys, ambiguous assets and restricted downloads are errors rather than empty/successful results.

## FTB

Use the official numeric pack ID and exact version ID. The current manifest API is `https://api.feed-the-beast.com/v1/modpacks/modpack/{pack}/{version}`, matching FTB's official installer. Older `/public` version endpoints are not assumed valid. Version lists are sorted newest-first before stable selection. Targets expose Minecraft, loader and Java requirements. Client-only files are excluded; required unavailable downloads, optional selection and unsupported hashes block preparation. CurseForge references require a configured CurseForge API key and permitted file URL.

Files can be staged without running FTB's installer. Generated loader libraries/launchers and Java installation are not supplied by the read-only worker; `runtime_prepared=false` blocks deployment. A staged published-file baseline must not be described as a tested full Minecraft installation.

## GitHub / GTNH

Source is `owner/repository`; installed version is an exact tag. Select a server ZIP asset explicitly, for example `*Server*Java17*.zip`, based on the **existing** launcher and Java variant. Zero or multiple matches block staging. The stable checker uses GitHub's `/releases/latest` and a bounded history fallback, excluding obvious nightly/snapshot/prerelease tags even when a publisher mislabeled them as normal releases. Exact older tags can always be supplied during adoption.

The GitHub asset's `sha256:` digest is required. If the release supplies a separate checksum file but no API digest, the current adapter requires an operator-reviewed custom recipe containing the independently verified checksum. It does not guess which sidecar file or client/server Java variant is correct. GTNH/Crucible launchers, scripts and image choices stay preserved; review migration notes and worlds separately.

## Modrinth

Use an immutable version ID from the project version list. Project ownership is checked before accepting a version. `.mrpack` is a ZIP container containing `modrinth.index.json`, checksummed download paths and Minecraft/loader dependencies. The worker resolves required server files, excludes `env.server=unsupported`, applies `overrides` followed by `server-overrides`, and excludes `client-overrides`. Optional server files require explicit custom normalization. A missing permitted download or failed hash cancels preparation. Loader dependencies are recorded but their executable installers are not run.

The index archive size is not presented as the full pack download estimate. Only fully known recipe download totals are displayed.

## CurseForge

Set the API key in native plugin settings. The adapter follows the exact client release's `serverPackFileId` or accepts a file explicitly marked as a server pack. Each file must belong to the expected project, be available, have an API-permitted download URL and a supported upstream hash. API distribution opt-outs are respected; no CDN URL is reconstructed to bypass them.

When no official server pack exists, the worker verifies the client archive and pauses at its manifest. The Panel resolves every required project/file pair in bounded, encrypted checkpoints and sends the complete pinned recipe back to that same node operation. The worker verifies all downloads, applies the declared overrides, then builds the upstream SHA-256 manifest. Required dependencies absent from the pack manifest, duplicate projects/filenames, optional selections and restricted downloads fail closed. API credentials are never sent to the worker. Published-file preparation does not establish that every mod supports a dedicated server: unknown client/server compatibility and generated loader runtime remain deployment blockers.

## ATLauncher

The adapter uses public pack/version metadata and per-version `Configs.json`. API access may be blocked by upstream bot protection; the plugin does not impersonate a browser to evade it. Browser-only downloads, unresolved selections, mods without supported independent hashes and config ZIPs without a pinned checksum block preparation. MD5-only metadata is not promoted to a verified SHA-256 baseline. Use an operator-reviewed custom server artifact if permitted by the pack's distribution terms. Loader runtime remains a separate preflight requirement.

## Custom HTTPS recipes and trusted local artifacts

An HTTPS recipe must use an exact host in `ARCTURUS_MODPACK_MANAGER_ALLOWED_CUSTOM_HOSTS`. Metadata requests validate public DNS, pin the address, disallow redirects/proxies and enforce 16 MiB while receiving. A local recipe must reside inside the operator-owned `ARCTURUS_MODPACK_MANAGER_CUSTOM_MANIFEST_DIRECTORY`. Local recipe files are never server-controlled live files.

Example pinned server ZIP recipe:

```json
{
  "schema": 2,
  "name": "Reviewed custom server pack",
  "version": "2026.11.1",
  "format": "zip",
  "runtime": {
    "minecraft": "1.7.10",
    "loader": "crucible",
    "loader_version": "EXACT_REVIEWED_VERSION",
    "java": 17,
    "runtime_prepared": false
  },
  "downloads": [
    {
      "url": "https://releases.example.net/server-2026.11.1.zip",
      "archive": true,
      "hashes": {"sha256": "REPLACE_WITH_THE_REAL_64_HEX_UPSTREAM_DIGEST"},
      "size": 123456
    }
  ]
}
```

Replace all example values, including the Java requirement, with verified release facts. The placeholder digest is intentionally invalid. The node must independently allow the artifact host and every legitimate redirect host. To use a pre-provisioned artifact on a node, replace `url` with `"artifact_id": "reviewed-server-pack"`; configure that ID's exact canonical path in the node configuration. Requests cannot select arbitrary local paths. Keep upstream checksum evidence independent from the live customized server.

For a file recipe, use `"format": "files"` and one entry per required file with a safe `path`, URL/artifact ID, supported hashes and optional known size. Duplicate paths and unsafe URLs are rejected. All required files must resolve. Do not use a custom recipe to bypass licensing or distribution restrictions.


A schema-2 catalog can retain old immutable baselines at the same source URL: replace the single recipe with `{"schema":2,"latest":"2.0","releases":[...]}`, where every release entry has its own exact `version`, `downloads`, optional `format`, `runtime` and `stable` flag. Entries must have unique versions; `latest` must point to an existing entry. Declare releases newest-first when no pointer is supplied. This permits selecting the original release for adoption even after a newer custom version is published. Custom sources have no public search index and report that capability explicitly.
