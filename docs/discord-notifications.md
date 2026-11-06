# Discord notifications

Configure **Managed server → Edit → Discord Notifications** as a root administrator. Enable notifications, optionally enter a server webhook override and role ID, and select successful updates, failures, confirmed rollbacks or new releases. Without an override, the existing encrypted global plugin webhook is used. The global enable switch also applies. Existing global release/failure behavior remains enabled by default; rollback notices are opt-in.

Saved webhook tokens are encrypted with the Panel APP_KEY and never hydrated into forms or serialized models/jobs. Leave the password field blank to retain the override, or select **Remove override and use global webhook**. Back up the Panel database and APP_KEY together. Discord settings are independent for every managed server and do not enable deployment.

Save settings before using **Send Test Notification**. This administrator action queues one bilingual test embed using saved settings, even if automatic notifications are disabled, and never pings roles. The UI confirms queuing; **History** records actual delivery status. No Minecraft files or power settings change.

A successful update generates one Czech/English embed after the existing startup verification returns and a verified deploy event is recorded. The original and target versions come from that event, not a possibly newer available release. The embed includes the server/pack label, success status, optional HTTPS release link and Arcturus Network footer. Startup verification is not proof that a world migration succeeded. Failed updates and verified restores use separate optional events with generic recovery wording; raw errors, host backup paths, signed URLs and credentials are not posted.

Role pings occur only in `content`; `allowed_mentions` permits only the configured role and explicitly disallows users/everyone/here. Test messages disallow all mentions. Server names, versions and release metadata cannot inject additional pings. Use a valid snowflake ID without mention syntax; whether a role is mentionable also depends on Discord permissions.

## Queue, recovery and errors

Run Pelican's normal durable queue worker and scheduler. Supported asynchronous queue drivers are Redis, database, SQS and Beanstalkd. A sync/null/non-durable queue is refused, so saving settings or completing an update never performs a synchronous Discord POST. Delivery jobs contain only an outbox ID; encrypted credentials and payloads remain in the Arcturus database. Existing `arcturus-modpack:recover` scheduling also reconciles pending Discord work.

Each source event has one unique durable delivery record. Duplicate queue jobs cannot repeat a sent request. Requests use `wait=true` and only a valid returned message ID confirms delivery. HTTP 429 respects Discord's retry delay, with bounded attempts and per-webhook/global cooldowns. Permanent HTTP errors fail visibly. No HTTP redirect is followed. Disabling notifications or changing the webhook cancels pending automatic delivery.

Discord execute-webhook requests do not provide this plugin with a transaction spanning Discord and the Panel database. On timeouts, ambiguous 5xx/invalid acknowledgements or worker interruption after claiming a POST, status is **uncertain** and automatic replay is suppressed. Check the Discord channel before sending anything manually. This avoids duplicate pings at the cost of requiring manual review for uncertain delivery. A new Test Notification is a separate deliberate message, not reconciliation of a production update.

Notification failures are recorded separately in History. They do not set Minecraft update failure, change the installed version or invoke rollback. Pending/rejected safe requests survive queue restarts. Work interrupted before event-to-outbox handoff is reconciled for recent marked events; historical releases from before this feature are not replayed.

Official references: [Execute Webhook and confirmation](https://docs.discord.com/developers/resources/webhook#execute-webhook), [rate limits](https://docs.discord.com/developers/topics/rate-limits), [allowed mentions](https://docs.discord.com/developers/resources/message#allowed-mentions-object).

Validation uses only fake Discord/Wings HTTP responses and disposable files/SQLite. It includes native Filament settings/save/test actions, event/queue deduplication, mentions, encryption, rate limits, errors and the actual retained offline deployment job with a synthetic startup log. No production webhook, Minecraft server or real Discord message was used.
