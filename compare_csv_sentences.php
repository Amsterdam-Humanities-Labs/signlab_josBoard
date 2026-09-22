<?php
// Script to compare sentences from CSV file with database
// This script reads zinnen_old.csv and compares the "Zin" column values
// against the zinString field in the sentences table

// Include database configuration
include '../mysql_config.php';

// Set timezone for proper timestamp display
date_default_timezone_set('Europe/Amsterdam');

// Initialize counters
$totalCsvSentences = 0;
$foundInDb = 0;
$notFoundInDb = 0;
$notFoundSentences = [];

// Status counters for found sentences
$statusCounts = [
    'status_annotatie' => ['Klaar' => 0, 'Niet Klaar' => 0, 'other' => 0],
    'status_glos' => ['Klaar' => 0, 'Niet Klaar' => 0, 'other' => 0],
    'status_video' => ['Klaar' => 0, 'Niet Klaar' => 0, 'other' => 0]
];

// CSV file path
$csvFile = __DIR__ . '/csv/zinnen_old.csv';

// Check if CSV file exists
if (!file_exists($csvFile)) {
    die("Error: CSV file not found at $csvFile\n");
}

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

// Set charset to handle special characters properly
$conn->set_charset("utf8mb4");

// Prepare the SQL query
$stmt = $conn->prepare("SELECT status_annotatie, status_glos, status_video FROM sentences WHERE zinString = ? LIMIT 1");
if (!$stmt) {
    die("Prepare failed: " . $conn->error . "\n");
}

// Open CSV file
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Error: Could not open CSV file\n");
}

echo "Starting comparison of CSV sentences with database...\n";
echo "================================================\n\n";

// Skip header row
$header = fgetcsv($handle, 0, ';');

// Process each row
while (($data = fgetcsv($handle, 0, ';')) !== FALSE) {
    if (isset($data[0]) && !empty(trim($data[0]))) {
        $totalCsvSentences++;
        $sentence = trim($data[0]);
        
        // Progress indicator every 100 sentences
        if ($totalCsvSentences % 100 == 0) {
            echo "Processed $totalCsvSentences sentences...\n";
        }
        
        // Bind parameter and execute query
        $stmt->bind_param("s", $sentence);
        $stmt->execute();
        
        // Get result
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $foundInDb++;
            $row = $result->fetch_assoc();
            
            // Count status values
            foreach (['status_annotatie', 'status_glos', 'status_video'] as $statusField) {
                $statusValue = $row[$statusField];
                
                if ($statusValue === 'Klaar') {
                    $statusCounts[$statusField]['Klaar']++;
                } elseif ($statusValue === 'Niet Klaar') {
                    $statusCounts[$statusField]['Niet Klaar']++;
                } else {
                    $statusCounts[$statusField]['other']++;
                }
            }
        } else {
            $notFoundInDb++;
            $notFoundSentences[] = $sentence;
        }
    }
}

// Close file and statement
fclose($handle);
$stmt->close();
$conn->close();

// Display results
echo "\n\n";
echo "COMPARISON RESULTS\n";
echo "==================\n\n";

echo "Total sentences in CSV file: " . $totalCsvSentences . "\n";
echo "Sentences found in database: " . $foundInDb . " (" . round(($foundInDb / $totalCsvSentences) * 100, 2) . "%)\n";
echo "Sentences NOT found in database: " . $notFoundInDb . " (" . round(($notFoundInDb / $totalCsvSentences) * 100, 2) . "%)\n";

echo "\n";
echo "STATUS BREAKDOWN FOR FOUND SENTENCES\n";
echo "====================================\n\n";

// Display status counts
foreach ($statusCounts as $statusField => $counts) {
    echo strtoupper(str_replace('_', ' ', $statusField)) . ":\n";
    echo "  - Klaar: " . $counts['Klaar'] . " (" . round(($counts['Klaar'] / $foundInDb) * 100, 2) . "%)\n";
    echo "  - Niet Klaar: " . $counts['Niet Klaar'] . " (" . round(($counts['Niet Klaar'] / $foundInDb) * 100, 2) . "%)\n";
    if ($counts['other'] > 0) {
        echo "  - Other values: " . $counts['other'] . " (" . round(($counts['other'] / $foundInDb) * 100, 2) . "%)\n";
    }
    echo "\n";
}

// Option to display sentences not found
if ($notFoundInDb > 0) {
    echo "\nWould you like to see the sentences not found in the database? (y/n): ";
    $showNotFound = trim(fgets(STDIN));
    
    if (strtolower($showNotFound) === 'y') {
        echo "\nSENTENCES NOT FOUND IN DATABASE:\n";
        echo "=================================\n";
        foreach ($notFoundSentences as $index => $sentence) {
            echo ($index + 1) . ". " . $sentence . "\n";
        }
    }
}

echo "\n";
echo "Comparison completed at: " . date('Y-m-d H:i:s') . "\n";