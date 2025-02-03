<?php
require_once __DIR__ . '/../src/db.php';

// Get search term from GET parameter
$search = isset($_GET['term']) ? trim($_GET['term']) : '';

if (strlen($search) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = getDbConnection();

// Search for players
$query = "
    SELECT DISTINCT picks_player_id, picks_player_name 
    FROM picks_info 
    WHERE picks_player_name LIKE :search 
    ORDER BY picks_player_name 
    LIMIT 10
";

$stmt = $pdo->prepare($query);
$stmt->execute([':search' => "%$search%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results); 