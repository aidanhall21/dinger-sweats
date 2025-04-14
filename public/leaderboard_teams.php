<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Get all team abbreviations for dropdowns
$stmtTeams = $pdo->prepare("
    SELECT team_id, team_name, team_abbr 
    FROM teams 
    ORDER BY team_name
");
$stmtTeams->execute();
$teamOptions = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

// Handle search and filters
$searchTerm = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : '';
$pitchers = isset($_GET['P']) && $_GET['P'] !== '' ? (int)$_GET['P'] : null;
$infielders = isset($_GET['IF']) && $_GET['IF'] !== '' ? (int)$_GET['IF'] : null;
$outfielders = isset($_GET['OF']) && $_GET['OF'] !== '' ? (int)$_GET['OF'] : null;
$slots = isset($_GET['slots']) && is_array($_GET['slots']) ? array_map('intval', $_GET['slots']) : [];
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$player1 = isset($_GET['player1']) && $_GET['player1'] !== '' ? trim($_GET['player1']) : '';
$player2 = isset($_GET['player2']) && $_GET['player2'] !== '' ? trim($_GET['player2']) : '';
$stack1 = isset($_GET['stack1']) && $_GET['stack1'] !== '' ? trim($_GET['stack1']) : '';
$stack2 = isset($_GET['stack2']) && $_GET['stack2'] !== '' ? trim($_GET['stack2']) : '';
$slowsOnly = isset($_GET['slows_only']) ? true : false;
$fastsOnly = isset($_GET['fasts_only']) ? true : false;
$leaguePlace = isset($_GET['league_place']) && $_GET['league_place'] !== '' ? (int)$_GET['league_place'] : null;
$wildCardsOnly = isset($_GET['wild_cards_only']) ? true : false;
$params = [];

// Build base query
$query = "
    SELECT 
        l.*,
        COUNT(*) OVER() as total_count
    FROM leaderboard l
    WHERE 1=1
";

$params = [];

if ($searchTerm !== '') {
    $query .= " AND l.username LIKE :search";
    $params[':search'] = "%$searchTerm%";
}

if ($leaguePlace !== null) {
    $query .= " AND l.league_place = :league_place";
    $params[':league_place'] = $leaguePlace;
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

// Add stack filtering
if ($stack1 !== '' || $stack2 !== '') {
    // If stack2 is set but stack1 is empty, swap them for consistent behavior
    if ($stack1 === '' && $stack2 !== '') {
        $stack1 = $stack2;
        $stack2 = '';
    }
    
    $query .= " AND l.draft_entry_id IN (
        SELECT s1.draft_entry_id 
        FROM stacks s1
        " . ($stack2 !== '' ? "JOIN stacks s2 ON s1.draft_entry_id = s2.draft_entry_id" : "") . "
        WHERE s1.team_id = :stack1" . 
        ($stack2 !== '' ? " AND s2.team_id = :stack2" : "") . "
    )";
    $params[':stack1'] = $stack1;
    if ($stack2 !== '') {
        $params[':stack2'] = $stack2;
    }
}

if ($slowsOnly || $fastsOnly) {
    $query .= " AND l.draft_clock = :draft_clock";
    $params[':draft_clock'] = $fastsOnly ? 'fast' : 'slow';
}

if (!empty($slots)) {
    $placeholders = array_map(function($i) { return ':slot' . $i; }, array_keys($slots));
    $query .= " AND l.pick_order IN (" . implode(',', $placeholders) . ")";
    
    foreach ($slots as $i => $slot) {
        $params[':slot' . $i] = $slot;
    }
}

if ($wildCardsOnly) {
    $query .= " AND l.wild_card = 1";
}

$query .= " ORDER BY l.rank ASC";

// Add window functions to get total count and advance info in one query
$finalQuery = "
    WITH filtered_teams AS (
        {$query}
    ),
    team_counts AS (
        SELECT COUNT(DISTINCT ft.draft_entry_id) as total_count,
               COUNT(DISTINCT CASE WHEN ft.advancing = 1 THEN ft.draft_entry_id END) as advancing_count
        FROM filtered_teams ft
    )
    SELECT DISTINCT
        t.draft_entry_id,
        t.username,
        t.rank,
        t.team_score,
        t.pick_order as draft_order,
        t.advancing,
        t.league_place,
        t.draft_filled_time,
        t.wild_card,
        c.total_count,
        c.advancing_count
    FROM (SELECT DISTINCT * FROM filtered_teams) t
    CROSS JOIN team_counts c
    ORDER BY t.rank ASC
    LIMIT 150 OFFSET :offset
";

// Replace the entire cache check block with direct query execution:
$statement = $pdo->prepare($finalQuery);
$params[':offset'] = $offset;
$statement->execute($params);
$teams = $statement->fetchAll(PDO::FETCH_ASSOC);

$totalCount = !empty($teams) ? $teams[0]['total_count'] : 0;
$advancingCount = !empty($teams) ? $teams[0]['advancing_count'] : 0;
$advanceRate = $totalCount > 0 ? round(($advancingCount * 100) / $totalCount, 1) : 0;

// Get wild card cutoff (lowest team score among wild card teams)
$wildCardCutoffQuery = "
    SELECT MIN(team_score) as wild_card_cutoff
    FROM leaderboard
    WHERE wild_card = 1
";
$wildCardStmt = $pdo->query($wildCardCutoffQuery);
$wildCardCutoff = $wildCardStmt->fetchColumn();

$rankedTeams = array_map(function($team) {
    // Convert date string to DateTime object and format it
    $date = DateTime::createFromFormat('Y-m-d H:i:s.u', $team['draft_filled_time']);
    $formattedDate = $date ? $date->format('M d') : $team['draft_filled_time'];
    
    return [
        'rank' => $team['rank'],
        'username' => $team['username'],
        'total_points' => $team['team_score'],
        'draft_entry_id' => $team['draft_entry_id'],
        'is_advancing' => $team['advancing'] === 1,
        'place' => $team['league_place'],
        'draft_date' => $formattedDate,
        'wild_card' => $team['wild_card']
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
    <title>Sweating Dingers | Drafts Leaderboard | Overall Score</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css?v=<?php echo filemtime(__DIR__ . '/css/common.css'); ?>">
    <style>
        /* Search container styles */
        .teams-search-container {
            position: relative;
            width: 200px;
        }
        .teams-search-container .search-results {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .teams-search-container .search-results div {
            padding: 8px 12px;
            cursor: pointer;
        }
        .teams-search-container .search-results div:hover {
            background-color: #f5f5f5;
        }
        
        /* Draft slots grid */
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
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        /* Team highlighting */
        tr.advancing {
            background-color: #fff0b3;  /* Slightly darker gold color */
        }
        tr.first-place {
            background-color: #fff0b3;  /* Light gold */
        }
        tr.second-place {
            background-color: #e8e8e8;  /* Light silver */
        }
        tr.wild-card {
            background-color: #e6ccb3;  /* Bronze color */
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
                                id: item.id
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
            
            // Username search functionality
            const searchInput = document.getElementById('teams-username-search');
            const searchResults = document.getElementById('teams-search-results');
            let debounceTimer;
            
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }
                
                debounceTimer = setTimeout(() => {
                    fetch(`search_users.php?q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResults.innerHTML = '';
                            
                            if (data.length === 0) {
                                searchResults.style.display = 'none';
                                return;
                            }
                            
                            data.forEach(username => {
                                const div = document.createElement('div');
                                div.textContent = username;
                                div.addEventListener('click', function() {
                                    searchInput.value = username;
                                    searchResults.style.display = 'none';
                                });
                                searchResults.appendChild(div);
                            });
                            
                            searchResults.style.display = 'block';
                        })
                        .catch(error => console.error('Error fetching search results:', error));
                }, 300);
            });
            
            // Close search results when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.style.display = 'none';
                }
            });
            
            // Handle form submission
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (this.value.trim()) {
                        this.form.submit();
                    }
                }
            });
            
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

            // Handle "None" selection
            $(document).on('click', '.ui-menu-item:contains("None")', function() {
                const inputId = $(this).closest('.ui-autocomplete').prev().attr('id');
                const hiddenId = inputId.replace('_search', '');
                $(`#${inputId}`).val('None');
                $(`#${hiddenId}`).val('NONE');
                return false;
            });
        });
    </script>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <h1>Dinger Drafts Leaderboard</h1>

    <form method="GET" class="filter-form">
        <!-- First Row -->
        <div class="filter-row">
            <div class="filter-section">
                <label for="teams-username-search">Username:</label>
                <div class="teams-search-container">
                    <input type="text" 
                           id="teams-username-search"
                           name="search" 
                           placeholder="Search by username..."
                           value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    <div id="teams-search-results" class="search-results"></div>
                </div>
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
        </div>

        <!-- Second Row -->
        <div class="filter-row">
            <div class="filter-section">
                <label for="P">Pitchers:</label>
                <input type="number" 
                       id="P"
                       name="P" 
                       min="1"
                       max="18"
                       value="<?php echo isset($_GET['P']) ? htmlspecialchars($_GET['P']) : ''; ?>">
            </div>
            
            <div class="filter-section">
                <label for="IF">Infielders:</label>
                <input type="number" 
                       id="IF"
                       name="IF" 
                       min="1"
                       max="18"
                       value="<?php echo isset($_GET['IF']) ? htmlspecialchars($_GET['IF']) : ''; ?>">
            </div>
            
            <div class="filter-section">
                <label for="OF">Outfielders:</label>
                <input type="number" 
                       id="OF"
                       name="OF" 
                       min="1"
                       max="18"
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

            <div class="filter-section checkbox-group">
                <label>
                    <input type="checkbox" 
                           name="wild_cards_only" 
                           <?php echo isset($_GET['wild_cards_only']) ? 'checked' : ''; ?>>
                    Wild Cards
                </label>
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

        <!-- Third Row -->
        <div class="filter-row">
            <div class="filter-section">
                <label for="league_place">League Place:</label>
                <input type="number" 
                       id="league_place"
                       name="league_place" 
                       min="1" 
                       max="12"
                       value="<?php echo isset($_GET['league_place']) ? htmlspecialchars($_GET['league_place']) : ''; ?>">
            </div>

            <div class="filter-section">
                <label for="stack1">Stack 1:</label>
                <select id="stack1" name="stack1" class="player-search">
                    <option value="">Select team...</option>
                    <?php foreach ($teamOptions as $team): ?>
                        <option value="<?php echo htmlentities($team['team_id'] ?? ''); ?>" 
                                <?php echo $stack1 === $team['team_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlentities($team['team_name'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-section">
                <label for="stack2">Stack 2:</label>
                <select id="stack2" name="stack2" class="player-search">
                    <option value="">Select team...</option>
                    <?php foreach ($teamOptions as $team): ?>
                        <option value="<?php echo htmlentities($team['team_id'] ?? ''); ?>" 
                                <?php echo $stack2 === $team['team_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlentities($team['team_name'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-section buttons">
                <button type="submit">Apply Filters</button>
                <button type="button" onclick="window.location.href='leaderboard_teams.php'">Reset Filters</button>
            </div>
        </div>
    </form>

    <div style="width: 80%; margin: 1rem auto; padding: 0.5rem; background-color: #f2f2f2; border-radius: 4px; text-align: center;">
        <strong>Advance Rate:</strong> <?php echo number_format($advancingCount); ?> of <?php echo number_format($totalCount); ?> teams (<?php echo $advanceRate; ?>%)<br>
        <div style="margin-top: 0.5rem;">
            <strong>Wild Card Cutoff:</strong> <?php echo number_format($wildCardCutoff, 0); ?> points
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Username</th>
                <th>Points</th>
                <th>Draft Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rankedTeams as $teamData): 
            $rowClass = '';
            if ($teamData['place'] === 1) {
                $rowClass = 'first-place';
            } elseif ($teamData['place'] === 2) {
                $rowClass = 'second-place';
            } elseif ($teamData['wild_card'] === 1) {
                $rowClass = 'wild-card';
            }
        ?>
            <tr class="<?php echo $rowClass; ?>">
                <td><?php echo $teamData['rank']; ?></td>
                <td>
                    <a href="user_details.php?username=<?php echo urlencode($teamData['username']); ?>">
                        <?php echo htmlentities($teamData['username']); ?>
                    </a>
                </td>
                <td>
                    <a href="team_details.php?draft_entry_id=<?php echo urlencode($teamData['draft_entry_id']); ?>">
                        <?php echo htmlentities($teamData['total_points']); ?>
                    </a>
                </td>
                <td><?php echo htmlentities($teamData['draft_date']); ?></td>
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
    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html> 