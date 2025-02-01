<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Handle search and filters
$searchTerm = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : '';
$pitchers = isset($_GET['P']) && $_GET['P'] !== '' ? (int)$_GET['P'] : null;
$infielders = isset($_GET['IF']) && $_GET['IF'] !== '' ? (int)$_GET['IF'] : null;
$outfielders = isset($_GET['OF']) && $_GET['OF'] !== '' ? (int)$_GET['OF'] : null;
$slots = isset($_GET['slots']) && is_array($_GET['slots']) ? array_map('intval', $_GET['slots']) : [];
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$player1 = isset($_GET['player1']) && $_GET['player1'] !== '' ? trim($_GET['player1']) : '';
$player2 = isset($_GET['player2']) && $_GET['player2'] !== '' ? trim($_GET['player2']) : '';
$slowsOnly = isset($_GET['slows_only']) ? true : false;
$fastsOnly = isset($_GET['fasts_only']) ? true : false;
$params = [];

// Build base query
$query = "
    SELECT 
        l.draft_entry_id,
        l.username,
        l.rank,
        l.cumulative_points,
        COUNT(*) OVER() as total_count
    FROM leaderboard l
    WHERE 1=1
";

$params = [];

if ($searchTerm !== '') {
    $query .= " AND l.username LIKE :search";
    $params[':search'] = "%$searchTerm%";
}

// Only JOIN when we need structure or picks data
$needsStructure = ($pitchers !== null || $infielders !== null || $outfielders !== null);
$needsPicks = ($player1 !== '' || $player2 !== '');

if ($needsStructure) {
    $query .= " AND EXISTS (
        SELECT 1 FROM structures s 
        WHERE s.draft_entry_id = l.draft_entry_id";
    
    if ($pitchers !== null) {
        $query .= " AND s.P = :pitchers";
        $params[':pitchers'] = $pitchers;
    }
    if ($infielders !== null) {
        $query .= " AND s.IF = :infielders";
        $params[':infielders'] = $infielders;
    }
    if ($outfielders !== null) {
        $query .= " AND s.OF = :outfielders";
        $params[':outfielders'] = $outfielders;
    }
    $query .= ")";
}

if ($needsPicks) {
    $query .= " AND l.draft_entry_id IN (
        SELECT draft_entry_id 
        FROM picks 
        WHERE player_id IN (:player1" . ($player2 !== '' ? ", :player2" : "") . ")
        GROUP BY draft_entry_id
        HAVING COUNT(DISTINCT player_id) = " . ($player2 !== '' ? "2" : "1") . "
    )";
    $params[':player1'] = $player1;
    if ($player2 !== '') {
        $params[':player2'] = $player2;
    }
}

if ($slowsOnly || $fastsOnly) {
    $query .= " AND EXISTS (
        SELECT 1 FROM slows s 
        WHERE s.draft_entry_id = l.draft_entry_id
        AND s.fast = :fast
    )";
    $params[':fast'] = $fastsOnly ? 1 : 0;
}

if (!empty($slots)) {
    $placeholders = array_map(function($i) { return ':slot' . $i; }, array_keys($slots));
    $query .= " AND EXISTS (
        SELECT 1 FROM draft_slots ds 
        WHERE ds.draft_entry_id = l.draft_entry_id 
        AND ds.draft_slot IN (" . implode(',', $placeholders) . ")
    )";
    
    foreach ($slots as $i => $slot) {
        $params[':slot' . $i] = $slot;
    }
}

$query .= " ORDER BY l.rank ASC";

// Add window functions to get total count and advance info in one query
$finalQuery = "
    WITH filtered_teams AS (
        {$query}
    ),
    team_counts AS (
        SELECT COUNT(DISTINCT ft.draft_entry_id) as total_count,
               COUNT(DISTINCT CASE WHEN a.draft_entry_id IS NOT NULL THEN ft.draft_entry_id END) as advancing_count
        FROM filtered_teams ft
        LEFT JOIN advance a ON ft.draft_entry_id = a.draft_entry_id
    )
    SELECT DISTINCT
        t.draft_entry_id,
        t.username,
        t.rank,
        t.cumulative_points,
        c.total_count,
        c.advancing_count,
        a.place
    FROM (SELECT DISTINCT * FROM filtered_teams) t
    CROSS JOIN team_counts c
    LEFT JOIN advance a ON t.draft_entry_id = a.draft_entry_id
    LIMIT 150 OFFSET :offset
";

// Execute single query to get all needed data
$statement = $pdo->prepare($finalQuery);
$params[':offset'] = $offset;
$statement->execute($params);
$teams = $statement->fetchAll(PDO::FETCH_ASSOC);

