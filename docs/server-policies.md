# Per-server protection policies

Only root administrators can edit these policies. Owners and authorized subusers can view a server's rules in the isolated Arcturus server page. Saving a rule changes database policy, never Minecraft files, power, egg, image or startup settings. Automatic deployment remains disabled by default; remote apply/rollback is still unavailable pending transaction qualification.

## Choose files or edit rules

Prepare the exact installed baseline, then compare the installation. **Mods & Files** shows a timestamped server inventory, upstream/current/target hashes, mod IDs, metadata name/version/loader, disabled state and the effective policy. Switch off **Mods only** to manage configuration and other files. Search by path, mod ID or name; select individual rows across pages and use **Set policy for selected files**. Destructive bulk policies require dependency-review confirmation.

Single-ID mod policies are saved against the stable metadata ID, so they survive renamed JARs. Missing or multi-ID metadata uses an exact-path rule instead; it is never guessed from a filename. Explicit administrator mappings remain available on Edit. Metadata formats include Fabric, Quilt, modern Forge/NeoForge TOML and legacy Forge `mcmod.info`. Names/versions remain unavailable when metadata is absent or unresolved. Bundled IDs and multiple target candidates are explicitly ambiguous. Inventory observations do not prove runtime compatibility.

**Protection Rules → Edit protection rules** provides the advanced editor. Add, duplicate, reorder, disable or delete rows. Each row has scope, selector, action, priority, optional classification and comment. **Test rule** previews matches against the saved inventory without saving, including each file's existing effective policy. Refresh comparison after live file changes. Test results cap display at 250 files but show the full match count.

Templates are optional, editable starting points: Empty, Conservative, Mostly upstream-managed, Custom mod-heavy, GTNH / Crucible and TFG Modern. Selecting a template replaces the unsaved rule list, not other servers' policies. New adoption drafts initially show Arcturus Network rules. Existing records receive no default rules on upgrade.

## Precedence and actions

The highest matching scope wins: **exact path → mod ID → mod filename → directory → glob → loader metadata → extension**. Deeper directories win within directory rules. Next, higher priority wins; the later row breaks remaining ties. Exact files therefore override broad globs even if the glob has higher priority. Mod-ID rules override broad filename defaults after upstream renames. The preview lists all matching rule numbers, the winner and its origin. A disabled row does not participate. Optional classification matches the original three-way file state before policy application.

Globs support `*` and `?` within a directory, and `**` across directories. Bracket expressions are not supported. Paths and globs are case-sensitive on the Linux server; mod IDs, loader and extensions compare case-insensitively.

| Action | Result |
| --- | --- |
| Keep local | Retain the installed file; do not add an absent target file |
| Use upstream | Use the target, explicitly replacing local customizations or removing an obsolete file; still requires a fresh reviewed deployment plan and backup |
| Keep disabled | Skip an absent mod or plan enforcement for a present active JAR; non-mod disabling is blocked |
| Remove | Plan explicit removal if present; requires administrator dependency review and deployment approval |
| Review | Block a changed file until an administrator chooses a resolution |
| Merge | Request a supported text merge or manual resolution; never report unsupported formats as merged |
| Keep local extras | Preserve files owned by neither upstream version; normal comparison applies elsewhere |
| Follow upstream | Apply genuine three-way comparison; does not mean overwrite local changes |

A missing or disabled mod stays absent across known identity renames. A generic upstream replacement rule cannot reactivate it. Multiple JARs claiming the same ID, unknown deleted upstream mod identities and ambiguous target identities require review. Resolving such cases needs explicit mappings and a fresh comparison; do not rename random JARs to manufacture identity.

## Safety boundaries

Ordinary policies cannot override world data, internal recovery metadata, unsafe relative paths or an unscanned directory. Worlds are detected from bounded `server.properties` reads (`level-name`, Nether/End variants) and dimension/world markers such as `DIM*`, `region`, `playerdata`, `level.dat` and `session.lock`. Escaped or unsafe world names fail closed rather than being guessed. World/runtime migration is a separate manual operator workflow. Loader/Java/egg/image/startup compatibility and backup requirements are never bypassed by a file rule.

Launcher names, `server.properties`, player/admin identity files and names containing Arcturus/Paradigm/Crucible/Fabric are **not immutable planner exceptions**. Optional templates preserve identity files and server properties, review scripts/JVM arguments and upstream removals, and preserve customized configuration. Administrators may edit or remove those defaults. Script files may be reviewed as files; the worker never executes them.

