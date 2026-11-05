<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Isolated encrypted values, including credentials. Secrets never enter form hydration. */
final class PluginSettings
{
    public const SECRETS = ['curseforge_api_key', 'discord_webhook'];

    public const FIELDS = ['default_policy', 'check_interval_hours', 'discord_events',
        'default_backup_exclusions', 'max_archive_bytes', 'download_concurrency'];

    public function all(): array
    {
        if (! Schema::hasTable('arcturus_modpack_settings')) {
            return [];
        }
        $values = [];
        foreach (DB::table('arcturus_modpack_settings')->get() as $row) {
            $values[$row->key] = json_decode(Crypt::decryptString($row->value), true, flags: JSON_THROW_ON_ERROR);
        }

        return $values;
    }

    public function formData(): array
    {
        Access::requireAdmin();
        $data = [];
        foreach (self::FIELDS as $key) {
            $data[$key] = config('arcturus-modpack-manager.'.$key);
        }
        foreach (self::SECRETS as $key) {
            $data[$key] = '';
            $data['clear_'.$key] = false;
        }

        return $data;
    }

    public function save(array $data): void
    {
        Access::requireAdmin();
        Validator::make($data, [
            'default_policy' => ['required', 'in:notify,approve'], 'check_interval_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'discord_events' => ['boolean'], 'default_backup_exclusions' => ['array', 'max:16'],
            'max_archive_bytes' => ['required', 'integer', 'min:1048576', 'max:34359738368'],
            'download_concurrency' => ['required', 'integer', 'in:1'],
            'curseforge_api_key' => ['nullable', 'string', 'max:512'], 'discord_webhook' => ['nullable', 'string', 'max:2048'],
        ])->validate();
        $values = array_intersect_key($data, array_flip(self::FIELDS));
        $values['default_backup_exclusions'] = BackupExclusions::normalize($data['default_backup_exclusions'] ?? []);
        foreach (self::SECRETS as $key) {
            if (($data['clear_'.$key] ?? false) === true) {
                $values[$key] = null;
            } elseif (isset($data[$key]) && $data[$key] !== '') {
                $values[$key] = $data[$key];
            }
        }
        if (! empty($values['discord_webhook'])) {
            DiscordNotifier::validateWebhook($values['discord_webhook']);
        }
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                DB::table('arcturus_modpack_settings')->updateOrInsert(['key' => $key],
                    ['value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))]);
            }
        });
        foreach ($values as $key => $value) {
            config(['arcturus-modpack-manager.'.$key => $value]);
        }
    }
}
