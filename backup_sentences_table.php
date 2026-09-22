<?php
// Script to backup the sentences table to SQL file
// Creates a timestamped backup file in the backup directory

// Include database configuration
include '../mysql_config.php';

// Set timezone
date_default_timezone_set('Europe/Amsterdam');

// Create backup directory if it doesn't exist
$backupDir = __DIR__ . '/backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Generate backup filename with timestamp
$timestamp = date('Ymd_His');
$backupFile = $backupDir . '/sentences_backup_' . $timestamp . '.sql';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

// Set charset
$conn->set_charset("utf8mb4");

echo "Starting backup of sentences table...\n";
echo "=====================================\n\n";

// Open file for writing
$handle = fopen($backupFile, 'w');
if (!$handle) {
    die("Error: Could not create backup file\n");
}

// Write header
fwrite($handle, "-- MySQL dump of sentences table\n");
fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($handle, "-- Database: $database\n");
fwrite($handle, "-- Table: sentences\n\n");

// Write character set
fwrite($handle, "SET NAMES utf8mb4;\n");
fwrite($handle, "SET CHARACTER SET utf8mb4;\n");
fwrite($handle, "SET character_set_connection=utf8mb4;\n\n");

// Get table structure
echo "Backing up table structure...\n";
$result = $conn->query("SHOW CREATE TABLE sentences");
if ($result && $row = $result->fetch_row()) {
    fwrite($handle, "-- Table structure for table `sentences`\n");
    fwrite($handle, "DROP TABLE IF EXISTS `sentences`;\n");
    fwrite($handle, $row[1] . ";\n\n");
} else {
    die("Error getting table structure: " . $conn->error . "\n");
}

// Get total row count
$countResult = $conn->query("SELECT COUNT(*) as total FROM sentences");
$totalRows = 0;
if ($countResult && $row = $countResult->fetch_assoc()) {
    $totalRows = $row['total'];
}
echo "Total rows to backup: $totalRows\n\n";

// Export data in batches
echo "Backing up data...\n";
$batchSize = 1000;
$offset = 0;
$processedRows = 0;

fwrite($handle, "-- Data for table `sentences`\n");
fwrite($handle, "LOCK TABLES `sentences` WRITE;\n");
fwrite($handle, "/*!40000 ALTER TABLE `sentences` DISABLE KEYS */;\n\n");

while ($offset < $totalRows) {
    $sql = "SELECT * FROM sentences LIMIT $offset, $batchSize";
    $result = $conn->query($sql);
    
    if (!$result) {
        die("Error fetching data: " . $conn->error . "\n");
    }
    
    while ($row = $result->fetch_assoc()) {
        $processedRows++;
        
        // Build INSERT statement
        $values = array();
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                // Properly escape the value
                $values[] = "'" . $conn->real_escape_string($value) . "'";
            }
        }
        
        if ($offset == 0 && $processedRows == 1) {
            // First row - include column names
            $columns = implode('`, `', array_keys($row));
            fwrite($handle, "INSERT INTO `sentences` (`$columns`) VALUES\n");
        } else {
            fwrite($handle, ",\n");
        }
        
        fwrite($handle, "(" . implode(', ', $values) . ")");
    }
    
    $offset += $batchSize;
    
    // Progress indicator
    $progress = min(100, round(($processedRows / $totalRows) * 100));
    echo "\rProgress: $progress% ($processedRows/$totalRows rows)";
}

// Close INSERT statement
if ($processedRows > 0) {
    fwrite($handle, ";\n");
}

fwrite($handle, "\n/*!40000 ALTER TABLE `sentences` ENABLE KEYS */;\n");
fwrite($handle, "UNLOCK TABLES;\n");

// Close file
fclose($handle);
$conn->close();

// Get file size
$fileSize = filesize($backupFile);
$fileSizeMB = round($fileSize / 1024 / 1024, 2);

echo "\n\n";
echo "BACKUP COMPLETED\n";
echo "================\n\n";
echo "Backup file: $backupFile\n";
echo "File size: $fileSizeMB MB\n";
echo "Total rows backed up: $processedRows\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n\n";

echo "To restore this backup, use:\n";
echo "mysql -u username -p $database < $backupFile\n";