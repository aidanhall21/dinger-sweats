<?php
require_once __DIR__ . '/../src/db.php';

// Get search term from GET parameter
$search = isset($_GET['term']) ? trim($_GET['term']) : '';

if (strlen($search) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = getDbConnection();

// Search for players using the players table
$query = "
    SELECT id as picks_player_id, CONCAT(first_name, ' ', last_name) as picks_player_name 
    FROM players 
    WHERE CONCAT(first_name, ' ', last_name) LIKE :search 
    ORDER BY 
        CASE 
            WHEN adp = '-' OR adp IS NULL THEN 999999 
            ELSE CAST(adp AS DECIMAL(10,1)) 
        END ASC,
        first_name, 
        last_name
    LIMIT 10
";

$stmt = $pdo->prepare($query);
$stmt->execute([':search' => "%$search%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results); 