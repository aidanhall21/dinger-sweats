<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Ensure we have a username to look up
if (!isset($_GET['username'])) {
    die('No username provided.');
}

// Retrieve and sanitize the username from the URL
$username = trim($_GET['username']);

// Add this near the top of the file, after getting the username
$fromTeamId = isset($_GET['from_team']) ? $_GET['from_team'] : null;
$fromAdvanceLeaderboard = isset($_GET['from_advance']) ? true : false;
$minDrafts = isset($_GET['min_drafts']) ? (int)$_GET['min_drafts'] : null;

// Verify the username exists in the database
$stmtUser = $pdo->prepare("
    SELECT DISTINCT username 
    FROM leaderboard 
    WHERE username = :username
");
$stmtUser->execute([':username' => $username]);
$userExists = $stmtUser->fetchColumn();

if (!$userExists) {
    die('User not found.');
}

// Get total teams and advancing teams count
$stmtTeamStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_teams,
        SUM(advancing) as advancing_teams
    FROM leaderboard
    WHERE username = :username
");
$stmtTeamStats->execute([':username' => $username]);
$teamStats = $stmtTeamStats->fetch(PDO::FETCH_ASSOC);

// Get daily scores for each team
$stmtDailyScores = $pdo->prepare("
    WITH ranked_scores AS (
        SELECT 
            ds.*,
            ROW_NUMBER() OVER (PARTITION BY ds.draft_entry_id ORDER BY ds.date DESC) as rn,
            LAG(ds.team_score) OVER (PARTITION BY ds.draft_entry_id ORDER BY ds.date) as prev_score,
            LAG(ds.advancing) OVER (PARTITION BY ds.draft_entry_id ORDER BY ds.date) as prev_advancing
        FROM daily_scores ds
        WHERE ds.draft_entry_id IN (
            SELECT draft_entry_id 
            FROM leaderboard 
            WHERE username = :username
        )
    )
    SELECT 
        ds.*,
        rs.prev_score,
        rs.prev_advancing
    FROM daily_scores ds
    JOIN ranked_scores rs ON ds.draft_entry_id = rs.draft_entry_id AND ds.date = rs.date
    WHERE rs.rn = 1
");
$stmtDailyScores->execute([':username' => $username]);
$dailyScores = $stmtDailyScores->fetchAll(PDO::FETCH_ASSOC);

// Create a lookup for daily scores by draft_entry_id
$dailyScoresLookup = [];
foreach ($dailyScores as $score) {
    $dailyScoresLookup[$score['draft_entry_id']] = $score;
}

// Update the query to include draft_entry_id
$stmtUserTeams = $pdo->prepare("
    SELECT username, draft_order, points_ahead, league_place, draft_entry_id, wild_card
    FROM leaderboard
    WHERE username = :username
    ORDER BY points_ahead DESC
");
$stmtUserTeams->execute([':username' => $username]);
$userTeams = $stmtUserTeams->fetchAll(PDO::FETCH_ASSOC);

// Calculate points changes and advancement changes
foreach ($userTeams as &$team) {
    $dailyScore = $dailyScoresLookup[$team['draft_entry_id']] ?? null;
    if ($dailyScore) {
        $team['score_change'] = $dailyScore['team_score'] - ($dailyScore['prev_score'] ?? $dailyScore['team_score']);
        
        // Only set advancement_change when there's an actual status change
        if ($dailyScore['prev_advancing'] !== null) {
            // From not advancing to advancing (0 to 1) = positive change
            if ($dailyScore['advancing'] == 1 && $dailyScore['prev_advancing'] == 0) {
                $team['advancement_change'] = 1;  // Up arrow
            }
            // From advancing to not advancing (1 to 0) = negative change
            else if ($dailyScore['advancing'] == 0 && $dailyScore['prev_advancing'] == 1) {
                $team['advancement_change'] = -1;  // Down arrow
            }
            else {
                $team['advancement_change'] = null;  // No change, no arrow
            }
        } else {
            $team['advancement_change'] = null;
        }
    } else {
        $team['score_change'] = 0;
        $team['advancement_change'] = null;
    }
}
unset($team); // Break the reference

// Get player exposures
$stmtExposures = $pdo->prepare("
    WITH user_stats AS (
        SELECT 
            player_id,
            player_name,
            slot_name,
            team_abbr,
            COUNT(*) as drafted_count,
            AVG(advancing) * 100.0 as user_advance_rate
        FROM exposures
        WHERE username = :username
        GROUP BY player_id, player_name, slot_name, team_abbr
    ),
    total_stats AS (
        SELECT 
            player_id,
            AVG(advancing) * 100.0 as total_advance_rate
        FROM exposures
        GROUP BY player_id
    ),
    user_total_teams AS (
        SELECT COUNT(DISTINCT draft_entry_id) as total_teams
        FROM exposures
        WHERE username = :username
    )
    SELECT 
        us.player_name,
        us.slot_name,
        us.team_abbr,
        us.player_id,
        us.drafted_count,
        ROUND((us.drafted_count * 100.0 / ut.total_teams), 1) as exposure_percentage,
        ROUND(us.user_advance_rate, 1) as user_advance_rate,
        ROUND(ts.total_advance_rate, 1) as total_advance_rate
    FROM user_stats us
    JOIN total_stats ts ON us.player_id = ts.player_id
    CROSS JOIN user_total_teams ut
    ORDER BY us.drafted_count DESC, us.player_name
");
$stmtExposures->execute([':username' => $username]);
$exposures = $stmtExposures->fetchAll(PDO::FETCH_ASSOC);

// Get all unique team IDs and abbreviations
$stmtTeams = $pdo->prepare("
    SELECT *
    FROM teams
    ORDER BY team_name
");
$stmtTeams->execute();
$teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

// Get stack data for this user
$stmtStacks = $pdo->prepare("
    SELECT 
        s.*,
        t.team_abbr as team_abbr
    FROM stacks s
    JOIN teams t ON s.team_id = t.team_id
    WHERE s.username = :username
");
$stmtStacks->execute([':username' => $username]);
$stacks = $stmtStacks->fetchAll(PDO::FETCH_ASSOC);

// Create a lookup array for stack data and count pairs
$stackLookup = [];
$teamTotals = [];
$draftPairs = [];

// First pass: collect all team data and count team occurrences
foreach ($stacks as $stack) {
    if (!isset($teamTotals[$stack['team_id']])) {
        $teamTotals[$stack['team_id']] = 0;
    }
    $teamTotals[$stack['team_id']]++;
    
    $stackLookup[$stack['team_id']] = [
        'count' => $stack['stack_count'],
        'total' => $stack['total_teams'],
        'percent' => round(($stack['stack_count'] / $stack['total_teams']) * 100, 1),
        'abbr' => $stack['team_abbr']
    ];
}

// Second pass: count unique pairs per draft
foreach ($stacks as $stack1) {
    foreach ($stacks as $stack2) {
        if ($stack1['draft_entry_id'] === $stack2['draft_entry_id'] && $stack1['team_id'] < $stack2['team_id']) {
            $pairKey = $stack1['team_id'] . '|' . $stack2['team_id'];
            if (!isset($draftPairs[$pairKey])) {
                $draftPairs[$pairKey] = 0;
            }
            $draftPairs[$pairKey]++;
        }
    }
}

// Get total number of teams for percentage calculation
$totalTeams = $teamStats['total_teams'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sweating Dingers | User Information | <?php echo htmlentities($username); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css">
    <style>
        /* Original styles */
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
        .visualization {
            width: 900px;
            margin: 1rem auto;
            padding: 0.5rem;
        }
        
        .bar-container {
            position: relative;
        }
        
        .team-row {
            display: flex;
            align-items: center;
            margin: 0.3rem 0;
            height: 20px;
        }
        
        .team-name {
            width: 200px;
            text-align: right;
            padding-right: 1rem;
            font-size: 0.9em;
        }
        
        .bar-area {
            flex-grow: 1;
            display: flex;
            justify-content: flex-start;
            position: relative;
            margin-left: 150px;
            margin-right: 100px;
        }

        .points-bar {
            position: absolute;
            height: 14px;
            opacity: 0.8;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .points-bar:hover {
            opacity: 1;
        }
        
        .tabs {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .tab {
            padding: 0.5rem 1rem;
            cursor: pointer;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f2f2f2;
        }
        
        .tab.active {
            background: #007bff;
            color: white;
            border-color: #0056b3;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stats-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 1rem;
            margin: 1rem auto;
            width: fit-content;
            text-align: center;
        }
        
        .exposures-table {
            margin: 0 auto;
            border-collapse: collapse;
            width: 80%;
            max-width: 800px;
        }
        
        .exposures-table th,
        .exposures-table td {
            padding: 0.5rem;
            border: 1px solid #dee2e6;
            text-align: left;
        }
        
        .exposures-table th {
            background: #f8f9fa;
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 20px;
        }
        
        .exposures-table th::after {
            content: '↕';
            position: absolute;
            right: 5px;
            opacity: 0.3;
        }
        
        .exposures-table th.asc::after {
            content: '↑';
            opacity: 1;
        }
        
        .exposures-table th.desc::after {
            content: '↓';
            opacity: 1;
        }
        
        .exposures-table th:hover::after {
            opacity: 0.7;
        }
        
        .exposures-table td {
            white-space: nowrap;
        }
        
        .exposures-table td.numeric {
            text-align: right;
            padding-right: 1em;
        }
        
        .position-tab {
            display: inline-block;
            padding: 5px 15px;
            margin: 0 5px;
            cursor: pointer;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .position-tab.active {
            background: #007bff;
            color: white;
            border-color: #0056b3;
        }

        .position-tabs {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin: 1rem 0;
        }

        .exposure-cell {
            position: relative;
        }

        .exposure-cell::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .exposure-cell.above::before {
            background-color: #00ff00;
            opacity: calc(var(--exposure-diff) * 0.8);
        }

        .exposure-cell.below::before {
            background-color: #ff0000;
            opacity: calc(var(--exposure-diff) * 0.8);
        }

        /* Add styles for clickable advance rates */
        .advance-cell a {
            text-decoration: none;
            color: inherit;
            padding: 2px 6px;
            border-radius: 3px;
            transition: all 0.2s ease;
        }

        .advance-cell a:hover {
            background-color: rgba(0, 0, 0, 0.1);
            text-decoration: underline;
        }

        .advance-cell.above a:hover {
            background-color: rgba(0, 255, 0, 0.2);
        }

        .advance-cell.below a:hover {
            background-color: rgba(255, 0, 0, 0.2);
        }

        /* Stacks Grid Styles */
        .stacks-grid-container {
            margin: 2rem auto;
            overflow-x: auto;
            max-width: 100%;
            padding: 0 1rem;
        }

        .stacks-grid {
            border-collapse: collapse;
            width: 100%;
            font-size: 0.8em;
            table-layout: fixed;
        }

        .stacks-grid th,
        .stacks-grid td {
            padding: 0.25rem;
            text-align: center;
            border: 1px solid #ddd;
            min-width: 30px;
            max-width: 30px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stacks-grid th {
            background: #f8f9fa;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 1;
            font-size: 0.9em;
        }

        .stacks-grid th:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            width: 80px;
            min-width: 80px;
        }

        .stack-cell {
            background: #f8f9fa;
            cursor: default;
            padding: 0.25rem;
        }

        .stack-cell.has-stack {
            cursor: pointer;
        }

        .stack-cell.stack-1 { background-color: #e3f2fd; }
        .stack-cell.stack-2 { background-color: #bbdefb; }
        .stack-cell.stack-3 { background-color: #90caf9; }
        .stack-cell.stack-4 { background-color: #64b5f6; }
        .stack-cell.stack-5 { background-color: #42a5f5; }
        .stack-cell.stack-6 { background-color: #2196f3; color: white; }
        .stack-cell.stack-7 { background-color: #1e88e5; color: white; }
        .stack-cell.stack-8 { background-color: #1976d2; color: white; }
        .stack-cell.stack-9 { background-color: #1565c0; color: white; }
        .stack-cell.stack-10 { background-color: #0d47a1; color: white; }

        .stack-info {
            font-weight: bold;
            font-size: 0.9em;
            text-shadow: 0px 0px 3px rgba(255, 255, 255, 0.5);
        }

        .stack-info a {
            color: inherit;
            text-decoration: none;
            display: block;
            width: 100%;
            text-align: center;
            padding: 2px;
            border-radius: 3px;
            transition: background-color 0.2s;
        }

        .stack-info a:hover {
            text-decoration: underline;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .totals-row {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .totals-row th,
        .totals-row td {
            border-top: 2px solid #ddd;
            font-size: 0.9em;
        }

        .wild-card-indicator {
            position: absolute;
            right: 50%;
            transform: translateX(50%);
            color: #007bff;
            font-weight: bold;
            font-size: 0.8em;
            line-height: 14px;
        }

        .score-change {
            margin-left: 10px;
            font-size: 0.85em;
            white-space: nowrap;
        }

        .score-change.positive {
            color: #008800;
        }

        .score-change.negative {
            color: #ff4444;
        }

        .advancement-change {
            display: inline-block;
            font-size: 1.1em;
            font-weight: bold;
            width: 20px;
            text-align: center;
            line-height: 1em;
        }

        .advancement-change.positive {
            color: #008800;
        }

        .advancement-change.negative {
            color: #ff4444;
        }

        /* Indicator styles */
        .indicator-container {
            position: absolute;
            display: flex;
            align-items: center;
            right: -100px;
            top: 0;
            width: 120px;
            height: 100%;
        }

        .arrow-column {
            width: 20px;
            text-align: center;
        }

        .score-column {
            width: 70px;
            text-align: right;
            padding-right: 5px;
        }

        .score-change {
            font-size: 0.85em;
            white-space: nowrap;
        }

        .score-change.positive {
            color: #008800;
        }

        .score-change.negative {
            color: #ff4444;
        }

        .advancement-change {
            display: inline-block;
            font-size: 1.1em;
            font-weight: bold;
            line-height: 1em;
        }

        .advancement-change.positive {
            color: #008800;
        }

        .advancement-change.negative {
            color: #ff4444;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <h1><?php echo htmlentities($username); ?></h1>
    
    <div class="tabs">
        <div class="tab active" data-tab="teams">Teams</div>
        <div class="tab" data-tab="exposures">Exposures</div>
        <div class="tab" data-tab="stacks">Stacks</div>
    </div>
    
    <div id="teams" class="tab-content active">
        <div class="stats-box">
            Advance Rate: <?php echo $teamStats['advancing_teams']; ?>/<?php echo $teamStats['total_teams']; ?>
            (<?php echo round(($teamStats['advancing_teams'] / $teamStats['total_teams']) * 100, 1); ?>%)
        </div>
        
        <div class="visualization">
            <div class="bar-container">
                <div class="tooltip"></div>
                <?php foreach ($userTeams as $team): ?>
                    <div class="team-row">
                        <div class="team-name">
                            <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>&from_user=<?php echo urlencode($username); ?><?php 
                                echo $fromAdvanceLeaderboard ? '&from_advance=1' : ''; 
                                echo $minDrafts ? '&min_drafts=' . urlencode($minDrafts) : ''; 
                            ?>" style="text-decoration: none; color: inherit;">
                                <?php echo htmlentities($team['username']); ?> (<?php echo $team['draft_order']; ?>)
                            </a>
                        </div>
                        
                        <div class="bar-area">
                            <?php
                            $points = $team['points_ahead'];
                            $maxPoints = max(array_map(function($t) { return abs($t['points_ahead']); }, $userTeams));
                            $width = $maxPoints > 0 ? (abs($points) / $maxPoints) * 300 : 0;
                            $tooltipText = number_format(abs($points)) . ' points ' . ($points >= 0 ? 'ahead' : 'behind');
                            
                            if ($points >= 0):
                            ?>
                                <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>&from_user=<?php echo urlencode($username); ?><?php 
                                    echo $fromAdvanceLeaderboard ? '&from_advance=1' : ''; 
                                    echo $minDrafts ? '&min_drafts=' . urlencode($minDrafts) : ''; 
                                ?>" style="text-decoration: none;">
                                    <div class="points-bar" 
                                         style="left: 150px; width: <?php echo $width; ?>px; background: #008800;"
                                         data-tooltip="<?php echo $tooltipText; ?>">
                                    </div>
                                    <?php if ($team['wild_card']): ?>
                                        <div class="wild-card-indicator">WC</div>
                                    <?php endif; ?>
                                    <div class="indicator-container">
                                        <div class="arrow-column">
                                            <?php if (isset($team['advancement_change']) && $team['advancement_change'] !== null): ?>
                                                <div class="advancement-change <?php echo $team['advancement_change'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $team['advancement_change'] > 0 ? '↑' : '↓'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="score-column">
                                            <?php if (isset($team['score_change']) && $team['score_change'] != 0): ?>
                                                <div class="score-change <?php echo $team['score_change'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $team['score_change'] > 0 ? '+' : ''; ?><?php echo number_format($team['score_change']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php else: ?>
                                <a href="team_details.php?draft_entry_id=<?php echo urlencode($team['draft_entry_id']); ?>&from_user=<?php echo urlencode($username); ?><?php 
                                    echo $fromAdvanceLeaderboard ? '&from_advance=1' : ''; 
                                    echo $minDrafts ? '&min_drafts=' . urlencode($minDrafts) : ''; 
                                ?>" style="text-decoration: none;">
                                    <div class="points-bar" 
                                         style="left: <?php echo 150 - $width; ?>px; width: <?php echo $width; ?>px; background: #ff4444;"
                                         data-tooltip="<?php echo $tooltipText; ?>">
                                    </div>
                                    <?php if ($team['wild_card']): ?>
                                        <div class="wild-card-indicator">WC</div>
                                    <?php endif; ?>
                                    <div class="indicator-container">
                                        <div class="arrow-column">
                                            <?php if (isset($team['advancement_change']) && $team['advancement_change'] !== null): ?>
                                                <div class="advancement-change <?php echo $team['advancement_change'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $team['advancement_change'] > 0 ? '↑' : '↓'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="score-column">
                                            <?php if (isset($team['score_change']) && $team['score_change'] != 0): ?>
                                                <div class="score-change <?php echo $team['score_change'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $team['score_change'] > 0 ? '+' : ''; ?><?php echo number_format($team['score_change']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div id="exposures" class="tab-content">
        <div class="position-tabs">
            <span class="position-tab active" data-position="ALL">ALL</span>
            <span class="position-tab" data-position="P">P</span>
            <span class="position-tab" data-position="IF">IF</span>
            <span class="position-tab" data-position="OF">OF</span>
        </div>
        <table class="exposures-table">
            <thead>
                <tr>
                    <th data-sort="text">Player</th>
                    <th data-sort="text">Position</th>
                    <th data-sort="numeric">Teams Drafted</th>
                    <th data-sort="numeric">Exposure %</th>
                    <th data-sort="numeric">Player Advance Rate</th>
                    <th data-sort="numeric">Your Advance Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($exposures as $exposure): ?>
                <tr class="exposure-row" data-position="<?php echo htmlentities($exposure['slot_name']); ?>">
                    <td><a href="player.php?id=<?php echo htmlentities($exposure['player_id']); ?>"><?php echo htmlentities($exposure['player_name']); ?></a></td>
                    <td><?php echo htmlentities($exposure['slot_name']); ?></td>
                    <td class="numeric"><?php echo $exposure['drafted_count']; ?></td>
                    <td class="numeric exposure-cell <?php 
                        $exposureRate = $exposure['exposure_percentage'] / 100;
                        $baseline = 0.0833; // 8.33%
                        $diff = abs($exposureRate - $baseline);
                        echo $exposureRate > $baseline ? 'above' : 'below';
                    ?>" style="--exposure-diff: <?php echo min($diff * 3, 1); ?>">
                        <?php echo round($exposure['exposure_percentage'], 1); ?>%
                    </td>
                    <td class="numeric advance-cell <?php 
                        $advanceRate = $exposure['total_advance_rate'] / 100;
                        $baseline = 0.1667; // 16.67% (2/12)
                        $diff = abs($advanceRate - $baseline);
                        echo $advanceRate > $baseline ? 'above' : 'below';
                    ?>" style="--exposure-diff: <?php echo min($diff * 3, 1); ?>">
                        <a href="leaderboard_teams.php?search=&player1=<?php echo urlencode($exposure['player_id']); ?>&player2=&P=&IF=&OF=&league_place=">
                            <?php echo round($exposure['total_advance_rate'], 1); ?>%
                        </a>
                    </td>
                    <td class="numeric advance-cell <?php 
                        $advanceRate = $exposure['user_advance_rate'] / 100;
                        $baseline = 0.1667; // 16.67% (2/12)
                        $diff = abs($advanceRate - $baseline);
                        echo $advanceRate > $baseline ? 'above' : 'below';
                    ?>" style="--exposure-diff: <?php echo min($diff * 3, 1); ?>">
                        <a href="leaderboard_teams.php?search=<?php echo urlencode($username); ?>&player1=<?php echo urlencode($exposure['player_id']); ?>&player2=&P=&IF=&OF=&league_place=">
                            <?php echo round($exposure['user_advance_rate'], 1); ?>%
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="stacks" class="tab-content">
        <div class="stacks-grid-container">
            <table class="stacks-grid">
                <thead>
                    <tr>
                        <th></th>
                        <?php foreach ($teams as $team): ?>
                            <th><?php echo htmlentities($team['team_abbr']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $team1): ?>
                        <tr>
                            <th><?php echo htmlentities($team1['team_abbr']); ?></th>
                            <?php foreach ($teams as $team2): ?>
                                <td class="stack-cell <?php 
                                    if ($team1['team_id'] === 'SOLO' || $team2['team_id'] === 'SOLO') {
                                        // Remove SOLO handling
                                    } else {
                                        $pairKey = $team1['team_id'] < $team2['team_id'] ? $team1['team_id'] . '|' . $team2['team_id'] : $team2['team_id'] . '|' . $team1['team_id'];
                                        if (isset($draftPairs[$pairKey])) {
                                            echo 'has-stack';
                                            $count = $draftPairs[$pairKey];
                                            // Adjust the shading logic to be more granular
                                            if ($count > 0) {
                                                echo ' stack-' . min($count, 10);
                                            }
                                        }
                                    }
                                ?>">
                                    <?php if ($team1['team_id'] === 'SOLO' || $team2['team_id'] === 'SOLO'): ?>
                                        <?php // Remove SOLO handling ?>
                                    <?php else: ?>
                                        <?php 
                                        $pairKey = $team1['team_id'] < $team2['team_id'] ? $team1['team_id'] . '|' . $team2['team_id'] : $team2['team_id'] . '|' . $team1['team_id'];
                                        if (isset($draftPairs[$pairKey])): 
                                        ?>
                                            <div class="stack-info" title="<?php 
                                                $percent = ($draftPairs[$pairKey] / $totalTeams) * 100;
                                                echo round($percent, 1) . '% of drafts';
                                            ?>">
                                                <a href="leaderboard_teams.php?search=<?php echo urlencode($username); ?>&stack1=<?php echo urlencode($team1['team_id']); ?>&stack2=<?php echo urlencode($team2['team_id']); ?>">
                                                    <?php echo $draftPairs[$pairKey]; ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="totals-row">
                        <th></th>
                        <?php foreach ($teams as $team): ?>
                            <td class="stack-cell <?php 
                                if (isset($teamTotals[$team['team_id']]) && $teamTotals[$team['team_id']] > 0) {
                                    echo 'has-stack';
                                }
                            ?>">
                                <?php 
                                if (isset($teamTotals[$team['team_id']]) && $teamTotals[$team['team_id']] > 0) {
                                    $percent = ($teamTotals[$team['team_id']] / $totalTeams) * 100;
                                    echo '<div class="stack-info" title="' . round($percent, 1) . '% of drafts">';
                                    echo '<a href="leaderboard_teams.php?search=' . urlencode($username) . '&stack1=' . urlencode($team['team_id']) . '">';
                                    echo $teamTotals[$team['team_id']];
                                    echo '</a></div>';
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tooltip = document.querySelector('.tooltip');
        const bars = document.querySelectorAll('.points-bar');

        bars.forEach(bar => {
            bar.addEventListener('mousemove', (e) => {
                const tooltipText = bar.getAttribute('data-tooltip');
                tooltip.textContent = tooltipText;
                tooltip.style.display = 'block';
                
                // Position tooltip 20px above cursor
                const x = e.clientX - (tooltip.offsetWidth / 2);
                const y = e.clientY - tooltip.offsetHeight - 20;
                
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            });

            bar.addEventListener('mouseout', () => {
                tooltip.style.display = 'none';
            });
        });
        
        // Tab switching logic
        const tabs = document.querySelectorAll('.tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
            });
        });

        // Position tab filtering
        const positionTabs = document.querySelectorAll('.position-tab');
        positionTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Update active tab
                positionTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Filter rows
                const position = tab.dataset.position;
                const rows = document.querySelectorAll('.exposure-row');
                rows.forEach(row => {
                    if (position === 'ALL' || row.dataset.position === position) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

        // Add sorting functionality
        const table = document.querySelector('.exposures-table');
        const headers = table.querySelectorAll('th');
        let currentSort = { column: null, direction: 'asc' };
        
        headers.forEach(header => {
            header.addEventListener('click', () => {
                const column = header.cellIndex;
                const sortType = header.dataset.sort;
                
                // Remove sort classes from all headers
                headers.forEach(h => h.classList.remove('asc', 'desc'));
                
                // Determine sort direction
                let direction = 'asc';
                if (currentSort.column === column) {
                    direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                }
                
                // Add sort class to current header
                header.classList.add(direction);
                
                // Sort the table
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                rows.sort((a, b) => {
                    let aVal = a.cells[column].textContent;
                    let bVal = b.cells[column].textContent;
                    
                    if (sortType === 'numeric') {
                        // Remove % symbol and convert to number
                        aVal = parseFloat(aVal.replace('%', ''));
                        bVal = parseFloat(bVal.replace('%', ''));
                    }
                    
                    if (direction === 'asc') {
                        return aVal > bVal ? 1 : -1;
                    } else {
                        return aVal < bVal ? 1 : -1;
                    }
                });
                
                // Reorder the rows
                rows.forEach(row => tbody.appendChild(row));
                
                // Update current sort
                currentSort = { column, direction };
            });
        });

        // Add click handler for stack cells
        document.querySelectorAll('.stack-cell.has-stack').forEach(cell => {
            cell.addEventListener('click', function() {
                const row = this.closest('tr');
                const col = this.cellIndex;
                const team1 = row.querySelector('th').textContent;
                const team2 = this.closest('table').querySelector('thead th:nth-child(' + col + ')').textContent;
                const count = this.querySelector('.stack-info').textContent;
                const title = this.querySelector('.stack-info').title;
                
                // Create and show modal
                const modal = document.createElement('div');
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <h3>Stack Details</h3>
                        <p>${team1} + ${team2}</p>
                        <p>${title}</p>
                        <button onclick="this.closest('.modal').remove()">Close</button>
                    </div>
                `;
                document.body.appendChild(modal);
            });
        });
    });
    </script>
    <?php include_once __DIR__ . '/../src/includes/footer.php'; ?>
</body>
</html> 