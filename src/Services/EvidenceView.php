<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Illuminate\Support\Facades\DB;

/** Database-side projections and bounded row access for HTTP callers. */
final class EvidenceView
{
    public static function value(int $id, string $column, string $path): mixed
    {
        if (! in_array($column, ['file_inventory', 'preview_summary', 'adoption_report'], true)) { throw new \InvalidArgumentException('Invalid evidence column'); }
        $extract = "json_extract($column, ?)";
        if (DB::getDriverName() === 'sqlite') { $extract = 'json_quote('.$extract.')'; }
        $raw = DB::table('arcturus_modpack_servers')->where('id', $id)->selectRaw($extract.' AS fragment', [$path])->value('fragment');
        return $raw === null ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function reportColumn(int $id): string
    {
        $row = DB::table('arcturus_modpack_servers')->where('id', $id)->selectRaw('preview_summary IS NOT NULL AS has_preview')->first();
        return $row?->has_preview ? 'preview_summary' : 'adoption_report';
    }

    public static function path(string $section, string $path): string
    {
        return '$.'.$section.'.'.json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function changePage(int $id, int $page, string $search = '', string $classification = ''): array
    {
        $count = 0; $rows = []; $page = max(1, $page); $offset = ($page - 1) * 50;
        foreach (JsonStream::column($id, self::reportColumn($id))->entries(['changes']) as $index => $row) {
            if ($row['action'] === 'unchanged' || ($classification !== '' && $row['classification'] !== $classification) ||
                ($search !== '' && ! str_contains(strtolower($row['path']), strtolower($search)))) { continue; }
            if ($count >= $offset && count($rows) < 50) { $rows[] = [...$row, '_evidence_index' => $index]; }
            $count++;
        }
        $pages = max(1, (int) ceil($count / 50));
        if ($page > $pages) { return self::changePage($id, $pages, $search, $classification); }
        return ['rows' => $rows, 'total' => $count, 'pages' => $pages, 'page' => $page];
    }

    public static function warnings(int $id, string $field): array
    {
        $rows = [];
        foreach (JsonStream::column($id, 'file_inventory')->entries([$field]) as $row) {
            $rows[] = $row;
            if (count($rows) >= 250) { break; }
        }
        return $rows;
    }
}
