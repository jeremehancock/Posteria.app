<?php
// Authentication section - Check for the 'key' parameter
$apiAccessKey = "medulla"; // Set your desired key here
$isAuthenticated = false;

// Check if key parameter exists and is correct
if (isset($_GET['key']) && $_GET['key'] === $apiAccessKey) {
    $isAuthenticated = true;
}

// If not authenticated, display an error
if (!$isAuthenticated) {
    header('HTTP/1.1 401 Unauthorized');
    echo '<html><head><title>Authentication Required</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:600px;margin:50px auto;padding:20px;border:1px solid #ddd;border-radius:5px;}
    h1{color:#e74c3c;}p{line-height:1.6;}input,button{padding:8px;margin:5px 0;}button{background:#3498db;color:white;border:none;cursor:pointer;border-radius:3px;}</style>';
    echo '</head><body>';
    echo '<h1>Authentication Required</h1>';
    echo '<p>You need to provide a valid API key to access this documentation.</p>';
    echo '<form method="GET">';
    echo '<input type="password" name="key" placeholder="Enter API key">';
    echo '<button type="submit">Access Documentation</button>';
    echo '</form>';
    echo '</body></html>';
    exit;
}

// If we're here, authentication was successful
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Posteria Media Poster API Documentation</title>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --accent-color: #e74c3c;
            --text-color: #333;
            --light-bg: #f8f9fa;
            --code-bg: #f1f1f1;
            --border-color: #ddd;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 20px;
        }
        
        h1 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        h2 {
            margin: 25px 0 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
            color: var(--secondary-color);
        }
        
        h3 {
            margin: 20px 0 10px;
            color: var(--secondary-color);
        }
        
        p {
            margin-bottom: 15px;
        }
        
        code {
            font-family: monospace;
            background-color: var(--code-bg);
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        
        pre {
            background-color: var(--code-bg);
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        
        pre code {
            background-color: transparent;
            padding: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            text-align: left;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background-color: var(--light-bg);
            font-weight: 600;
        }
        
        .example-container {
            margin-bottom: 30px;
        }
        
        .tabs {
            display: flex;
            margin-bottom: 10px;
        }
        
        .tab {
            padding: 8px 15px;
            cursor: pointer;
            background-color: var(--light-bg);
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 5px 5px 0 0;
            margin-right: 5px;
        }
        
        .tab.active {
            background-color: white;
            border-bottom: 1px solid white;
            position: relative;
            z-index: 1;
        }
        
        .tab-content {
            display: none;
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 0 5px 5px 5px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .try-it {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        input, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        
        .response-container {
            margin-top: 20px;
            display: none;
        }
        
        .auth-notice {
            background-color: #e3f2fd;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            border-radius: 0 5px 5px 0;
        }
    </style>
</head>
<body>
    <header>
        <h1>Posteria Media Poster API Documentation</h1>
        <p>A simple API to fetch movie and TV show posters from TMDB (The Movie Database) and Fanart.tv.</p>
    </header>

    <section id="authentication">
        <h2>Authentication</h2>
        <p>Authentication is required to use the API. There are two methods available:</p>

        <h3>1. API Key Authentication</h3>
        <p>Append the <code>key</code> parameter to your requests:</p>
        <pre><code>https://posteria.app/api/fetch/posters?key=medulla&q=inception</code></pre>

        <h3>2. Client Header Authentication</h3>
        <p>Send a <code>X-Client-Info</code> header with your request. The header should contain a base64-encoded JSON object with the following structure:</p>
        <pre><code>{
  "name": "Posteria",
  "ts": 1635000000000  // Current timestamp in milliseconds
}</code></pre>
    </section>

    <section id="parameters">
        <h2>Parameters</h2>
        <table>
            <thead>
                <tr>
                    <th>Parameter</th>
                    <th>Type</th>
                    <th>Required</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>key</code></td>
                    <td>string</td>
                    <td>Conditional</td>
                    <td>API access key. Required if not using header authentication.</td>
                </tr>
                <tr>
                    <td><code>q</code></td>
                    <td>string</td>
                    <td>Yes</td>
                    <td>Search query term (movie, TV show, or collection name).</td>
                </tr>
                <tr>
                    <td><code>type</code></td>
                    <td>string</td>
                    <td>No</td>
                    <td>Type of media to search for. Options: <code>movie</code>, <code>tv</code>, <code>collection</code>, or <code>all</code>. Default: <code>all</code>.</td>
                </tr>
                <tr>
                    <td><code>show_seasons</code></td>
                    <td>boolean</td>
                    <td>No</td>
                    <td>Include season information for TV shows. Values: <code>true</code> or <code>1</code>. Default: <code>false</code>.</td>
                </tr>
                <tr>
                    <td><code>season</code></td>
                    <td>integer</td>
                    <td>No</td>
                    <td>Fetch specific season information for TV shows. Values: season number.</td>
                </tr>
                <tr>
                    <td><code>include_all_posters</code></td>
                    <td>boolean</td>
                    <td>No</td>
                    <td>Include all available posters for the media. Values: <code>true</code>. Default: <code>false</code>.</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section id="examples">
        <h2>Examples</h2>

        <div class="example-container">
            <h3>Basic Movie Search</h3>
            <div class="tabs">
                <div class="tab active" data-tab="movie-request">Request</div>
                <div class="tab" data-tab="movie-response">Response</div>
            </div>
            <div class="tab-content active" id="movie-request">
                <pre><code>GET https://posteria.app/api/fetch/posters?key=medulla&q=inception&type=movie</code></pre>
            </div>
            <div class="tab-content" id="movie-response">
                <pre><code>{
  "success": true,
  "query": "inception",
  "type": "movie",
  "count": 1,
  "results": [
    {
      "id": 27205,
      "type": "movie",
      "title": "Inception",
      "release_date": "2010-07-15",
      "poster": {
        "small": "https://image.tmdb.org/t/p/w185/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg",
        "medium": "https://image.tmdb.org/t/p/w342/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg",
        "large": "https://image.tmdb.org/t/p/w500/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg",
        "original": "https://image.tmdb.org/t/p/original/9gk7adHYeDvHkCSEqAvQNLV5Uge.jpg"
      }
    }
  ]
}</code></pre>
            </div>
        </div>

        <div class="example-container">
            <h3>TV Show with Season Information</h3>
            <div class="tabs">
                <div class="tab active" data-tab="tv-request">Request</div>
                <div class="tab" data-tab="tv-response">Response</div>
            </div>
            <div class="tab-content active" id="tv-request">
                <pre><code>GET https://posteria.app/api/fetch/posters?key=medulla&q=Stranger Things Season 2</code></pre>
            </div>
            <div class="tab-content" id="tv-response">
                <pre><code>{
  "success": true,
  "query": "Stranger Things",
  "type": "tv",
  "requested_season": 2,
  "results": [
    {
      "id": 66732,
      "type": "tv",
      "title": "Stranger Things",
      "season": {
        "season_number": 2,
        "name": "Season 2",
        "episode_count": 9,
        "air_date": "2017-10-27"
      }
    }
  ]
}</code></pre>
            </div>
        </div>
    </section>

    <section id="try-it">
        <h2>Try It</h2>
        <p>Test the API with different parameters:</p>
        
        <div class="form-group">
            <label for="apiUrl">API URL:</label>
            <input type="text" id="apiUrl" placeholder="https://posteria.app/api/fetch/posters" value="https://posteria.app/api/fetch/posters">
        </div>
        
        <div class="form-group">
            <label for="apiKey">API Key:</label>
            <input type="text" id="apiKey" placeholder="medulla" value="medulla">
        </div>
        
        <div class="form-group">
            <label for="searchQuery">Search Query:</label>
            <input type="text" id="searchQuery" placeholder="Enter movie or TV show title">
        </div>
        
        <div class="form-group">
            <label for="mediaType">Media Type:</label>
            <select id="mediaType">
                <option value="all">All</option>
                <option value="movie">Movie</option>
                <option value="tv">TV Show</option>
                <option value="collection">Collection</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="showSeasons">Show Seasons:</label>
            <select id="showSeasons">
                <option value="false">No</option>
                <option value="true">Yes</option>
            </select>
            <small style="display:block;margin-top:5px;color:#666;">Include season information for TV shows</small>
        </div>
        
        <div class="form-group">
            <label for="season">Season Number:</label>
            <input type="number" id="season" placeholder="Season number" min="1">
            <small style="display:block;margin-top:5px;color:#666;">Fetch specific season information for TV shows</small>
        </div>
        
        <div class="form-group">
            <label for="includeAllPosters">Include All Posters:</label>
            <select id="includeAllPosters">
                <option value="false">No</option>
                <option value="true">Yes</option>
            </select>
            <small style="display:block;margin-top:5px;color:#666;">Include all available posters for the media</small>
        </div>
        
        <button class="try-it" id="sendRequest">Send Request</button>
        
        <div class="response-container" id="responseContainer">
            <h3>Response</h3>
            <pre id="responseOutput"></pre>
        </div>
    </section>
    
    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabId = tab.getAttribute('data-tab');
                    const container = tab.closest('.example-container');
                    
                    container.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    container.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    
                    tab.classList.add('active');
                    container.querySelector('#' + tabId).classList.add('active');
                });
            });
            
            // Set current URL as default
            const currentUrl = window.location.href.split('?')[0];
            document.getElementById('apiUrl').value = "https://posteria.app/api/fetch/posters";
            
            // Preserve key parameter in the API URL field
            const urlParams = new URLSearchParams(window.location.search);
            const keyParam = urlParams.get('key');
            if (keyParam) {
                document.getElementById('apiKey').value = keyParam;
            }
        });
        
        // API testing functionality
        document.getElementById('sendRequest').addEventListener('click', async () => {
            const apiUrl = document.getElementById('apiUrl').value.trim();
            const apiKey = document.getElementById('apiKey').value.trim();
            const searchQuery = document.getElementById('searchQuery').value.trim();
            
            if (!apiUrl || !searchQuery) {
                alert('Please enter API URL and search query');
                return;
            }
            
            const mediaType = document.getElementById('mediaType').value;
            const showSeasons = document.getElementById('showSeasons').value;
            const season = document.getElementById('season').value;
            const includeAllPosters = document.getElementById('includeAllPosters').value;
            
            // Build URL
            let url = new URL(apiUrl);
            url.searchParams.append('key', apiKey);
            url.searchParams.append('q', searchQuery);
            
            if (mediaType !== 'all') {
                url.searchParams.append('type', mediaType);
            }
            
            if (showSeasons === 'true') {
                url.searchParams.append('show_seasons', 'true');
            }
            
            if (season) {
                url.searchParams.append('season', season);
            }
            
            if (includeAllPosters === 'true') {
                url.searchParams.append('include_all_posters', 'true');
            }
            
            // Display response container and set loading state
            const responseOutput = document.getElementById('responseOutput');
            responseOutput.textContent = 'Loading...';
            document.getElementById('responseContainer').style.display = 'block';
            
            try {
                // Make request
                const response = await fetch(url.toString());
                const data = await response.json();
                
                // Format and display response
                responseOutput.textContent = JSON.stringify(data, null, 2);
            } catch (error) {
                responseOutput.textContent = `Error: ${error.message}`;
            }
        });
    </script>
</body>
</html>
