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
    SELECT DISTINCT player_id, player_name 
    FROM picks 
    WHERE player_name LIKE :search 
    ORDER BY player_name 
    LIMIT 10
";

$stmt = $pdo->prepare($query);
$stmt->execute([':search' => "%$search%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results); 