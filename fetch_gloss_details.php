<?php
header('Content-Type: application/json');

// Include database configuration
include '../mysql_config.php';

// Cache configuration
$cacheDir = __DIR__ . '/details_cache';
$cacheTime = 24 * 60 * 60; // 24 hours in seconds

// Ensure cache directory exists
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Get parameters
$label = $_GET['label'] ?? '';
$countParam = $_GET['count'] ?? ''; // Renamed to avoid conflict with $count variable later
$forceRefresh = isset($_GET['force_refresh_internal']) && $_GET['force_refresh_internal'] === 'true';

if (empty($label)) {
    echo json_encode(['success' => false, 'error' => "Label parameter is required"]);
    exit;
}

if ($countParam === '') {
    echo json_encode(['success' => false, 'error' => "Count parameter is required"]);
    exit;
}

// Sanitize label and count for cache filename
$safeLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
$safeCount = preg_replace('/[^a-zA-Z0-9_+]/', '_', (string)$countParam); // Ensure count is string for preg_replace
$cacheFile = $cacheDir . '/gloss_details_' . md5($label . '_' . $countParam) . '.json';

// Try to load from cache
if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedData !== false) {
        $decodedCachedData = json_decode($cachedData, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decodedCachedData['dataSource'] = 'cache';
            $decodedCachedData['lastRefreshed'] = filemtime($cacheFile);
            echo json_encode($decodedCachedData);
            exit;
        }
    }
}

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'error' => 'Connection failed: ' . $conn->connect_error
    ]));
}

// Set charset explicitly to utf8mb4 for better compatibility
$conn->set_charset("utf8mb4");

try {
    // Get words with this label from jb_woorden
    $sql = "SELECT id, lemma, label FROM jb_woorden WHERE (
                CONVERT(label USING utf8mb4) = CONVERT(? USING utf8mb4) OR 
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR 
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4)) 
                AND lemma IS NOT NULL AND lemma != ''";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Prepare failed for words query: " . $conn->error);
    }

    $exactLabelPattern = $label;
    $startLabelPattern = $label . ',%';
    $containLabelPattern = '%,' . $label . ',%';
    $endLabelPattern = '%,' . $label;
    $stmt->bind_param("ssss", $exactLabelPattern, $startLabelPattern, $containLabelPattern, $endLabelPattern);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Failed to execute words query: " . $stmt->error);
    }

    $glosses = [];

    // Process each word and check if its occurrence count matches the requested count
    while ($row = $result->fetch_assoc()) {
        // Make sure this word actually has the specified label (not just a substring match)
        $hasLabel = false;
        $rowLabels = explode(',', $row['label']);
        foreach ($rowLabels as $rowLabel) {
            if (trim($rowLabel) === $label) {
                $hasLabel = true;
                break;
            }
        }

        if (!$hasLabel) {
            continue; // Skip this word if it doesn't have the exact label
        }

        $wordId = $row['id'];
        $woord = $row['lemma'];

        // Count occurrences in sentences
        $sqlCount = "SELECT COUNT(*) as count FROM sentences WHERE 
                   CONVERT(lemmaList USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                   CONVERT(lemmaList USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                   CONVERT(lemmaList USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                   CONVERT(zinArray USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                   CONVERT(zinArray USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                   CONVERT(zinArray USING utf8mb4) LIKE CONVERT(? USING utf8mb4)";
        $stmtCount = $conn->prepare($sqlCount);

        if (!$stmtCount) {
            throw new Exception("Prepare failed for count query: " . $conn->error);
        }

        // Convert word to lowercase for case-insensitive matching
        $woordLower = strtolower($woord); // Use a different variable to keep original $woord

        // Search for the word in JSON array format - these patterns catch the word in different positions in the JSON array
        // Ensure the word is properly escaped for JSON context if it contains special characters, though typically lemma won't.
        // The LIKE patterns are generally robust for simple strings.
        $searchPattern1 = '%"' . $conn->real_escape_string($woordLower) . '"%'; // Word anywhere in JSON
        $searchPattern2 = '%[' . $conn->real_escape_string($woordLower) . ']%'; // Word at beginning or end (less likely for lemmaList)
        $searchPattern3 = '%,' . $conn->real_escape_string($woordLower) . ',%'; // Word in the middle (less likely for lemmaList)
        // For zinArray, we might need more robust matching if words can have punctuation or are part of larger strings.
        // The current patterns are broad.
        $stmtCount->bind_param("ssssss", $searchPattern1, $searchPattern2, $searchPattern3, $searchPattern1, $searchPattern2, $searchPattern3);
        $stmtCount->execute();
        $resultCount = $stmtCount->get_result();

        if (!$resultCount) {
            throw new Exception("Failed to execute count query: " . $stmtCount->error);
        }

        $countRow = $resultCount->fetch_assoc();
        $occurrences = (int)$countRow['count'];
        $stmtCount->close();

        // Check if this word matches the requested occurrence count
        $matches = false;
        if ($countParam === '5+' && $occurrences > 5) { // Use $countParam
            $matches = true;
        } else if ($countParam !== '5+' && $occurrences == (int)$countParam) { // Use $countParam
            $matches = true;
        }

        if ($matches) {
            $glosses[] = [
                'id' => $wordId,
                'woord' => $woord, // Original case word
                'count' => $occurrences
            ];
        }
    }

    $stmt->close();

    // Sort glosses alphabetically by word
    usort($glosses, function($a, $b) {
        return strcasecmp($a['woord'], $b['woord']);
    });

    $responseData = [
        'success' => true,
        'label' => $label,
        'count' => $countParam, // Use $countParam
        'glosses' => $glosses,
        'dataSource' => 'database'
    ];

    // Save to cache
    if (@file_put_contents($cacheFile, json_encode($responseData)) !== false) {
        clearstatcache(true, $cacheFile);
        $responseData['lastRefreshed'] = filemtime($cacheFile);
    } else {
        // Log error or handle cache write failure if necessary
        $responseData['cache_error'] = "Failed to write to cache file: " . $cacheFile;
    }
    
    echo json_encode($responseData);

} catch (Exception $e) {
    // Provide detailed error information for collation issues
    $errorMsg = $e->getMessage();
    $collationInfo = "";

    if (stripos($errorMsg, 'collation') !== false ||
        stripos($errorMsg, 'mix of collations') !== false) {
        $collationInfo = "\n\nDatabasebeheerder informatie: De tabellen in de database hebben verschillende karaktersets. " .
            "Gebruik ALTER TABLE om alle tabellen te updaten naar utf8mb4_unicode_ci. " .
            "Voorbeeld query: ALTER TABLE sentences CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
    }

    echo json_encode([
        'success' => false,
        'error' => $errorMsg . $collationInfo,
        'dbInfo' => [
            'charset' => $conn->character_set_name(),
            'collation' => $conn->query("SELECT @@collation_connection")->fetch_row()[0]
        ]
    ]);
}

// Close the connection
$conn->close();
?>
