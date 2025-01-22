<?php

function getDbConnection() {
    // Example path: /var/www/myproject/database/mydatabase.db
    $databasePath = __DIR__ . '/../database/data.db'; 
    // If db.php is in /var/www/myproject, then we can do __DIR__ . '/database'

    try {
        $pdo = new PDO('sqlite:' . $databasePath);
        // Recommended: set error mode to exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        echo 'Connection failed: ' . $e->getMessage();
        exit;
    }
} 