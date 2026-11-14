# Native Applying and Comparison measurements

Baseline: GitHub `main` **695318811360f1c3c617383571d833dc1a04c590**, verified with `git ls-remote` on 2026-11-14. The uptime intersection fix from that commit is retained. These changes use standard authenticated Wings endpoints only. They do not upload while Minecraft is running, change backup exclusions/restore rules, follow world paths, skip SHA-256, or extend the 10,800-second job timeout.

## Staging and persistence boundaries

Staging uploads use at most **four files**, normally at most **64 MiB** combined; a larger permitted file occupies its own batch. Only absent numeric leaves in `.arcturus-update-<operation>/new` are accepted. The existing local-stage manifest/path validation still runs first. Each batch independently inspects fresh parent/leaf metadata, streams its writes serially, inspects fresh metadata, reads **every uploaded file through SHA-256**, and takes another independent snapshot. Upload metadata never survives the method call or enters a live mutation. A missing staging directory, existing leaf, symlink, failed/no-op/partial write, bad hash, or changing metadata aborts apply. HTTP success alone never advances the batch.

The complete upload batch intent is encrypted and committed before its first write; only a fully verified batch receives `upload_batch_verified`. Rename/chmod intents and all existing live hash/permission checks remain in place. All staging uploads must verify before live retirement begins. After a failure the code never retries a write or applies the next batch. Existing exact-backup recovery or the retained stopped/manual-intervention receipt handles the result.

`DeploymentReceipt` checks the execution ID and terminal state under a row lock and commits heartbeat plus checkpoint in **one update**, without transaction retry. It merges fresh context and decodes the encrypted context once per checkpoint. A worker that loses its execution lease also exits the failure path without taking over a newer execution's recovery. Stream/metadata heartbeats are limited to once per ten seconds; mutation requests still force a fresh ownership read. A checkpoint or progress save also supplies the heartbeat, so the immediately following request does not write another heartbeat. No per-file progress persistence was introduced: progress remains time-throttled (five seconds, step boundaries and final counts), independently of necessary mutation intents.

## Applying budget

The budget starts at entry to native deployment, before preview, stopping and backup. It subtracts elapsed prior work from the unchanged job timeout and reserves:

- target boot: configured startup timeout + acknowledgement timeout + 120 seconds for settings/sync;
- existing recovery: configured restore timeout + the same boot allowance + 600 seconds for stopping + at least 600 seconds for recovery verification.

With defaults this leaves an absolute Applying deadline **3,840 seconds after job start** (not 3,840 additional seconds after backup). The receipt records actual prior elapsed time, the deadline and both reserves. A slow backup can therefore leave insufficient time to begin Applying. This is deliberate admission control, not an ETA.

A unit defaults to 120 seconds (`native_apply_unit_seconds`, clamped to 1–600). Each local/remote verification batch, staging setup/upload/chmod batch, disabled-mod move, live retire-and-replace batch and cleanup is admitted only if its **whole maximum duration** still fits before the deadline. Nested verification never resets the parent mutation deadline. Applying requests use the remaining shared deadline, and streaming checks it between reads/progress callbacks. Exhaustion at a verified boundary starts no next unit; exhaustion or transport failure within a unit retains its intent and enters existing recovery without replay. The Applying deadline is detached before recovery so it cannot prevent restoring the original installation.

These are bounded HTTP/stream units and reserved recovery time, not a guarantee against a hung DB connection, worker termination, unavailable Wings, a restore exceeding its configured timeout, or recovery verification taking longer than its allowance. Such failures retain the existing stopped recovery checkpoint. Set `native_recovery_verification_reserve` from a **measured copy rehearsal** if critical-file verification needs more than 600 seconds; increasing it reduces the Applying budget.

## Recorded measurements

The deployment status displays real file counts, recorded elapsed time, endpoint/work counters, transferred payload bytes, failures and phase times. The backup wait is separate from `verifying_before_backup` and `verifying_after_backup`. Applying subphases include local verification, live verification, permissions, upload, staging chmod, live recheck, deployment and final verification. Endpoint categories independently distinguish `list-directory`, `upload` (`write`), `hash-read`/`text-read` (`contents`), `rename`, `chmod`, directory creation/deletion, DB checkpoints, DB heartbeats and DB progress.

Work times exclude nested measured work (for example heartbeat DB time inside a streamed read); phase times are inclusive wall times. Do not sum phase times and work times together. Bytes count application payload, not TLS/HTTP overhead. Counters include failed calls; an in-flight call's elapsed time is complete only when it returns. Snapshots are periodically persisted and retained on failure/completion. They are diagnostics, never authorization or recovery proof. No overall percentage or predicted downtime is calculated.

