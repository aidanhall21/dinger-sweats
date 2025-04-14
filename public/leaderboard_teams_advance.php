<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Only keep the min_drafts filter
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$minDrafts = isset($_GET['min_drafts']) && $_GET['min_drafts'] !== '' ? (int)$_GET['min_drafts'] : 20; // Default minimum drafts is 20

// Build base query for advance rates
$query = "
    WITH user_stats AS (
        SELECT 
            username,
            COUNT(*) as total_drafts,
            SUM(CASE WHEN league_place = 1 OR league_place = 2 THEN 1 ELSE 0 END) as advanced_count
        FROM leaderboard
        GROUP BY username
        HAVING COUNT(*) >= :min_drafts
    )
    SELECT 
        username,
        total_drafts,
        advanced_count,
        ROUND((advanced_count * 100.0) / total_drafts, 1) as advance_rate,
        COUNT(*) OVER() as total_count
    FROM user_stats
    ORDER BY advance_rate DESC, total_drafts DESC, advanced_count DESC
    LIMIT 150 OFFSET :offset
";

// Execute query
$statement = $pdo->prepare($query);
$statement->bindParam(':min_drafts', $minDrafts, PDO::PARAM_INT);
$statement->bindParam(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$teams = $statement->fetchAll(PDO::FETCH_ASSOC);

$totalCount = !empty($teams) ? $teams[0]['total_count'] : 0;

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
    <title>Sweating Dingers | Drafts Leaderboard | Advance Rate</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/android-chrome-512x512.png">
    <link rel="stylesheet" href="/css/common.css">
    <style>
        /* Only keeping styles specific to this page */
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../src/includes/navigation.php'; ?>
    
    <h1>Dinger Advance Rate Leaderboard</h1>

    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-section">
                <label for="min_drafts">Min Drafts:</label>
                <input type="number" 
                       id="min_drafts"
                       name="min_drafts" 
                       min="1" 
                       value="<?php echo isset($_GET['min_drafts']) ? htmlspecialchars($_GET['min_drafts']) : '20'; ?>">
            </div>
            <div class="filter-section">
                <button type="submit">Apply</button>
                <button type="button" onclick="window.location.href='leaderboard_teams_advance.php'">Reset</button>
            </div>
        </div>
    </form>

    <div style="width: 80%; margin: 1rem auto; padding: 0.5rem; background-color: #f2f2f2; border-radius: 4px; text-align: center;">
        <strong>Showing:</strong> <?php echo $totalCount; ?> users with at least <?php echo $minDrafts; ?> draft<?php echo $minDrafts > 1 ? 's' : ''; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Username</th>
                <th>Advance Rate</th>
                <th>Advanced/Total</th>
            </tr>
        </thead>
        <tbody>
        <?php 
        $rank = 1;
        foreach ($teams as $team): 
        ?>
            <tr>
                <td><?php echo $rank++; ?></td>
                <td>
                    <a href="user_details.php?username=<?php echo urlencode($team['username']); ?>&from_advance=1&min_drafts=<?php echo $minDrafts; ?>">
                        <?php echo htmlentities($team['username']); ?>
                    </a>
                </td>
                <td>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: <?php echo min(100, $team['advance_rate']); ?>%;"></div>
                        <div class="progress-text"><?php echo $team['advance_rate']; ?>%</div>
                    </div>
                </td>
                <td><?php echo $team['advanced_count']; ?>/<?php echo $team['total_drafts']; ?></td>
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