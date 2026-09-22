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

// Get labels for dropdown (for filtering)
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

// Process delete operation
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM jb_woorden WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
    header("Location: words_manager.php?deleted=1");
    exit;
}

// Retrieve search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$labelFilter = isset($_GET['labelFilter']) ? trim($_GET['labelFilter']) : '';

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 50; // Number of records per page
$offset = ($page - 1) * $limit;

// Get total records for pagination
if (!empty($search) && !empty($labelFilter)) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM jb_woorden WHERE 
                         (woord LIKE ? OR label LIKE ?) AND 
                         (label = ? OR label LIKE ? OR label LIKE ? OR label LIKE ?)");
    $searchParam = "%" . $search . "%";
    $labelExactPattern = $labelFilter;
    $labelStartPattern = $labelFilter . ',%';  
    $labelContainPattern = '%,' . $labelFilter . ',%';
    $labelEndPattern = '%,' . $labelFilter;
    $stmt->bind_param("ssssss", $searchParam, $searchParam, $labelExactPattern, $labelStartPattern, $labelContainPattern, $labelEndPattern);
} elseif (!empty($search)) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM jb_woorden WHERE woord LIKE ? OR label LIKE ?");
    $searchParam = "%" . $search . "%";
    $stmt->bind_param("ss", $searchParam, $searchParam);
} elseif (!empty($labelFilter)) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM jb_woorden WHERE 
                         label = ? OR label LIKE ? OR label LIKE ? OR label LIKE ?");
    $labelExactPattern = $labelFilter;
    $labelStartPattern = $labelFilter . ',%';  
    $labelContainPattern = '%,' . $labelFilter . ',%';
    $labelEndPattern = '%,' . $labelFilter;
    $stmt->bind_param("ssss", $labelExactPattern, $labelStartPattern, $labelContainPattern, $labelEndPattern);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM jb_woorden");
}
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_records = $row['total'];
$stmt->close();

$total_pages = ceil($total_records / $limit);

