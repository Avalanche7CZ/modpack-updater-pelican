# Real TFG Modern server mod upgrade evidence

These fixtures contain the nine changed **server** JARs between the official [0.13.15 lockfile](https://raw.githubusercontent.com/TerraFirmaGreg-Team/Modpack-Modern/0.13.15/pakku-lock.json) and [0.13.17 lockfile](https://raw.githubusercontent.com/TerraFirmaGreg-Team/Modpack-Modern/0.13.17/pakku-lock.json). They are a subset, not installable serverpacks or live-server observations. Do not use them for production deployment.

Both lockfiles were fetched on 2026-11-07. Their complete SHA-256 hashes are recorded in `provenance.json`. Projects were compared by their upstream Pakku project keys solely to select changed artifacts. CLIENT-only Reliable EMI is excluded. All eighteen original JARs were downloaded to private temporary storage; every available upstream SHA-512/SHA-1/MD5 hash was checked before metadata extraction. No JAR was executed and no test suite was run.

Each release fixture records the actual JAR SHA-256, upstream download URL and hashes, embedded metadata SHA-256, complete `META-INF/mods.toml` ID set and per-ID versions. ID matching in the implementation uses the checksum-bound embedded metadata, **not** Pakku IDs, filenames, project names or version-number heuristics. No mod binaries are redistributed here.

| Embedded ID set | 0.13.15 JAR | 0.13.17 JAR |
| --- | --- | --- |
| `ae2` | `appliedenergistics2-forge-15.5.5-uelm.jar` | `appliedenergistics2-forge-15.5.6-uelm.jar` |
| `everycomp` | `everycomp-1.20-2.9.27-forge.jar` | `everycomp-1.20-2.9.28-forge.jar` |
| `expatternprovider` | `ExtendedAE-1.20-1.4.21-forge.jar` | `ExtendedAE-1.20-1.4.22-forge.jar` |
| `reliable_recipes` | `reliable_recipes-3.7.1+1.20.1-forge.jar` | `reliable_recipes-3.8.2+1.20.1-forge.jar` |
| `reliable_remover` | `reliable_remover-3.8.1+1.20.1-forge.jar` | `reliable_remover-3.8.3+1.20.1-forge.jar` |
| `sophisticatedbackpacks` | `sophisticatedbackpacks-1.20.1-3.26.14.2226.jar` | `sophisticatedbackpacks-1.20.1-3.26.15.2228.jar` |
| `sophisticatedcore` | `sophisticatedcore-1.20.1-1.5.14.2419.jar` | `sophisticatedcore-1.20.1-1.5.16.2433.jar` |
| `tfc+tfc_textile+tfcambiental+tfg` | `TerraFirmaGreg-Core-Modern-0.9.29.jar` | `TerraFirmaGreg-Core-Modern-0.9.31.jar` |
| `voicechat+voicechat_api` | `voicechat-forge-1.20.1-2.6.24.jar` | `voicechat-forge-1.20.1-2.6.26.jar` |

TFG Core bundles four IDs with different per-ID versions; Voice Chat bundles two. These are one physical JAR each. The fixtures preserve those actual versions, including `voicechat_api` 2.6.25 in the target 2.6.26 JAR. The implementation must not derive these values from filenames.

To build a future regression scenario, use the two `files`, `identities` and `metadata` maps as baseline/target evidence and initially use the baseline JAR hashes/metadata as the installed state. Expected representation: nine logical `mod_upgrade` rows, each with explicit conditional physical members; no unrelated removal review. Change an installed hash with matching freshly extracted embedded metadata to represent a local modification, protect a member path to preserve the whole mod, or introduce duplicate IDs to require review. Compare directly from the chosen baseline to target; no intermediate release is involved.

**Verification status:** source hashes and embedded artifact metadata were collected and verified. The CI follow-up now runs `tests/mod_replacements.php` against these real nine pairs and covers logical grouping, bundled identities, modified local state, protections, disabled/absent mods, duplicates and missing proof. `tests/mod_transaction.php` separately exercises actual small synthetic JARs, conditional active-JAR snapshots, journal failure after publication and full backup restoration. The native deployment suite explicitly rejects unqualified logical upgrades and retains its regular-file backup/start/restore scenarios. The 22,000-file browser suite covers file decisions, not full logical-mod production qualification. Production deployment of `upgrade_mod` remains blocked pending that qualification.
