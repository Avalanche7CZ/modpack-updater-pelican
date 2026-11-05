<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

final class ModIdentityMappings
{
    public function apply(array $identities, array $mappings, array $inventories): array
    {
        if (count($mappings) > 256) {
            throw new InvalidArgumentException('Too many mod identity mappings');
        }
        foreach ($mappings as $mapping) {
            $scope = $mapping['scope'] ?? '';
            $path = $mapping['path'] ?? '';
            $id = $mapping['identity'] ?? '';
            UpdatePlanner::assertPath($path);
            if (! in_array($scope, ['base', 'local', 'target'], true) ||
                ! preg_match('/^[A-Za-z0-9_.+-]{1,128}$/D', $id) || ! str_starts_with($path, 'mods/') ||
                (! str_ends_with(strtolower($path), '.jar') && ! str_ends_with(strtolower($path), '.jar.disabled'))) {
                throw new InvalidArgumentException('Invalid explicit mod identity mapping');
            }
            // A target mapping may be configured before target preparation, but only extant files affect a plan.
            if (isset($inventories[$scope][$path])) {
                $identities[$scope][$path] = strtolower($id);
            }
        }

        return $identities;
    }
}
