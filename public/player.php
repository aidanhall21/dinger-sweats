<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Get player ID from URL
$playerId = isset($_GET['id']) ? $_GET['id'] : null;

if (!$playerId) {
    die("No player ID provided");
}

// Get player details
$playerQuery = "SELECT * FROM players WHERE id = ?";
$playerStmt = $pdo->prepare($playerQuery);
$playerStmt->execute([$playerId]);
$player = $playerStmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    die("Player not found");
}

// Get ADP history
$adpQuery = "
    SELECT *
    FROM adp
    WHERE player_id = ?
    ORDER BY pick_created_time ASC
";
$adpStmt = $pdo->prepare($adpQuery);
$adpStmt->execute([$playerId]);
$adpHistory = $adpStmt->fetchAll(PDO::FETCH_ASSOC);

// Get scoring history
$scoringQuery = "
    SELECT Date, TOT, Opponent, player_id, week_id
    FROM (
        SELECT Date, TOT, Opponent, player_id, week_id
        FROM hitters
        WHERE player_id = ?
        UNION ALL
        SELECT Date, TOT, Opponent, player_id, week_id
        FROM pitchers
        WHERE player_id = ?
    ) combined
    ORDER BY Date ASC
";
$scoringStmt = $pdo->prepare($scoringQuery);
$scoringStmt->execute([$playerId, $playerId]);
$scoringHistory = $scoringStmt->fetchAll(PDO::FETCH_ASSOC);

// Get week mapping
$weekMappingQuery = "
    SELECT DISTINCT h.Date, w.week_number
    FROM hitters h
    JOIN weeks w ON h.week_id = w.week_id
    ORDER BY h.Date
";
$weekMappingStmt = $pdo->prepare($weekMappingQuery);
$weekMappingStmt->execute();
$weekMapping = $weekMappingStmt->fetchAll(PDO::FETCH_ASSOC);

// Create a map of date to week number
$dateToWeekNumber = [];
foreach ($weekMapping as $mapping) {
    $dateToWeekNumber[$mapping['Date']] = $mapping['week_number'];
}

// Check if player is a hitter or pitcher
$playerTypeQuery = "
    SELECT 
        (SELECT COUNT(*) FROM hitters WHERE player_id = ?) as hitter_count,
        (SELECT COUNT(*) FROM pitchers WHERE player_id = ?) as pitcher_count
";
$playerTypeStmt = $pdo->prepare($playerTypeQuery);
$playerTypeStmt->execute([$playerId, $playerId]);
$playerType = $playerTypeStmt->fetch(PDO::FETCH_ASSOC);
$isHitter = $playerType['hitter_count'] > 0;

// Prepare ADP data for scatterplot
$adpData = [];
foreach ($adpHistory as $record) {
    $adpData[] = [
        'x' => $record['pick_created_time'],
        'y' => floatval($record['overall_pick_number']),
        'username' => $record['username'],
        'advancing' => $record['advancing'],
        'draft_entry_id' => $record['draft_entry_id']
    ];
}

$scoringData = [];
$dateTotals = [];
foreach ($scoringHistory as $record) {
    $date = $record['Date'];
    $points = floatval($record['TOT']);
    $opponent = $record['Opponent'];
    
    if (!isset($dateTotals[$date])) {
        $dateTotals[$date] = [
            'points' => 0,
            'opponents' => []
        ];
    }
    
    $dateTotals[$date]['points'] += $points;
    $dateTotals[$date]['opponents'][] = $opponent;
}

foreach ($dateTotals as $date => $data) {
    $scoringData[] = [
        'date' => $date,
        'points' => $data['points'],
        'opponent' => implode(', ', $data['opponents'])
    ];
}

// Generate complete date range with zeros
if (!empty($scoringData)) {
    // Get max date from week mapping
    $maxDate = max(array_keys($dateToWeekNumber));
    
    // Create a map of existing data for easy lookup
    $existingData = [];
    foreach ($scoringData as $record) {
        $existingData[$record['date']] = $record;
    }
    
    // Generate complete date range starting from 03/27
    $currentDate = DateTime::createFromFormat('m/d', '03/27');
    $endDate = DateTime::createFromFormat('m/d', $maxDate);
    $completeData = [];
    
    while ($currentDate <= $endDate) {
        $dateStr = $currentDate->format('m/d');
        if (isset($existingData[$dateStr])) {
            $completeData[] = $existingData[$dateStr];
        } else {
            $completeData[] = [
                'date' => $dateStr,
                'points' => 0,
                'opponent' => 'No Game'
            ];
        }
        $currentDate->modify('+1 day');
    }
    
    $scoringData = $completeData;
}

// Get exposure metrics
$exposureQuery = "SELECT exposure_count, exposure_pct, advance_rate FROM exposure_metrics WHERE player_id = ?";
$exposureStmt = $pdo->prepare($exposureQuery);
$exposureStmt->execute([$playerId]);
$exposureMetrics = $exposureStmt->fetch(PDO::FETCH_ASSOC);