// Extract counts from first row (they're the same for all rows)
$totalCount = !empty($teams) ? $teams[0]['total_count'] : 0;
$advancingCount = !empty($teams) ? $teams[0]['advancing_count'] : 0;
$advanceRate = $totalCount > 0 ? round(($advancingCount * 100) / $totalCount, 1) : 0;

$rankedTeams = array_map(function($team) {
    return [
        'rank' => $team['rank'],
        'username' => $team['username'],
        'total_points' => $team['cumulative_points'],
        'draft_entry_id' => $team['draft_entry_id'],
        'is_advancing' => $team['place'] !== null,
        'place' => $team['place']
    ];
}, $teams);

// Create current URL without offset parameter
$currentUrl = strtok($_SERVER["REQUEST_URI"], '?');
$queryParams = $_GET;
unset($queryParams['offset']);
$currentUrlWithParams = $currentUrl . '?' . http_build_query($queryParams);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Drafted Teams Leaderboard</title>
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
        .search-form {
            width: 80%;
            margin: 1rem auto;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .search-form input[type="text"] {
            padding: 0.5rem;
            width: 300px;
        }
        .search-form button {
            padding: 0.5rem 1rem;
        }
        h1 {
            text-align: center;
        }
        .filters {
            width: 80%;
            margin: 1rem auto;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .filters input[type="number"] {
            width: 60px;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .load-more {
            display: block;
            margin: 1rem auto;
            padding: 0.5rem 1rem;
            background: #f2f2f2;
            border: 1px solid #aaa;
            border-radius: 4px;
            cursor: pointer;
        }
        .load-more:hover {
            background: #e0e0e0;
        }
        .ui-autocomplete {
            max-height: 200px;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
        }
        .player-search {
            width: 150px;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .hidden-input {
            display: none;
        }
        .filter-form {
            width: 90%;
            margin: 1rem auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1rem;
            background: #f8f8f8;
            border-radius: 8px;
        }
        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            justify-content: center;
            align-items: flex-end;
        }
        .filter-section {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .filter-section.buttons {
            margin-left: auto;
        }
        .checkbox-group {
            display: flex;
            gap: 1rem;
        }
        .filter-section label {
            font-weight: bold;
            white-space: nowrap;
        }
        .filter-section input[type="text"],
        .filter-section input[type="number"] {
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .filter-section button {
            padding: 0.5rem 1rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f2f2f2;
            cursor: pointer;
        }
        .filter-section button:hover {
            background: #e0e0e0;
        }
        tr.advancing {
            background-color: #fff0b3;  /* Slightly darker gold color */
        }
        tr.first-place {
            background-color: #fff0b3;  /* Light gold */
        }
        tr.second-place {
            background-color: #e8e8e8;  /* Light silver */
        }
        select[multiple] {
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        select[multiple] option {
            padding: 0.25rem 0.5rem;
        }
        .draft-slots-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.25rem;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: white;
        }
        .slot-checkbox {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .slot-checkbox:hover {
            background-color: #f5f5f5;
        }
    </style>
    <link rel="stylesheet" href="//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script>
        function setupPlayerAutocomplete(inputId, hiddenId) {
            $(`#${inputId}`).autocomplete({
                source: function(request, response) {
                    $.getJSON("player_search.php", {
                        term: request.term
                    }, function(data) {
                        response(data.map(function(item) {
                            return {
                                label: item.player_name,
                                value: item.player_name,
                                id: item.player_id
                            };
                        }));
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $(`#${hiddenId}`).val(ui.item.id);
                }
            });
        }

        $(document).ready(function() {
            setupPlayerAutocomplete('player1_search', 'player1');
            setupPlayerAutocomplete('player2_search', 'player2');
            
            $('input[name="slows_only"]').change(function() {
                if ($(this).is(':checked')) {
                    $('input[name="fasts_only"]').prop('checked', false);
                }
            });

            $('input[name="fasts_only"]').change(function() {
                if ($(this).is(':checked')) {
                    $('input[name="slows_only"]').prop('checked', false);
                }
            });
        });
    </script>
</head>
<body>
    <a href="/" class="home-link">← Back to Home</a>
    <h1>Drafted Teams Leaderboard</h1>

    <form method="GET" class="filter-form">
        <!-- First Row -->
        <div class="filter-row">
            <div class="filter-section">
                <label for="search">Username:</label>
                <input type="text" 
                       id="search"
                       name="search" 
                       placeholder="Search by username..."
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            </div>

            <div class="filter-section">
                <label for="player1_search">Player 1:</label>
                <input type="text" 
                       id="player1_search" 
                       class="player-search" 
                       placeholder="Search player..."
                       value="<?php 
                           if ($player1 !== '') {
                               $stmt = $pdo->prepare("SELECT player_name FROM picks WHERE player_id = ? LIMIT 1");
                               $stmt->execute([$player1]);
                               echo htmlspecialchars($stmt->fetchColumn());
                           }
                       ?>">
                <input type="hidden" 
                       id="player1" 
                       name="player1" 
                       value="<?php echo htmlspecialchars($player1); ?>">
            </div>

            <div class="filter-section">
                <label for="player2_search">Player 2:</label>
                <input type="text" 
                       id="player2_search" 
                       class="player-search" 
                       placeholder="Search player..."
                       value="<?php 
                           if ($player2 !== '') {
                               $stmt = $pdo->prepare("SELECT player_name FROM picks WHERE player_id = ? LIMIT 1");
                               $stmt->execute([$player2]);
                               echo htmlspecialchars($stmt->fetchColumn());
                           }
                       ?>">
                <input type="hidden" 
                       id="player2" 
                       name="player2" 
                       value="<?php echo htmlspecialchars($player2); ?>">
            </div>

            <div class="filter-section checkbox-group">
                <label>
                    <input type="checkbox" 
                           name="slows_only" 
                           <?php echo $slowsOnly ? 'checked' : ''; ?>>
                    Slows Only
                </label>
                <label>
                    <input type="checkbox" 
                           name="fasts_only" 
                           <?php echo $fastsOnly ? 'checked' : ''; ?>>
                    Fasts Only
                </label>
            </div>
        </div>

        <!-- Second Row -->
        <div class="filter-row">
            <div class="filter-section">
                <label for="P">Pitchers:</label>
                <input type="number" 
                       id="P"
                       name="P" 
                       min="0" 
                       value="<?php echo isset($_GET['P']) ? htmlspecialchars($_GET['P']) : ''; ?>">
            </div>
            
            <div class="filter-section">
                <label for="IF">Infielders:</label>
                <input type="number" 
                       id="IF"
                       name="IF" 
                       min="0"
                       value="<?php echo isset($_GET['IF']) ? htmlspecialchars($_GET['IF']) : ''; ?>">
            </div>
            
            <div class="filter-section">
                <label for="OF">Outfielders:</label>
                <input type="number" 
                       id="OF"
                       name="OF" 
                       min="0"
                       value="<?php echo isset($_GET['OF']) ? htmlspecialchars($_GET['OF']) : ''; ?>">
            </div>

            <div class="filter-section">
                <label>Draft Order:</label>
                <div class="draft-slots-grid">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <label class="slot-checkbox">
                            <input type="checkbox" 
                                   name="slots[]" 
                                   value="<?php echo $i; ?>" 
                                   <?php echo in_array($i, $slots) ? 'checked' : ''; ?>>
                            <?php echo $i; ?>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="filter-section buttons">
                <button type="submit">Apply Filters</button>
                <button type="button" onclick="window.location.href='leaderboard_teams.php'">Reset Filters</button>
            </div>
        </div>
    </form>

    <div style="width: 80%; margin: 1rem auto; padding: 0.5rem; background-color: #f2f2f2; border-radius: 4px; text-align: center;">
        <strong>Advance Rate:</strong> <?php echo $advancingCount; ?> of <?php echo $totalCount; ?> teams (<?php echo $advanceRate; ?>%)
    </div>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Username</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rankedTeams as $teamData): 
            $rowClass = '';
            if ($teamData['place'] === 1) {
                $rowClass = 'first-place';
            } elseif ($teamData['place'] === 2) {
                $rowClass = 'second-place';
            }
        ?>
            <tr class="<?php echo $rowClass; ?>">
                <td><?php echo htmlentities($teamData['rank']); ?></td>
                <td>
                    <a href="team_details.php?draft_entry_id=<?php echo urlencode($teamData['draft_entry_id']); ?>">
                        <?php echo htmlentities($teamData['username']); ?>
                    </a>
                </td>
                <td><?php echo htmlentities($teamData['total_points']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($offset + 150 < $totalCount): ?>
    <button class="load-more" onclick="loadMore()">Load More</button>
    <?php endif; ?>

    <script>
        function loadMore() {
            const currentOffset = <?php echo $offset; ?>;
            const newOffset = currentOffset + 150;
            const baseUrl = <?php echo json_encode($currentUrlWithParams); ?>;
            const separator = baseUrl.includes('?') ? '&' : '?';
            window.location.href = `${baseUrl}${separator}offset=${newOffset}`;
        }
    </script>
</body>
</html> 