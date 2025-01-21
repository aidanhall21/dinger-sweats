<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// ----------------------------------------------------------------------------
// EXCLUDED WEEKS
// ----------------------------------------------------------------------------
$excludedWeeks = [2426, 2427, 2410, 2414, 2416, 2418, 2428, 2430, 2432, 2435];
$excludedWeeksList = implode(',', $excludedWeeks);

// ----------------------------------------------------------------------------
// GET FILTER VALUES
// ----------------------------------------------------------------------------
$teamFilter = !empty($_GET['team']) ? trim($_GET['team']) : '';
$positionFilter = !empty($_GET['position']) ? trim($_GET['position']) : '';
$adpMin = isset($_GET['adp_min']) ? trim($_GET['adp_min']) : '';
$adpMax = isset($_GET['adp_max']) ? trim($_GET['adp_max']) : '';
$noAdpOnly = isset($_GET['no_adp']) && $_GET['no_adp'] === '1';

// ----------------------------------------------------------------------------
// BUILD QUERY
// ----------------------------------------------------------------------------
// We sum the TOT columns from hitters and pitchers, excluding certain weeks.
// We also join against the teams table to get the team name (or abbreviation).
$query = "
    SELECT 
        p.id,
        p.first_name,
        p.last_name,
        t.name AS team_name,
        p.slotName AS position,
        p.adp AS final_adp,
        IFNULL(SUM(h.TOT), 0) + IFNULL(SUM(pc.TOT), 0) AS total_score
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.id
    
    -- Exclude the unwanted weeks right in the JOIN condition
    LEFT JOIN hitters h 
        ON h.player_id = p.id 
        AND h.week_id NOT IN ($excludedWeeksList)
    LEFT JOIN pitchers pc 
        ON pc.player_id = p.id 
        AND pc.week_id NOT IN ($excludedWeeksList)
    WHERE 1=1
";

// Add filters if any:
if ($teamFilter !== '') {
    $query .= " AND p.team_id = :team_id";
}
if ($positionFilter !== '') {
    $query .= " AND p.slotName = :position";
}
if ($noAdpOnly) {
    $query .= " AND (p.adp IS NULL OR p.adp = '-')";
} else {
    if ($adpMin !== '') {
        $query .= " AND (CAST(p.adp AS DECIMAL(10,2)) >= :adp_min OR p.adp IS NULL OR p.adp = '-')";
    }
    if ($adpMax !== '') {
        $query .= " AND p.adp IS NOT NULL AND p.adp != '-' AND CAST(p.adp AS DECIMAL(10,2)) <= :adp_max";
    }
}

$query .= "
    GROUP BY p.id
    HAVING total_score > 0
    ORDER BY total_score DESC
";

$statement = $pdo->prepare($query);

// Bind parameters
if ($teamFilter !== '') {
    $statement->bindValue(':team_id', $teamFilter);
}
if ($positionFilter !== '') {
    $statement->bindValue(':position', $positionFilter);
}
if ($adpMin !== '') {
    $statement->bindValue(':adp_min', $adpMin);
}
if ($adpMax !== '') {
    $statement->bindValue(':adp_max', $adpMax);
}

$statement->execute();

