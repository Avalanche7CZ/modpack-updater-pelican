<?php

declare(strict_types=1);
// Read only exported diagnostic fields, never connect to Panel or initiate an operation.
if ($argc !== 2) { fwrite(STDERR, "Usage: php bin/native-flow-report.php operations.json\n"); exit(2); }
$rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$start = null; $ready = null; $phases = [];
foreach ($rows as $row) {
    $created = strtotime($row['created_at']); $completed = isset($row['completed_at']) ? strtotime($row['completed_at']) : null;
    $start = $start === null ? $created : min($start, $created);
    $metrics = $row['context']['native_metrics'] ?? null;
    $phase = ['id' => $row['id'], 'kind' => $row['kind'], 'phase' => $row['phase'], 'wall_including_queue_seconds' => $completed === null ? null : $completed - $created];
    if ($metrics !== null) {
        $phase['deployment'] = $metrics;
        // Use only target boot evidence; successful rollback cannot satisfy update acceptance.
        if (isset($metrics['milestones_seconds']['target_boot_verified'], $metrics['started_at_unix'])) {
            $ready = $metrics['started_at_unix'] + $metrics['milestones_seconds']['target_boot_verified'];
        }
    } else { $phase['recorded_preparation_stages'] = $row['progress']['stages'] ?? null; }
    $phases[] = $phase;
}
$elapsed = $ready !== null && $start !== null ? $ready - $start : null;
$kinds = array_column($rows, 'kind'); $complete = count(array_intersect(['prepare', 'comparison', 'deploy'], $kinds)) === 3;
$successful = count(array_filter($rows, fn ($row) => ($row['phase'] ?? '') !== 'completed')) === 0;
echo json_encode(['includes_preparation_comparison_deployment' => $complete, 'all_selected_operations_completed' => $successful, 'flow_to_target_ready_seconds' => $elapsed, 'within_900_seconds' => ! $complete || ! $successful || $elapsed === null ? null : $elapsed <= 900,
    'scope' => 'Exactly the explicitly exported operation IDs, including queue and approval gaps; missing phases cannot be reconstructed.', 'operations' => $phases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
