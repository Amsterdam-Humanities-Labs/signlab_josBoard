<?php
error_reporting(E_ERROR | E_PARSE);

include('../mysql_config.php');

$words = $_GET['words'] ?? '';
$wordsArray = explode(" ", $words);

// Create a connection to the database
$conn = new mysqli($servername, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Set to utf8mb4 instead of utf8 for better compatibility
$conn->set_charset("utf8mb4");

// Prepare statement for fetching jb_woorden
$stmt = $conn->prepare("SELECT * FROM jb_woorden WHERE woord = ?");
$stmt->bind_param("s", $woord);
$wordData = [];

foreach ($wordsArray as $woord) {
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $wordData[$woord] = $row;
    }
}
$stmt->close();

// Preload matched_transcriptions data into memory - use CONVERT for consistency
$transcriptions = [];
$transcriptionSql = "SELECT * FROM matched_transcriptions WHERE (CONVERT(zOg USING utf8mb4) = CONVERT('Glos' USING utf8mb4) OR CONVERT(zOg USING utf8mb4) = CONVERT('' USING utf8mb4)) AND CONVERT(added USING utf8mb4) != CONVERT('DELETE' USING utf8mb4)";
$transcriptionResult = $conn->query($transcriptionSql);

if ($transcriptionResult) {
    while ($transcriptionRow = $transcriptionResult->fetch_assoc()) {
        if (isset($wordData[$transcriptionRow['m_transcription']])) {
            continue;
        }
        $transcriptions[$transcriptionRow['definitive_outcome']][] = $transcriptionRow;
    }
}

$data = [];

foreach ($wordData as $row) {
    $videoLeft = [];
    $videoCenter = [];
    $videoRight = [];

    if (isset($transcriptions[$row['id']])) {
        foreach ($transcriptions[$row['id']] as $transcriptionRow) {
            $videoLeft[] = ["file" => str_replace(".wav", ".mp4", $transcriptionRow["l_file"])];
            $videoCenter[] = [
                "file" => str_replace(".wav", ".mp4", $transcriptionRow["m_file"]),
                "id" => strval($transcriptionRow["id"]),      // Ensure id is string
                "added" => $transcriptionRow["added"]
            ];
            $videoRight[] = ["file" => str_replace(".wav", ".mp4", $transcriptionRow["r_file"])];
            
            $processed = ($transcriptionRow["post_processed"] == "1") ? "2" : "1";
        }
    }

    //for usd file we are going to look in mocap_files and retrieve the latest .glb file from filename and change .glb to .usdz file
    $usd_file = "";
    // Use prepared statement with CONVERT for this query too
    $mocapSql = "SELECT * FROM mocap_files WHERE CONVERT(woord USING utf8mb4) = CONVERT(? USING utf8mb4) ORDER BY id DESC LIMIT 1";
    $stmtMocap = $conn->prepare($mocapSql);
    if ($stmtMocap) {
        $stmtMocap->bind_param("s", $row['woord']);
        $stmtMocap->execute();
        $mocapResult = $stmtMocap->get_result();
        if ($mocapResult) {
            $mocapRow = $mocapResult->fetch_assoc();
            if ($mocapRow) {
                $usd_file = str_replace(".fbx", ".usdz", $mocapRow["filename"]);
                $usd_file = str_replace(".glb", ".usdz", $usd_file);
            }
        }
        $stmtMocap->close();
    }

    $data[] = [
        "processed" => $processed ?? "",
        "woord" => htmlspecialchars($row["woord"] ?? "", ENT_QUOTES, 'UTF-8'),
        "lemma" => htmlspecialchars($row["lemma"] ?? "", ENT_QUOTES, 'UTF-8'),
        "id" => strval($row["id"] ?? ""), // Convert id to string
        "videoLeft" => json_encode($videoLeft, JSON_UNESCAPED_UNICODE),
        "videoCenter" => json_encode($videoCenter, JSON_UNESCAPED_UNICODE),
        "videoRight" => json_encode($videoRight, JSON_UNESCAPED_UNICODE),
        "thema" => htmlspecialchars($row["thema"] ?? "", ENT_QUOTES, 'UTF-8'),
        "usd_file" => htmlspecialchars($usd_file ?? "", ENT_QUOTES, 'UTF-8'),
    ];
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode($data, JSON_UNESCAPED_UNICODE);

$conn->close();
?>