Worker inventory has a separate read-capability boundary. Unknown directories are reported as unscanned and protected, not silently treated as absent. Node operators may configure `managed_directories` and `excluded_directories` per server in the worker config to include custom utilities; world invariants always apply. These are inventory permissions, not duplicate update policies. Update the worker script and restart its isolated service to obtain the new name/version metadata and inventory configuration support. Older workers still supply IDs/hashes; unavailable metadata is shown explicitly.

## Review, persistence and upgrades

File Changes supports **Keep local**, **Accept upstream** and supported `.properties` merge review. Mods & Files also supports bulk policies. Saving invalidates the old preview and approval, increments the server's policy revision and records an audit event with actor and before/after values. A fresh comparison is attempted immediately; if the node is unavailable, the policy remains saved and approval stays invalid. Recompare when the node is reachable.

Policy changes are rejected during preparation/deployment and on stale editor revisions. A comparison cannot resurrect an approval invalidated by a concurrent policy edit. Preview and the retained local deployment engine use the same normalized policy context; mismatched decisions abort before mutation. Per-server JSON rules, disabled IDs and manual mappings persist through release selection, re-association and process restarts.

The additive migration preserves existing rule JSON, disabled IDs and history; it invalidates old previews/approvals because precedence changed. Existing rules without a scope infer exact-path or glob semantics. No rule is copied to another server. Before enabling any future deployment capability, review the new plan and explicitly choose a template if the previous implicit protections are desired. No reference-plugin data is read or modified.

Text merging remains strict single-line `.properties` review, with no automatic write-back. Mod dependency resolution is not a dependency solver: removal confirmation records human review, not proof that the remaining pack starts. Live Wings apply/rollback, real Minecraft startup/world migration and MariaDB remain separate unverified integration boundaries.

## Editing managed-server settings

Edit Managed Server includes a **Save changes** action in the header and sticky Save/Cancel actions below the form. The existing Pelican server binding, provider, project/source, installed version, ZIP filename pattern, filesystem backend and saved policies are editable. Changing the server/release association or filename pattern invalidates its verified baseline, release selection, inventory, previews and approvals; prepare a new installed baseline before continuing. Existing protection rules, operation history and retained stages are preserved. Rebinding also clears the former server's runtime, backup reference and filesystem pins. Local access is revalidated through the approved node setup; this form cannot grant permissions or accept arbitrary volume paths. Active operations or an in-flight release check block conflicting saves. These settings never edit live Minecraft files, startup commands or Java settings, and do not authorize deployment. The [manual workflow](manual-deployment.md) requires separate qualification and exact approval.

## KubeJS updates

TFG Modern and TFG + Arcturus presets no longer require manual review for every `kubejs/` change. The verified installed upstream manifest establishes ownership. An unchanged local file follows upstream updates or deletion; new upstream files are added. Local-only scripts and unchanged upstream files with local edits stay local. A local/upstream collision, including deletion of a locally edited upstream file, requires **Keep local** or **Use upstream**. Exact file protections and immutable runtime/world exclusions still apply.

**View text diff** compares the current local snapshot with verified retained target staging for UTF-8 JavaScript, JSON, SNBT, mcmeta, text, properties, TOML and CFG files up to 64 KiB. It displays a bounded changed region; it never writes, concatenates or merges anything. Binary NBT, large files, unavailable stages and stale hashes have no text preview. Remote nodes need the updated `node-worker/worker.py` to read staged KubeJS text; an older worker leaves the diff unavailable while policy choices still work. Review unsupported files outside this view before choosing a policy. KubeJS properties files also use these two choices, not the configuration merge preview.

Decisions save exact file policies, invalidate approval and queue a fresh comparison. File Changes retains 50-row pagination and filters; changing a filter returns to page one. While comparison runs, prior evidence remains visible and the operation must finish before another decision. Keep local preserves the local version for future updates; Use upstream authorizes that file's upstream replacement or removal in a future reviewed plan. Neither action deploys files. The [manual same-host workflow](manual-deployment.md) consumes a fresh exact plan only after its runtime, backup, transaction and restore gates pass. Remote deployment remains blocked. Automatic same-host maintenance requires explicit per-server scheduling opt-in; see [manifest updates](manifest-updates.md).

Existing saved policies are not silently rewritten. On an existing TFG server, review **Protection Rules → Edit protection rules** and remove the old directory-wide `kubejs` Review rule, or select the updated TFG template and review the resulting list before saving. The updated template includes a removal-only Follow upstream rule for `kubejs` so the generic deletion-review rule does not block unchanged obsolete scripts. Preserve any intentional exact protections and custom rules when updating a preset.
