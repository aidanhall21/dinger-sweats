<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// ----------------------------------------------------------------------------
// SQLite requires version 3.25+ for window functions like DENSE_RANK()
// ----------------------------------------------------------------------------

// ----------------------------------------------------------------------------
// EXCLUDED WEEKS
// ----------------------------------------------------------------------------
$excludedWeeks = [2426, 2427, 2410, 2414, 2416, 2418, 2428, 2430, 2432, 2435];
$excludedWeeksList = implode(',', $excludedWeeks);

// ----------------------------------------------------------------------------
// GET FILTER VALUES
// ----------------------------------------------------------------------------
$teamFilter     = !empty($_GET['team']) ? trim($_GET['team']) : '';
$positionFilter = !empty($_GET['position']) ? trim($_GET['position']) : '';
$adpMin         = isset($_GET['adp_min']) ? trim($_GET['adp_min']) : '';
$adpMax         = isset($_GET['adp_max']) ? trim($_GET['adp_max']) : '';
$noAdpOnly      = (isset($_GET['no_adp']) && $_GET['no_adp'] === '1');
$ownershipMin   = isset($_GET['ownership_min']) ? trim($_GET['ownership_min']) : '';
$ownershipMax   = isset($_GET['ownership_max']) ? trim($_GET['ownership_max']) : '';

// ----------------------------------------------------------------------------
// BUILD QUERY
// ----------------------------------------------------------------------------
// We add a subselect (advance_rate) to count how often the player's picks appear
// in the top 2 for each draft. The subselect uses a window function (DENSE_RANK).
// Because GREATEST() is not a builtin in older SQLite, we use a CASE expression
// to safeguard against dividing by zero when a player has zero picks.

$query = "
    SELECT 
        p.id,
        p.first_name,
        p.last_name,
        t.name AS team_name,
        p.slotName AS position,
        p.adp AS final_adp,
        IFNULL(SUM(h.TOT), 0) + IFNULL(SUM(pc.TOT), 0) AS total_score,

        -- Simplified Advance Rate calculation using the advance table
        (
            COALESCE(
                (SELECT SUM(times_advanced) * 1.0 FROM advance WHERE player_id = p.id)
                /
                (SELECT COUNT(*) FROM picks WHERE player_id = p.id)
            , 0)
        ) AS advance_rate,

        (
            (SELECT COUNT(*) * 1.0 FROM picks pk WHERE pk.player_id = p.id)
            /
            (SELECT COUNT(DISTINCT draft_id) * 1.0 FROM picks)
        ) AS ownership

    FROM players p
    LEFT JOIN teams t ON p.team_id = t.id

    -- Exclude unwanted weeks in the join
    LEFT JOIN hitters h 
        ON h.player_id = p.id 
        AND h.week_id NOT IN ($excludedWeeksList)
    LEFT JOIN pitchers pc 
        ON pc.player_id = p.id 
        AND pc.week_id NOT IN ($excludedWeeksList)
    WHERE 1=1
";

// ----------------------------------------------------------------------------
// ADD FILTERS
// ----------------------------------------------------------------------------
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
        // For safety, treat blank and dash as "null" ADP
        $query .= " AND (CAST(p.adp AS REAL) >= :adp_min OR p.adp IS NULL OR p.adp = '-')";
    }
    if ($adpMax !== '') {
        $query .= " AND p.adp IS NOT NULL AND p.adp != '-' AND CAST(p.adp AS REAL) <= :adp_max";
    }
}

// Group and require total_score > 0
$query .= "
    GROUP BY p.id
    HAVING total_score > 0
";

// Ownership filters
if ($ownershipMin !== '') {
    $query .= " AND (ownership * 100) >= :ownership_min";
}
if ($ownershipMax !== '') {
    $query .= " AND (ownership * 100) <= :ownership_max";
}

// Sort by total_score descending
$query .= " ORDER BY total_score DESC";

// ----------------------------------------------------------------------------
// PREPARE AND EXECUTE
// ----------------------------------------------------------------------------
$statement = $pdo->prepare($query);

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
if ($ownershipMin !== '') {
    $statement->bindValue(':ownership_min', $ownershipMin, PDO::PARAM_INT);
}
if ($ownershipMax !== '') {
    $statement->bindValue(':ownership_max', $ownershipMax, PDO::PARAM_INT);
}

$statement->execute();
$players = $statement->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------------------------------
// FETCH DISTINCT TEAMS AND POSITIONS FOR FILTER SELECT
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
            document.querySelectorAll('.sortable').forEach((th, index) => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
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
                    <option 
                        value="<?php echo htmlspecialchars($team['id']); ?>"
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
                    <option 
                        value="<?php echo htmlspecialchars($pos['slotName']); ?>"
                        <?php if ($positionFilter === $pos['slotName']) echo 'selected'; ?>
                    >
                        <?php echo htmlspecialchars($pos['slotName']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

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
                value="<?php echo htmlspecialchars($ownershipMin); ?>"
            />
        </div>
        <div class="filter-section">
            <label for="ownership_max">Max Ownership %:</label>
            <input 
                type="text" 
                name="ownership_max" 
                id="ownership_max" 
                value="<?php echo htmlspecialchars($ownershipMax); ?>"
            />
        </div>

        <div class="filter-section" style="justify-content:flex-end;">
            <br />
            <button type="submit">Apply Filters</button>
            <button 
                type="button" 
                onclick="window.location.href='leaderboard_players.php'"
            >
                Reset Filters
            </button>
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
    >
        <thead>
            <tr>
                <th class="sortable numeric-sort">Rank</th>
                <th class="sortable">Player Name</th>
                <th class="sortable">Team</th>
                <th class="sortable">Position</th>
                <th class="sortable numeric-sort">Final ADP</th>
                <th class="sortable numeric-sort">Ownership (%)</th>
                <th class="sortable numeric-sort">Total Score</th>
                <th class="sortable numeric-sort">Advance Rate</th>
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
                    
                    // Ownership might be NULL if no picks exist
                    $ownership = isset($player['ownership']) ? (float) $player['ownership'] : 0.0;

                    // The subselect in our query already calculates "advance_rate"
                    $advanceRate = isset($player['advance_rate']) ? (float) $player['advance_rate'] : 0.0;
            ?>
            <tr>
                <td><?php echo $rank++; ?></td>
                <td><?php echo $fullName; ?></td>
                <td><?php echo $teamName; ?></td>
                <td><?php echo $position; ?></td>
                <td><?php echo $finalAdp; ?></td>
                <td><?php echo round($ownership * 100, 1); ?></td>
                <td><?php echo $totalScore; ?></td>
                <td><?php echo round($advanceRate * 100, 1) . '%'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>