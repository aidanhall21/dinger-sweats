<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();
$term = isset($_GET['term']) ? trim($_GET['term']) : '';

if (strlen($term) < 1) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, abbr, name
    FROM teams
    WHERE abbr LIKE :term
    OR name LIKE :term
    ORDER BY abbr
    LIMIT 10
");

$stmt->execute([':term' => "%$term%"]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($teams); 