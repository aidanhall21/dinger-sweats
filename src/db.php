<?php

function getDbConnection() {
    // Use RENDER_DISK_PATH for persistent storage when deployed
    $dbPath = getenv('RENDER_DISK_PATH') 
        ? getenv('RENDER_DISK_PATH') . '/database.sqlite'
        : __DIR__ . '/../database/data.db';

    try {
        // Create database directory if it doesn't exist
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        // Recommended: set error mode to exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
} 