<?php
require_once __DIR__ . '/../src/db.php';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sweating Dingers | Home Page</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css">
    <style>
        .main-content {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 300px); /* Reduced height to prevent scrolling */
            padding: 20px;
        }
        
        .main-content h1 {
            margin-bottom: 10px;
        }
        
        .main-content h3 {
            margin-bottom: 8px;
        }
        
        .main-content h4 {
            margin-bottom: 20px;
        }
        
        .main-content p {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <div class="main-content">
        <h1>Welcome to Sweating Dingers!</h1>
        <!-- <h3>Your 2024 Dinger regular season data review site</h3> -->
        <h4>Please see <a href="https://x.com/tistonionwings/status/1905676475988410864" target="_blank">this post</a> for an update on 2025 progress.</h4>
        <!-- <p>Select a leaderboard above to begin.</p> -->
    </div>
    
    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
    
</body>
</html> 