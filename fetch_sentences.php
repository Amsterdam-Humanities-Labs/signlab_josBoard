<?php
header('Content-Type: application/json');

// Include database configuration
include '../mysql_config.php';

// Cache configuration
$cacheDir = __DIR__ . '/sentences_cache';
$cacheTime = 24 * 60 * 60; // 24 hours in seconds

// Ensure cache directory exists
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Get word parameter and convert to lowercase for normalized matching
$word = isset($_GET['woord']) ? strtolower(trim($_GET['woord'])) : '';
$forceRefresh = isset($_GET['force_refresh_internal']) && $_GET['force_refresh_internal'] === 'true';

if (empty($word)) {
    echo json_encode(['success' => false, 'error' => 'No word provided']);
    exit;
}

// Sanitize word for cache filename
$safeWord = preg_replace('/[^a-zA-Z0-9_-]/', '_', $word);
$cacheFile = $cacheDir . '/sentences_' . md5($word) . '.json';

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
    die(json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]));
}

// Set charset
$conn->set_charset("utf8mb4"); // Updated to utf8mb4 for better compatibility

try {
    // Get all sentences with label ZNN
    $sql = "SELECT ID, zinString, lemmaList, zinArray FROM sentences WHERE label = 'ZNN' ORDER BY ID";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    $sentences = [];

    while ($row = $result->fetch_assoc()) {
        $isMatch = false;

        // Check if lemmaList is a valid JSON array
        if (!empty($row['lemmaList'])) {
            $lemmaArray = json_decode($row['lemmaList'], true);

            // Check if lemma array contains a case-insensitive match for the word
            if (is_array($lemmaArray)) {
                // Convert all items in lemmaArray to lowercase for comparison
                $lowerLemmaArray = array_map('strtolower', $lemmaArray);
                if (in_array($word, $lowerLemmaArray)) {
                    $isMatch = true;
                }
            }
        }

        // Also check zinArray if we haven't found a match yet
        if (!$isMatch && !empty($row['zinArray'])) {
            $zinArray = json_decode($row['zinArray'], true);

            // Check if zinArray contains a case-insensitive match for the word
            if (is_array($zinArray)) {
                // Convert all items in zinArray to lowercase for comparison
                $lowerZinArray = array_map('strtolower', $zinArray);
                if (in_array($word, $lowerZinArray)) {
                    $isMatch = true;
                }
            }
        }

        if ($isMatch) {
            $sentences[] = $row;
        }
    }

    $responseData = [
        'success' => true, 
        'woord' => $word, // Store the original word
        'sentences' => $sentences, 
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
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>
