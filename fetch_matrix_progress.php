<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate'); // Ensure fresh progress
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past

$progressFile = __DIR__ . '/matrix_progress.json';

if (file_exists($progressFile)) {
    $content = @file_get_contents($progressFile);
    if ($content === false) {
        echo json_encode(['message' => 'Error reading progress file.', 'percentage' => null, 'timestamp' => time()]);
    } else {
        // Validate JSON before outputting
        $decodedContent = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Add a timestamp to help client detect stale data if needed, though no-cache headers should handle it
            $decodedContent['timestamp'] = filemtime($progressFile);
            echo json_encode($decodedContent);
        } else {
            echo json_encode(['message' => 'Progress data corrupted.', 'percentage' => null, 'timestamp' => time()]);
        }
    }
} else {
    echo json_encode(['message' => 'Waiting for progress to start...', 'percentage' => 0, 'timestamp' => time()]);
}
?>