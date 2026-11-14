# Native bounded verification: first downtime patch

This patch uses only standard authenticated Wings `list-directory` and streamed `contents` requests. No live deployment, active operation, runtime selection or File Changes decision is changed by installing the source patch. Do not reload an in-flight worker to measure this change. Qualify it on a separate test server and let existing operations finish normally before the ordinary worker upgrade.

`Transaction::verify()` orders the explicit approved paths by directory and splits them into batches of at most 32. `Wings::hashBatch()` builds fresh parent/leaf metadata snapshots using one listing per needed directory per snapshot, streams each existing candidate through SHA-256, then takes an independent fresh snapshot. It returns only after both leaf and ancestor metadata agree. Successful, validated directory listings are necessary even for expected absence. Symlinks, invalid types, HTTP failures, changed or incomplete metadata, mismatching hashes, stalled/oversize/truncated streams fail closed. Only explicit candidates are hashed; listing a parent does not authorize reading other entries or protected worlds. Directory caches are local variables and cannot survive a batch, phase, write, operation or failed call.

Contents remain **serial (concurrency 1)** in this patch. RC2's installed Guzzle transport uses `Utils::addStreamHandler()` / `Proxy::wrapStreaming()` for `stream=true`; an async/pool API alone would not establish simultaneous body reads. True concurrent bounded streaming is deferred. Readers request at most 1 MiB, enforce the existing per-file 2 GiB cap, expected length, a 600-second wall deadline, 5-second read/connect timeouts, direct HTTP 200 and no redirects. Neither size nor mtime substitutes for SHA-256. The standard Wings API does not expose a pinned inode/file descriptor or atomic filesystem snapshot: changes visible in either snapshot or in expected digests are rejected, but an adversarial replace-and-restore entirely between API observations cannot be proven absent. The stopped maintenance guard and all independent safety boundaries remain necessary.

Deployment phases now distinguish `stopping`, `verifying_before_backup`, `backing_up`, and `verifying_after_backup`. Verification reports only fully checked batches, including expected missing paths; persistence occurs at most once per five seconds plus initial/final state. The existing console projection emits fixed verification labels and these real counts with its existing 15-second progress throttle. Failed batches publish no partial success. The new phases remain nonterminal: interrupted deployment detection retains the stopped recovery checkpoint rather than replaying apply. The explicitly confirmed prewrite-retirement command recognizes the new pre-backup phase while retaining its independent proof and no-backup gates.

All `Transaction::verify()` boundaries use fresh batches, including before/after backup, before/after staging, final files, runtime dependency checks and recovery. None of those passes was removed. Immediate mutation, permission and upload safety boundaries remain independent. See [Applying mutation batches](native-applying.md) for the subsequent bounded permission/chmod/rename optimization. `Native\Preparation::inventory()` was reviewed and intentionally retains its existing path-by-path protected-symlink classification in this first patch; it runs before stopping Minecraft. Its properties/world-root discovery, mod inspection and Crucible launcher inspection also stay independent. Crucible runtime and approved plan semantics are unchanged.

## Reproducible fixture measurements

Run with a disposable RC2 checkout containing its official locked dependencies and no `.env`:

```sh
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/native_batch.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/native_wings.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/native_deployment.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/livewire.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/console.php
```

Routine execution verifies the current request budget without repeating the historical serial implementation. Use `WINGS_COMPARE_BASELINE=1 PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/native_batch.php` to reproduce both sides of the comparison.

Local PHP 8.5.9 measurement: 2,048 small files across 16 shared nested directories plus one expected absence (2,049 explicit paths). Same fixture and client before/after, no artificial network latency:

| Method | list-directory | contents | Wall time |
| --- | ---: | ---: | ---: |
| Individual hash calls | 16,388 | 2,048 | 4.434 s |
| Batches of 32 | 550 | 2,048 | 0.528 s |

The fixture counts requests directly and disables Laravel's test-only unbounded request/response recorder, which would otherwise measure recorder retention rather than reader memory. A generated 64 MiB PSR response stream had 2,097,152 bytes of additional peak PHP allocation and maximum requested reads of 1,048,576 bytes. This proves the bounded hashing reader, not a real network throughput or full production memory benchmark. HTTP fixtures cover same-size changed content, parent replacement/symlink, denied listing, unexpected presence/absence, truncation, mid-batch failure and fresh caches on repeated calls. Native deployment fixtures also verify a pre-backup mismatch cannot request a backup or write files, plus existing rollback/Crucible scenarios. UI and console tests cover the new counts and phases. No production speedup is estimated from these synthetic times.

## Later steps, not implemented

Pre-upload can be added between verified private preparation and `guardAndStop()`, into an operation-owned isolated Wings stage. Bind it to the exact approved plan and stage hashes; do not include the stage in the backup or confuse it with approved live files. Revalidate live paths and the staged package after stopping and preserve the locked completed backup before any live mutation.

Batch rename/chmod can later replace the mutation loops inside `Transaction::apply()` and the Wings permission/rename adapters. Wings batch endpoints are not an atomic transaction. Journal the entire intended batch and original hashes/modes before sending it; after any response, timeout or disconnect, inspect every source/destination/mode to classify applied, unapplied and ambiguous members. Never blindly replay a batch with ambiguous outcomes. Retain the guard and exact backup for rollback or operator recovery, and verify the original Minecraft startup before declaring recovery successful.
