<?php
session_start();
require_once 'db_config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$baseDir = getUserBaseDir($userId);
$csrfToken = generateCSRFToken();
$darkMode = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'true';

// Handle AJAX search request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_term'])) {
    header('Content-Type: application/json');
    
    $searchTerm = trim($_POST['search_term'] ?? '');
    
    if (strlen($searchTerm) < 2) {
        echo json_encode(['success' => false, 'message' => 'Search term must be at least 2 characters']);
        exit();
    }
    
    // Search files
    $results = searchFiles($baseDir, $searchTerm);
    
    // Format results
    foreach ($results as &$item) {
        $item['icon'] = $item['is_folder'] ? '📁' : getFileIcon($item['name']);
        $item['size_formatted'] = $item['is_folder'] ? '-' : formatFileSize($item['size']);
        $item['modified_formatted'] = date('M j, Y g:i A', $item['modified']);
        $item['folder'] = dirname($item['path']);
        if ($item['folder'] === '.') $item['folder'] = '';
    }
    
    echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
    exit();
}

$searchTerm = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];

if ($searchTerm && strlen($searchTerm) >= 2) {
    $results = searchFiles($baseDir, $searchTerm);
    foreach ($results as &$item) {
        $item['icon'] = $item['is_folder'] ? '📁' : getFileIcon($item['name']);
        $item['size_formatted'] = $item['is_folder'] ? '-' : formatFileSize($item['size']);
        $item['modified_formatted'] = date('M j, Y g:i A', $item['modified']);
        $item['folder'] = dirname($item['path']);
        if ($item['folder'] === '.') $item['folder'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $darkMode ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Files - File Manager</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --bg-primary: #f5f5f5;
            --bg-secondary: #ffffff;
            --text-primary: #333;
            --text-secondary: #666;
            --border-color: #e0e0e0;
            --accent-color: #667eea;
            --hover-color: #f0f0f0;
        }
        .dark {
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --text-primary: #eee;
            --text-secondary: #aaa;
            --border-color: #0f3460;
            --hover-color: #1f2b4d;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        .back-btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }
        .back-btn:hover { opacity: 0.9; }
        h1 {
            margin: 0;
            font-size: 24px;
        }
        .search-box {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .search-form {
            display: flex;
            gap: 10px;
        }
        .search-form input[type="text"] {
            flex: 1;
            padding: 15px 20px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 16px;
        }
        .search-form input[type="text"]:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        .search-form button {
            padding: 15px 30px;
            background: var(--accent-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
        }
        .search-form button:hover { opacity: 0.9; }
        .search-tips {
            margin-top: 15px;
            color: var(--text-secondary);
            font-size: 13px;
        }
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .results-count {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .results-list {
            background: var(--bg-secondary);
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .result-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background 0.2s;
        }
        .result-item:hover {
            background: var(--hover-color);
        }
        .result-item:last-child {
            border-bottom: none;
        }
        .result-icon {
            font-size: 24px;
            margin-right: 15px;
            width: 35px;
            text-align: center;
        }
        .result-info {
            flex: 1;
        }
        .result-name {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .result-name mark {
            background: #ffeb3b;
            padding: 0 2px;
            border-radius: 2px;
        }
        .dark .result-name mark {
            background: #795548;
            color: #fff;
        }
        .result-path {
            font-size: 12px;
            color: var(--text-secondary);
        }
        .result-meta {
            text-align: right;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .result-size {
            font-weight: 500;
            margin-bottom: 4px;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .no-results .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        .keyboard-hint {
            background: var(--bg-secondary);
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .keyboard-hint kbd {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            padding: 2px 8px;
            border-radius: 4px;
            font-family: monospace;
            margin: 0 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-btn">← Back to Files</a>
            <h1>🔍 Search Files</h1>
        </div>
        
        <div class="keyboard-hint">
            <strong>Tip:</strong> Press <kbd>Ctrl</kbd> + <kbd>F</kbd> in the dashboard to quickly open search
        </div>
        
        <div class="search-box">
            <form class="search-form" method="GET" action="" id="searchForm">
                <input type="text" name="q" id="searchInput" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       placeholder="Search for files and folders..." autofocus autocomplete="off">
                <button type="submit">Search</button>
            </form>
            <div class="search-tips">
                Search by file name, folder name, or extension. Minimum 2 characters.
            </div>
        </div>
        
        <div id="resultsContainer">
            <?php if ($searchTerm): ?>
                <div class="results-header">
                    <h2>Search Results</h2>
                    <span class="results-count"><?php echo count($results); ?> result(s) for "<?php echo htmlspecialchars($searchTerm); ?>"</span>
                </div>
                
                <?php if (empty($results)): ?>
                    <div class="no-results">
                        <div class="icon">📭</div>
                        <h3>No results found</h3>
                        <p>Try a different search term or check your spelling</p>
                    </div>
                <?php else: ?>
                    <div class="results-list">
                        <?php foreach ($results as $item): ?>
                            <div class="result-item" onclick="openResult('<?php echo addslashes($item['path']); ?>', <?php echo $item['is_folder'] ? 'true' : 'false'; ?>)">
                                <div class="result-icon"><?php echo $item['icon']; ?></div>
                                <div class="result-info">
                                    <div class="result-name"><?php echo highlightSearch(htmlspecialchars($item['name']), $searchTerm); ?></div>
                                    <div class="result-path">📁 <?php echo htmlspecialchars($item['folder'] ?: 'Root'); ?></div>
                                </div>
                                <div class="result-meta">
                                    <div class="result-size"><?php echo $item['size_formatted']; ?></div>
                                    <div class="result-date"><?php echo $item['modified_formatted']; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-results">
                    <div class="icon">🔎</div>
                    <h3>Search your files</h3>
                    <p>Enter a search term above to find files and folders</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Live search with debouncing
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const term = this.value.trim();
            
            if (term.length < 2) {
                return;
            }
            
            searchTimeout = setTimeout(() => {
                performSearch(term);
            }, 300);
        });
        
        async function performSearch(term) {
            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.innerHTML = '<div class="loading">Searching...</div>';
            
            try {
                const formData = new FormData();
                formData.append('search_term', term);
                
                const response = await fetch('search.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayResults(data.results, term);
                } else {
                    resultsContainer.innerHTML = `<div class="no-results"><div class="icon">⚠️</div><p>${data.message}</p></div>`;
                }
            } catch (error) {
                resultsContainer.innerHTML = '<div class="no-results"><div class="icon">❌</div><p>Search failed. Please try again.</p></div>';
            }
        }
        
        function displayResults(results, term) {
            const container = document.getElementById('resultsContainer');
            
            let html = `
                <div class="results-header">
                    <h2>Search Results</h2>
                    <span class="results-count">${results.length} result(s) for "${escapeHtml(term)}"</span>
                </div>
            `;
            
            if (results.length === 0) {
                html += `
                    <div class="no-results">
                        <div class="icon">📭</div>
                        <h3>No results found</h3>
                        <p>Try a different search term or check your spelling</p>
                    </div>
                `;
            } else {
                html += '<div class="results-list">';
                results.forEach(item => {
                    html += `
                        <div class="result-item" onclick="openResult('${escapeHtml(item.path)}', ${item.is_folder})">
                            <div class="result-icon">${item.icon}</div>
                            <div class="result-info">
                                <div class="result-name">${highlightSearch(escapeHtml(item.name), term)}</div>
                                <div class="result-path">📁 ${escapeHtml(item.folder || 'Root')}</div>
                            </div>
                            <div class="result-meta">
                                <div class="result-size">${item.size_formatted}</div>
                                <div class="result-date">${item.modified_formatted}</div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }
            
            container.innerHTML = html;
        }
        
        function openResult(path, isFolder) {
            if (isFolder) {
                window.location.href = 'dashboard.php?path=' + encodeURIComponent(path);
            } else {
                // Open folder containing the file
                const folder = path.substring(0, path.lastIndexOf('/')) || '';
                window.location.href = 'dashboard.php?path=' + encodeURIComponent(folder);
            }
        }
        
        function highlightSearch(text, term) {
            const regex = new RegExp('(' + escapeRegex(term) + ')', 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function escapeRegex(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
    </script>
</body>
</html>

<?php
function highlightSearch($text, $term) {
    return preg_replace('/(' . preg_quote($term, '/') . ')/i', '<mark>$1</mark>', $text);
}
?>
