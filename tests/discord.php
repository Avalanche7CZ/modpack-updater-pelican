<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\DiscordNotifier;

foreach (['123456789012345678', '18446744073709551615'] as $id) {
    DiscordNotifier::validateSnowflake($id);
}
foreach (['1234', '012345678901234567', '18446744073709551616', '<@&123456789012345678>', '@everyone', '-123456789012345678'] as $id) {
    rejects(fn () => DiscordNotifier::validateSnowflake($id), 'Invalid snowflake accepted');
}
foreach (['discord.com', 'discordapp.com'] as $host) {
    DiscordNotifier::validateWebhook('https://'.$host.'/api/webhooks/123456789012345678/'.str_repeat('x', 64));
}
foreach (['http://discord.com/api/webhooks/123456789012345678/token', 'https://discord.com.evil.test/api/webhooks/123456789012345678/'.str_repeat('x', 64),
    'https://discord.com@evil.test/path', 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('x', 64).'?wait=true',
    'https://discord.com:8443/api/webhooks/123456789012345678/'.str_repeat('x', 64), 'https://127.0.0.1/api/webhooks/123456789012345678/token'] as $url) {
    rejects(fn () => DiscordNotifier::validateWebhook($url), 'Unsafe webhook accepted');
}
echo "Official webhook and unsigned Discord snowflake validation passed\n";
