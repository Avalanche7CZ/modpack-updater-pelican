# Applying: independent progress and bounded Wings mutations

For the subsequent staging-upload, DB/budget telemetry and Comparison optimization, see [native performance measurements](native-performance.md). Historical results below remain tied to their original commits and fixtures.

This follows `8d3a107` on main. That commit already made every `Transaction::verify()` use fresh directory-sharing hash batches, including Applying's initial check, post-upload recheck and final verification. It did not batch permission reads, chmod or rename, and its first checkpoint followed local hashing and permission inspection.

Applying still uses the same operation phase and approval/backup gates. `progress.step` now reports:

1. Hashing prepared files, with a count of candidates actually checked in private Panel storage.
2. Checking approved live paths before staging, including expected absence.
3. Reading original permissions, with a count of existing files inspected.
4. Uploading **and SHA-256 verifying** Wings stage files, against the number of files actually requiring upload.
5. Setting and verifying staged permissions, against files requiring preserved modes.
6. A separate live-path recheck after upload.
7. Deploying confirmed paths, including removals and both paths of an approved disabled-mod move.
8. Independently verifying final deployed paths.

Progress starts before `stage_intent`. It is optional display state: step transitions and final counts persist immediately, ordinary progress at most every five seconds. It neither authorizes writes nor substitutes for the encrypted recovery journal. No total percentage or ETA is estimated. Phase and step messages in console history use fixed vocabulary and validated counters.

## Work that changed

`MutationBatches` adds optional native capabilities without changing `Files` or the fallback serial adapter contract. Wings permission reads share directory listings within a maximum 32-path batch, with fresh snapshots on both sides of inspection. No metadata cache persists through a write or another method call.

Staged chmod requests contain at most 32 independent files. Rename requests contain at most 16 independent source/destination pairs; overlapping/dependent pairs are rejected. Each deployment chunk revalidates live hashes and modes and candidate hashes. Original paths are retired in one batch; their saved hashes are verified before a separate batch installs candidates. Then target hashes, expected absence and preserved modes are verified. Immediate disabled-mod renames retain their existing serial behavior. Final verification is still independent.

The caller durably saves the **entire intended chmod/rename batch before its request**. The existing encrypted `native_checkpoint` object can now carry `chmod_batch_intent`, `retire_batch_intent`, `replace_batch_intent` and `batch_verified`, with bounded modes/moves/paths arrays. Top-level recovery fields and the operation phase machine are unchanged. Compatibility was checked against native automatic recovery, `RecoveryVerification`, interrupted-worker handling and prewrite retirement: recovery does not parse/replay checkpoint steps; it restores the exact locked backup using the existing original file/runtime receipts, checks the original launcher and verifies a fresh Minecraft start. Old single-file checkpoints remain valid.

A batch is not atomic. Error, timeout, a partly executed request or a no-op HTTP 204 cannot advance to the next batch unless actual postconditions verify. The request is never blindly retried. Existing automatic backup recovery or the retained stopped checkpoint handles ambiguity. Source, staging and live paths are still checked for symlinks; no direct volume access or host tooling is introduced.

## Fixture and reproduction

`tests/integration/apply_batches.php` uses real RC2 daemon repositories and the actual Wings/Transaction classes against an in-memory HTTP boundary. Default count 128 keeps regression runs short. Its optional comparison reads immutable `8d3a107` source with `git show` into an isolated PHP namespace; it never checks out/reset files. The only baseline instrumentation adds read-only step timing callbacks. The baseline commit must be present locally.

```sh
PELICAN_SOURCE=/tmp/disposable-rc2 \
APPLY_COMPARE_BASELINE=1 APPLY_BENCH_COUNT=2048 \
php tests/integration/apply_batches.php
```

The measured case has **2,048 uploads and 2,080 deployment paths** (32 removals), all small files in shared nested directories. The fixture counts endpoint calls directly and disables Laravel's unbounded test-only response recorder. It is a zero-network-latency synthetic benchmark; it excludes production DB/receipt persistence, real disk/network latency and a live Minecraft server. No production speedup follows from its wall times.

Measured on local PHP 8.5.9 against the official RC2 lockfile (one sequential before/after run):

| Step | Wings requests, baseline → current | Seconds, baseline → current |
| --- | --- | ---: |
| Prepared-file verification | 0 → 0 | 0.036 → 0.044 |
| Initial live verification | list-directory 390 → 390; contents 2,080 → 2,080 | 1.087 → 2.249 |
| Reading permissions | list-directory 6,240 → 390 | 10.561 → 1.389 |
| Stage directory creation | list-directory 11 → 11; create-directory 3 → 3 | 0.004 → 0.005 |
| Upload + stage chmod | list-directory 30,720 → 19,200; write 2,048 → 2,048; contents 2,048 → 2,048; chmod 2,048 → 64 | 39.093 → 40.999 |
| Live recheck after upload | list-directory 390 → 390; contents 2,080 → 2,080 | 1.518 → 1.865 |
| Deploying paths | list-directory 99,264 → 7,620; contents 6,208 → 8,256; rename 4,128 → 258 | 242.794 → 32.355 |
| Final verification + cleanup | list-directory 392 → 392; contents 2,048 → 2,048; delete 1 → 1 | 1.887 → 1.844 |

Total: **160,100 → 47,280 requests; 297.014 → 80.755 seconds**, including the initial stage-existence check. The new upload portion alone took 38.529 seconds; its separate stage-chmod portion took 2.470 seconds. Upload remains the largest step and its combined wall time did not improve in this large fixture. It includes independent per-file staging metadata and SHA-256 reads, so this is not a raw network transfer measurement. Deployment performs 2,048 additional candidate-content checks before retiring originals: metadata savings were not achieved by removing hashes. Raw counts and timings are retained in [the 2,048-upload measurement JSON](measurements/native-applying-2048.json). A separate [1,024-upload run](measurements/native-applying-1024.json) also records per-step results; wall times vary with local fixture overhead and load.

The fixture additionally checks progress before the first checkpoint, distinct upload/deployment totals, partial rename with HTTP 204, no-op rename with HTTP 204, a mutation error after its first member, a chmod no-op, and no automatic mutation replay. Native deployment scenarios verify actual rollback orchestration for partial/no-op batches and preserve Crucible/Java/runtime decisions. Console/widget tests and Chromium cover authorized feeds, xterm/fallback, refresh, late hook attachment and ordinary game output.

## Remaining per-file work and next patch

Uploads remain serial and each upload still performs its independent byte/SHA-256 verification. Batching chmod/rename does not turn upload into a multi-file transfer. Content hashing still reads every required file; metadata sharing never replaces a hash. Some staging checks, directory creation and disabled-mod moves also remain individual requests. The benchmark's upload step includes per-file stage metadata/hash inspection, not just time sending bytes, so it cannot establish raw network transfer as the sole bottleneck.

A separate pre-upload patch can stage the verified package while Minecraft runs, before `guardAndStop()`. It must first define operation-owned stage identity bound to the exact approved plan and artifact digests, capacity checks, expiry and orphan cleanup, cancellation/retry ownership, and exclusion from the server backup. After stopping, revalidate the approved live paths and staged files; require the exact completed locked backup before live mutation. Do not reuse pre-upload metadata as approval or recovery proof. That change is not implemented here.

The Console fallback and append-hook deployment boundary are documented in [console integration](console-integration.md). The plugin never applies a Panel patch. No production Panel or active GTNH operation was changed for this work.
