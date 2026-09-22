<?php
header('Content-Type: application/json');

// Include the MySQL configuration file
include '../mysql_config.php';

// Disable PHP warnings
error_reporting(E_ERROR | E_PARSE);

// Create a new MySQLi connection
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

// Check for a connection error
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Get the raw input data
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Validate input
if (!$input || !isset($input['theme']) || !isset($input['sentences']) || !is_array($input['sentences'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input data']);
    exit();
}

$theme = trim($input['theme']);
$sentences = $input['sentences'];
$isNewTheme = isset($input['isNewTheme']) ? $input['isNewTheme'] : false;
$userId = isset($input['userId']) ? $input['userId'] : null;
$woord = isset($input['woord']) ? trim($input['woord']) : null; // Added woord parameter

// Validate theme
if (empty($theme)) {
    echo json_encode(['success' => false, 'error' => 'Theme cannot be empty']);
    exit();
}

// Validate sentences
if (empty($sentences)) {
    echo json_encode(['success' => false, 'error' => 'No sentences provided']);
    exit();
}

// Start a transaction
$conn->begin_transaction();

try {
    // If it's a new theme, verify it doesn't already exist
    if ($isNewTheme) {
        $checkThemeSQL = "SELECT COUNT(*) as count FROM sentences WHERE thema = ?";
        $checkStmt = $conn->prepare($checkThemeSQL);
        $checkStmt->bind_param("s", $theme);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['count'] > 0) {
            echo json_encode(['success' => false, 'error' => 'Theme already exists. Please select it from the dropdown.']);
            exit();
        }
    }
    
    // Log the action
    logAction($conn, 'batch_add_sentences', [
        'theme' => $theme,
        'count' => count($sentences),
        'is_new_theme' => $isNewTheme,
        'userId' => $userId,
        'woord' => $woord // Add word to logs
    ]);
    
    // Set default status values
    $defaultStatus = "Niet Klaar";
    
    $label = "ZNN"; // Default label for sentences
    // Insert sentences with default status values
    $insertCount = 0;
    // Remove woord from the field list since it doesn't exist in the table
    $insertSQL = "INSERT INTO sentences (zinArray, thema, userId, status, status_annotatie, status_glos, status_video, label) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insertStmt = $conn->prepare($insertSQL);
    
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (empty($sentence)) continue;
        
        // Create JSON array of words
        $words = explode(' ', $sentence);
        $zinArray = json_encode($words, JSON_UNESCAPED_UNICODE);
        
        // Remove woord parameter from bind_param since it's not in the table
        $insertStmt->bind_param("ssissss", $zinArray, $theme, $userId, $defaultStatus, $defaultStatus, $defaultStatus, $defaultStatus, $label);
        if ($insertStmt->execute()) {
            $insertCount++;
            
            // Get the inserted ID
            $newId = $conn->insert_id;
            
            // Set the zinString field for easier searching
            $zinString = implode(' ', $words);
            $updateZinStringSQL = "UPDATE sentences SET zinString = ? WHERE ID = ?";
            $updateZinStringStmt = $conn->prepare($updateZinStringSQL);
            $updateZinStringStmt->bind_param("si", $zinString, $newId);
            $updateZinStringStmt->execute();
            $updateZinStringStmt->close();
            
            // Generate a numeric ID instead of a string ID
            // This is a temporary solution until the database schema can be changed
            $numericId = $newId + 10000; // Simple formula to create a unique numeric ID
            
            // Update the zinID with a numeric value
            $updateZinIDSQL = "UPDATE sentences SET zinID = ? WHERE ID = ?";
            $updateStmt = $conn->prepare($updateZinIDSQL);
            $updateStmt->bind_param("ii", $numericId, $newId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    
    $insertStmt->close();
    
    // Commit transaction if everything was successful
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'added' => $insertCount,
        'message' => "Successfully added $insertCount sentences under theme '$theme'."
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

// Close the connection
$conn->close();

/**
 * Log an action to the sentences_logs table
 */
function logAction($conn, $action, $parameters = []) {
    // Get the client's IP address
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    
    // Encode parameters as JSON
    $parametersJson = json_encode($parameters, JSON_UNESCAPED_UNICODE);
    
    // Prepare the SQL statement
    $sql = "INSERT INTO sentences_logs (action, parameters, ip_address) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // Log the error to the server's error log and exit
        error_log('Prepare failed for logAction: ' . $conn->error);
        return;
    }
    
    // Bind parameters
    $stmt->bind_param("sss", $action, $parametersJson, $ipAddress);
    
    // Execute the statement
    if (!$stmt->execute()) {
        // Log the error to the server's error log
        error_log('Execute failed for logAction: ' . $stmt->error);
    }
    
    // Close the statement
    $stmt->close();
}

/**
 * This function is no longer used since we're generating numeric IDs
 * instead of string IDs to match the database schema requirements
 */
function generateZinID($theme, $id) {
    // Create a numeric ID based on the row ID
    return $id + 10000; // Simple formula to create a unique numeric ID
}
?>