<?php
header('Content-Type: application/json');

// Include database configuration
include '../mysql_config.php';

//disable php warnings
error_reporting(E_ALL & ~E_NOTICE);
// Set error handling to display errors as JSON instead of HTML
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Clear progress on error
    $progressFile = __DIR__ . '/matrix_progress.json';
    if (file_exists($progressFile)) {
        @unlink($progressFile);
    }
    echo json_encode([
        'success' => false,
        'error' => "$errstr in $errfile on line $errline"
    ]);
    exit;
});

// Cache and Progress configuration
$cacheFile = __DIR__ . '/matrix_data_cache.json';
$cacheTime = 24 * 60 * 60; // 24 hours in seconds
$progressFile = __DIR__ . '/matrix_progress.json';
$detailsCacheDir = __DIR__ . '/details_cache'; // Cache directory for fetch_gloss_details.php
$sentencesCacheDir = __DIR__ . '/sentences_cache'; // Cache directory for fetch_sentences.php

// Function to update progress
function update_progress($message, $percentage) {
    global $progressFile;
    $progressData = json_encode(['message' => $message, 'percentage' => $percentage]);
    @file_put_contents($progressFile, $progressData);
}

// Initialize progress only if we are not serving from cache or if refresh is requested
$refreshCache = isset($_GET['refresh']) && $_GET['refresh'] === 'true';

if ($refreshCache || !file_exists($cacheFile) || (time() - filemtime($cacheFile)) >= $cacheTime) {
    update_progress('Starting matrix data generation...', 0);
}

// Check for refresh request
// $refreshCache is already defined above

// Try to load from cache if not refreshing
if (!$refreshCache && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedData !== false) {
        $decodedCachedData = json_decode($cachedData, true); 
        if (json_last_error() === JSON_ERROR_NONE && isset($decodedCachedData['success']) && $decodedCachedData['success'] === true) {
            $decodedCachedData['lastRefreshed'] = filemtime($cacheFile);
            $decodedCachedData['dataSource'] = 'cache';
            echo json_encode($decodedCachedData);
            // Update progress for cache load, then clear
            update_progress('Loaded from cache. Last refreshed: ' . date('Y-m-d H:i:s', filemtime($cacheFile)), 100);
            // No need to unlink progress file here if we want the message to persist until next refresh request
            exit;
        }
    }
}

