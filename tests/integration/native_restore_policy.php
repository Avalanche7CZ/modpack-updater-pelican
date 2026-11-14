<?php

declare(strict_types=1);
// Reuse only the isolated Native HTTP/DB fixture setup, not the full deployment scenario suite.
putenv('ARCTURUS_RESTORE_POLICY_ONLY=1');
require __DIR__.'/native_deployment.php';
