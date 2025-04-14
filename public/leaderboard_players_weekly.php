<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Add cache configuration
$cacheFile = __DIR__ . '/../cache/weekly_leaderboard.json';
$cacheExpiry = 1800; // 5 minutes

// ----------------------------------------------------------------------------
// SQLite requires version 3.25+ for window functions like DENSE_RANK()
// ----------------------------------------------------------------------------

// ----------------------------------------------------------------------------
// EXCLUDED WEEKS
// ----------------------------------------------------------------------------
// $excludedWeeks = [2426, 2427, 2428, 2430, 2432, 2435];
// $excludedWeeksList = implode(',', $excludedWeeks);

// ----------------------------------------------------------------------------
// GET FILTER VALUES
// ----------------------------------------------------------------------------
$teamFilter     = !empty($_GET['team']) ? trim($_GET['team']) : '';
$positionFilter = !empty($_GET['position']) ? trim($_GET['position']) : '';
$adpMin         = isset($_GET['adp_min']) ? trim($_GET['adp_min']) : '';
$adpMax         = isset($_GET['adp_max']) ? trim($_GET['adp_max']) : '';
$noAdpOnly      = (isset($_GET['no_adp']) && $_GET['no_adp'] === '1');
// Initialize ownership variables
$ownershipMin = isset($_GET['ownership_min']) ? trim($_GET['ownership_min']) : '';
$ownershipMax = isset($_GET['ownership_max']) ? trim($_GET['ownership_max']) : '';

// ----------------------------------------------------------------------------
// BUILD QUERY
// ----------------------------------------------------------------------------
$query = "
    WITH WeeklyScores AS (
        SELECT 
            p.id,
            w.week_id,
            w.week_number,
            SUM(IFNULL(h.TOT, 0)) as hitter_score,
            SUM(IFNULL(pc.TOT, 0)) as pitcher_score,
            SUM(IFNULL(h.TOT, 0) + IFNULL(pc.TOT, 0)) as total_score,
            COUNT(pc.week_id) as pitch_count
        FROM players p
        LEFT JOIN hitters h ON h.player_id = p.id
        LEFT JOIN pitchers pc ON pc.player_id = p.id
        LEFT JOIN weeks w ON w.week_id = COALESCE(h.week_id, pc.week_id)
        LEFT JOIN exposure_metrics em ON em.player_id = p.id
        WHERE (h.week_id IS NOT NULL OR pc.week_id IS NOT NULL)
        GROUP BY p.id, w.week_id, w.week_number
        HAVING total_score > 0
    )
    SELECT 
        p.id,
        p.first_name,
        p.last_name,
        p.team_name,
        p.slot_name AS position,
        p.final_adp,
        ws.week_number,
        ws.total_score,
        ws.pitch_count,
        em.exposure_pct
    FROM players p
    JOIN WeeklyScores ws ON ws.id = p.id
    LEFT JOIN exposure_metrics em ON em.player_id = p.id
    WHERE 1=1
";

// ----------------------------------------------------------------------------
// ADD FILTERS
// ----------------------------------------------------------------------------
if ($teamFilter !== '') {
    $query .= " AND p.team_name = :team_name";
}
if ($positionFilter !== '') {
    if ($positionFilter === 'FLEX') {
        $query .= " AND (p.slot_name = 'IF' OR p.slot_name = 'OF')";
    } else {
        $query .= " AND p.slot_name = :position";
    }
}
if ($noAdpOnly) {
    $query .= " AND (p.final_adp IS NULL OR p.final_adp = '-')";
} else {
    if ($adpMin !== '') {
        // For safety, treat blank and dash as "null" ADP
        $query .= " AND (CAST(p.final_adp AS REAL) >= :adp_min OR p.final_adp IS NULL OR p.final_adp = '-')";
    }
    if ($adpMax !== '') {
        $query .= " AND p.final_adp IS NOT NULL AND p.final_adp != '-' AND CAST(p.final_adp AS REAL) <= :adp_max";
    }
}

