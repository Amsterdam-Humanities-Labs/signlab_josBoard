<?php
// Include database configuration
include '../mysql_config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset - changed from "utf8" to "utf8mb4" to support special characters.
$conn->set_charset("utf8mb4");

// Get labels for dropdown
function getLabels() {
    global $conn;
    $labels = [];
    
    // Try to get labels from uniqueLabels.php first
    $labelsJsonUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/uniqueLabels.php';
    $labelsJson = @file_get_contents($labelsJsonUrl);
    
    if ($labelsJson !== false) {
        $labelData = json_decode($labelsJson, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            foreach ($labelData as $label) {
                $labels[] = $label['name'];
            }
            sort($labels);
            return $labels;
        }
    }
    
    // Fallback to direct database query
    $sql = "SELECT DISTINCT label FROM labels WHERE label IS NOT NULL AND label != '' ORDER BY label ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $allLabels = [];
        while ($row = $result->fetch_assoc()) {
            $rowLabels = explode(',', $row['label']);
            foreach ($rowLabels as $label) {
                $trimmedLabel = trim($label);
                if (!empty($trimmedLabel) && !in_array($trimmedLabel, $allLabels)) {
                    $allLabels[] = $trimmedLabel;
                }
            }
        }
        sort($allLabels);
        return $allLabels;
    }
    
    return $labels;
}

// New function to lookup lemma from hh_words table
function lookupLemma($word, $conn) {
    $lemma = null;
    
    // Remove the COLLATE clause as it's not compatible with binary columns
    $stmt = $conn->prepare("SELECT lemma FROM hh_words WHERE word = ?");
    if ($stmt) {
        $stmt->bind_param("s", $word);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // Handle potential binary data by ensuring it's properly encoded
            if (isset($row['lemma'])) {
                $lemma = mb_convert_encoding($row['lemma'], 'UTF-8', 'UTF-8');
            }
        }
        
        $stmt->close();
    }
    
    return $lemma;
}

$error = '';
$success = '';
$addedCount = 0;
$skippedCount = 0;
$lemmaFoundCount = 0;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_words'])) {
    $words_text = trim($_POST['words_list']);
    $label = trim($_POST['label']);
    $thema = trim($_POST['thema']);
    
    if (empty($words_text)) {
        $error = "Woorden lijst kan niet leeg zijn.";
    } else {
        // Split input into words
        $words = preg_split('/\r\n|\r|\n/', $words_text);
        $words = array_filter(array_map('trim', $words), 'strlen');
        
        if (empty($words)) {
            $error = "Geen geldige woorden gevonden.";
        } else {
            // Prepare statements
            // Changed query to convert the 'woord' column to UTF-8 for a collation-compatible comparison.
            $checkStmt = $conn->prepare("SELECT id, label FROM jb_woorden WHERE CONVERT(woord USING utf8) = ?");
            $insertStmt = $conn->prepare("INSERT INTO jb_woorden (woord, lemma, label, thema) VALUES (?, ?, ?, ?)");
            $updateStmt = $conn->prepare("UPDATE jb_woorden SET label = ? WHERE id = ?");
            
            if (!$checkStmt || !$insertStmt || !$updateStmt) {
                $error = "Database fout bij voorbereiden van statements: " . $conn->error;
            } else {
                $updatedCount = 0;
                foreach ($words as $word) {
                    // Extract lemma from word if provided (format: word [lemma])
                    $manualLemma = null;
                    if (preg_match('/^(.*?)\s*\[(.*?)\]$/', $word, $matches)) {
                        $word = trim($matches[1]);
                        $manualLemma = trim($matches[2]);
                    }
                    
                    // Check if word already exists
                    $checkStmt->bind_param("s", $word);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Word already exists - update label if not already present
                        if (!empty($label)) {
                            $row = $checkResult->fetch_assoc();
                            $existingLabels = explode(',', $row['label']);
                            $existingLabels = array_map('trim', $existingLabels);
                            
                            // Only add label if it doesn't already exist
                            if (!in_array($label, $existingLabels)) {
                                $newLabel = !empty($row['label']) ? $row['label'] . ',' . $label : $label;
                                $updateStmt->bind_param("si", $newLabel, $row['id']);
                                if ($updateStmt->execute()) {
                                    $updatedCount++;
                                }
                            } else {
                                $skippedCount++;
                            }
                        } else {
                            $skippedCount++;
                        }
                    } else {
                        // Set lemma - prioritize manual input over lookup
                        $lemma = $manualLemma;
                        
                        // If no manual lemma, try to look it up
                        if (empty($lemma)) {
                            $lemma = lookupLemma($word, $conn);
                            // If lookup failed and word contains a space, try using only the first part
                            if (empty($lemma) && strpos($word, ' ') !== false) {
                                $firstPart = trim(explode(' ', $word)[0]);
                                if (!empty($firstPart)) {
                                    $lemma = lookupLemma($firstPart, $conn);
                                }
                            }
                            if (!empty($lemma)) {
                                $lemmaFoundCount++;
                            }
                        }
                        
                        // Insert new word
                        $insertStmt->bind_param("ssss", $word, $lemma, $label, $thema);
                        if ($insertStmt->execute()) {
                            $addedCount++;
                        } else {
                            $error = "Fout bij toevoegen van woord '{$word}': " . $insertStmt->error;
                            break;
                        }
                    }
                }
                
                $checkStmt->close();
                $insertStmt->close();
                $updateStmt->close();
                
                if (empty($error)) {
                    $success = "Woorden batch verwerkt: {$addedCount} toegevoegd ({$lemmaFoundCount} met automatisch lemma), {$updatedCount} bijgewerkt met nieuwe labels, {$skippedCount} overgeslagen.";
                    // Clear form data on success
                    $words_text = '';
                    $label = '';
                    $thema = '';
                }
            }
        }
    }
}

