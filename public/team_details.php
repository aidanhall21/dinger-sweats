<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Ensure we have a draft_entry_id to look up.
if (!isset($_GET['draft_entry_id'])) {
    die('No draft_entry_id provided.');
}

// Retrieve the draft_entry_id from the URL.
$draftEntryId = trim($_GET['draft_entry_id']);

// First, fetch the team's username from the leaderboard table.
$stmtUser = $pdo->prepare("
    SELECT username 
    FROM leaderboard 
    WHERE draft_entry_id = :draft_entry_id
    LIMIT 1
");
$stmtUser->execute([':draft_entry_id' => $draftEntryId]);
$username = $stmtUser->fetchColumn();

if (!$username) {
    die('Team not found or invalid draft_entry_id.');
}

// Next, fetch all players from the picks table for that draft_entry_id,
// joining to players to get the position ID, then to positions to get the position name.
// Order first by position in order of P→IF→OF, and then by team_pick_number ascending.
$stmtRoster = $pdo->prepare("
    WITH player_totals AS (
        SELECT 
            player_id,
            total_points,
            used_points
        FROM usable_points
        WHERE draft_entry_id = :draft_entry_id
    )
    SELECT 
        p.picks_player_id,
        p.picks_player_name,
        p.players_slotName,
        CAST(p.picks_overall_pick_number AS INTEGER) as picks_overall_pick_number,
        COALESCE(pt.total_points, 0) as total_points,
        COALESCE(pt.used_points, 0) as used_points
    FROM picks_info p
    LEFT JOIN player_totals pt ON pt.player_id = p.picks_player_id
    WHERE p.picks_draft_entry_id = :draft_entry_id
    ORDER BY
        CASE 
            WHEN p.players_slotName = 'P' THEN 1
            WHEN p.players_slotName = 'IF' THEN 2
            WHEN p.players_slotName = 'OF' THEN 3
            ELSE 4
        END,
        CAST(p.picks_overall_pick_number AS INTEGER) ASC
");
$stmtRoster->execute([':draft_entry_id' => $draftEntryId]);
$roster = $stmtRoster->fetchAll(PDO::FETCH_ASSOC);

// Fetch weekly scores data by joining the scores and weeks tables
$stmtScores = $pdo->prepare("
    SELECT w.week_number, s.total_points
    FROM scores s
    JOIN weeks w ON s.week_id = w.week_id
    WHERE s.draft_entry_id = :draft_entry_id
    AND w.week_number <= 16
    ORDER BY w.week_number ASC
");
$stmtScores->execute([':draft_entry_id' => $draftEntryId]);
$weeklyScores = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

// Comment out statistics query since it's not being used
/*
$stmtStats = $pdo->prepare("
    SELECT metric, value
    FROM score_statistics
");
$stmtStats->execute();
$stats = [];
while ($row = $stmtStats->fetch(PDO::FETCH_ASSOC)) {
    $stats[$row['metric']] = $row['value'];
}
*/

// Initialize empty stats array to avoid JavaScript errors
$stats = [];

// Add query to get TOT statistics for both hitters and pitchers
$stmtTotStats = $pdo->prepare("
    SELECT table_name, metric, value
    FROM tot_statistics
    WHERE metric = 'median'
");
$stmtTotStats->execute();
$totStats = [];
while ($row = $stmtTotStats->fetch(PDO::FETCH_ASSOC)) {
    $totStats[$row['table_name']] = $row['value'];
}

// Prepare arrays for Chart.js
$weekNumbers = array_column($weeklyScores, 'week_number');
$totalPoints = array_column($weeklyScores, 'total_points');

// Calculate the median used points from the roster
$usedPointsArray = array_column($roster, 'used_points');
sort($usedPointsArray);
$medianUsedPoints = $usedPointsArray[floor(count($usedPointsArray)/2)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Details</title>
    <style>
        body {
            font-family: Arial, sans-serif;
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
        table {
            border-collapse: collapse;
            margin: 0.5rem auto;
            width: 60%;
        }
        th, td {
            border: 1px solid #999;
            padding: 0.4rem;
            text-align: center;
        }
        thead {
            background: #f2f2f2;
        }
        h1 {
            text-align: center;
        }
        /* Center the canvas and make it narrower */
        .chart-container {
            width: 60%;
            margin: 0.5rem auto;
        }
        /* Add total points coloring styles */
        .total-points {
            position: relative;
        }
        
        .total-points::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }
        
        .total-points.above::before {
            background-color: #00ff00;
            opacity: calc(var(--points-diff) * 0.8);
        }
        
        .total-points.below::before {
            background-color: #ff0000;
            opacity: calc(var(--points-diff) * 0.8);
        }
        
        /* Add used points coloring styles */
        .used-points {
            position: relative;
        }
        
        .used-points::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }
        
        .used-points.above::before {
            background-color: #00ff00;
            opacity: calc(var(--points-diff) * 0.8);
        }
        
        .used-points.below::before {
            background-color: #ff0000;
            opacity: calc(var(--points-diff) * 0.8);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
</head>
<body>
    <a href="leaderboard_teams.php" class="home-link">← Back to Leaderboard</a>
    <h1>Team Details for <?php echo htmlentities($username); ?></h1>

    <?php if (count($roster) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Player Name</th>
                    <th>Position</th>
                    <th>Overall Pick #</th>
                    <th>Total Points</th>
                    <th>Used Points</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $player): ?>
                <tr>
                    <td><?php echo htmlentities($player['picks_player_name']); ?></td>
                    <td><?php echo htmlentities($player['players_slotName']); ?></td>
                    <td><?php echo htmlentities($player['picks_overall_pick_number']); ?></td>
                    <td class="total-points <?php 
                        $position = $player['players_slotName'];
                        $points = (float)$player['total_points'];
                        $median = ($position === 'P') ? $totStats['pitchers'] : $totStats['hitters'];
                        $diff = abs($points - $median);
                        $maxDiff = $median;
                        $normalizedDiff = min($diff / $maxDiff, 1);
                        echo $points > $median ? 'above' : 'below';
                    ?>" style="--points-diff: <?php echo $normalizedDiff; ?>">
                        <?php echo htmlentities($player['total_points']); ?>
                    </td>
                    <td class="used-points <?php 
                        $usedPoints = (float)$player['used_points'];
                        $diff = abs($usedPoints - $medianUsedPoints);
                        $maxDiff = max(array_map('abs', array_map(function($p) use ($medianUsedPoints) {
                            return (float)$p - $medianUsedPoints;
                        }, $usedPointsArray)));
                        $normalizedDiff = $maxDiff > 0 ? min($diff / $maxDiff, 1) : 0;
                        echo $usedPoints > $medianUsedPoints ? 'above' : 'below';
                    ?>" style="--points-diff: <?php echo $normalizedDiff; ?>">
                        <?php echo htmlentities($player['used_points']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center;">No roster data found for this team.</p>
    <?php endif; ?>

    <!-- Chart container -->
    <div class="chart-container">
        <canvas id="weeklyScoresChart"></canvas>
    </div>

    <script>
        const weekNumbers = JSON.parse('<?php echo json_encode($weekNumbers); ?>');
        const totalPoints = JSON.parse('<?php echo json_encode($totalPoints); ?>');
        const stats = JSON.parse('<?php echo json_encode($stats); ?>');

        const ctx = document.getElementById('weeklyScoresChart').getContext('2d');
        const weeklyScoresChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: weekNumbers,
                datasets: [{
                    label: 'Points',
                    data: totalPoints,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        ticks: {
                            display: true
                        },
                        grid: {
                            drawBorder: false,
                            display: true
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Week Number'
                        }
                    }
                },
                plugins: {
                    annotation: {
                        annotations: {
                            /* Comment out all reference lines
                            median: {
                                type: 'line',
                                yMin: stats['median'],
                                yMax: stats['median'],
                                borderColor: 'rgba(255, 165, 0, 0.8)',
                                borderWidth: 2,
                                borderDash: [6, 6],
                                label: {
                                    enabled: true,
                                    content: 'Median',
                                    position: 'end'
                                }
                            },
                            p20: {
                                type: 'line',
                                yMin: stats['percentile_20'],
                                yMax: stats['percentile_20'],
                                borderColor: 'rgba(255, 150, 150, 0.8)',
                                borderWidth: 2,
                                borderDash: [6, 6],
                                label: {
                                    enabled: true,
                                    content: '20th Percentile',
                                    position: 'end'
                                }
                            },
                            p80: {
                                type: 'line',
                                yMin: stats['percentile_80'],
                                yMax: stats['percentile_80'],
                                borderColor: 'rgba(150, 255, 150, 0.8)',
                                borderWidth: 2,
                                borderDash: [6, 6],
                                label: {
                                    enabled: true,
                                    content: '80th Percentile',
                                    position: 'end'
                                }
                            },
                            p05: {
                                type: 'line',
                                yMin: stats['percentile_05'],
                                yMax: stats['percentile_05'],
                                borderColor: 'rgba(200, 0, 0, 0.8)',
                                borderWidth: 2,
                                borderDash: [6, 6],
                                label: {
                                    enabled: true,
                                    content: '5th Percentile',
                                    position: 'end'
                                }
                            },
                            p95: {
                                type: 'line',
                                yMin: stats['percentile_95'],
                                yMax: stats['percentile_95'],
                                borderColor: 'rgba(0, 150, 0, 0.8)',
                                borderWidth: 2,
                                borderDash: [6, 6],
                                label: {
                                    enabled: true,
                                    content: '95th Percentile',
                                    position: 'end'
                                }
                            }
                            */
                        }
                    },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            label: function(context) {
                                return 'Points: ' + context.formattedValue;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html> 