// Add ownership filters
if ($ownershipMin !== '') {
    $query .= " AND em.exposure_pct >= :ownership_min";
}
if ($ownershipMax !== '') {
    $query .= " AND em.exposure_pct <= :ownership_max";
}

// Sort by ADP (treating "-" and NULL as highest value), then name, then week
$query .= " ORDER BY 
    CASE 
        WHEN p.final_adp IS NULL OR p.final_adp = '-' THEN 999999 
        ELSE CAST(p.final_adp AS REAL) 
    END ASC,
    p.first_name ASC,
    ws.week_number ASC";

// ----------------------------------------------------------------------------
// PREPARE AND EXECUTE
// ----------------------------------------------------------------------------

// After connecting to the database but before the main query
$medianQuery = "
    WITH WeeklyScores AS (
        SELECT 
            w.week_id,
            w.week_number,
            p.slot_name,
            SUM(IFNULL(h.TOT, 0) + IFNULL(pc.TOT, 0)) as total_score
        FROM players p
        LEFT JOIN hitters h ON h.player_id = p.id
        LEFT JOIN pitchers pc ON pc.player_id = p.id
        LEFT JOIN weeks w ON w.week_id = COALESCE(h.week_id, pc.week_id)
        WHERE (h.week_id IS NOT NULL OR pc.week_id IS NOT NULL)
            AND " . ($positionFilter === 'P' ? "p.slot_name LIKE 'P%'" : "NOT p.slot_name LIKE 'P%'") . "
        GROUP BY p.id, w.week_id, w.week_number, p.slot_name
    )
    SELECT 
        week_number,
        total_score as median_score
    FROM (
        SELECT 
            week_number, 
            total_score,
            ROW_NUMBER() OVER (PARTITION BY week_number ORDER BY total_score DESC) as rank
        FROM WeeklyScores
    ) ranked
    WHERE rank = " . ($positionFilter === 'P' ? "36" : "84") . "
    GROUP BY week_number;
";

$medianStmt = $pdo->query($medianQuery);
$medianScores = [];
while ($row = $medianStmt->fetch(PDO::FETCH_ASSOC)) {
    $medianScores[$row['week_number']] = $row['median_score'];
}

