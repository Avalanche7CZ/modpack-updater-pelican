<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Services\SafetyPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$properties = <<<'PROPERTIES'
#Minecraft server properties
motd=\u00a7aWelcome to TFG\n\u00a7bArcturus Network
resource-pack-prompt={"text":"Use \u00a76server resources"}
rcon.password=test\=quoted
level-name=TFGWorld
difficulty=hard
PROPERTIES;

$roots = SafetyPaths::roots($properties);
foreach (['TFGWorld', 'TFGWorld_nether', 'TFGWorld_the_end', 'world'] as $root) {
    check(in_array($root, $roots, true), 'Escaped unrelated server.properties values blocked a valid world root: '.$root);
}
check(SafetyPaths::reason('TFGWorld/region/r.0.0.mca', $roots) !== null,
    'Custom world protection was lost due to unrelated escaped properties');

rejects(fn () => SafetyPaths::roots('level-name=TFG\\ World'), 'Escaped custom world name must require verification');
rejects(fn () => SafetyPaths::roots('level-name=../escape'), 'Unsafe world path must remain blocked');

echo "Server.properties unrelated escapes and strict custom world safety tests passed\n";
