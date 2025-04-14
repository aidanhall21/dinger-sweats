<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

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
// Initialize ownership variables with default values
$ownershipMin = isset($_GET['ownership_min']) ? $_GET['ownership_min'] : '';
$ownershipMax = isset($_GET['ownership_max']) ? $_GET['ownership_max'] : '';

// ----------------------------------------------------------------------------
// BUILD QUERY
// ----------------------------------------------------------------------------
// We add a subselect (advance_rate) to count how often the player's picks appear
// in the top 2 for each draft. The subselect uses a window function (DENSE_RANK).
// Because GREATEST() is not a builtin in older SQLite, we use a CASE expression
// to safeguard against dividing by zero when a player has zero picks.

// Calculate yesterday's date in MM/DD format
// $yesterday = date('m/d', strtotime('-1 day'));

$query = "
    SELECT 
        p.id,
        p.first_name,
        p.last_name,
        p.team_name,
        p.slot_name AS position,
        p.final_adp,
        IFNULL(SUM(h.TOT), 0) + IFNULL(SUM(pc.TOT), 0) AS total_score,
        COALESCE(
            (SELECT SUM(TOT) FROM hitters WHERE player_id = p.id AND Date = (SELECT MAX(Date) FROM hitters)),
            0
        ) + COALESCE(
            (SELECT SUM(TOT) FROM pitchers WHERE player_id = p.id AND Date = (SELECT MAX(Date) FROM pitchers)),
            0
        ) as last_day_change,
        em.exposure_pct,
        em.advance_rate,
        (
            SELECT 
                arh_current.advance_rate - arh_previous.advance_rate
            FROM 
                advance_rate_history arh_current
            LEFT JOIN (
                SELECT 
                    player_id, 
                    date, 
                    advance_rate
                FROM 
                    advance_rate_history 
                WHERE 
                    player_id = p.id
                ORDER BY 
                    date DESC
                LIMIT 1 OFFSET 1
            ) arh_previous ON arh_current.player_id = arh_previous.player_id
            WHERE 
                arh_current.player_id = p.id
            ORDER BY 
                arh_current.date DESC
            LIMIT 1
        ) as advance_rate_change
    FROM players p
    LEFT JOIN hitters h ON h.player_id = p.id 
    LEFT JOIN pitchers pc ON pc.player_id = p.id 
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

// Group and require total_score > 0
$query .= "
    GROUP BY p.id
    HAVING total_score > 0
";

// Sort by total_score descending
$query .= " ORDER BY total_score DESC";

// After connecting to the database but before the main query
$medianQuery = "
    WITH PlayerScores AS (
        SELECT 
            p.id, 
            IFNULL(SUM(h.TOT), 0) + IFNULL(SUM(pc.TOT), 0) AS total_score
        FROM players p
        LEFT JOIN hitters h ON h.player_id = p.id
        LEFT JOIN pitchers pc ON pc.player_id = p.id
        WHERE p.final_adp != '-'
        GROUP BY p.id
        HAVING total_score > 0
    )
    SELECT total_score as median_score
    FROM (
        SELECT 
            total_score, 
            ROW_NUMBER() OVER (ORDER BY total_score) as row_num,
            COUNT(*) OVER () as total_count
        FROM PlayerScores
    ) ranked
    WHERE row_num IN ((total_count + 1)/2, (total_count + 2)/2)
    LIMIT 1;
";

// Execute the median query
$medianStmt = $pdo->query($medianQuery);
$medianScore = $medianStmt->fetchColumn();