$players = $statement->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// FETCH DISTINCT TEAMS AND POSITIONS FOR FILTERS
// ----------------------------------------------------------------------------
$teamsStmt = $pdo->query("SELECT id, name FROM teams ORDER BY name");
$allTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$posStmt = $pdo->query("SELECT DISTINCT slotName FROM players WHERE slotName != '' ORDER BY slotName");
$allPositions = $posStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Player Leaderboard</title>

    <!-- Simple style to highlight sorting & filtering UI -->
    <style>
        table {
            border-collapse: collapse;
            width: 80%;
            margin: 1rem auto;
        }
        th, td {
            padding: 0.5rem;
            border: 1px solid #aaa;
            text-align: center;
        }
        .filter-form {
            width: 80%;
            margin: 1rem auto;
            display: flex;
            gap: 1rem;
            justify-content: space-around;
        }
        .filter-section {
            display: flex;
            flex-direction: column;
        }
        .filter-section label {
            font-weight: bold;
        }
        .sortable:hover {
            cursor: pointer;
            text-decoration: underline;
        }
        thead {
            background: #f2f2f2;
        }
        .home-link {
            position: absolute;
            top: 1rem;
            left: 1rem;
            padding: 0.5rem 1rem;
            background: #f2f2f2;
            text-decoration: none;
            color: black;
            border-radius: 4px;
        }
        .home-link:hover {
            background: #e0e0e0;
        }
    </style>

    <!-- Basic JS for client-side table sorting -->
    <script>
        function sortTableByColumn(table, columnIndex, isNumeric = false) {
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const direction = table.getAttribute('data-sort-direction-' + columnIndex) === 'asc' ? 'desc' : 'asc';
            
            rows.sort((a, b) => {
                const aCol = a.children[columnIndex].innerText.trim();
                const bCol = b.children[columnIndex].innerText.trim();

                if (isNumeric) {
                    // Handle empty/dash values in numeric sorting
                    const aNum = aCol === '-' ? Infinity : parseFloat(aCol);
                    const bNum = bCol === '-' ? Infinity : parseFloat(bCol);
                    return direction === 'asc'
                        ? aNum - bNum
                        : bNum - aNum;
                } else {
                    return direction === 'asc'
                        ? aCol.localeCompare(bCol)
                        : bCol.localeCompare(aCol);
                }
            });

            // Remove existing rows & re-append
            const tbody = table.querySelector('tbody');
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));

            table.setAttribute('data-sort-direction-' + columnIndex, direction);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.sortable').forEach((th, index) => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    
                    // Update to include rank column as numeric
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
    <a href="/" class="home-link">← Back to Home</a>
    <h1 style="text-align:center;">Top Players Leaderboard</h1>

    <!-- Filter Section -->
    <form method="GET" class="filter-form">
        <div class="filter-section">
            <label for="team">Team Filter:</label>
            <select name="team" id="team">
                <option value="">All Teams</option>
                <?php foreach ($allTeams as $team): ?>
                    <option value="<?php echo htmlspecialchars($team['id']); ?>"
                        <?php if ($teamFilter === $team['id']) echo 'selected'; ?>
                    >
                        <?php echo htmlspecialchars($team['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-section">
            <label for="position">Position Filter:</label>
            <select name="position" id="position">
                <option value="">All Positions</option>
                <?php foreach ($allPositions as $pos): ?>
                    <option value="<?php echo htmlspecialchars($pos['slotName']); ?>"
                        <?php if ($positionFilter === $pos['slotName']) echo 'selected'; ?>
                    >
                        <?php echo htmlspecialchars($pos['slotName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-section">
            <label for="adp_min">Min ADP:</label>
            <input type="text" name="adp_min" id="adp_min" value="<?php echo htmlspecialchars($adpMin); ?>" 
                   <?php if ($noAdpOnly) echo 'disabled'; ?> />
        </div>
        <div class="filter-section">
            <label for="adp_max">Max ADP:</label>
            <input type="text" name="adp_max" id="adp_max" value="<?php echo htmlspecialchars($adpMax); ?>"
                   <?php if ($noAdpOnly) echo 'disabled'; ?> />
        </div>
        <div class="filter-section">
            <label for="no_adp">No ADP Only:</label>
            <input type="checkbox" name="no_adp" id="no_adp" value="1" 
                   <?php if ($noAdpOnly) echo 'checked'; ?> 
                   onchange="toggleAdpInputs(this)" />
        </div>

        <div class="filter-section" style="justify-content:flex-end;">
            <br />
            <button type="submit">Apply Filters</button>
            <button type="button" onclick="window.location.href='leaderboard_players.php'">Reset Filters</button>
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

    <table border="1" data-sort-direction-0="" data-sort-direction-1="" data-sort-direction-2="" data-sort-direction-3="" data-sort-direction-4="" data-sort-direction-5="">
        <thead>
            <tr>
                <th class="sortable numeric-sort">Rank</th>
                <th class="sortable">Player Name</th>
                <th class="sortable">Team</th>
                <th class="sortable">Position</th>
                <th class="sortable numeric-sort">Final ADP</th>
                <th class="sortable numeric-sort">Total Score</th>
            </tr>
        </thead>
        <tbody>
            <?php 
                $rank = 1;
                foreach ($players as $player):
                    // Safely escape user-facing values
                    $fullName = htmlspecialchars($player['first_name'] . ' ' . $player['last_name']);
                    $teamName = htmlspecialchars($player['team_name'] ?? '');
                    $position = htmlspecialchars($player['position'] ?? '');
                    $finalAdp = $player['final_adp'] ? htmlspecialchars($player['final_adp']) : '-';
                    $totalScore = htmlspecialchars($player['total_score'] ?? '0');
            ?>
            <tr>
                <td><?php echo $rank++; ?></td>
                <td><?php echo $fullName; ?></td>
                <td><?php echo $teamName; ?></td>
                <td><?php echo $position; ?></td>
                <td><?php echo $finalAdp; ?></td>
                <td><?php echo $totalScore; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html> 