`offline_to_verified_boot_seconds` is available only when both the stopped-container guard and a target/original Minecraft boot were actually verified. It starts when offline is confirmed, excludes time spent stopping, and ends at verified boot (the original boot after recovery, when applicable). It is not a measurement of when a public player can reconnect; maintenance-release/sync can finish later. It remains null for the standalone transaction fixture and for unverified recovery.

An operator can export only the diagnostic subset, without contacting Wings or changing the operation:

```sh
php artisan arcturus-modpack:native-measurements OPERATION_UUID > native-measurements.json
```

## Reproducing the synthetic comparisons

Use a **disposable official Pelican rc.2 checkout without `.env`**, with its Composer dependencies installed, and PHP 8.4/8.5 including SQLite, sodium, curl, zip, intl and bcmath. The bootstrap refuses an operator `.env`; production credentials and databases are not used. The immutable baseline must exist locally (`git fetch origin main` if needed). HTTP fixture recording is disabled to avoid measuring Laravel's unbounded test recorder.

```sh
PELICAN_SOURCE=/tmp/disposable-panel \
APPLY_COMPARE_BASELINE=1 APPLY_BASELINE=6953188 APPLY_BENCH_COUNT=2048 \
APPLY_BENCH_MIXED=1 APPLY_BENCH_DB=1 \
APPLY_BENCH_OUTPUT=/tmp/native-staging-2048.json \
php -d memory_limit=1G tests/integration/apply_batches.php

PELICAN_SOURCE=/tmp/disposable-panel INVENTORY_COMPARE_MAIN=1 \
INVENTORY_BENCH_COUNT=2048 INVENTORY_BENCH_OUTPUT=/tmp/native-inventory-2048.json \
php -d memory_limit=1G tests/integration/native_inventory.php
```

Both inputs include **2,016 small configs and 32 valid uncompressed JARs** (24 with 1 MiB and eight with 8 MiB payloads). Upload target content totals **93,377,107 bytes**. Applying additionally removes 32 small files. Comparison adds expected absence, leaf/ancestor symlinks and excluded-world/unapproved candidates; it hashes the same 2,048 existing candidates and reads `server.properties`. The fixture directly counts endpoint requests and payload bytes.

The Applying DB mode exercises actual encrypted Eloquent receipts, ownership reads, checkpoint/progress updates and console projection using **in-memory SQLite**. The baseline uses the exact heartbeat/checkpoint work from `main`; the current side uses `DeploymentReceipt`. This measures serialization/encryption/framework/query cost, not MariaDB/network latency, fsync or crash durability. It is substantially more work than the earlier request-only benchmark. The measured environment was PHP 8.5.9, official rc.2 source/lockfile, Laravel 13.38.0 and Guzzle 8.3.0. The transport is a zero-latency HTTP fake, not a real Wings server or real Minecraft disk.

Comparison results ([raw JSON](measurements/native-inventory-2048.json)):

| Inventory only | main | changed |
| --- | ---: | ---: |
| `list-directory` | 12,236 | 392 |
| `contents` | 2,049 | 2,049 |
| content bytes | 93,377,124 | 93,377,124 |
| seconds | 19.164 | 1.127 |

The full inventory response and resulting `UpdatePlanner` plan are exactly equal, including missing paths, skipped symlinks, protected descendants, requested ordering and excluded roots. Batched comparison classifies symlinks individually and never follows them. Failed reads, truncation, appearing files, changed leaves/parents/links and denied listings reject the batch; they never become missing files. Fresh metadata is shared only inside one 32-path batch, and every existing regular candidate is still hashed. No directory is recursively enumerated.

Applying results ([raw JSON](measurements/native-staging-2048.json)):

| Applying endpoint | main | changed |
| --- | ---: | ---: |
| `list-directory` | 28,358 | 14,534 |
| `write` | 2,048 | 2,048 |
| `contents` | 16,512 | 16,512 |
| `create-directory` | 3 | 3 |
| `chmod` | 64 | 64 |
| `rename` | 258 | 258 |
| `delete` | 1 | 1 |
| **total** | **47,244** | **33,420** |

Only the upload phase's directory reads changed: **18,432 → 4,608**, from nine per file to nine per four-file batch. Both sides send **93,377,107 bytes** and read **747,017,624 bytes** across all independent SHA-256 passes. Extra candidate/live/final/retired-file hashes were retained.

| Applying phase (inclusive wall seconds) | main | changed |
| --- | ---: | ---: |
| Initial fixture setup / stage-existence check | 0.043 | 0.008 |
| Local stage SHA-256 | 0.115 | 0.224 |
| Initial live verification | 23.662 | 1.533 |
| Permission inspection | 15.268 | 0.970 |
| Stage directory setup | 0.004 | 0.032 |
| Upload + SHA-256 + checkpoint work | 259.596 | 74.206 |
| Staged permissions | 34.030 | 6.002 |
| Live recheck | 24.423 | 1.631 |
| Retire/install/verify | 244.920 | 41.781 |
| Final verification + cleanup | 29.974 | 1.761 |
| **total** | **632.035** | **128.148** |