// Fetch records with filters
if (!empty($search) && !empty($labelFilter)) {
    $stmt = $conn->prepare("SELECT * FROM jb_woorden WHERE 
                         (woord LIKE ? OR label LIKE ?) AND 
                         (label = ? OR label LIKE ? OR label LIKE ? OR label LIKE ?) 
                         ORDER BY woord ASC LIMIT ? OFFSET ?");
    $searchParam = "%" . $search . "%";
    $labelExactPattern = $labelFilter;
    $labelStartPattern = $labelFilter . ',%';  
    $labelContainPattern = '%,' . $labelFilter . ',%';
    $labelEndPattern = '%,' . $labelFilter;
    $stmt->bind_param("ssssssii", $searchParam, $searchParam, $labelExactPattern, $labelStartPattern, $labelContainPattern, $labelEndPattern, $limit, $offset);
} elseif (!empty($search)) {
    $stmt = $conn->prepare("SELECT * FROM jb_woorden WHERE woord LIKE ? OR label LIKE ? ORDER BY woord ASC LIMIT ? OFFSET ?");
    $searchParam = "%" . $search . "%";
    $stmt->bind_param("ssii", $searchParam, $searchParam, $limit, $offset);
} elseif (!empty($labelFilter)) {
    $stmt = $conn->prepare("SELECT * FROM jb_woorden WHERE 
                         label = ? OR label LIKE ? OR label LIKE ? OR label LIKE ? 
                         ORDER BY woord ASC LIMIT ? OFFSET ?");
    $labelExactPattern = $labelFilter;
    $labelStartPattern = $labelFilter . ',%';  
    $labelContainPattern = '%,' . $labelFilter . ',%';
    $labelEndPattern = '%,' . $labelFilter;
    $stmt->bind_param("ssssii", $labelExactPattern, $labelStartPattern, $labelContainPattern, $labelEndPattern, $limit, $offset);
} else {
    $stmt = $conn->prepare("SELECT * FROM jb_woorden ORDER BY woord ASC LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();
$words = [];
while ($row = $result->fetch_assoc()) {
    $words[] = $row;
}
$stmt->close();

// Get filter labels for dropdown
$filterLabels = getLabels();
$conn->close();
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Woorden Beheer</title>
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
            max-width: 1200px;
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
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .alert {
            margin-bottom: 1.5rem;
        }
        
        .navbar {
            background-color: #fff;
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
            margin-bottom: 2rem;
        }
        
        .search-form {
            max-width: 300px;
        }
        
        .badge-label {
            background-color: #e9ecef;
            color: #495057;
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">
            <div class="container-fluid">
                <span class="navbar-brand mb-0 h1">Woorden Beheer</span>
                <div class="d-flex">
                    <a href="index.html" class="btn btn-outline-secondary me-2">Matrix</a>
                    <a href="words_manager.php" class="btn btn-primary me-2">Woorden Lijst</a>
                    <a href="batch_add.php" class="btn btn-success me-2">Batch Toevoegen</a>
                    <a href="https://signcollect.nl/labels_add.html" class="btn btn-info">Labels Beheer</a>
                </div>
            </div>
        </nav>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Woord is succesvol verwijderd.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Woord is succesvol bijgewerkt.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Woorden Lijst</h5>
                <form class="d-flex search-form" action="" method="GET">
                    <input class="form-control me-2" type="search" placeholder="Zoeken..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <!-- New filter dropdown for labels -->
                    <select name="labelFilter" class="form-select me-2">
                        <option value="">Filter op label</option>
                        <?php foreach ($filterLabels as $filterLabel): ?>
                            <option value="<?php echo htmlspecialchars($filterLabel); ?>" <?php echo ($labelFilter === $filterLabel) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filterLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Zoek</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Woord</th>
                                <th>Lemma</th>
                                <th>Label</th>
                                <th>Thema</th>
                                <th>Zinnen</th>
                                <th>Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($words)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-3">Geen woorden gevonden</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($words as $word): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($word['id']); ?></td>
                                    <td><?php echo htmlspecialchars($word['woord']); ?></td>
                                    <td><?php echo htmlspecialchars($word['lemma'] ?? ''); ?></td>
                                    <td>
                                        <?php if (!empty($word['label'])): ?>
                                            <?php 
                                                $labelArray = explode(',', $word['label']);
                                                foreach ($labelArray as $singleLabel): 
                                                    $trimmedLabel = trim($singleLabel);
                                                    if (!empty($trimmedLabel)):
                                            ?>
                                                <span class="badge badge-label"><?php echo htmlspecialchars($trimmedLabel); ?></span>
                                            <?php 
                                                    endif;
                                                endforeach; 
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($word['thema'] ?? ''); ?></td>
                                    <td>
                                        <!-- Clickable link to load sentences -->
                                        <a href="javascript:void(0);" class="sentence-toggle" 
                                           data-word="<?php echo htmlspecialchars($word['woord']); ?>"
                                           data-lemma="<?php echo htmlspecialchars($word['lemma'] ?? $word['woord']); ?>">Show Zinnen</a>
                                    </td>
                                    <td>
                                        <a href="edit_word.php?id=<?php echo $word['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> Bewerken
                                        </a>
                                        <a href="javascript:void(0);" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $word['id']; ?>, '<?php echo addslashes($word['woord']); ?>')">
                                            <i class="bi bi-trash"></i> Verwijderen
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo ($page - 1); ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($labelFilter) ? '&labelFilter=' . urlencode($labelFilter) : ''; ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?page=1' . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($labelFilter) ? '&labelFilter=' . urlencode($labelFilter) : '') . '">1</a></li>';
                    if ($start_page > 2) {
                        echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($labelFilter) ? '&labelFilter=' . urlencode($labelFilter) : '') . '">' . $i . '</a></li>';
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . (!empty($search) ? '&search=' . urlencode($search) : '') . (!empty($labelFilter) ? '&labelFilter=' . urlencode($labelFilter) : '') . '">' . $total_pages . '</a></li>';
                }
                ?>
                
                <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo ($page + 1); ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($labelFilter) ? '&labelFilter=' . urlencode($labelFilter) : ''; ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <p class="text-center text-muted mt-3">
            Totaal aantal woorden: <?php echo $total_records; ?>
        </p>
    </div>
    
    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete(id, woord) {
            if (confirm(`Weet je zeker dat je het woord "${woord}" wilt verwijderen?`)) {
                window.location.href = `words_manager.php?delete=${id}`;
            }
        }

        // Add script to save scroll position when clicking edit links
        document.addEventListener('DOMContentLoaded', function() {
            // Save scroll position when clicking edit links
            document.querySelectorAll('a[href^="edit_word.php"]').forEach(function(link) {
                link.addEventListener('click', function() {
                    // Save current scroll position
                    sessionStorage.setItem('wordsManagerScrollPos', window.scrollY);
                    // Save current page number if it exists in URL
                    const urlParams = new URLSearchParams(window.location.search);
                    const currentPage = urlParams.get('page');
                    if (currentPage) {
                        sessionStorage.setItem('wordsManagerPage', currentPage);
                    }
                    // Save any search or filter parameters
                    const search = urlParams.get('search');
                    if (search) {
                        sessionStorage.setItem('wordsManagerSearch', search);
                    } else {
                        // Clear if there's no search parameter in URL
                        sessionStorage.removeItem('wordsManagerSearch');
                    }
                    const labelFilter = urlParams.get('labelFilter');
                    if (labelFilter) {
                        sessionStorage.setItem('wordsManagerLabel', labelFilter);
                    } else {
                        // Clear if there's no label filter in URL
                        sessionStorage.removeItem('wordsManagerLabel');
                    }
                });
            });
        });

        // Use window.onload instead of DOMContentLoaded for scroll position restoration
        window.onload = function() {
            // Check if we need to restore scroll position
            if (window.location.href.includes('fromEdit=1')) {
                // Check current URL parameters vs stored values - prioritize URL
                const urlParams = new URLSearchParams(window.location.search);
                
                // If URL contains search params, they take precedence over stored values
                const urlSearch = urlParams.get('search');
                const urlLabelFilter = urlParams.get('labelFilter');
                
                // Clear stored values if they don't match the URL (URL is the source of truth)
                if (urlSearch === null && sessionStorage.getItem('wordsManagerSearch')) {
                    sessionStorage.removeItem('wordsManagerSearch');
                }
                
                if (urlLabelFilter === null && sessionStorage.getItem('wordsManagerLabel')) {
                    sessionStorage.removeItem('wordsManagerLabel');
                }
                
                // Set a small delay to ensure all DOM elements are properly rendered
                setTimeout(function() {
                    const savedScrollPos = sessionStorage.getItem('wordsManagerScrollPos');
                    if (savedScrollPos) {
                        window.scrollTo(0, parseInt(savedScrollPos));
                        // Clear the saved position after using it
                        sessionStorage.removeItem('wordsManagerScrollPos');
                    }
                }, 100); // 100ms delay to ensure page content is rendered
            } else {
                // If not coming from edit page, clear all stored params
                sessionStorage.removeItem('wordsManagerScrollPos');
                sessionStorage.removeItem('wordsManagerPage');
                sessionStorage.removeItem('wordsManagerSearch');
                sessionStorage.removeItem('wordsManagerLabel');
            }
        };

        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.sentence-toggle').forEach(function(link){
                link.addEventListener('click', function(e){
                    var btn = this;
                    var currentRow = btn.closest('tr');
                    // Check if a subrow is already present
                    var nextRow = currentRow.nextElementSibling;
                    if(nextRow && nextRow.classList.contains('sentence-details')){
                        // Toggle display of the subrow
                        if(nextRow.style.display === 'none'){
                            nextRow.style.display = '';
                            btn.textContent = btn.getAttribute('data-count') + " zinnen";
                        } else {
                            nextRow.style.display = 'none';
                            btn.textContent = btn.getAttribute('data-count') + " zinnen (Show)";
                        }
                        return;
                    }
                    // Fetch sentences from fetch_sentences.php using lemma instead of word
                    var lemma = btn.getAttribute('data-lemma');
                    var woord = btn.getAttribute('data-word');
                    
                    // Use lemma for fetching if available, otherwise fall back to word
                    var searchTerm = lemma || woord;
                    
                    fetch('fetch_sentences.php?woord=' + encodeURIComponent(searchTerm))
                        .then(function(response){ return response.json(); })
                        .then(function(data){
                            var count = data.sentences ? data.sentences.length : 0;
                            btn.setAttribute('data-count', count);
                            btn.textContent = count + " zinnen";
                            
                            // Create container row for add sentence form
                            var formRow = document.createElement('tr');
                            formRow.classList.add('sentence-details', 'add-sentence-row');
                            var formTd = document.createElement('td');
                            formTd.colSpan = 7;
                            formTd.className = 'bg-light p-3';
                            
                            // Create form for adding new sentences
                            var form = document.createElement('form');
                            form.className = 'd-flex';
                            form.innerHTML = `
                                <div class="input-group">
                                    <input type="text" class="form-control" placeholder="Nieuwe zin toevoegen" required>
                                    <button type="submit" class="btn btn-primary">Toevoegen</button>
                                </div>
                            `;
                            
                            // Handle form submission
                            form.addEventListener('submit', function(e) {
                                e.preventDefault();
                                var input = this.querySelector('input');
                                var newSentence = input.value.trim();
                                
                                if(newSentence !== "") {
                                    fetch('addZinnen.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ 
                                            theme: "Woordenbeheer",
                                            sentences: [newSentence],
                                            isNewTheme: false,
                                            woord: woord // Keep this for logging, but it won't be inserted into the sentence table
                                        })
                                    })
                                    .then(function(response){ return response.json(); })
                                    .then(function(result){
                                        if(result.success) {
                                            // Create a new row for the sentence
                                            addSentenceRow(woord, newSentence, formRow);
                                            // Update count and button text
                                            count++;
                                            btn.setAttribute('data-count', count);
                                            btn.textContent = count + " zinnen";
                                            // Clear the input
                                            input.value = '';
                                        } else {
                                            alert("Fout bij toevoegen van zin: " + (result.error || "Onbekende fout"));
                                        }
                                    })
                                    .catch(function(err){
                                        console.error("Error adding sentence:", err);
                                    });
                                }
                            });
                            
                            formTd.appendChild(form);
                            formRow.appendChild(formTd);
                            
                            // Insert form row into DOM
                            currentRow.parentNode.insertBefore(formRow, currentRow.nextSibling);
                            
                            // Add existing sentences as individual rows
                            if(count > 0){
                                data.sentences.forEach(function(sentence){
                                    addSentenceRow(woord, sentence.zinString, formRow, sentence.ID);
                                });
                            } else {
                                // Create an empty state row
                                var emptyRow = document.createElement('tr');
                                emptyRow.classList.add('sentence-details');
                                var emptyTd = document.createElement('td');
                                emptyTd.colSpan = 7;
                                emptyTd.className = 'text-center text-muted py-3';
                                emptyTd.textContent = "Geen zinnen gevonden.";
                                emptyRow.appendChild(emptyTd);
                                currentRow.parentNode.insertBefore(emptyRow, formRow.nextSibling);
                            }
                        })
                        .catch(function(err){
                            console.error('Error fetching sentences:', err);
                        });
                });
            });
            
            // Helper function to add a sentence row
            function addSentenceRow(woord, text, referenceRow, sentenceId) {
                var sentenceRow = document.createElement('tr');
                sentenceRow.classList.add('sentence-details');
                if (sentenceId) {
                    sentenceRow.setAttribute('data-id', sentenceId);
                }
                
                var sentenceTd = document.createElement('td');
                sentenceTd.colSpan = 7;
                sentenceTd.className = 'ps-4 py-2 border-bottom';
                
                // Create a card-like container for each sentence
                var sentenceContent = document.createElement('div');
                sentenceContent.className = 'd-flex justify-content-between align-items-center';
                
                // Sentence text
                var sentenceText = document.createElement('div');
                sentenceText.textContent = text;
                sentenceContent.appendChild(sentenceText);
                
                // Action buttons
                var actionButtons = document.createElement('div');
                actionButtons.className = 'btn-group btn-group-sm';
                
                var editBtn = document.createElement('button');
                editBtn.className = 'btn btn-outline-secondary';
                editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
                editBtn.title = 'Bewerken';
                editBtn.addEventListener('click', function() {
                    var newText = prompt('Zin bewerken:', text);
                    if (newText && newText.trim() !== '' && newText !== text) {
                        if (sentenceId) {
                            // Call API to update the sentence in database
                            fetch('editSentence.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ 
                                    id: sentenceId,
                                    text: newText
                                })
                            })
                            .then(function(response) { return response.json(); })
                            .then(function(result) {
                                if (result.success) {
                                    // Update the UI
                                    sentenceText.textContent = newText;
                                    // Update stored text for future edits
                                    text = newText;
                                } else {
                                    alert("Fout bij bewerken van zin: " + (result.error || "Onbekende fout"));
                                }
                            })
                            .catch(function(err) {
                                console.error("Error editing sentence:", err);
                                alert("Fout bij bewerken van zin: " + err.message);
                            });
                        } else {
                            // For newly added sentences without IDs yet
                            sentenceText.textContent = newText;
                        }
                    }
                });
                
                var deleteBtn = document.createElement('button');
                deleteBtn.className = 'btn btn-outline-danger';
                deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
                deleteBtn.title = 'Verwijderen';
                deleteBtn.addEventListener('click', function() {
                    if (confirm('Weet je zeker dat je deze zin wilt verwijderen?')) {
                        if (sentenceId) {
                            // Call API to delete the sentence from database
                            fetch('deleteSentence.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id: sentenceId })
                            })
                            .then(function(response) { return response.json(); })
                            .then(function(result) {
                                if (result.success) {
                                    // Remove the row from UI
                                    sentenceRow.remove();
                                    // Update count displayed in toggle button
                                    var btn = document.querySelector('.sentence-toggle[data-word="' + woord + '"]');
                                    if (btn) {
                                        var count = parseInt(btn.getAttribute('data-count')) - 1;
                                        btn.setAttribute('data-count', count);
                                        btn.textContent = count + " zinnen";
                                    }
                                } else {
                                    alert("Fout bij verwijderen van zin: " + (result.error || "Onbekende fout"));
                                }
                            })
                            .catch(function(err) {
                                console.error("Error deleting sentence:", err);
                                alert("Fout bij verwijderen van zin: " + err.message);
                            });
                        } else {
                            // For newly added sentences without IDs yet
                            sentenceRow.remove();
                        }
                    }
                });
                
                actionButtons.appendChild(editBtn);
                actionButtons.appendChild(deleteBtn);
                sentenceContent.appendChild(actionButtons);
                
                sentenceTd.appendChild(sentenceContent);
                sentenceRow.appendChild(sentenceTd);
                
                // Insert after reference row
                referenceRow.parentNode.insertBefore(sentenceRow, referenceRow.nextSibling);
                
                return sentenceRow;
            }
        });
    </script>
</body>
</html>