// If no filters are applied, try to use cached data
if (empty($teamFilter) && empty($positionFilter) && 
    empty($adpMin) && empty($adpMax) && !$noAdpOnly && 
    empty($ownershipMin) && empty($ownershipMax)) {
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheExpiry)) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        $players = $cachedData['players'];
        $weeklyScores = $cachedData['weeklyScores'];
        $weeklyPitchCounts = $cachedData['weeklyPitchCounts'];
        $medianScores = $cachedData['medianScores'];
    } else {
        // Execute queries and cache the results
        $statement = $pdo->prepare($query);
        $statement->execute();
        $players = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Get median scores
        $medianStmt = $pdo->query($medianQuery);
        $medianScores = [];
        while ($row = $medianStmt->fetch(PDO::FETCH_ASSOC)) {
            $medianScores[$row['week_number']] = $row['median_score'];
        }

        // Organize weekly scores
        $weeklyScores = [];
        $weeklyPitchCounts = [];
        foreach ($players as $player) {
            if (!isset($weeklyScores[$player['id']])) {
                $weeklyScores[$player['id']] = [];
                $weeklyPitchCounts[$player['id']] = [];
            }
            $weeklyScores[$player['id']][$player['week_number']] = $player['total_score'];
            $weeklyPitchCounts[$player['id']][$player['week_number']] = $player['pitch_count'];
        }

        // Cache the data
        $cacheData = [
            'players' => $players,
            'weeklyScores' => $weeklyScores,
            'weeklyPitchCounts' => $weeklyPitchCounts,
            'medianScores' => $medianScores
        ];
        
        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }
        file_put_contents($cacheFile, json_encode($cacheData));
    }
} else {
    // Execute queries normally for filtered results
    $statement = $pdo->prepare($query);
    
    if ($teamFilter !== '') {
        $statement->bindValue(':team_name', $teamFilter);
    }
    if ($positionFilter !== '' && $positionFilter !== 'FLEX') {
        $statement->bindValue(':position', $positionFilter);
    }
    if ($adpMin !== '') {
        $statement->bindValue(':adp_min', $adpMin);
    }
    if ($adpMax !== '') {
        $statement->bindValue(':adp_max', $adpMax);
    }
    if ($ownershipMin !== '') {
        $statement->bindValue(':ownership_min', $ownershipMin);
    }
    if ($ownershipMax !== '') {
        $statement->bindValue(':ownership_max', $ownershipMax);
    }
    
    $statement->execute();
    $players = $statement->fetchAll(PDO::FETCH_ASSOC);

    // Get median scores
    $medianStmt = $pdo->query($medianQuery);
    $medianScores = [];
    while ($row = $medianStmt->fetch(PDO::FETCH_ASSOC)) {
        $medianScores[$row['week_number']] = $row['median_score'];
    }

    // Organize weekly scores
    $weeklyScores = [];
    $weeklyPitchCounts = [];
    foreach ($players as $player) {
        if (!isset($weeklyScores[$player['id']])) {
            $weeklyScores[$player['id']] = [];
            $weeklyPitchCounts[$player['id']] = [];
        }
        $weeklyScores[$player['id']][$player['week_number']] = $player['total_score'];
        $weeklyPitchCounts[$player['id']][$player['week_number']] = $player['pitch_count'];
    }
}

// After getting the weekly scores data, get unique weeks
$availableWeeks = [];
foreach ($weeklyScores as $playerScores) {
    foreach ($playerScores as $weekNum => $score) {
        if (!in_array($weekNum, $availableWeeks)) {
            $availableWeeks[] = $weekNum;
        }
    }
}
sort($availableWeeks);