if ($refreshCache) {
    update_progress('Refreshing cache. Connecting to database...', 5);
    
    $baseUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);

    // Actively refresh items in details_cache
    if (is_dir($detailsCacheDir)) {
        $detailFiles = glob($detailsCacheDir . '/*.json');
        update_progress('Refreshing ' . count($detailFiles) . ' detail cache items...', 6);
        foreach ($detailFiles as $filePath) {
            if (is_file($filePath)) {
                $content = @file_get_contents($filePath);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data && isset($data['label']) && isset($data['count'])) {
                        $refreshUrl = $baseUrl . '/fetch_gloss_details.php?' . 
                                      http_build_query([
                                          'label' => $data['label'],
                                          'count' => $data['count'],
                                          'force_refresh_internal' => 'true'
                                      ]);
                        @file_get_contents($refreshUrl); // Fire-and-forget refresh
                    }
                }
            }
        }
        update_progress(count($detailFiles) . ' detail cache items refresh initiated.', 7);
    } else {
        update_progress('Details cache directory not found, skipping refresh.', 7);
    }

    // Actively refresh items in sentences_cache
    if (is_dir($sentencesCacheDir)) {
        $sentenceFiles = glob($sentencesCacheDir . '/*.json');
        update_progress('Refreshing ' . count($sentenceFiles) . ' sentence cache items...', 8);
        foreach ($sentenceFiles as $filePath) {
            if (is_file($filePath)) {
                $content = @file_get_contents($filePath);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data && isset($data['woord'])) { // 'woord' should now be in sentence cache
                        $refreshUrl = $baseUrl . '/fetch_sentences.php?' .
                                      http_build_query([
                                          'woord' => $data['woord'],
                                          'force_refresh_internal' => 'true'
                                      ]);
                        @file_get_contents($refreshUrl); // Fire-and-forget refresh
                    }
                }
            }
        }
        update_progress(count($sentenceFiles) . ' sentence cache items refresh initiated.', 9);
    } else {
        update_progress('Sentences cache directory not found, skipping refresh.', 9);
    }
    
    // Clear main matrix cache file if it exists (it will be regenerated by this script)
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

} else {
    update_progress('Cache expired or missing. Connecting to database...', 5);
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

// Set charset explicitly to utf8mb4 which is more compatible
$conn->set_charset("utf8mb4");

try {
    update_progress('Fetching labels...', 10);
    // 1. Fetch labels from uniqueLabels.php
    $labelsJsonUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/uniqueLabels.php';
    $labelsJson = @file_get_contents($labelsJsonUrl);
    
    if ($labelsJson === false) {
        // If external fetch fails, try direct database query
        $sql = "SELECT DISTINCT label, color FROM labels WHERE label IS NOT NULL AND label != '' ORDER BY label ASC";
        $result = $conn->query($sql);
        
        if (!$result) {
            throw new Exception("Failed to fetch labels: " . $conn->error);
        }
        
        $labels = [];
        while ($row = $result->fetch_assoc()) {
            $rowLabels = explode(',', $row['label']);
            foreach ($rowLabels as $label) {
                $trimmedLabel = trim($label);
                if (!empty($trimmedLabel)) {
                    $labelExists = false;
                    foreach ($labels as $existingLabel) {
                        if ($existingLabel['name'] === $trimmedLabel) {
                            $labelExists = true;
                            break;
                        }
                    }
                    
                    if (!$labelExists) {
                        $labels[] = [
                            'name' => $trimmedLabel,
                            'color' => $row['color'] ?? '#e9ecef'
                        ];
                    }
                }
            }
        }
    } else {
        $labels = json_decode($labelsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse labels JSON: " . json_last_error_msg());
        }
    }
    
    // Initialize matrix data and tracking variables
    $matrixData = [];
    $totalWords = 0;
    $processedWords = []; // Keep track of unique words for the total count
    $totalLabels = count($labels);
    $labelsProcessed = 0;
    
    update_progress('Processing labels...', 15);
    // 2. Process each label
    foreach ($labels as $label) {
        $labelName = $label['name'];
        $labelsProcessed++;
        $basePercentage = 15;
        $percentageRange = 75 - 15; // 60% of progress for label processing
        $currentLabelProgress = ($labelsProcessed / $totalLabels) * $percentageRange;
        $currentTotalPercentage = $basePercentage + $currentLabelProgress;
        update_progress("Processing label: {$labelName} ({$labelsProcessed}/{$totalLabels})", round($currentTotalPercentage));
        
        // 3. Get words for this label from jb_woorden - USE CONVERT() to ensure consistent collation
        // First get potential matches using LIKE for efficiency
        $sql = "SELECT id, lemma, label FROM jb_woorden WHERE (
                CONVERT(label USING utf8mb4) = CONVERT(? USING utf8mb4) OR 
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR 
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4) OR
                CONVERT(label USING utf8mb4) LIKE CONVERT(? USING utf8mb4))
                AND woord IS NOT NULL AND woord != ''";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed for words query: " . $conn->error);
        }
        
        $exactLabelPattern = $labelName;
        $startLabelPattern = $labelName . ',%';
        $containLabelPattern = '%,' . $labelName . ',%';
        $endLabelPattern = '%,' . $labelName;
        $stmt->bind_param("ssss", $exactLabelPattern, $startLabelPattern, $containLabelPattern, $endLabelPattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$result) {
            throw new Exception("Failed to execute words query: " . $stmt->error);
        }
        
        $words = [];
        while ($row = $result->fetch_assoc()) {
            // Additional check: verify the label is actually in the comma-separated list
            $rowLabels = explode(',', $row['label']);
            foreach ($rowLabels as $rowLabel) {
                if (trim($rowLabel) === $labelName) {
                    // Only keep the id and lemma fields
                    $words[] = [
                        'id' => $row['id'],
                        'lemma' => $row['lemma']
                    ];
                    
                    // Track this as a processed word (for the global total)
                    if (!isset($processedWords[$row['id']])) {
                        $processedWords[$row['id']] = true;
                    }
                    
                    // We found a match for this label, no need to check other labels for this word
                    break;
                }
            }
        }
        $stmt->close();
        
        // Skip labels with no words
        if (empty($words)) {
            continue;
        }
        
        // 4. Initialize counters for this label
        $occurrenceCounts = [
            0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, '5+' => 0
        ];
        
        // 5. Count occurrences for each word
        foreach ($words as $wordItem) {
            $woord = $wordItem['lemma'];
            
            // 6. Look for this word in sentences - USE CONVERT() here too
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
            // Fix: Add null check before calling strtolower()
            $woord = !is_null($woord) ? strtolower($woord) : '';
            
            // Search for the word in JSON array format - these patterns catch the word in different positions in the JSON array
            $searchPattern1 = '%"' . $woord . '"%'; // Word anywhere in JSON
            $searchPattern2 = '%[' . $woord . ']%'; // Word at beginning or end
            $searchPattern3 = '%,' . $woord . ',%'; // Word in the middle
            $stmtCount->bind_param("ssssss", $searchPattern1, $searchPattern2, $searchPattern3, $searchPattern1, $searchPattern2, $searchPattern3);
            $stmtCount->execute();
            $resultCount = $stmtCount->get_result();
            
            if (!$resultCount) {
                throw new Exception("Failed to execute count query: " . $stmtCount->error);
            }
            
            $countRow = $resultCount->fetch_assoc();
            $occurrences = (int)$countRow['count'];
            $stmtCount->close();
            
            // 7. Increment the appropriate counter
            if ($occurrences > 5) {
                $occurrenceCounts['5+']++;
            } else {
                $occurrenceCounts[$occurrences]++;
            }
        }
        
        $totalLabelWords = count($words);
        
        // 8. Add to matrix data
        $matrixData[] = [
            'label' => $labelName,
            'color' => $label['color'],
            'totalGlosses' => $totalLabelWords,
            'occurrenceCounts' => $occurrenceCounts
        ];
    }
    
    // Calculate total unique words across all labels
    $totalWords = count($processedWords);
    
    update_progress('Sorting data...', 90);
    // 9. Sort matrix data by total number of words (descending)
    usort($matrixData, function($a, $b) {
        return $b['totalGlosses'] - $a['totalGlosses'];
    });
    
    update_progress('Encoding and saving data...', 95);
    // 10. Return success response
    $responseData = [
        'success' => true,
        'matrix' => $matrixData,
        'labelCount' => count($matrixData),
        'totalGlosses' => $totalWords,
        'dataSource' => 'generated'
    ];

    $jsonToCache = json_encode($responseData); 
    if ($responseData['success'] === true) { 
        if (@file_put_contents($cacheFile, $jsonToCache) !== false) {
            clearstatcache(true, $cacheFile); // Clear file status cache to get the correct mtime
            $responseData['lastRefreshed'] = filemtime($cacheFile);
        } else {
            $responseData['lastRefreshed'] = null; // Indicate cache write failed
            // Potentially log an error here if cache writing is critical
        }
    } else {
        $responseData['lastRefreshed'] = null;
    }

    echo json_encode($responseData);
    $finalProgressMessage = 'Matrix data generation complete.';
    if (isset($responseData['lastRefreshed']) && $responseData['lastRefreshed']) {
        $finalProgressMessage .= ' New cache saved at: ' . date('Y-m-d H:i:s', $responseData['lastRefreshed']);
    }
    update_progress($finalProgressMessage, 100);
    // No need to unlink progress file here if we want the message to persist
    
} catch (Exception $e) {
    // Provide detailed error information for collation issues
    $errorMsg = $e->getMessage();
    $collationInfo = "";
    
    if (stripos($errorMsg, 'collation') !== false || 
        stripos($errorMsg, 'mix of collations') !== false) {
        $collationInfo = "\n\nDatabasebeheerder informatie: De tabellen in de database hebben verschillende karaktersets. " .
            "Gebruik ALTER TABLE om alle tabellen te updaten naar utf8mb4_unicode_ci. " .
            "Voorbeeld query: ALTER TABLE jb_woorden CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
    }
    
    echo json_encode([
        'success' => false,
        'error' => $errorMsg . $collationInfo,
        'dbInfo' => [
            'charset' => $conn->character_set_name(),
            'collation' => $conn->query("SELECT @@collation_connection")->fetch_row()[0]
        ]
    ]);
    update_progress('Error: ' . $e->getMessage(), -1); // Indicate error in progress
    // No unlink here, let the error handler do it or keep for debugging
}

// Close the connection
$conn->close();
?>
