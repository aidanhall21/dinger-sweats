<nav>
    <?php if (basename($_SERVER['PHP_SELF']) !== 'index.php'): ?>
    <div class="dropdown">
        <a href="/" class="nav-button">Home</a>
    </div>
    <span class="nav-separator">|</span>
    <?php endif; ?>
    <div class="dropdown">
        <a href="#" class="nav-button">Player Leaderboards</a>
        <div class="dropdown-content">
            <a href="leaderboard_players.php">Total Scores</a>
            <a href="leaderboard_players_weekly.php">Weekly Scores</a>
        </div>
    </div>
    <span class="nav-separator">|</span>
    <div class="dropdown">
        <a href="#" class="nav-button">Drafts Leaderboard</a>
        <div class="dropdown-content">
            <a href="leaderboard_teams.php">Overall Score</a>
            <a href="leaderboard_teams_advance.php">Advance Rate</a>
        </div>
    </div>
    <span class="nav-separator">|</span>
    <div class="search-container">
        <form id="search-form" action="user_details.php" method="get">
            <input type="text" id="username-search" class="search-input" placeholder="Username search..." autocomplete="off">
            <input type="hidden" id="username-hidden" name="username">
        </form>
        <div id="search-results" class="search-results"></div>
    </div>
    <span class="nav-separator">|</span>
    <div class="search-container">
        <form id="player-search-form" action="player.php" method="get">
            <input type="text" id="player-search" class="search-input" placeholder="Player search..." autocomplete="off">
            <input type="hidden" id="player-id-hidden" name="id">
        </form>
        <div id="player-search-results" class="search-results"></div>
    </div>
    <?php if (basename($_SERVER['PHP_SELF']) !== 'support.php'): ?>
    <span class="nav-separator">|</span>
    <a href="support.php" class="nav-button">Contact & Support</a>
    <?php endif; ?>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    /* Temporarily disabled username search functionality
    const searchInput = document.getElementById('username-search');
    const searchResults = document.getElementById('search-results');
    const hiddenInput = document.getElementById('username-hidden');
    const searchForm = document.getElementById('search-form');
    
    let debounceTimer;
    let touchStartY;
    
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
                            hiddenInput.value = username;
                            searchResults.style.display = 'none';
                            searchForm.submit();
                        });
                        searchResults.appendChild(div);
                    });
                    
                    searchResults.style.display = 'block';
                })
                .catch(error => console.error('Error fetching search results:', error));
        }, 300);
    });
    
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (searchInput.value.trim()) {
                hiddenInput.value = searchInput.value.trim();
                searchForm.submit();
            }
        }
    });
    
    // Handle touch events for mobile scrolling in dropdown
    searchResults.addEventListener('touchstart', function(e) {
        touchStartY = e.touches[0].clientY;
    }, { passive: true });
    
    searchResults.addEventListener('touchmove', function(e) {
        if (!touchStartY) return;
        
        const touchY = e.touches[0].clientY;
        const scrollTop = searchResults.scrollTop;
        const scrollHeight = searchResults.scrollHeight;
        const height = searchResults.clientHeight;
        
        // If at the top and trying to scroll down, or at the bottom and trying to scroll up, allow default behavior
        if ((scrollTop === 0 && touchY > touchStartY) || 
            (scrollTop + height >= scrollHeight && touchY < touchStartY)) {
            e.stopPropagation();
        }
    }, { passive: true });
    
    // Close search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
    
    // Add focus and blur handlers for mobile
    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) {
            searchResults.style.display = 'block';
        }
    });
    */
    
    // Player search functionality
    const playerSearchInput = document.getElementById('player-search');
    const playerSearchResults = document.getElementById('player-search-results');
    const playerIdHidden = document.getElementById('player-id-hidden');
    const playerSearchForm = document.getElementById('player-search-form');
    
    let playerDebounceTimer;
    let playerTouchStartY;
    
    playerSearchInput.addEventListener('input', function() {
        clearTimeout(playerDebounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            playerSearchResults.style.display = 'none';
            return;
        }
        
        playerDebounceTimer = setTimeout(() => {
            fetch(`search_players.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    playerSearchResults.innerHTML = '';
                    
                    if (data.length === 0) {
                        playerSearchResults.style.display = 'none';
                        return;
                    }
                    
                    data.forEach(player => {
                        const div = document.createElement('div');
                        div.textContent = player.picks_player_name;
                        div.dataset.playerId = player.picks_player_id;
                        div.addEventListener('click', function() {
                            playerSearchInput.value = player.picks_player_name;
                            playerIdHidden.value = player.picks_player_id;
                            playerSearchResults.style.display = 'none';
                            playerSearchForm.submit();
                        });
                        playerSearchResults.appendChild(div);
                    });
                    
                    playerSearchResults.style.display = 'block';
                })
                .catch(error => console.error('Error fetching player search results:', error));
        }, 300);
    });
    
    playerSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (playerSearchInput.value.trim()) {
                // If there are results, submit the first one
                const firstResult = playerSearchResults.firstChild;
                if (firstResult) {
                    playerIdHidden.value = firstResult.dataset.playerId;
                    playerSearchForm.submit();
                }
            }
        }
    });
    
    // Handle touch events for mobile scrolling in dropdown
    playerSearchResults.addEventListener('touchstart', function(e) {
        playerTouchStartY = e.touches[0].clientY;
    }, { passive: true });
    
    playerSearchResults.addEventListener('touchmove', function(e) {
        if (!playerTouchStartY) return;
        
        const touchY = e.touches[0].clientY;
        const scrollTop = playerSearchResults.scrollTop;
        const scrollHeight = playerSearchResults.scrollHeight;
        const height = playerSearchResults.clientHeight;
        
        // If at the top and trying to scroll down, or at the bottom and trying to scroll up, allow default behavior
        if ((scrollTop === 0 && touchY > playerTouchStartY) || 
            (scrollTop + height >= scrollHeight && touchY < playerTouchStartY)) {
            e.stopPropagation();
        }
    }, { passive: true });
    
    // Close player search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!playerSearchInput.contains(e.target) && !playerSearchResults.contains(e.target)) {
            playerSearchResults.style.display = 'none';
        }
    });
    
    // Add focus and blur handlers for mobile
    playerSearchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) {
            playerSearchResults.style.display = 'block';
        }
    });
});
</script> 