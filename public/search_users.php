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
    
    // Search for usernames that match the query
    $stmt = $pdo->prepare("
        SELECT DISTINCT username 
        FROM leaderboard 
        WHERE username LIKE :query 
        ORDER BY username 
        LIMIT 10
    ");
    
    $stmt->execute([':query' => "%$query%"]);
    $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($usernames);
} catch (PDOException $e) {
    // Return empty array on error
    echo json_encode([]);
} 