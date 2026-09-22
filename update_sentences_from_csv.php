<?php
// Script to update sentences in database
// Matches sentences from zinnen_old.csv and replaces with values from zinnen_new.csv
// Both CSV files must have the same order

// Include database configuration
include '../mysql_config.php';

// Set timezone
date_default_timezone_set('Europe/Amsterdam');

// Script parameters
$dryRun = false;
$verbose = true;

// Check command line arguments
if ($argc > 1) {
    for ($i = 1; $i < $argc; $i++) {
        if ($argv[$i] == '--dry-run') {
            $dryRun = true;
        }
        if ($argv[$i] == '--quiet') {
            $verbose = false;
        }
    }
}

// CSV file paths
$oldCsvFile = __DIR__ . '/csv/zinnen_old.csv';
$newCsvFile = __DIR__ . '/csv/zinnen_new.csv';

// Check if CSV files exist
if (!file_exists($oldCsvFile)) {
    die("Error: Old CSV file not found at $oldCsvFile\n");
}
if (!file_exists($newCsvFile)) {
    die("Error: New CSV file not found at $newCsvFile\n");
}

// Create log file
$timestamp = date('Ymd_His');
$logFile = __DIR__ . '/update_log_' . $timestamp . '.txt';
$logHandle = fopen($logFile, 'w');

function writeLog($message) {
    global $logHandle, $verbose;
    fwrite($logHandle, date('[Y-m-d H:i:s] ') . $message . "\n");
    if ($verbose) {
        echo $message . "\n";
    }
}

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

// Set charset
$conn->set_charset("utf8mb4");

writeLog("Starting sentence update process...");
writeLog("Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE UPDATE"));
writeLog("========================================\n");

// Open both CSV files
$oldHandle = fopen($oldCsvFile, 'r');
$newHandle = fopen($newCsvFile, 'r');

if (!$oldHandle || !$newHandle) {
    die("Error: Could not open CSV files\n");
}

// Skip headers
$oldHeader = fgetcsv($oldHandle, 0, ';');
$newHeader = fgetcsv($newHandle, 0, ';');

// Initialize counters
$lineNumber = 1;
$totalProcessed = 0;
$successfulUpdates = 0;
$notFound = 0;
$errors = 0;
$skipped = 0;

// Prepare SQL statements
$selectStmt = $conn->prepare("SELECT ID, zinString, zinArray FROM sentences WHERE zinString = ? LIMIT 1");
$updateStmt = $conn->prepare("UPDATE sentences SET zinArray = ?, zinString = ? WHERE ID = ?");

if (!$selectStmt || !$updateStmt) {
    die("Error preparing statements: " . $conn->error . "\n");
}

// Process files line by line
while (($oldData = fgetcsv($oldHandle, 0, ';')) !== FALSE && 
       ($newData = fgetcsv($newHandle, 0, ';')) !== FALSE) {
    
    $lineNumber++;
    
    // Check if both lines have data in first column
    if (!isset($oldData[0]) || !isset($newData[0]) || 
        empty(trim($oldData[0])) || empty(trim($newData[0]))) {
        $skipped++;
        continue;
    }
    
    $totalProcessed++;
    $oldSentence = trim($oldData[0]);
    $newSentence = trim($newData[0]);
    
    // Progress indicator
    if ($totalProcessed % 100 == 0) {
        echo "\rProcessed: $totalProcessed sentences...";
    }
    
    // Find the sentence in database
    $selectStmt->bind_param("s", $oldSentence);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $sentenceId = $row['ID'];
        
        // Check if update is needed
        if ($oldSentence === $newSentence) {
            writeLog("Line $lineNumber: No change needed for sentence ID $sentenceId");
            $skipped++;
            continue;
        }
        
        // Create new zinArray
        $words = explode(' ', $newSentence);
        $newZinArray = json_encode($words, JSON_UNESCAPED_UNICODE);
        
        if ($dryRun) {
            writeLog("Line $lineNumber: Would update sentence ID $sentenceId");
            writeLog("  OLD: $oldSentence");
            writeLog("  NEW: $newSentence");
            $successfulUpdates++;
        } else {
            // Perform update
            $updateStmt->bind_param("ssi", $newZinArray, $newSentence, $sentenceId);
            
            if ($updateStmt->execute()) {
                writeLog("Line $lineNumber: Updated sentence ID $sentenceId");
                writeLog("  OLD: $oldSentence");
                writeLog("  NEW: $newSentence");
                $successfulUpdates++;
            } else {
                writeLog("Line $lineNumber: ERROR updating sentence ID $sentenceId: " . $updateStmt->error);
                $errors++;
            }
        }
    } else {
        writeLog("Line $lineNumber: Sentence not found in database: $oldSentence");
        $notFound++;
    }
}

// Close resources
fclose($oldHandle);
fclose($newHandle);
$selectStmt->close();
$updateStmt->close();
$conn->close();

// Final summary
echo "\n\n";
writeLog("\nUPDATE SUMMARY");
writeLog("==============");
writeLog("Total lines processed: $totalProcessed");
writeLog("Successful updates: $successfulUpdates");
writeLog("Sentences not found in DB: $notFound");
writeLog("Skipped (no change needed): $skipped");
writeLog("Errors: $errors");
writeLog("\nLog file: $logFile");
writeLog("Completed at: " . date('Y-m-d H:i:s'));

fclose($logHandle);

// If this was a dry run, remind the user
if ($dryRun) {
    echo "\n";
    echo "This was a DRY RUN. No changes were made to the database.\n";
    echo "To perform the actual update, run without --dry-run flag:\n";
    echo "php update_sentences_from_csv.php\n";
} else {
    echo "\n";
    echo "Update completed. Please verify the changes.\n";
    echo "Backup file available at: /web/josBoard/backup/sentences_backup_*.sql\n";
}