$allLabels = getLabels();
$conn->close();
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Woorden Batch Toevoegen</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", Helvetica, sans-serif;
            padding-top: 20px;
            background-color: #f8f9fa;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .card {
            margin-bottom: 1.5rem;
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
            border: none;
            border-radius: 0.5rem;
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid rgba(0,0,0,.125);
            padding: 1rem 1.5rem;
            font-weight: 500;
        }
        
        .navbar {
            background-color: #fff;
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
            margin-bottom: 2rem;
        }
        
        textarea.form-control {
            min-height: 200px;
            font-family: monospace;
        }
        
        .instructions {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
        }
        
        .instructions h6 {
            margin-top: 0;
            margin-bottom: 0.5rem;
        }
        
        .instructions p {
            margin-bottom: 0.5rem;
        }
        
        .instructions ul {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Woorden Batch Toevoegen</span>
                <div class="d-flex">
                    <a href="index.html" class="btn btn-outline-secondary me-2">Matrix</a>
                    <a href="words_manager.php" class="btn btn-outline-primary">Woorden Lijst</a>
                </div>
            </div>
        </nav>
        
        <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
        <div class="alert alert-success" role="alert">
            <?php echo $success; ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Meerdere woorden tegelijk toevoegen</h5>
            </div>
            <div class="card-body">
                <div class="instructions">
                    <h6><i class="bi bi-info-circle"></i> Instructies</h6>
                    <p>Voeg één woord per regel toe. Alle woorden krijgen dezelfde label en thema.</p>
                    <p>Formaat voor woorden:</p>
                    <ul>
                        <li>Simpel: <code>woord</code> (lemma wordt automatisch opgezocht)</li>
                        <li>Met handmatig lemma: <code>woord [lemma]</code></li>
                    </ul>
                    <p>Woorden die al bestaan worden automatisch overgeslagen.</p>
                    <p class="text-info"><i class="bi bi-info-circle-fill"></i> Het systeem zoekt automatisch lemma's op in de database. Als een lemma handmatig is opgegeven, wordt deze gebruikt.</p>
                </div>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="words_list" class="form-label">Woorden Lijst</label>
                        <textarea class="form-control" id="words_list" name="words_list" rows="10" required><?php echo htmlspecialchars($words_text ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <select class="form-select" id="label" name="label">
                            <option value="">-- Geen label --</option>
                            <?php foreach ($allLabels as $labelOption): ?>
                                <option value="<?php echo htmlspecialchars($labelOption); ?>" <?php echo ($label ?? '') == $labelOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($labelOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="thema" class="form-label">Thema</label>
                        <input type="text" class="form-control" id="thema" name="thema" value="<?php echo htmlspecialchars($thema ?? ''); ?>">
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="words_manager.php" class="btn btn-outline-secondary">Annuleren</a>
                        <button type="submit" name="add_words" class="btn btn-success">
                            <i class="bi bi-plus-circle"></i> Woorden Toevoegen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