// Execute the main query
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
    <title>Sweating Dingers | Player Leaderboard | Top Scores</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css?v=<?php echo filemtime(__DIR__ . '/css/common.css'); ?>">
    <style>
        /* Remove overlapping table and filter form styles */
        .filter-form {
            width: 80%;
            margin: 1rem auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1rem;
            background: #f8f8f8;
            border-radius: 8px;
        }
        
        /* Update advance rate coloring to use solid colors */
        .advance-rate {
            position: relative;
        }
        
        .advance-rate::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }
        
        .advance-rate.above::before {
            background-color: #00ff00;
            opacity: calc(var(--rate-diff) * 0.8);
        }
        
        .advance-rate.below::before {
            background-color: #ff0000;
            opacity: calc(var(--rate-diff) * 0.8);
        }
    </style>

    <!-- Basic JS for client-side table sorting -->
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

        function sortTableByColumn(table, columnIndex, isNumeric = false) {
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const direction = table.getAttribute('data-sort-direction-' + columnIndex) === 'asc' ? 'desc' : 'asc';
            
            // Remove sort classes from all headers
            table.querySelectorAll('th').forEach(th => th.classList.remove('asc', 'desc'));
            
            // Add sort class to current header
            const currentHeader = table.querySelector(`th:nth-child(${columnIndex + 1})`);
            currentHeader.classList.add(direction);
            
            rows.sort((a, b) => {
                const aCol = a.children[columnIndex].innerText.trim();
                const bCol = b.children[columnIndex].innerText.trim();

                // Special handling for blank team values
                if (columnIndex === 2) { // Team column
                    if (aCol === '' && bCol === '') return 0;
                    if (aCol === '') return 1;
                    if (bCol === '') return -1;
                }

                if (isNumeric) {
                    // Treat " (dash) as Infinity for numeric sorts
                    const aNum = aCol === '-' ? Infinity : parseFloat(aCol);
                    const bNum = bCol === '-' ? Infinity : parseFloat(bCol);
                    return direction === 'asc' ? aNum - bNum : bNum - aNum;
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
            const table = document.querySelector('table');
            // Set initial sort direction for Total Score and Last Day Change columns
            table.setAttribute('data-sort-direction-5', 'desc');
            table.setAttribute('data-sort-direction-6', 'desc');
            
            // Add initial sort classes to headers
            const totalScoreHeader = table.querySelector('th:nth-child(6)');
            const lastDayChangeHeader = table.querySelector('th:nth-child(7)');
            totalScoreHeader.classList.add('desc');
            lastDayChangeHeader.classList.add('desc');

            document.querySelectorAll('.sortable').forEach((th, index) => {
                th.addEventListener('click', () => {
                    if (th.classList.contains('numeric-sort') || index === 0) {
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
    
    <h1 style="text-align:center;">Top Players Leaderboard</h1>

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
                    onclick="window.location.href='leaderboard_players.php'"
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
        data-sort-direction-3=""
        data-sort-direction-4=""
        data-sort-direction-5=""
        data-sort-direction-6=""
        data-sort-direction-7=""
        data-sort-direction-8=""
    >
        <thead>
            <tr>
                <th class="sortable numeric-sort">Rank</th>
                <th class="sortable">Player Name</th>
                <th class="sortable">Team</th>
                <th class="sortable">Position</th>
                <th class="sortable numeric-sort">Final ADP</th>
                <th class="sortable numeric-sort">Total Score</th>
                <th class="sortable numeric-sort">Last Day Change</th>
                <th class="sortable numeric-sort">Ownership %</th>
                <th class="sortable numeric-sort">Advance Rate %</th>
                <th class="sortable numeric-sort">Last Day Change</th>
            </tr>
        </thead>
        <tbody>
            <?php 
                $rank = 1;
                foreach ($players as $player):
                    $fullName   = htmlspecialchars($player['first_name'] . ' ' . $player['last_name']);
                    $teamName   = htmlspecialchars($player['team_name'] ?? '');
                    $position   = htmlspecialchars($player['position'] ?? '');
                    $finalAdp   = $player['final_adp'] ? htmlspecialchars($player['final_adp']) : '-';
                    $totalScore = htmlspecialchars($player['total_score'] ?? '0');
                    $lastDayChange = htmlspecialchars($player['last_day_change'] ?? '0');
                    $ownership = ($player['exposure_pct'] ?? 0) == 100 ? '100' : number_format($player['exposure_pct'] ?? 0, 1);
                    $advanceRate = number_format($player['advance_rate'] ?? 0, 1);
            ?>
            <tr>
                <td><?php echo $rank++; ?></td>
                <td><a href="player.php?id=<?php echo htmlspecialchars($player['id']); ?>"><?php echo $fullName; ?></a></td>
                <td><?php echo $teamName; ?></td>
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
                <td class="total-score <?php 
                    $scoreDiff = abs($totalScore - $medianScore) / max($medianScore, 1);
                    // Use power function for gradual initial progression while maintaining strong maximum opacity
                    $opacity = min(pow($scoreDiff, 2.5), 1);
                    echo $totalScore >= $medianScore ? 'above' : 'below';
                ?>" style="--score-diff: <?php echo $opacity; ?>">
                    <?php echo $totalScore; ?>
                </td>
                <td><?php echo $lastDayChange; ?></td>
                <td class="ownership-cell">
                    <?php echo $ownership; ?>
                </td>
                <td class="advance-rate <?php 
                    $advanceRateValue = $advanceRate / 100;
                    $baseline = 0.1667; // 16.67% (1/6)
                    $diff = $advanceRateValue - $baseline;
                    if ($diff > 0) {
                        // For values above baseline (16.67% to 100%)
                        // Scale the difference to 0-1 range (83.33% range)
                        $normalizedDiff = $diff / (1 - $baseline);
                        $opacity = min(pow($normalizedDiff, 0.5), 1);
                        echo 'above';
                    } else {
                        // For values below baseline (0% to 16.67%)
                        // Scale the difference to 0-1 range (16.67% range)
                        $normalizedDiff = abs($diff) / $baseline;
                        $opacity = min(pow($normalizedDiff, 3), 1);
                        echo 'below';
                    }
                ?>" style="--rate-diff: <?php echo $opacity; ?>">
                    <a href="leaderboard_teams.php?search=&player1=<?php echo urlencode($player['id']); ?>&player2=&P=&IF=&OF=&league_place=">
                        <?php echo $advanceRate; ?>
                    </a>
                </td>
                <td class="<?php echo ($player['advance_rate_change'] > 0) ? 'positive' : (($player['advance_rate_change'] < 0) ? 'negative' : ''); ?>">
                    <?php 
                        $advRateChange = $player['advance_rate_change'] ?? 0;
                        echo ($advRateChange > 0 ? '+' : '') . number_format($advRateChange, 1); 
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html>