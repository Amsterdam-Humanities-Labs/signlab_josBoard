<?php
// Include database configuration
include '../mysql_config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8");

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

$error = '';
$success = '';
$word = null;

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $error = "Geen ID opgegeven.";
} else {
    $id = intval($_GET['id']);
    
    // Fetch word data
    $stmt = $conn->prepare("SELECT * FROM jb_woorden WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $error = "Woord niet gevonden.";
    } else {
        $word = $result->fetch_assoc();
    }
    $stmt->close();
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        $woord = trim($_POST['woord']);
        $lemma = trim($_POST['lemma']);
        $label = trim($_POST['labels']); // Now contains comma-separated labels
        $thema = trim($_POST['thema']);
        
        if (empty($woord)) {
            $error = "Woord kan niet leeg zijn.";
        } else {
            $stmt = $conn->prepare("UPDATE jb_woorden SET woord = ?, lemma = ?, label = ?, thema = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $woord, $lemma, $label, $thema, $id);
            
            if ($stmt->execute()) {
                // Add JavaScript to handle the redirect with saved parameters
                echo "<script>
                    // Build redirect URL with all saved parameters
                    let redirectUrl = 'words_manager.php?updated=1&fromEdit=1';
                    
                    // Add page parameter if saved
                    const savedPage = sessionStorage.getItem('wordsManagerPage');
                    if (savedPage) {
                        redirectUrl += '&page=' + savedPage;
                    }
                    
                    // Add search parameter if saved
                    const savedSearch = sessionStorage.getItem('wordsManagerSearch');
                    if (savedSearch) {
                        redirectUrl += '&search=' + encodeURIComponent(savedSearch);
                    }
                    
                    // Add label filter if saved
                    const savedLabel = sessionStorage.getItem('wordsManagerLabel');
                    if (savedLabel) {
                        redirectUrl += '&labelFilter=' + encodeURIComponent(savedLabel);
                    }
                    
                    // Redirect to the constructed URL
                    window.location.href = redirectUrl;
                </script>";
                exit;
            } else {
                $error = "Fout bij bijwerken: " . $stmt->error;
            }
            $stmt->close();
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
    <title>Woord Bewerken</title>
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
        
        .label-badge {
            display: inline-block;
            background-color: #e9ecef;
            color: #495057;
            padding: 0.35em 0.65em;
            border-radius: 0.25rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .label-badge .remove-label {
            margin-left: 0.5rem;
            cursor: pointer;
            color: #6c757d;
        }
        
        .label-badge .remove-label:hover {
            color: #dc3545;
        }
        
        #labels-container {
            margin-bottom: 1rem;
            min-height: 40px;
        }
        
        .add-label-container {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .add-label-container select {
            flex-grow: 1;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Woord Bewerken</span>
                <div class="d-flex">
                    <a href="index.html" class="btn btn-outline-secondary me-2">Matrix</a>
                    <a href="words_manager.php?fromEdit=1" class="btn btn-outline-primary" id="backToListBtn">Terug naar Lijst</a>
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
        
        <?php if ($word): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Woord Bewerken: <?php echo htmlspecialchars($word['woord']); ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="edit-form">
                    <div class="mb-3">
                        <label for="woord" class="form-label">Woord</label>
                        <input type="text" class="form-control" id="woord" name="woord" value="<?php echo htmlspecialchars($word['woord']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="lemma" class="form-label">Lemma</label>
                        <input type="text" class="form-control" id="lemma" name="lemma" value="<?php echo htmlspecialchars($word['lemma'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Labels</label>
                        <div id="labels-container">
                            <?php 
                            $labelArray = !empty($word['label']) ? explode(',', $word['label']) : [];
                            foreach ($labelArray as $singleLabel): 
                                $trimmedLabel = trim($singleLabel);
                                if (!empty($trimmedLabel)):
                            ?>
                                <div class="label-badge">
                                    <?php echo htmlspecialchars($trimmedLabel); ?>
                                    <i class="bi bi-x remove-label" data-label="<?php echo htmlspecialchars($trimmedLabel); ?>"></i>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                        <div class="add-label-container">
                            <select class="form-select" id="label-dropdown">
                                <option value="">-- Selecteer label --</option>
                                <?php foreach ($allLabels as $labelOption): 
                                    // Skip if label is already assigned
                                    if (in_array($labelOption, $labelArray)) continue;
                                ?>
                                    <option value="<?php echo htmlspecialchars($labelOption); ?>">
                                        <?php echo htmlspecialchars($labelOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" id="add-label-btn">
                                <i class="bi bi-plus"></i> Toevoegen
                            </button>
                        </div>
                        <!-- Hidden input to store comma-separated labels -->
                        <input type="hidden" name="labels" id="labels-input" value="<?php echo htmlspecialchars($word['label'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="thema" class="form-label">Thema</label>
                        <input type="text" class="form-control" id="thema" name="thema" value="<?php echo htmlspecialchars($word['thema'] ?? ''); ?>">
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="words_manager.php" class="btn btn-outline-secondary" id="cancelBtn">Annuleren</a>
                        <button type="submit" name="save" class="btn btn-primary">Opslaan</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modify the back to list button to include saved page and search parameters
            const backBtn = document.getElementById('backToListBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            
            if (backBtn) {
                let backUrl = 'words_manager.php?fromEdit=1';
                
                // Add page parameter if saved
                const savedPage = sessionStorage.getItem('wordsManagerPage');
                if (savedPage) {
                    backUrl += '&page=' + savedPage;
                }
                
                // Only add search parameter if it actually exists (not empty)
                const savedSearch = sessionStorage.getItem('wordsManagerSearch');
                if (savedSearch && savedSearch.trim() !== '') {
                    backUrl += '&search=' + encodeURIComponent(savedSearch);
                }
                
                // Only add label filter if it actually exists (not empty)
                const savedLabel = sessionStorage.getItem('wordsManagerLabel');
                if (savedLabel && savedLabel.trim() !== '') {
                    backUrl += '&labelFilter=' + encodeURIComponent(savedLabel);
                }
                
                backBtn.href = backUrl;
                
                // Also update the cancel button to use the same URL
                if (cancelBtn) {
                    cancelBtn.href = backUrl;
                }
            }
            
            const labelsContainer = document.getElementById('labels-container');
            const labelsInput = document.getElementById('labels-input');
            const labelDropdown = document.getElementById('label-dropdown');
            const addLabelBtn = document.getElementById('add-label-btn');
            
            // Initialize labels array from hidden input
            let labels = labelsInput.value ? labelsInput.value.split(',').map(label => label.trim()).filter(Boolean) : [];
            
            // Function to update hidden input value
            function updateLabelsInput() {
                labelsInput.value = labels.join(',');
            }
            
            // Function to add a new label
            function addLabel(label) {
                if (!label || labels.includes(label)) return;
                
                // Add to array
                labels.push(label);
                updateLabelsInput();
                
                // Add to UI
                const labelBadge = document.createElement('div');
                labelBadge.className = 'label-badge';
                labelBadge.innerHTML = `
                    ${label}
                    <i class="bi bi-x remove-label" data-label="${label}"></i>
                `;
                labelsContainer.appendChild(labelBadge);
                
                // Remove from dropdown
                Array.from(labelDropdown.options).forEach(option => {
                    if (option.value === label) {
                        option.remove();
                    }
                });
                
                // Reset dropdown
                labelDropdown.value = '';
            }
            
            // Function to remove a label
            function removeLabel(label) {
                // Remove from array
                labels = labels.filter(l => l !== label);
                updateLabelsInput();
                
                // Remove from UI
                const badges = labelsContainer.querySelectorAll('.label-badge');
                badges.forEach(badge => {
                    if (badge.textContent.trim() === label) {
                        badge.remove();
                    }
                });
                
                // Add back to dropdown if not already there
                let exists = false;
                Array.from(labelDropdown.options).forEach(option => {
                    if (option.value === label) {
                        exists = true;
                    }
                });
                
                if (!exists) {
                    const option = document.createElement('option');
                    option.value = label;
                    option.textContent = label;
                    labelDropdown.appendChild(option);
                    
                    // Sort options
                    const options = Array.from(labelDropdown.options).slice(1);
                    options.sort((a, b) => a.text.localeCompare(b.text));
                    
                    // Clear dropdown
                    while (labelDropdown.options.length > 1) {
                        labelDropdown.options.remove(1);
                    }
                    
                    // Add sorted options back
                    options.forEach(option => labelDropdown.add(option));
                }
            }
            
            // Add label button click
            addLabelBtn.addEventListener('click', function() {
                const selectedLabel = labelDropdown.value;
                if (selectedLabel) {
                    addLabel(selectedLabel);
                }
            });
            
            // Remove label click (delegated)
            labelsContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-label')) {
                    const label = e.target.getAttribute('data-label');
                    removeLabel(label);
                }
            });
            
            // Add label on Enter key in dropdown
            labelDropdown.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const selectedLabel = labelDropdown.value;
                    if (selectedLabel) {
                        addLabel(selectedLabel);
                    }
                }
            });
        });
    </script>
</body>
</html>
