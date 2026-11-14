<?php

declare(strict_types=1);
putenv('ARCTURUS_RUNNING_CONFIRMATION_ONLY=1');
putenv('ARCTURUS_CONFIG_ACCEPTANCE_ONLY=1');
putenv('ARCTURUS_CONFIG_SET_ONLY=1');
require __DIR__.'/native_deployment.php';