// Calculate total score
$totalScore = round(array_sum(array_column($scoringData, 'points')));

// Get final ADP from existing adpHistory array
$finalAdp = end($adpHistory)['projection_adp'] ?? null;

// Get user draft frequency
$userFrequencyQuery = "
    SELECT 
        user_id,
        username,
        COUNT(*) as draft_count,
        SUM(CASE WHEN advancing = 1 THEN 1 ELSE 0 END) as advance_count
    FROM adp
    WHERE player_id = ?
    GROUP BY user_id, username
    ORDER BY draft_count DESC, advance_count DESC
    LIMIT 25
";
$userFrequencyStmt = $pdo->prepare($userFrequencyQuery);
$userFrequencyStmt->execute([$playerId]);
$userFrequency = $userFrequencyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get advance rate history
$advanceRateQuery = "
    SELECT date, advance_rate
    FROM advance_rate_history
    WHERE player_id = ?
    ORDER BY date ASC
";
$advanceRateStmt = $pdo->prepare($advanceRateQuery);
$advanceRateStmt->execute([$playerId]);
$advanceRateHistory = $advanceRateStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Sweating Dingers | <?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?> ADP History</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css?v=<?php echo filemtime(__DIR__ . '/css/common.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <style>
        /* No styles needed here - all moved to common.css */
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <div class="player-info">
        <h1><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></h1>
        <div style="width: 80%; margin: 1rem auto; padding: 0.5rem; background-color: #f2f2f2; border-radius: 4px; text-align: center;">
            <div class="stat-item">
                <span class="stat-label">Team:</span>
                <span class="stat-value"><?php echo htmlspecialchars($player['team_name']); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Position:</span>
                <span class="stat-value"><?php echo htmlspecialchars($player['slot_name']); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Total Score:</span>
                <span class="stat-value"><?php echo number_format($totalScore, 0); ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Ownership:</span>
                <span class="stat-value"><?php echo $exposureMetrics ? number_format($exposureMetrics['exposure_pct'], 1) : '0.0'; ?>%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Advance Rate:</span>
                <span class="stat-value"><?php echo $exposureMetrics ? number_format($exposureMetrics['advance_rate'], 1) : '0.0'; ?>%</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Final ADP:</span>
                <span class="stat-value"><?php echo $finalAdp ? number_format($finalAdp, 1) : 'N/A'; ?></span>
            </div>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-container">
            <canvas id="scoringChart"></canvas>
        </div>

        <div class="chart-container">
            <?php if (empty($adpHistory)): ?>
                <div style="display: flex; justify-content: center; align-items: center; height: 300px; background-color: #f9f9f9; border-radius: 8px;">
                    <p style="font-size: 18px; color: #666;">No draft data available</p>
                </div>
            <?php else: ?>
                <canvas id="adpChart"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <div style="width: 80%; margin: 2rem auto;">
        <h2>Top Drafters</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #f2f2f2;">
                    <th style="padding: 8px; text-align: left;">Rank</th>
                    <th style="padding: 8px; text-align: left;">Username</th>
                    <th style="padding: 8px; text-align: right;">Drafts</th>
                    <th style="padding: 8px; text-align: right;">Advancing</th>
                    <th style="padding: 8px; text-align: right;">Advance Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($userFrequency as $index => $user): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 8px;"><?php echo $index + 1; ?></td>
                        <td style="padding: 8px;">
                            <a href="user_details.php?username=<?php echo urlencode($user['username']); ?>">
                                <?php echo htmlspecialchars($user['username']); ?>
                            </a>
                        </td>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($user['draft_count']); ?></td>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($user['advance_count']); ?></td>
                        <td style="padding: 8px; text-align: right;">
                            <a href="leaderboard_teams.php?search=<?php echo urlencode($user['username']); ?>&player1=<?php echo urlencode($playerId); ?>">
                                <?php echo number_format(($user['advance_count'] / $user['draft_count']) * 100, 1); ?>%
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        const adpData = <?php echo json_encode($adpData); ?>;
        const scoringData = <?php echo json_encode($scoringData); ?>;
        const advanceRateHistory = <?php echo json_encode($advanceRateHistory); ?>;
        
        // Determine chart color based on position
        const position = '<?php echo $player['slot_name']; ?>';
        let chartColor;
        
        if (position.startsWith('P')) {
            chartColor = '#800080';  // Purple for pitchers
        } else if (position === 'IF') {
            chartColor = '#008800';  // Green for infielders
        } else if (position.startsWith('OF')) {
            chartColor = '#FFA500';  // Orange for outfielders
        } else {
            chartColor = '#666666';  // Default gray
        }
        
        <?php if (!empty($adpHistory)): ?>
        // ADP Chart
        const ctx = document.getElementById('adpChart').getContext('2d');

        new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Advancing Picks',
                    data: adpData.filter(d => d.advancing),
                    backgroundColor: '#0000FF',
                    borderColor: '#0000FF',
                    pointRadius: 1,
                    pointHoverRadius: 4
                },
                {
                    label: 'Non-Advancing Picks',
                    data: adpData.filter(d => !d.advancing),
                    backgroundColor: '#CCCCCC',
                    borderColor: '#CCCCCC',
                    pointRadius: 1,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'point',
                    intersect: true
                },
                scales: {
                    y: {
                        min: 1,
                        max: 240,
                        reverse: true,
                        title: {
                            display: true,
                            text: 'Pick Number'
                        }
                    },
                    x: {
                        type: 'time',
                        time: {
                            unit: 'day',
                            displayFormats: {
                                day: 'MMM d'
                            },
                            parser: 'yyyy-MM-dd HH:mm:ss.SSSSSS'
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        },
                        min: '2025-01-13',
                        max: '2025-03-27'
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'ADP History'
                    },
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const data = context.raw;
                                return [
                                    `Pick: ${data.y}`,
                                    `User: ${data.username}`,
                                    `Date: ${new Date(data.x).toLocaleDateString()}`
                                ];
                            },
                            afterBody: function(context) {
                                const data = context[0].raw;
                                return [
                                    '',
                                    'Click to view team details'
                                ];
                            }
                        }
                    }
                },
                onClick: function(evt, elements) {
                    if (elements.length > 0) {
                        const datasetIndex = elements[0].datasetIndex;
                        const dataIndex = elements[0].index;
                        const data = this.data.datasets[datasetIndex].data[dataIndex];
                        if (data.draft_entry_id) {
                            window.location.href = `team_details.php?draft_entry_id=${data.draft_entry_id}`;
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Scoring Chart
        const scoringCtx = document.getElementById('scoringChart').getContext('2d');
        new Chart(scoringCtx, {
            type: 'bar',
            data: {
                labels: scoringData.map(d => d.date),
                datasets: [
                {
                    label: 'Advance Rate',
                    data: scoringData.map(d => {
                        const rate = advanceRateHistory.find(r => r.date === d.date);
                        return rate ? rate.advance_rate : null;
                    }),
                    type: 'line',
                    borderColor: '#000000',
                    backgroundColor: 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 8,
                    pointHitRadius: 15,
                    yAxisID: 'y1',
                    order: 1
                },
                {
                    label: 'Points Scored',
                    data: scoringData.map(d => d.points),
                    backgroundColor: scoringData.map(d => chartColor),
                    borderColor: scoringData.map(d => chartColor),
                    borderWidth: 1,
                    yAxisID: 'y',
                    order: 2
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'nearest',
                    intersect: false,
                    axis: 'x'
                },
                scales: {
                    y: {
                        beginAtZero: <?php echo $isHitter ? 'true' : 'false'; ?>,
                        min: <?php echo $isHitter ? '0' : '-20'; ?>,
                        max: <?php echo $isHitter ? '70' : '80'; ?>,
                        stacked: true,
                        title: {
                            display: true,
                            text: 'Points'
                        },
                        position: 'left'
                    },
                    y1: {
                        beginAtZero: <?php echo $isHitter ? 'true' : 'false'; ?>,
                        min: <?php echo $isHitter ? '0' : '-20'; ?>,
                        max: <?php echo $isHitter ? '70' : '80'; ?>,
                        title: {
                            display: true,
                            text: 'Advance Rate (%)'
                        },
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Daily Scoring & Advance Rate'
                    },
                    legend: {
                        display: true,
                        position: 'top',
                        reverse: true
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const data = scoringData[context.dataIndex];
                                if (context.datasetIndex === 1) {
                                    return [
                                        `Points: ${context.raw}`,
                                        `Opponent: ${data.opponent}`
                                    ];
                                } else {
                                    return `Advance Rate: ${context.raw}%`;
                                }
                            }
                        }
                    }
                }
            },
            plugins: [{
                id: 'customBackground',
                beforeDraw(chart) {
                    const {ctx, chartArea: {left, right, top, bottom}, scales: {x}} = chart;
                    
                    ctx.save();
                    ctx.fillStyle = 'rgba(200, 200, 200, 0.2)';
                    
                    // Draw background for each date based on its week number
                    chart.data.labels.forEach((date, i) => {
                        const weekNumber = <?php echo json_encode($dateToWeekNumber); ?>[date];
                        if (weekNumber && weekNumber % 2 === 1) {  // Odd weeks get gray background
                            const x1 = x.getPixelForValue(i) - (x.getPixelForValue(1) - x.getPixelForValue(0))/2;
                            const x2 = x.getPixelForValue(i) + (x.getPixelForValue(1) - x.getPixelForValue(0))/2;
                            ctx.fillRect(x1, top, x2 - x1, bottom - top);
                        }
                    });
                    
                    ctx.restore();
                }
            }]
        });
    </script>

    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html> 
