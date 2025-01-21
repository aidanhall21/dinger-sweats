<?php
require_once __DIR__ . '/../src/db.php';

$pdo = getDbConnection();

// Handle search
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];

// Build query
$query = "
    SELECT 
        username,
        rank,
        cumulative_points
    FROM leaderboard
";

if ($searchTerm !== '') {
    $query .= " WHERE username LIKE :search";
    $params[':search'] = "%$searchTerm%";
}

$query .= "
    ORDER BY rank ASC
    LIMIT 150
";

// Execute query
$statement = $pdo->prepare($query);
$statement->execute($params);
$teams = $statement->fetchAll(PDO::FETCH_ASSOC);

// No need for ranking logic since it's now in the database
$rankedTeams = array_map(function($team) {
    return [
        'rank' => $team['rank'],
        'username' => $team['username'],
        'total_points' => $team['cumulative_points']
    ];
}, $teams);
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
    </style>
</head>
<body>
    <a href="/" class="home-link">← Back to Home</a>
    <h1>Drafted Teams Leaderboard</h1>

    <form method="GET" class="search-form">
        <input type="text" 
               name="search" 
               placeholder="Search by team name..."
               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
        <button type="submit">Search</button>
        <button type="button" onclick="window.location.href='leaderboard_teams.php'">Reset</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Rank</th>
                <th>Username</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rankedTeams as $teamData): ?>
            <tr>
                <td><?php echo htmlentities($teamData['rank']); ?></td>
                <td><?php echo htmlentities($teamData['username']); ?></td>
                <td><?php echo htmlentities($teamData['total_points']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html> 