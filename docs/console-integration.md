# Console integration (Panel v1.0.0-rc.2)

The plugin adds **Update** after the native Start / Restart / Stop header actions through `Console::registerCustomHeaderActions(HeaderActionPosition::After, ...)`. It is visible only for a managed server and a root administrator, matching the existing manual update authority. The backend resolves and checks the current tenant again before redirecting to that server's existing administrator **Updates** tab. Opening this link creates no operation, stops nothing and grants no approval. Version selection, preparation, exact-plan review, locked backup, deployment and recovery remain the existing Native Pelican / Wings workflow. An active operation remains the same receipt, including a retained recovery checkpoint.

## Optional Panel patch

Audited upstream tag: [`v1.0.0-rc.2`, commit `f64a167eea349bfbb89cda8732eacf682a82a4b3`](https://github.com/pelican-dev/panel/tree/f64a167eea349bfbb89cda8732eacf682a82a4b3). Its [Console page](https://github.com/pelican-dev/panel/blob/f64a167eea349bfbb89cda8732eacf682a82a4b3/app/Filament/Server/Pages/Console.php) has header actions and widget registration; the existing [xterm component](https://github.com/pelican-dev/panel/blob/f64a167eea349bfbb89cda8732eacf682a82a4b3/resources/views/filament/components/server-console.blade.php) keeps its terminal private and offers no append hook. The optional append API is still required to write into xterm. A small native Console widget now provides status when that API is unavailable; no console component is cloned or rewritten.

The standalone [`patches/pelican/rc2-console-append.patch`](../patches/pelican/rc2-console-append.patch) adds only an append interface to that Blade component. Obtain the patch from this repository separately from the plugin package. The plugin installer **never applies it or overwrites Panel files**. Without it, Update and all normal updater pages continue working; the same authorized feed now drives a small **Modpack update** widget above the console. When the hook connects, that fallback hides and history is replayed into xterm without duplicates.

On a **test Panel** checkout at the exact RC2 tag, first inspect the patch and run from the Panel root (replace the example patch path):

```sh
git apply --check /path/to/modpack-updater-pelican/patches/pelican/rc2-console-append.patch
git apply /path/to/modpack-updater-pelican/patches/pelican/rc2-console-append.patch
php artisan view:clear
```

No Wings change or JavaScript asset rebuild is required: this small hook lives in Blade. Reload the Console page. For removal, use `git apply --reverse --check` then `git apply --reverse` with the same patch and clear the view cache. Recheck/rebase the patch when upgrading Panel; do not force it onto a different component revision.

The hook advertises `window.PelicanConsoleAppend = { version: 1, serverUuid, append }` and emits `pelican:console-ready` with that object as `detail`. `append({ serverUuid, source, id, text })` accepts one bounded plain-text line, verifies the server UUID, strips C0/C1 and bidi control characters, and ignores IDs already accepted for that source in this terminal instance. Its boolean return value acknowledges acceptance (including an already accepted ID). It exposes no terminal, socket, reset, power or command methods. Navigation retires the hook with the native terminal; native game output handlers are unchanged. This is an extension API for trusted Panel code, not a substitute for backend authorization.

## Plugin output and safety

Upgrade the plugin with Pelican's normal plugin migration procedure, including the new console-lines migration, and reload workers through the existing upgrade procedure. Phase and progress changes on the existing operation receipts generate a durable display projection in `arcturus_modpack_console_lines`. The projection is optional: a projection failure is reported privately and cannot approve an update, release a lock or interrupt deployment/recovery.

Only fixed messages and validated nonnegative counts enter this projection. No arbitrary progress message, exception, path, recipe, credential, signed URL or boot-log excerpt is rendered. Unknown totals stay unknown; no percentage or ETA is estimated. Preparation measurements come from the existing normalized progress snapshots. Native apply counts advance only after file verification, including verified disabled-mod moves. Repeated progress in the same phase is limited to at most one line per 15 seconds; phase transitions appear immediately. There is no per-file listing.

The server-specific session-authenticated feed checks the existing plugin view permission **and** Pelican's `WebsocketConnect` permission on every request. It also uses session authentication checks, the Panel's 2FA requirement and request throttling. It makes no Wings calls and works with Minecraft offline. Owners and appropriately permitted read-only subusers can see status; they gain no manual update authority.

A new terminal replays the last 40 recorded lines in order. An upgrade can seed the latest operation's current receipt if it predates this display projection; it does not reconstruct or invent missing historical transitions. Subsequent polls (normally every 3 seconds) request IDs after the last accepted line, at most 100 per page. A feed/network reconnect retains that terminal's cursor. A refresh creates a blank terminal and replays its recent history once. Neither cookies nor session storage can make a blank terminal accidentally skip history. Server navigation aborts the outstanding request, clears the timer and removes the old readiness listener. The terminal itself also deduplicates IDs. Existing native Wings log replay behavior is unchanged.

`restarting_original` means restore verification finished and startup is still pending. Only the terminal `restored` receipt with the existing verified recovery evidence announces a verified rollback. A failed/unverified recovery remains the existing retained safety checkpoint; the console does not report success or attempt recovery itself.

## Quick test on a disposable server

1. Upgrade the plugin and migrate it on an isolated test Panel. Before applying the patch, verify Update opens the correct Updates tab, both with and without a prepared plan. Check that a running operation is unchanged. Confirm an owner/subuser and an unmanaged server have no Update button; direct attempts to invoke the action are denied.
2. Apply the optional patch above. Prepare and explicitly approve an ordinary update with the existing workflow. Keep Console open. Verify `[Modpack]` phases share the existing xterm with game lines, including backup/apply while Minecraft is stopped. Refresh and temporarily interrupt the browser's network connection; each recovered line should appear once in that terminal.
3. Switch between two test servers while polling. Check that the old server's feed request is aborted and its UUID never appears in the new server's requests. Test another account's access to the feed URL; it must be denied without access to that specific server. Revoke a subuser's permissions and check the next request is denied.
4. On the disposable server only, use a deliberately failing candidate startup to exercise existing rollback. Restore completion must precede the pending-startup line; verified rollback must follow fresh original startup. Separately make recovery verification fail and verify the safety checkpoint stays retained and the console says recovery is unverified. Do not perform failure injection on production maty.

Automated checks:

```sh
bash scripts/test.sh
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/console.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/livewire.php
PELICAN_SOURCE=/tmp/disposable-panel php tests/integration/native_deployment.php
PELICAN_SOURCE=/tmp/disposable-panel node tests/console_output.mjs
```

The integration checkout must have RC2's locked Composer dependencies and no `.env`. Native tests use real Panel services with local HTTP/TLS Wings fixtures, including checks of console history during offline backups, verified success, restored startup and unverified recovery. They do not qualify a live Wings/Docker/Minecraft installation. CI checks the standalone patch applies to the exact upstream tag.

For the optional real xterm rendering smoke test, install Playwright and `@xterm/xterm@6.0.0` in disposable tooling directories and install Playwright's Chromium. Run:

```sh
PLAYWRIGHT_MODULE=/tmp/browser/node_modules/playwright/index.mjs \
XTERM_SCRIPT=/tmp/browser/node_modules/@xterm/xterm/lib/xterm.js \
PELICAN_SOURCE=/tmp/disposable-panel node tests/console_browser.mjs
```

`CHROMIUM_PATH` can select an already installed compatible Chromium. This test runs the actual RC2 game-output handler, patch hook and plugin client in Chromium with a real xterm buffer and fixture feed. It verifies interleaved game, backup, success and rollback lines, and saves `/tmp/arcturus-console-xterm.png`. It is a rendering smoke test, not a full authenticated Panel browser or live-server test.


## Applying progress and fallback

The native AboveConsole widget extension registers `ConsoleOutput`. Its view starts the same server-authorized feed regardless of hook availability. Without a matching v1 hook it shows the last eight recorded lines, using text-only rendering and an explicit connection-error state. With a working hook it hides the fallback and appends into the existing terminal. A late hook aborts the fallback poll and replays recent history to the new terminal; terminal IDs prevent duplicates. Navigation aborts the request and removes the listener. Neither display errors nor widget visibility grants deployment authority.

Applying stays the same operation phase; `progress.step` distinguishes prepared-file hashing, live verification, permission inspection, confirmed staging uploads, staging chmod, a separate post-upload live recheck, verified deployment paths and final verification. These fields are display state and contain no filenames/secrets. Upload totals count only installed candidate files; deployment totals include removals and both paths of an approved disabled-mod move. Step changes and final counters persist immediately, ordinary updates at most every five seconds. Console history uses the step in its phase identity so transitions are not swallowed by the existing 15-second same-step throttle.

The optional Chromium test now exercises both hook and fallback modes, refresh, native game output, offline progress and late hook attachment. Run it as documented above. No Panel patch is installed automatically.
