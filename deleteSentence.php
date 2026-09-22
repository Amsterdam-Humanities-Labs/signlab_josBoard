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
if (!$input || !isset($input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input data']);
    exit();
}

$id = intval($input['id']);

try {
    // Delete sentence
    $sql = "DELETE FROM sentences WHERE ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Sentence deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete sentence: ' . $stmt->error]);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

// Close the connection
$conn->close();
?>
