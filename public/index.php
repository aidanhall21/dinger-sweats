<?php
// Provide links to the two pages. You could use a router or basic links.
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sweating Dingers</title>
    <style>
        nav {
            text-align: center;
            margin: 20px 0;
        }
        h1, h3, h4 {
            text-align: center;
        }
        p {
            text-align: center;
        }
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            left: 50%;
            transform: translateX(-50%);
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }
        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            text-align: center;
        }
        .dropdown-content a:hover {
            background-color: #f1f1f1;
        }
        .nav-button {
            background-color: #f2f2f2;
            color: black;
            padding: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .nav-separator {
            margin: 0 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <nav>
        <div class="dropdown">
            <a href="#" class="nav-button">Player Leaderboard</a>
            <div class="dropdown-content">
                <a href="leaderboard_players.php">Total Scores</a>
                <a href="leaderboard_players_weekly.php">Weekly Scores</a>
            </div>
        </div>
        <span class="nav-separator">|</span>
        <a href="leaderboard_teams.php" class="nav-button">Team Leaderboard</a>
    </nav>
    <h1>Welcome to Sweating Dingers!</h1>
    <h3>Your 2024 Dinger regular season data review site</h3>
    <h4>New features added daily! 2025 live updates coming soon!</h4>
    <p>Select a leaderboard above to begin.</p>
    
    <footer style="text-align: center; padding: 20px; margin-top: 40px; color: #666; font-size: 0.8em;">
        <p>© 2025 Aidan Hall. All rights reserved.</p>
        <p>This site is unaffiliated with Underdog Fantasy. All stats are unofficial.</p>
        <p>Questions? Reach out on <a href="https://x.com/tistonionwings" target="_blank" rel="noopener noreferrer" style="color: #666; text-decoration: underline;">X (Twitter)</a></p>
    </footer>
</body>
</html> 