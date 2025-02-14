<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Ensure we have a username to look up
if (!isset($_GET['username'])) {
    die('No username provided.');
}

// Retrieve and sanitize the username from the URL
$username = trim($_GET['username']);

// Add this near the top of the file, after getting the username
$fromTeamId = isset($_GET['from_team']) ? $_GET['from_team'] : null;

// Verify the username exists in the database
$stmtUser = $pdo->prepare("
    SELECT DISTINCT username 
    FROM usable_points 
    WHERE username = :username
");
$stmtUser->execute([':username' => $username]);
$userExists = $stmtUser->fetchColumn();

if (!$userExists) {
    die('User not found.');
}

// Update the query to include draft_entry_id
$stmtUserTeams = $pdo->prepare("
    SELECT username, draft_order, points_ahead, league_place, draft_entry_id
    FROM leaderboard
    WHERE username = :username
    ORDER BY points_ahead DESC
");
$stmtUserTeams->execute([':username' => $username]);
$userTeams = $stmtUserTeams->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Details - <?php echo htmlentities($username); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .home-link {
            display: inline-block;
            margin: 1rem;
            padding: 0.5rem 1rem;
            background: #f2f2f2;
            text-decoration: none;
            color: black;
            border-radius: 4px;
        }
        .home-link:hover {
            background: #e0e0e0;
        }
        h1 {
            text-align: center;
        }
        .visualization {
            width: 800px;
            margin: 1rem auto;
            padding: 0.5rem;
        }
        
        .bar-container {
            position: relative;
        }
        
        .team-row {
            display: flex;
            align-items: center;
            margin: 0.3rem 0;
            height: 20px;
        }
        
        .team-name {
            width: 200px;
            text-align: right;
            padding-right: 1rem;
            font-size: 0.9em;
        }
        
        .bar-area {
            flex-grow: 1;
            display: flex;
            justify-content: flex-start;
            position: relative;
            margin-left: 150px;
        }

        .points-bar {
            position: absolute;
            height: 14px;
            opacity: 0.8;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .points-bar:hover {
            opacity: 1;
        }

        .tooltip {
            position: fixed;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 14px;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
            transform: translateZ(0);
            display: none;
        }
    </style>
</head>
<body>
    <?php if ($fromTeamId): ?>
        <a href="team_details.php?draft_entry_id=<?php echo urlencode($fromTeamId); ?>" class="home-link">← Back to Team Details</a>
    <?php else: ?>
        <a href="leaderboard_teams.php" class="home-link">← Back to Leaderboard</a>
    <?php endif; ?>
    <h1>Teams for <?php echo htmlentities($username); ?></h1>
    
    <div class="visualization">
        <div class="bar-container">
            <div class="tooltip"></div>
            <?php foreach ($userTeams as $team): ?>
                <div class="team-row">
                    <div class="team-name">
                        <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>" 
                           style="text-decoration: none; color: inherit;">
                            <?php echo htmlentities($team['username']); ?> (<?php echo $team['draft_order']; ?>)
                        </a>
                    </div>
                    
                    <div class="bar-area">
                        <?php
                        $points = $team['points_ahead'];
                        $maxPoints = max(array_map(function($t) { return abs($t['points_ahead']); }, $userTeams));
                        $width = $maxPoints > 0 ? (abs($points) / $maxPoints) * 300 : 0;
                        $tooltipText = number_format(abs($points)) . ' points ' . ($points >= 0 ? 'ahead' : 'behind');
                        
                        if ($points >= 0):
                        ?>
                            <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>" 
                               style="text-decoration: none;">
                                <div class="points-bar" 
                                     style="left: 150px; width: <?php echo $width; ?>px; background: #ffd700;"
                                     data-tooltip="<?php echo $tooltipText; ?>">
                                </div>
                            </a>
                        <?php else: ?>
                            <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>" 
                               style="text-decoration: none;">
                                <div class="points-bar" 
                                     style="left: <?php echo 150 - $width; ?>px; width: <?php echo $width; ?>px; background: #ff4444;"
                                     data-tooltip="<?php echo $tooltipText; ?>">
                                </div>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tooltip = document.querySelector('.tooltip');
        const bars = document.querySelectorAll('.points-bar');

        bars.forEach(bar => {
            bar.addEventListener('mousemove', (e) => {
                const tooltipText = bar.getAttribute('data-tooltip');
                tooltip.textContent = tooltipText;
                tooltip.style.display = 'block';
                
                // Position tooltip 20px above cursor
                const x = e.clientX - (tooltip.offsetWidth / 2);
                const y = e.clientY - tooltip.offsetHeight - 20;
                
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            });

            bar.addEventListener('mouseout', () => {
                tooltip.style.display = 'none';
            });
        });
    });
    </script>

    <p style="text-align: center;">Additional user details coming soon...</p>
</body>
</html> 