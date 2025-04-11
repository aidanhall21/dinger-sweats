<?php
require_once __DIR__ . '/../src/db.php';

// Get the search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // Search for players that match the query
    $stmt = $pdo->prepare("
        SELECT id as picks_player_id, CONCAT(first_name, ' ', last_name) as picks_player_name 
        FROM players 
        WHERE CONCAT(first_name, ' ', last_name) LIKE :query 
        ORDER BY
            first_name, 
            last_name
        LIMIT 10
    ");
    
    $stmt->execute([':query' => "%$query%"]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($players);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode([]);
} 