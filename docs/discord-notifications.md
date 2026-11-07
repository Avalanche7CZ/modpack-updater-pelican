# Discord notifications

Configure **Managed server → Edit → Discord Notifications** as a root administrator. Enable notifications, optionally enter a server webhook override and role ID, and select successful updates, failures, confirmed rollbacks or new releases. Without an override, the existing encrypted global plugin webhook is used. The global enable switch also applies. Existing global release/failure behavior remains enabled by default; rollback notices are opt-in.

Saved webhook tokens are encrypted with the Panel APP_KEY and excluded from serialized models/jobs. Only root administrator forms hydrate them, masked by default with Show/Hide and Copy controls. The form indicates whether a server override, global fallback or neither is configured. Leave the password field blank to retain the override, or select **Remove override and use global webhook**. Back up the Panel database and APP_KEY together. Discord settings are independent for every managed server and do not enable deployment.

**Send Test Notification** uses the webhook currently entered in the server form, including an unsaved URL. Blank input retains the saved override; **Remove override** tests the global fallback. Testing never saves the form, even if automatic notifications are disabled, and never pings roles. The encrypted outbox snapshots the test URL so the worker uses the requested destination. The form polls the latest test every three seconds and reports **Queued**, **Sending**, **Delivered**, **Failed**, **Rate Limited** or **Uncertain**, with its delivery ID and sanitized diagnostics. Queued means pending worker processing; Delivered requires HTTP 200 and a valid Discord message ID. History retains the result. No Minecraft files or power settings change.

A successful update generates one Czech/English embed after the existing startup verification returns and a verified deploy event is recorded. The original and target versions come from that event, not a possibly newer available release. The embed includes the server/pack label, success status, optional HTTPS release link and Arcturus Network footer. Startup verification is not proof that a world migration succeeded. Failed updates and verified restores use separate optional events with generic recovery wording; raw errors, host backup paths, signed URLs and credentials are not posted.

Role pings occur only in `content`; `allowed_mentions` permits only the configured role and explicitly disallows users/everyone/here. Test messages disallow all mentions. Server names, versions and release metadata cannot inject additional pings. Use a valid snowflake ID without mention syntax; whether a role is mentionable also depends on Discord permissions.

## Queue, recovery and errors

Run Pelican's normal durable queue worker and scheduler. Supported asynchronous queue drivers are Redis, database, SQS and Beanstalkd. A sync/null/non-durable queue is refused, so saving settings or completing an update never performs a synchronous Discord POST. Delivery jobs contain only an outbox ID; encrypted credentials and payloads remain in the Arcturus database. Existing `arcturus-modpack:recover` scheduling also reconciles pending Discord work.

For a missing test message:

1. Read the test status in the form. Unsupported sync/null queues and dispatch connection failures are reported explicitly. A test queued for over a minute has not been claimed by a worker.
2. Verify `QUEUE_CONNECTION` uses a supported durable driver and the worker listens on that connection and its configured queue. Run `php artisan queue:failed`, inspect the worker service status/logs, and use `php artisan queue:restart` after changing configuration or installing updated plugin code.
3. Verify the Panel cron runs `php artisan schedule:run` every minute and `php artisan schedule:list` includes `arcturus-modpack:recover` every five minutes. The form displays the last observed recovery execution (15-minute observation window). A manual recovery run also updates this timestamp; it does not prove cron is running. No observation is not proof of a stopped scheduler. Use a shared persistent cache for cross-process recovery observations and rate-limit locks.
4. HTTP 401/403 requires checking or replacing the webhook, 404 means the webhook no longer exists, and 429 waits until the displayed retry time. Connection/timeout diagnostics call out DNS, outbound HTTPS, TLS and proxies. Uncertain requests require checking the Discord channel before resending.

A delivered test proves a worker processed that delivery; production worker/cron health cannot be confirmed from a plugin checkout. Integration tests use a real Laravel database queue worker and recovery command against disposable SQLite with fake Discord HTTP responses.

Each source event has one unique durable delivery record. Duplicate queue jobs cannot repeat a sent request. Requests use `wait=true` and only a valid returned message ID confirms delivery. HTTP 429 respects Discord's retry delay, with bounded attempts and per-webhook/global cooldowns. Permanent HTTP errors fail visibly. No HTTP redirect is followed. Disabling notifications or changing the webhook cancels pending automatic delivery.

Discord execute-webhook requests do not provide this plugin with a transaction spanning Discord and the Panel database. On timeouts, ambiguous 5xx/invalid acknowledgements or worker interruption after claiming a POST, status is **uncertain** and automatic replay is suppressed. Check the Discord channel before sending anything manually. This avoids duplicate pings at the cost of requiring manual review for uncertain delivery. A new Test Notification is a separate deliberate message, not reconciliation of a production update.

Notification failures are recorded separately in History. They do not set Minecraft update failure, change the installed version or invoke rollback. Pending/rejected safe requests survive queue restarts. Work interrupted before event-to-outbox handoff is reconciled for recent marked events; historical releases from before this feature are not replayed.

Official references: [Execute Webhook and confirmation](https://docs.discord.com/developers/resources/webhook#execute-webhook), [rate limits](https://docs.discord.com/developers/topics/rate-limits), [allowed mentions](https://docs.discord.com/developers/resources/message#allowed-mentions-object).

Validation uses only fake Discord/Wings HTTP responses and disposable files/SQLite. It includes native Filament settings/save/test actions, event/queue deduplication, mentions, encryption, rate limits, errors and the actual retained offline deployment job with a synthetic startup log. No production webhook, Minecraft server or real Discord message was used.
