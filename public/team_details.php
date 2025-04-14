<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Ensure we have a draft_entry_id to look up.
if (!isset($_GET['draft_entry_id'])) {
    die('No draft_entry_id provided.');
}

// Retrieve the draft_entry_id from the URL.
$draftEntryId = trim($_GET['draft_entry_id']);

// Track where the user came from
$fromUserDetails = isset($_GET['from_user']) ? $_GET['from_user'] : null;
$fromTeamDetails = isset($_GET['from_team']) ? $_GET['from_team'] : null;
$fromAdvance = isset($_GET['from_advance']) ? $_GET['from_advance'] : null;
$minDrafts = isset($_GET['min_drafts']) ? $_GET['min_drafts'] : null;

// Get username, draft_order, league_place, and draft_place from leaderboard and join with usable_points
$stmtUser = $pdo->prepare("
    SELECT 
        l.username, 
        l.league_place,
        l.wild_card,
        td.player_id,
        td.player_name,
        td.slot_name,
        SUM(td.total_points) as total_points,
        SUM(td.usable_points) as usable_points,
        td.overall_pick_number
    FROM leaderboard l
    LEFT JOIN team_details td ON l.draft_entry_id = td.draft_entry_id
    WHERE l.draft_entry_id = :draft_entry_id
    GROUP BY 
        l.username, 
        l.league_place,
        l.wild_card,
        td.player_id,
        td.player_name,
        td.slot_name,
        td.overall_pick_number
    ORDER BY 
        CASE td.slot_name 
            WHEN 'P' THEN 1 
            WHEN 'IF' THEN 2 
            WHEN 'OF' THEN 3 
        END,
        td.overall_pick_number ASC
");
$stmtUser->execute([':draft_entry_id' => $draftEntryId]);
$results = $stmtUser->fetchAll(PDO::FETCH_ASSOC);

if (empty($results)) {
    die('Team not found or invalid draft_entry_id.');
}

// Get username and draft_order from first row
$username = $results[0]['username'];
//$draftOrder = $results[0]['pick_order'];
$leaguePlace = $results[0]['league_place'];
$isAdvancing = $results[0]['league_place'] <= 2;
$isWildCard = $results[0]['wild_card'] === 1;
// Format roster data
$roster = array_map(function($row) {
    return [
        'player_id' => $row['player_id'],
        'player_name' => $row['player_name'],
        'slot_name' => $row['slot_name'],
        'total_points' => $row['total_points'],
        'usable_points' => $row['usable_points'],
        'overall_pick_number' => $row['overall_pick_number']
    ];
}, $results);

// Fetch weekly scores data from top_position_scores
$stmtScores = $pdo->prepare("
    SELECT 
        w.week_number,
        SUM(CASE WHEN slot_name = 'P' THEN usable_points ELSE 0 END) as pitcher_points,
        SUM(CASE WHEN slot_name = 'IF' THEN usable_points ELSE 0 END) as infield_points,
        SUM(CASE WHEN slot_name = 'OF' THEN usable_points ELSE 0 END) as outfield_points,
        SUM(usable_points) as usable_points
    FROM team_details td
    JOIN weeks w ON td.week_id = w.week_id
    WHERE td.draft_entry_id = :draft_entry_id
    GROUP BY w.week_number
    ORDER BY w.week_number ASC
");
$stmtScores->execute([':draft_entry_id' => $draftEntryId]);
$weeklyScores = $stmtScores->fetchAll(PDO::FETCH_ASSOC);

// Add query to get weekly averages
$stmtAverages = $pdo->prepare("
    SELECT week_number, average_advancing_score 
    FROM weekly_averages
    ORDER BY week_number ASC
");
$stmtAverages->execute();
$weeklyAverages = $stmtAverages->fetchAll(PDO::FETCH_ASSOC);

$averageScores = array_column($weeklyAverages, 'average_advancing_score');

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
$pitcherPoints = array_column($weeklyScores, 'pitcher_points');
$infieldPoints = array_column($weeklyScores, 'infield_points');
$outfieldPoints = array_column($weeklyScores, 'outfield_points');

// Calculate the median used points from the roster
$usedPointsArray = array_column($roster, 'usable_points');
sort($usedPointsArray);
$medianUsedPoints = $usedPointsArray[floor(count($usedPointsArray)/2)];

// Get total used points
$totalUsedPoints = array_sum(array_column($roster, 'usable_points'));

// After getting the initial team data, get all teams in same draft
$stmtLeague = $pdo->prepare("
    SELECT draft_entry_id, team_score, username, league_place, wild_card
    FROM leaderboard
    WHERE draft_id = (
        SELECT draft_id 
        FROM leaderboard 
        WHERE draft_entry_id = :draft_entry_id
    )
    ORDER BY team_score DESC
");
$stmtLeague->execute([':draft_entry_id' => $draftEntryId]);
$leagueTeams = $stmtLeague->fetchAll(PDO::FETCH_ASSOC);

// Calculate min and max scores for scaling
$maxScore = $leagueTeams[0]['team_score'];
$minScore = end($leagueTeams)['team_score'];

// Modify the weekly player scores query to include week_id
$stmtWeeklyScores = $pdo->prepare("
    SELECT 
        td.player_id,
        td.total_points as weekly_points,
        td.position_rank,
        w.week_id,
        w.week_number
    FROM team_details td
    JOIN weeks w ON td.week_id = w.week_id
    WHERE td.draft_entry_id = :draft_entry_id
");
$stmtWeeklyScores->execute([':draft_entry_id' => $draftEntryId]);
$weeklyPlayerScores = [];
while ($row = $stmtWeeklyScores->fetch(PDO::FETCH_ASSOC)) {
    $weeklyPlayerScores[$row['player_id']][$row['week_number']] = [
        'points' => $row['weekly_points'],
        'rank' => $row['position_rank'],
        'week_id' => $row['week_id']
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sweating Dingers | Draft Information | <?php echo htmlentities($username); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css">
    <style>
        /* Table styles */
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
        
        /* Add position coloring */
        .position {
            position: relative;
        }
        
        .position::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }
        
        .position.pitcher::before {
            background-color: #800080;  /* Purple */
        }
        
        .position.infield::before {
            background-color: #008800;  /* Green */
        }
        
        .position.outfield::before {
            background-color: #FFA500;  /* Orange */
        }
        
        svg a circle {
            cursor: pointer;
            transition: r 0.2s;
        }
        
        svg a circle:hover {
            r: 8;
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
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <h1>Team Details for <?php echo htmlentities($username); ?></h1>
    
    <div style="width: 300px; margin: 1rem auto; padding: 1rem; background: #f8f8f8; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong>Points:</strong> <?php echo number_format($totalUsedPoints, 0); ?>
            </div>
            <div>
                <strong>Place:</strong> <?php echo $leaguePlace; ?>
            </div>
            <div>
                <strong>Status:</strong> 
                <?php if ($isAdvancing): ?>
                    <span style="color: #008800; font-weight: bold;">ADV</span>
                <?php elseif ($isWildCard): ?>
                    <span style="color: #0066cc; font-weight: bold;">WC</span>
                <?php else: ?>
                    <span>-</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="width: 800px; margin: 1rem auto; padding: 0.5rem; position: relative;">
        <svg width="100%" height="60" viewBox="-20 0 840 60">
            <!-- Base line -->
            <line x1="0" y1="30" x2="800" y2="30" 
                  stroke="#ccc" 
                  stroke-width="2"/>
            
            <?php
            foreach ($leagueTeams as $team) {
                // Calculate x position (0-800 range now)
                $xPos = 800 * ($team['team_score'] - $minScore) / ($maxScore - $minScore);
                $isCurrentTeam = $team['draft_entry_id'] === $draftEntryId;
                $isAdvancing = $team['league_place'] <= 2;
                $isWildCard = $team['wild_card'] === 1;
                
                // Determine fill and stroke colors
                $fill = '#fff';
                $stroke = '#666';
                
                if ($isCurrentTeam && $isAdvancing) {
                    $fill = '#008800';
                    $stroke = '#ffd700';
                } elseif ($isCurrentTeam && $isWildCard) {
                    $fill = '#0066cc';
                    $stroke = '#ffd700';
                } elseif ($isCurrentTeam) {
                    $fill = '#ffd700';
                    $stroke = '#000';
                } elseif ($isAdvancing) {
                    $fill = '#008800';
                    $stroke = '#008800';
                } elseif ($isWildCard) {
                    $fill = '#0066cc';
                    $stroke = '#0066cc';
                }
                
                // Create a clickable group with preserved navigation parameters
                echo sprintf('
                    <a href="team_details.php?draft_entry_id=%s&from_team=%s%s%s" class="dot-link" data-username="%s" data-points="%s">
                        <circle cx="%d" cy="30" r="6" 
                               fill="%s" 
                               stroke="%s" 
                               stroke-width="2"/>
                    </a>',
                    htmlspecialchars($team['draft_entry_id']),
                    htmlspecialchars($draftEntryId),
                    $fromAdvance ? '&from_advance=1' : '',
                    $minDrafts ? '&min_drafts=' . htmlspecialchars($minDrafts) : '',
                    htmlspecialchars($team['username']),
                    number_format($team['team_score'], 0),
                    $xPos,
                    $fill,
                    $stroke
                );
            }
            ?>
        </svg>
        <div class="tooltip" style="display: none;"></div>
    </div>

    <p style="text-align: center;">
        View all teams for <a href="user_details.php?username=<?php echo urlencode($username); ?>&from_team=<?php echo urlencode($draftEntryId); ?><?php 
            echo $fromAdvance ? '&from_advance=1' : ''; 
            echo $minDrafts ? '&min_drafts=' . urlencode($minDrafts) : ''; 
        ?>">
            <?php echo htmlentities($username); ?>
        </a>
    </p>

    <?php if (count($roster) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Player</th>
                    <th>Position</th>
                    <th>Draft Pick</th>
                    <th class="points-col">Total Points</th>
                    <th class="points-col">Used Points</th>
                </tr>
            </thead>
            <tbody id="rosterBody">
            <?php foreach ($roster as $player): ?>
                <tr>
                    <td><a href="player.php?id=<?php echo htmlentities($player['player_id']); ?>"><?php echo htmlentities($player['player_name']); ?></a></td>
                    <td class="position <?php 
                        if ($player['slot_name'] === 'P') {
                            echo 'pitcher';
                        } elseif ($player['slot_name'] === 'IF') {
                            echo 'infield';
                        } elseif ($player['slot_name'] === 'OF') {
                            echo 'outfield';
                        }
                    ?>"><?php echo htmlentities($player['slot_name']); ?></td>
                    <td><?php echo htmlentities($player['overall_pick_number']); ?></td>
                    <td class="total-points <?php 
                        $position = $player['slot_name'];
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
                        $usedPoints = (float)$player['usable_points'];
                        $diff = abs($usedPoints - $medianUsedPoints);
                        $maxDiff = max(array_map('abs', array_map(function($p) use ($medianUsedPoints) {
                            return (float)$p - $medianUsedPoints;
                        }, $usedPointsArray)));
                        $normalizedDiff = $maxDiff > 0 ? min($diff / $maxDiff, 1) : 0;
                        echo $usedPoints > $medianUsedPoints ? 'above' : 'below';
                    ?>" style="--points-diff: <?php echo $normalizedDiff; ?>">
                        <?php echo htmlentities($player['usable_points']); ?>
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
        // Global variables
        const weeklyPlayerScores = <?php echo json_encode($weeklyPlayerScores); ?>;
        const rosterData = <?php echo json_encode($roster); ?>;
        const weekNumbers = <?php echo json_encode($weekNumbers); ?>;
        const pitcherPoints = <?php echo json_encode($pitcherPoints); ?>;
        const infieldPoints = <?php echo json_encode($infieldPoints); ?>;
        const outfieldPoints = <?php echo json_encode($outfieldPoints); ?>;
        const averageScores = <?php echo json_encode($averageScores); ?>;

        function updateTableForWeek(weekNum) {
            const headers = document.querySelector('table thead tr');
            const tbody = document.getElementById('rosterBody');
            
            const headerCells = headers.querySelectorAll('th');
            headerCells[3].textContent = `Week ${weekNum} Points`;
            headerCells[4].style.display = 'none';
            
            const positionPlayers = rosterData
                .filter(player => player.slot_name !== 'P')
                .map(player => ({
                    ...player,
                    weekData: weeklyPlayerScores[player.player_id]?.[weekNum]
                }))
                .filter(player => player.weekData)
                .sort((a, b) => b.weekData.points - a.weekData.points);
            
            const topNonTop3 = positionPlayers.find(player => {
                const position = player.slot_name;
                const positionRank = player.weekData.rank;
                return positionRank > 3;
            });
            
            rosterData.forEach((player, index) => {
                const row = tbody.children[index];
                const playerId = player.player_id;
                const position = player.slot_name;
                
                const pointsCell = row.querySelector('td:nth-child(4)');
                const usedPointsCell = row.querySelector('td:nth-child(5)');
                
                usedPointsCell.style.display = 'none';
                
                const weekData = weeklyPlayerScores[playerId]?.[weekNum];
                
                pointsCell.className = 'weekly-points';
                pointsCell.style.removeProperty('--points-diff');
                
                if (weekData) {
                    pointsCell.textContent = weekData.points;
                    
                    if (position === 'P' && weekData.rank <= 3) {
                        pointsCell.style.backgroundColor = 'rgba(128, 0, 128, 0.2)';
                    } else if (position === 'IF' && weekData.rank <= 3) {
                        pointsCell.style.backgroundColor = 'rgba(0, 128, 0, 0.2)';
                    } else if (position === 'OF' && weekData.rank <= 3) {
                        pointsCell.style.backgroundColor = 'rgba(255, 165, 0, 0.2)';
                    } else if (topNonTop3 && playerId === topNonTop3.player_id) {
                        pointsCell.style.backgroundColor = 'rgba(255, 0, 0, 0.2)';
                    } else {
                        pointsCell.style.backgroundColor = '';
                    }
                } else {
                    pointsCell.textContent = '-';
                    pointsCell.style.backgroundColor = '';
                }
            });
        }

        function resetTable() {
            const headers = document.querySelector('table thead tr');
            const tbody = document.getElementById('rosterBody');
            
            const headerCells = headers.querySelectorAll('th');
            headerCells[3].textContent = 'Total Points';
            headerCells[4].style.display = '';
            
            rosterData.forEach((player, index) => {
                const row = tbody.children[index];
                const pointsCell = row.querySelector('td:nth-child(4)');
                const usedPointsCell = row.querySelector('td:nth-child(5)');
                
                usedPointsCell.style.display = '';
                
                pointsCell.textContent = player.total_points;
                usedPointsCell.textContent = player.usable_points;
                
                pointsCell.className = `total-points ${getPointsClass(player.total_points, player.slot_name)}`;
                usedPointsCell.className = `used-points ${getUsedPointsClass(player.usable_points)}`;
                
                pointsCell.style.setProperty('--points-diff', getPointsDiff(player.total_points, player.slot_name));
                usedPointsCell.style.setProperty('--points-diff', getUsedPointsDiff(player.usable_points));
                pointsCell.style.backgroundColor = '';
            });
        }

        // Helper functions for class and diff calculations
        function getPointsClass(points, position) {
            const median = position === 'P' ? <?php echo $totStats['pitchers']; ?> : <?php echo $totStats['hitters']; ?>;
            return points > median ? 'above' : 'below';
        }

        function getUsedPointsClass(points) {
            const median = <?php echo $medianUsedPoints; ?>;
            return points > median ? 'above' : 'below';
        }

        function getPointsDiff(points, position) {
            const median = position === 'P' ? <?php echo $totStats['pitchers']; ?> : <?php echo $totStats['hitters']; ?>;
            const diff = Math.abs(points - median);
            const maxDiff = median;
            return Math.min(diff / maxDiff, 1);
        }

        function getUsedPointsDiff(points) {
            const median = <?php echo $medianUsedPoints; ?>;
            const diff = Math.abs(points - median);
            const maxDiff = <?php echo max(array_map('abs', array_map(function($p) use ($medianUsedPoints) {
                return (float)$p - $medianUsedPoints;
            }, $usedPointsArray))); ?>;
            return maxDiff > 0 ? Math.min(diff / maxDiff, 1) : 0;
        }

        // Create the chart
        const ctx = document.getElementById('weeklyScoresChart').getContext('2d');
        const weeklyScoresChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: weekNumbers.map(num => `Week ${num}`),
                datasets: [
                    {
                        label: 'Pitchers',
                        data: pitcherPoints,
                        backgroundColor: 'rgba(128, 0, 128, 0.5)',
                        borderColor: 'rgba(128, 0, 128, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Infielders',
                        data: infieldPoints,
                        backgroundColor: 'rgba(0, 136, 0, 0.5)',
                        borderColor: 'rgba(0, 136, 0, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Outfielders',
                        data: outfieldPoints,
                        backgroundColor: 'rgba(255, 165, 0, 0.5)',
                        borderColor: 'rgba(255, 165, 0, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Average Advancing Score',
                        data: averageScores,
                        type: 'line',
                        fill: false,
                        borderColor: 'rgba(0, 0, 0, 0.5)',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: 'rgba(0, 0, 0, 0.5)',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: 'rgba(0, 0, 0, 0.8)',
                        pointHoverBorderColor: 'white',
                        pointHoverBorderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                },
                onClick: function(e, elements) {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const weekNum = weekNumbers[index];
                        updateTableForWeek(weekNum);
                    } else {
                        resetTable();
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let value = context.raw;
                                
                                if (label === 'Average Advancing Score') {
                                    value = Math.round(value);
                                }
                                
                                return `${label}: ${value}`;
                            },
                            footer: function(tooltipItems) {
                                const index = tooltipItems[0].dataIndex;
                                const weekNum = weekNumbers[index];
                                const total = pitcherPoints[index] + infieldPoints[index] + outfieldPoints[index];
                                return `Total: ${Math.round(total)} points`;
                            }
                        }
                    }
                }
            }
        });

        // Add tooltip functionality for the SVG dots
        document.addEventListener('DOMContentLoaded', function() {
            const tooltip = document.querySelector('.tooltip');
            const dots = document.querySelectorAll('.dot-link');
            
            dots.forEach(dot => {
                dot.addEventListener('mousemove', (e) => {
                    const username = dot.getAttribute('data-username');
                    const points = dot.getAttribute('data-points');
                    tooltip.textContent = `${username}: ${points} points`;
                    tooltip.style.display = 'block';
                    
                    // Position tooltip near cursor
                    const x = e.clientX + 10;
                    const y = e.clientY - 20;
                    
                    tooltip.style.left = x + 'px';
                    tooltip.style.top = y + 'px';
                });
                
                dot.addEventListener('mouseout', () => {
                    tooltip.style.display = 'none';
                });
            });
        });
    </script>
    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html> 