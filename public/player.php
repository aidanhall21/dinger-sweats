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
    SELECT date, projection_adp
    FROM adp
    WHERE player_id = ?
    ORDER BY date ASC
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

// Prepare data for the graphs
$adpData = [];
$lastAdp = 240; // Default value for missing dates

// Create a map of existing data for easy lookup
$existingData = [];
foreach ($adpHistory as $record) {
    $adp = $record['projection_adp'] == 0.0 ? 240 : floatval($record['projection_adp']);
    $existingData[$record['date']] = $adp;
}

// Get the earliest and latest dates from the ADP data
$earliestDate = null;
$latestDate = null;
if (!empty($adpHistory)) {
    $earliestDate = min(array_column($adpHistory, 'date'));
    $latestDate = max(array_column($adpHistory, 'date'));
}

// Generate complete date range starting from earliest date
$currentDate = DateTime::createFromFormat('m/d', $earliestDate ?? '01/01');
$endDate = DateTime::createFromFormat('m/d', $latestDate ?? '03/27');
$completeData = [];

while ($currentDate <= $endDate) {
    $dateStr = $currentDate->format('m/d');
    if (isset($existingData[$dateStr])) {
        $lastAdp = $existingData[$dateStr];
    }
    $completeData[] = [
        'date' => $dateStr,
        'adp' => $lastAdp
    ];
    $currentDate->modify('+1 day');
}

$adpData = $completeData;

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

// Calculate 10-day moving average
$movingAverages = [];
for ($i = 0; $i < count($scoringData); $i++) {
    $sum = 0;
    $count = 0;
    // Look back up to 5 days
    for ($j = max(0, $i - 4); $j <= $i; $j++) {
        $sum += $scoringData[$j]['points'];
        $count++;
    }
    $movingAverages[] = $count > 0 ? round($sum / $count, 2) : 0;
}

// Add moving averages to scoring data
for ($i = 0; $i < count($scoringData); $i++) {
    $scoringData[$i]['movingAverage'] = $movingAverages[$i];
}

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
    <style>
        /* No styles needed here - all moved to common.css */
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <div class="player-info">
        <h1><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></h1>
        <p><?php echo htmlspecialchars($player['team_name'] . ' - ' . $player['slot_name']); ?></p>
    </div>

    <div class="charts-container">
        <div class="chart-container">
            <canvas id="scoringChart"></canvas>
        </div>

        <div class="chart-container">
            <canvas id="adpChart"></canvas>
        </div>
    </div>

    <script>
        const adpData = <?php echo json_encode($adpData); ?>;
        const scoringData = <?php echo json_encode($scoringData); ?>;
        
        // ADP Chart
        const ctx = document.getElementById('adpChart').getContext('2d');
        const position = '<?php echo $player['slot_name']; ?>';
        let chartColor;
        
        if (position.startsWith('P')) {
            chartColor = '#800080';  // Red for pitchers
        } else if (position === 'IF') {
            chartColor = '#008800';  // Teal for infielders
        } else if (position.startsWith('OF')) {
            chartColor = '#FFA500';  // Blue for outfielders
        } else {
            chartColor = '#666666';  // Default gray
        }

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: adpData.map(d => d.date),
                datasets: [{
                    label: 'ADP',
                    data: adpData.map(d => d.adp),
                    borderColor: chartColor,
                    backgroundColor: chartColor,
                    pointBackgroundColor: chartColor,
                    pointBorderColor: chartColor,
                    tension: 0.1,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        min: 1,
                        max: 240,
                        reverse: true,
                        title: {
                            display: true,
                            text: 'ADP'
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
                        text: 'ADP History'
                    },
                    legend: {
                        display: false
                    }
                }
            },
            plugins: [{
                id: 'customBackground',
                beforeDraw(chart) {
                    const {ctx, chartArea: {left, right, top, bottom}, scales: {y}} = chart;
                    const roundHeight = (y.max - y.min) / 20; // 20 rounds (240/12)
                    
                    ctx.save();
                    ctx.fillStyle = 'rgba(200, 200, 200, 0.2)';
                    
                    for (let i = 0; i < 20; i++) {
                        if (i % 2 === 0) {
                            const y1 = y.getPixelForValue(y.min + (i * roundHeight));
                            const y2 = y.getPixelForValue(y.min + ((i + 1) * roundHeight));
                            ctx.fillRect(left, y2, right - left, y1 - y2);
                        }
                    }
                    
                    ctx.restore();
                }
            }]
        });

        // Scoring Chart
        const scoringCtx = document.getElementById('scoringChart').getContext('2d');
        new Chart(scoringCtx, {
            type: 'bar',
            data: {
                labels: scoringData.map(d => d.date),
                datasets: [
                <?php if ($isHitter) { ?>
                {
                    label: '5-Day Moving Average',
                    data: scoringData.map(d => d.movingAverage),
                    type: 'line',
                    borderColor: '#000000',
                    backgroundColor: 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: false,
                    tension: 0.1
                },
                <?php } ?>
                {
                    label: 'Points Scored',
                    data: scoringData.map(d => d.points),
                    backgroundColor: scoringData.map(d => chartColor),
                    borderColor: scoringData.map(d => chartColor),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'nearest',
                    intersect: true
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        min: <?php echo $isHitter ? '0' : '-20'; ?>,
                        max: <?php echo $isHitter ? '70' : '80'; ?>,
                        stacked: true,
                        title: {
                            display: true,
                            text: 'Points'
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
                        text: 'Daily Scoring'
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
                                <?php if ($isHitter) { ?>
                                if (context.datasetIndex === 0) {
                                    return `5-Day Avg: ${context.raw}`;
                                } else {
                                    return [
                                        `Points: ${context.raw}`,
                                        `Opponent: ${data.opponent}`
                                    ];
                                }
                                <?php } else { ?>
                                return [
                                    `Points: ${context.raw}`,
                                    `Opponent: ${data.opponent}`
                                ];
                                <?php } ?>
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