// ----------------------------------------------------------------------------
// FETCH DISTINCT TEAMS AND POSITIONS FOR FILTER SELECT
// ----------------------------------------------------------------------------
$teamsStmt = $pdo->query("SELECT DISTINCT team_name FROM players WHERE team_name != '0' ORDER BY team_name");
$allTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$posStmt = $pdo->query("SELECT DISTINCT slot_name FROM players WHERE slot_name != '' ORDER BY slot_name");
$allPositions = $posStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Sweating Dingers | Player Leaderboard | Weekly Scores</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css?v=<?php echo filemtime(__DIR__ . '/css/common.css'); ?>">
    <style>
        /* Prevent text selection during sorting */
        .sortable {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
    </style>

    <!-- Add this script before the closing </head> tag -->
    <script>
        function sortTableByColumn(table, columnIndex, isNumeric = false) {
            // For week columns (index 3+), default to descending on first click
            const isWeekColumn = columnIndex >= 3;
            const direction = isWeekColumn && !table.getAttribute('data-sort-direction-' + columnIndex) 
                ? 'desc' 
                : table.getAttribute('data-sort-direction-' + columnIndex) === 'asc' ? 'desc' : 'asc';
            
            // Remove sort classes from all headers
            table.querySelectorAll('th').forEach(th => th.classList.remove('asc', 'desc'));
            
            // Add sort class to current header
            const currentHeader = table.querySelector(`th:nth-child(${columnIndex + 1})`);
            currentHeader.classList.add(direction);
            
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            
            rows.sort((a, b) => {
                const aCol = a.children[columnIndex].innerText.trim();
                const bCol = b.children[columnIndex].innerText.trim();

                if (isNumeric) {
                    // Special handling for Final ADP column (index 2)
                    if (columnIndex === 2) {
                        const aNum = aCol === '-' ? Infinity : parseFloat(aCol);
                        const bNum = bCol === '-' ? Infinity : parseFloat(bCol);
                        return direction === 'asc' ? aNum - bNum : bNum - aNum;
                    } 
                    // For week columns (index 3 and up), treat "-" as lowest value when sorting desc
                    else {
                        const aNum = aCol === '-' ? (direction === 'desc' ? -Infinity : Infinity) : parseFloat(aCol);
                        const bNum = bCol === '-' ? (direction === 'desc' ? -Infinity : Infinity) : parseFloat(bCol);
                        return direction === 'asc' ? aNum - bNum : bNum - aNum;
                    }
                } else {
                    return direction === 'asc'
                        ? aCol.localeCompare(bCol)
                        : bCol.localeCompare(aCol);
                }
            });

            // Re-append sorted rows
            const tbody = table.querySelector('tbody');
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));

            table.setAttribute('data-sort-direction-' + columnIndex, direction);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.sortable').forEach((th, index) => {
                th.addEventListener('click', (e) => {
                    e.preventDefault(); // Prevent default action
                    const table = th.closest('table');
                    if (th.classList.contains('numeric-sort')) {
                        sortTableByColumn(table, index, true);
                    } else {
                        sortTableByColumn(table, index, false);
                    }
                });
            });
        });
    </script>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <h1 style="text-align:center;">Weekly Player Scores</h1>

    <!-- Filter Section -->
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-section">
                <label for="team">Team Filter:</label>
                <select name="team" id="team">
                    <option value="">All Teams</option>
                    <?php foreach ($allTeams as $team): ?>
                        <option 
                            value="<?php echo htmlspecialchars($team['team_name']); ?>"
                            <?php if ($teamFilter === $team['team_name']) echo 'selected'; ?>
                        >
                            <?php echo htmlspecialchars($team['team_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-section">
                <label for="position">Position Filter:</label>
                <select name="position" id="position">
                    <option value="">All Positions</option>
                    <?php foreach ($allPositions as $pos): ?>
                        <option 
                            value="<?php echo htmlspecialchars($pos['slot_name']); ?>"
                            <?php if ($positionFilter === $pos['slot_name']) echo 'selected'; ?>
                        >
                            <?php echo htmlspecialchars($pos['slot_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="FLEX" <?php if ($positionFilter === 'FLEX') echo 'selected'; ?>>FLEX</option>
                </select>
            </div>
        </div>

        <div class="filter-row">
            <div class="filter-section">
                <label for="adp_min">Min ADP:</label>
                <input 
                    type="text" 
                    name="adp_min" 
                    id="adp_min"
                    value="<?php echo htmlspecialchars($adpMin); ?>"
                    <?php if ($noAdpOnly) echo 'disabled'; ?>
                />
            </div>
            <div class="filter-section">
                <label for="adp_max">Max ADP:</label>
                <input 
                    type="text" 
                    name="adp_max" 
                    id="adp_max"
                    value="<?php echo htmlspecialchars($adpMax); ?>"
                    <?php if ($noAdpOnly) echo 'disabled'; ?>
                />
            </div>
            <div class="filter-section">
                <label for="no_adp">No ADP Only:</label>
                <input 
                    type="checkbox" 
                    name="no_adp" 
                    id="no_adp" 
                    value="1"
                    <?php if ($noAdpOnly) echo 'checked'; ?>
                    onchange="toggleAdpInputs(this)"
                />
            </div>
            <div class="filter-section">
                <label for="ownership_min">Min Ownership %:</label>
                <input 
                    type="text" 
                    name="ownership_min" 
                    id="ownership_min" 
                    value="<?php echo htmlspecialchars($ownershipMin ?? ''); ?>"
                />
            </div>
            <div class="filter-section">
                <label for="ownership_max">Max Ownership %:</label>
                <input 
                    type="text" 
                    name="ownership_max" 
                    id="ownership_max" 
                    value="<?php echo htmlspecialchars($ownershipMax ?? ''); ?>"
                />
            </div>

            <div class="filter-section buttons">
                <button type="submit">Apply Filters</button>
                <button 
                    type="button" 
                    onclick="window.location.href='leaderboard_players_weekly.php'"
                >
                    Reset Filters
                </button>
            </div>
        </div>
    </form>

    <script>
        function toggleAdpInputs(checkbox) {
            const adpMin = document.getElementById('adp_min');
            const adpMax = document.getElementById('adp_max');
            adpMin.disabled = checkbox.checked;
            adpMax.disabled = checkbox.checked;
            if (checkbox.checked) {
                adpMin.value = '';
                adpMax.value = '';
            }
        }
    </script>

    <table 
        border="1"
        data-sort-direction-0=""
        data-sort-direction-1=""
        data-sort-direction-2=""
        <?php foreach ($availableWeeks as $index => $week): ?>
            data-sort-direction-<?php echo $index + 3; ?>=""
        <?php endforeach; ?>
    >
        <thead>
            <tr>
                <th class="sortable">Player Name</th>
                <th class="sortable">Position</th>
                <th class="sortable numeric-sort">Final ADP</th>
                <th class="sortable numeric-sort">Ownership %</th>
                <?php foreach ($availableWeeks as $week): ?>
                    <th class="sortable numeric-sort">Week <?php echo $week; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $processedPlayers = [];
            foreach ($players as $player):
                // Only process each player once
                if (isset($processedPlayers[$player['id']])) continue;
                $processedPlayers[$player['id']] = true;
                
                $fullName = htmlspecialchars($player['first_name'] . ' ' . $player['last_name']);
                $position = htmlspecialchars($player['position'] ?? '');
                $finalAdp = $player['final_adp'] ? htmlspecialchars($player['final_adp']) : '-';
                $ownership = ($player['exposure_pct'] ?? 0) == 100 ? '100' : number_format($player['exposure_pct'] ?? 0, 1);
            ?>
                <tr>
                    <td><a href="/player.php?id=<?php echo $player['id']; ?>"><?php echo $fullName; ?></a></td>
                    <td class="position <?php 
                        if (str_starts_with($position, 'P')) {
                            echo 'pitcher';
                        } elseif (in_array($position, ['IF'])) {
                            echo 'infield';
                        } elseif (str_starts_with($position, 'OF')) {
                            echo 'outfield';
                        }
                    ?>"><?php echo $position; ?></td>
                    <td><?php echo $finalAdp; ?></td>
                    <td class="ownership-cell"><?php echo $ownership; ?></td>
                    <?php foreach ($availableWeeks as $week): 
                        $weekScore = isset($weeklyScores[$player['id']][$week]) ? 
                            $weeklyScores[$player['id']][$week] : '-';
                        $pitchCount = isset($weeklyPitchCounts[$player['id']][$week]) ? 
                            $weeklyPitchCounts[$player['id']][$week] : 0;
                        $medianScore = isset($medianScores[$week]) ? $medianScores[$week] : 0;
                        $scoreValue = $weekScore === '-' ? 0 : floatval($weekScore);
                        $scoreDiff = $medianScore > 0 ? abs($scoreValue - $medianScore) / $medianScore : 0;
                    ?>
                        <td class="score-cell <?php 
                            if ($weekScore !== '-') {
                                echo $scoreValue > $medianScore ? 'above' : 'below';
                            }
                            if ($pitchCount > 1) {
                                echo ' two-start';  // We'll keep the class name but it now handles 2+ starts
                            }
                        ?>" style="--score-diff: <?php echo min($scoreDiff, 1); ?>"
                           <?php if ($pitchCount > 1) echo 'data-starts="' . $pitchCount . '"'; ?>>
                            <?php echo $weekScore; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html> 