DB instrumentation is orthogonal to these inclusive phases:

| DB work | main | changed |
| --- | ---: | ---: |
| Checkpoint calls reaching DB | 4,551 | 1,479 |
| Checkpoint exclusive seconds | 240.217 | 79.959 |
| Heartbeat calls reaching DB | 87,943 | 2,374 |
| Heartbeat exclusive seconds | 339.293 | 10.263 |
| SQL statements, incl. fixture setup/progress/console | 111,627 | 7,026 |

Current time-based progress cost was 2.187 seconds over 39 updates. Baseline progress is included in its phase/SQL measurements but was not separately timed. The baseline heartbeat/checkpoint cost totals about **92%** of its wall time; current checkpoint/heartbeat/progress cost totals about **72%**. This fixture therefore demonstrates a substantial serialization/DB cost in addition to directory-request overhead. The current upload subphase takes 58% of the fixture wall time, but raw write requests take only 1.107 seconds in a zero-latency fake. That does **not** demonstrate that production network upload dominates downtime or establish a production speedup.

These synthetic times **must not be used as an estimate of production downtime**. The standalone transaction does not stop/boot Minecraft or wait for a real backup, so its downtime is unmeasured.

## Exact GTNH copy rehearsal

No separated, authorized live Wings test server was available in this session. Production deployment was not started. Use this procedure before deciding on GTNH 2.8.1 → 2.9.0:

1. Provision an isolated test Panel/Wings/server with separate IDs, keys, backup storage and queue. Copy a consistent **stopped** GTNH 2.8.1 snapshot using the operator's normal backup process, including the real configs, JARs, launcher, world and permissions. Keep public players, schedules and external integrations disabled on the copy. Do not substitute a production Panel/database or point the test server at the production volume. Protect/retain the same Crucible/runtime files and world roots as production; preserve the existing backup/rollback policy.
2. Install the candidate plugin on this test Panel and restart its queue worker so it loads the candidate source. Keep the 10,800-second job timeout. Prepare the actual verified GTNH 2.9.0 target. Export the reviewed plan ID, counts, target byte total, launcher/runtime/image/Java, backup exclusions and measured baseline hardware/storage/network details. Resolve genuine conflicts through the existing review flow.
3. Enable a dedicated access log at the **test Wings reverse proxy**, filtered to the test UUID. For each request record timestamp, HTTP method, `$uri` (without query arguments), status, `$request_time` and `$body_bytes_sent`. Never log Authorization headers. Group the file routes by endpoint. These timings corroborate endpoint counts; application stream bytes and DB timings come from the exported operation metrics. If using a DB profiler, scope it to this disposable test DB/process and do not export bindings or encrypted receipts.
4. Start exactly one approved deployment of the copy. Watch the deployment measurement panel while offline. Export `native-measurements` every 30 seconds to separate files and once at its terminal state. Record the exact operation UUID and locked backup UUID. Do not retry a failed/partial operation. On soft exhaustion let existing recovery finish; on `manual_intervention`, retain the stage, backup, journal and stopped guard for operator review.
5. Record pre-backup verification, actual backup wait, post-backup verification, each Applying subphase, list/write/content/rename/chmod counts and seconds, checkpoint/heartbeat/progress calls and seconds, payload bytes and `offline_to_verified_boot_seconds`. Confirm the boot identifies the expected loader/Java and GTNH version; independently check the copied worlds/dimensions/player data in game. A fixture pass or `Done` line alone is not that qualification.
6. On the disposable copy, rehearse a failed/partial stage and the existing exact-backup recovery. Confirm the original GTNH boot and protected data. Restore the initial copy before any additional comparison; never replay a retained apply. If comparing `main` with the candidate on real Wings, restore the **same initial snapshot**, prepare the **same target/plan**, and collect the same proxy log with each plugin version in separate runs.
7. Decide from the copy's measured phase times and variance, with room for the configured recovery path. If staging upload/read-back still dominates offline time, propose a **second PR** to stage while Minecraft is running, with its own freshness/fencing design. This PR does not implement that behavior. Do not compensate by disabling hashes or increasing the hard timeout.

Automated coverage includes corrupt/no-op/partial uploads, partial/no-op/error rename/chmod, ownership loss, uncertain DB writes with no retry, admission exhaustion before another batch, expiry within an upload unit, failed backup, pre-backup mismatch and actual existing recovery orchestration with virtual fixture time. It also covers comparison plan equality, protected symlinks and descendants, missing paths, scope/world exclusion and read/metadata failures. Actual Wings, MariaDB, GTNH boot/world migration and production downtime remain separate qualification.
