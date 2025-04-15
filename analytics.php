<?php
// analytics.php
// Enhanced PHP analytics tracker that stores each visit as a separate file.
// This version tracks time, language, visited URL, referrer, and user agent,
// aggregates data by a selectable period (day, week, month, year, overall),
// fills in missing time buckets for a smooth line chart,
// displays grouped logs with clickable filters using a Bootstrap-based UI,
// and computes recurring visitors (those with more than one visit) in both the overall
// totals and per time bucket so they can be displayed in the chart.
// Crawlers (e.g., Googlebot, Bingbot, etc.) are tracked but filtered out from the stats view.

// Ensure the data folder exists.
$dataFolder = 'analytics_data';
if (!is_dir($dataFolder)) {
    mkdir($dataFolder, 0777, true);
}

// ==============================================
// Helper functions
// ==============================================

// Convert the HTTP_ACCEPT_LANGUAGE header into a human‑readable language.
function getReadableLanguage($langHeader) {
    $parts = explode(',', $langHeader);
    $code = strtolower(substr(trim($parts[0]), 0, 2));
    $languages = array(
        'en' => 'English',
        'fr' => 'French',
        'de' => 'German',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean'
    );
    return isset($languages[$code]) ? $languages[$code] : ucfirst($code);
}

// Parse a simple browser name from the user agent.
function getBrowserName($userAgent) {
    $ua = strtolower($userAgent);
    if (strpos($ua, 'chrome') !== false && strpos($ua, 'edge') === false && strpos($ua, 'opr') === false) {
        return 'Chrome';
    } elseif (strpos($ua, 'firefox') !== false) {
        return 'Firefox';
    } elseif (strpos($ua, 'safari') !== false && strpos($ua, 'chrome') === false) {
        return 'Safari';
    } elseif (strpos($ua, 'edge') !== false) {
        return 'Edge';
    } elseif (strpos($ua, 'opr') !== false || strpos($ua, 'opera') !== false) {
        return 'Opera';
    } elseif (strpos($ua, 'msie') !== false || strpos($ua, 'trident') !== false) {
        return 'Internet Explorer';
    }
    return 'Other';
}

// Check if the user agent indicates a known crawler.
function isCrawler($userAgent) {
    $bots = array(
        'googlebot',
        'bingbot',
        'slurp',         // Yahoo!
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'sogou'
    );
    foreach ($bots as $bot) {
        if (stripos($userAgent, $bot) !== false) {
            return true;
        }
    }
    return false;
}

// ==============================================
// STATS VIEW
// ==============================================
if (isset($_GET['stats']) && $_GET['stats'] == '1') {

    // Load all individual visit files.
    $files = glob($dataFolder . "/*.json");
    $rawEntries = array();
    foreach ($files as $file) {
        $entry = json_decode(file_get_contents($file), true);
        if (is_array($entry)) {
            $rawEntries[] = $entry;
        }
    }
    
    // ----------------------------------------------------
    // Filter out crawler entries (track them but don't show them)
    // ----------------------------------------------------
    $rawEntries = array_filter($rawEntries, function($entry) {
        return !isCrawler($entry['user_agent']);
    });
    $rawEntries = array_values($rawEntries);
    
    // ----------------------------------------------------
    // Determine the period to view.
    // Allowed values: day, week, month, year, overall (default = overall)
    // "day": data from today (group by hour)
    // "week": past 7 days (group by day)
    // "month": past 30 days (group by day)
    // "year": past 365 days (group by month)
    // "overall": all data (group by year)
    // ----------------------------------------------------
    $period = isset($_GET['period']) ? $_GET['period'] : 'overall';
    $now = time();
    
    switch ($period) {
        case 'day':
            $startTime = strtotime("today midnight");
            $groupFormat = "H:00"; // group by hour (e.g., "14:00")
            break;
        case 'week':
            $startTime = $now - (7 * 24 * 3600);
            $groupFormat = "Y-m-d"; // group by day
            break;
        case 'month':
            $startTime = $now - (30 * 24 * 3600);
            $groupFormat = "Y-m-d"; // group by day
            break;
        case 'year':
            $startTime = $now - (365 * 24 * 3600);
            $groupFormat = "Y-m"; // group by month
            break;
        case 'overall':
        default:
            $startTime = 0;
            $groupFormat = "Y"; // group by year
            break;
    }
    
    // ----------------------------------------------------
    // Filter entries by the selected period.
    // ----------------------------------------------------
    $allEntries = array_filter($rawEntries, function($entry) use ($startTime) {
        return $entry['time'] >= $startTime;
    });
    $allEntries = array_values($allEntries);

    // ----------------------------------------------------
    // Check for additional filtering by a grouped value.
    // Allowed filters: filter_by = language | url | browser, filter_value = value.
    // ----------------------------------------------------
    $filterBy = isset($_GET['filter_by']) ? $_GET['filter_by'] : null;
    $filterValue = isset($_GET['filter_value']) ? $_GET['filter_value'] : null;
    
    if ($filterBy && $filterValue) {
        $allEntries = array_filter($allEntries, function($entry) use ($filterBy, $filterValue) {
            if ($filterBy === 'language') {
                return getReadableLanguage($entry['language']) === $filterValue;
            } elseif ($filterBy === 'browser') {
                return getBrowserName($entry['user_agent']) === $filterValue;
            } elseif ($filterBy === 'url') {
                return $entry['url'] === $filterValue;
            }
            return true;
        });
        $allEntries = array_values($allEntries);
    }
    
    // ----------------------------------------------------
    // Aggregate statistics based on (possibly filtered) entries.
    // ----------------------------------------------------
    $totalVisits = count($allEntries);
    $overallUniqueIPs = array();
    $aggregated = array();       // e.g., [ '2025-04-15' => count, ... ]
    $aggregatedUnique = array(); // e.g., [ '2025-04-15' => array(ip=>true,...), ... ]
    
    foreach ($allEntries as $entry) {
        $dateKey = date($groupFormat, $entry['time']);
        if (!isset($aggregated[$dateKey])) {
            $aggregated[$dateKey] = 0;
            $aggregatedUnique[$dateKey] = array();
        }
        $aggregated[$dateKey]++;
        $aggregatedUnique[$dateKey][$entry['ip']] = true;
        $overallUniqueIPs[$entry['ip']] = true;
    }
    
    $aggregatedUniqueCounts = array();
    foreach ($aggregatedUnique as $key => $ips) {
        $aggregatedUniqueCounts[$key] = count($ips);
    }
    ksort($aggregated);
    ksort($aggregatedUniqueCounts);
    $overallUniqueCount = count($overallUniqueIPs);
    
    // ----------------------------------------------------
    // Compute overall recurring visitors for the period.
    // A recurring visitor is an IP that appears more than once in the filtered period.
    // ----------------------------------------------------
    $ipCounts = array();
    foreach ($allEntries as $entry) {
        $ip = $entry['ip'];
        if (!isset($ipCounts[$ip])) {
            $ipCounts[$ip] = 0;
        }
        $ipCounts[$ip]++;
    }
    $recurringVisitorsCount = 0;
    foreach ($ipCounts as $count) {
        if ($count > 1) {
            $recurringVisitorsCount++;
        }
    }
    
    // ----------------------------------------------------
    // Generate a complete timeline (empty buckets for missing data)
    // ----------------------------------------------------
    $completeLabels = array();
    switch ($period) {
        case 'day':
            // 24 hours: "00:00" to "23:00"
            for ($h = 0; $h < 24; $h++){
                $completeLabels[] = sprintf("%02d:00", $h);
            }
            break;
        case 'week':
            // Last 7 days (including today)
            $startDate = new DateTime();
            $startDate->modify('-6 days');
            for ($i = 0; $i < 7; $i++){
                $completeLabels[] = $startDate->format("Y-m-d");
                $startDate->modify('+1 day');
            }
            break;
        case 'month':
            // Last 30 days (including today)
            $startDate = new DateTime();
            $startDate->modify('-29 days');
            for ($i = 0; $i < 30; $i++){
                $completeLabels[] = $startDate->format("Y-m-d");
                $startDate->modify('+1 day');
            }
            break;
        case 'year':
            // Last 12 months (including current month)
            $currentMonth = new DateTime();
            $startMonth = clone $currentMonth;
            $startMonth->modify('-11 months');
            for ($i = 0; $i < 12; $i++){
                $completeLabels[] = $startMonth->format("Y-m");
                $startMonth->modify('+1 month');
            }
            break;
        case 'overall':
        default:
            // Group by year: from the earliest record to current year.
            if (count($rawEntries) > 0) {
                $years = array_map(function($entry) {
                    return date("Y", $entry['time']);
                }, $rawEntries);
                $minYear = min($years);
                $maxYear = date("Y");
                for ($year = $minYear; $year <= $maxYear; $year++) {
                    $completeLabels[] = strval($year);
                }
            } else {
                $completeLabels[] = date("Y");
            }
            break;
    }
    
    // Create arrays with complete data (fill with 0 when no data exists)
    $visitsComplete = array();
    $uniqueComplete = array();
    foreach ($completeLabels as $label) {
        $visitsComplete[] = isset($aggregated[$label]) ? $aggregated[$label] : 0;
        $uniqueComplete[] = isset($aggregatedUniqueCounts[$label]) ? $aggregatedUniqueCounts[$label] : 0;
    }
    
    // ----------------------------------------------------
    // Compute recurring visitors per time bucket.
    // For each bucket, count those IPs that appear more than once within that bucket.
    // ----------------------------------------------------
    $recurringComplete = array();
    foreach ($completeLabels as $label) {
        $bucketIPs = array();
        foreach ($allEntries as $entry) {
            if(date($groupFormat, $entry['time']) == $label) {
                $ip = $entry['ip'];
                if (!isset($bucketIPs[$ip])) {
                    $bucketIPs[$ip] = 0;
                }
                $bucketIPs[$ip]++;
            }
        }
        $bucketRecurring = 0;
        foreach ($bucketIPs as $count) {
            if ($count > 1) {
                $bucketRecurring++;
            }
        }
        $recurringComplete[] = $bucketRecurring;
    }
    
    // ----------------------------------------------------
    // Group logs for display (by language, URL, and browser)
    // ----------------------------------------------------
    $groupByLanguage = array();
    $groupByUrl = array();
    $groupByBrowser = array();
    foreach ($allEntries as $entry) {
        $readableLang = getReadableLanguage($entry['language']);
        if (!isset($groupByLanguage[$readableLang])) {
            $groupByLanguage[$readableLang] = 0;
        }
        $groupByLanguage[$readableLang]++;
        
        $urlVal = $entry['url'];
        if (!isset($groupByUrl[$urlVal])) {
            $groupByUrl[$urlVal] = 0;
        }
        $groupByUrl[$urlVal]++;
        
        $browser = getBrowserName($entry['user_agent']);
        if (!isset($groupByBrowser[$browser])) {
            $groupByBrowser[$browser] = 0;
        }
        $groupByBrowser[$browser]++;
    }
    ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Analytics Statistics</title>
    <!-- Include Bootstrap CSS and Chart.js -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" crossorigin="anonymous" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { padding-top: 20px; background-color: #f8f9fa; }
        .container { max-width: 1200px; }
        .card { margin-bottom: 20px; }
        .chart-container { position: relative; height:400px; }
        .btn-group a { text-decoration: none; }
        .table-responsive { max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="container">
    <h1 class="mb-4">Analytics Statistics</h1>
    
    <!-- Period Switcher -->
    <div class="card p-3 mb-4">
        <h5>Select Period:</h5>
        <?php
            // Preserve current filter parameters when switching period.
            $extra = "";
            if ($filterBy && $filterValue) {
                $extra .= "&filter_by=" . urlencode($filterBy) . "&filter_value=" . urlencode($filterValue);
            }
        ?>
        <div class="btn-group" role="group">
            <a href="analytics.php?stats=1&period=day<?php echo $extra; ?>" class="btn btn-outline-primary <?php echo ($period=='day' ? 'active' : ''); ?>">Today</a>
            <a href="analytics.php?stats=1&period=week<?php echo $extra; ?>" class="btn btn-outline-primary <?php echo ($period=='week' ? 'active' : ''); ?>">Past Week</a>
            <a href="analytics.php?stats=1&period=month<?php echo $extra; ?>" class="btn btn-outline-primary <?php echo ($period=='month' ? 'active' : ''); ?>">Past Month</a>
            <a href="analytics.php?stats=1&period=year<?php echo $extra; ?>" class="btn btn-outline-primary <?php echo ($period=='year' ? 'active' : ''); ?>">Past Year</a>
            <a href="analytics.php?stats=1&period=overall<?php echo $extra; ?>" class="btn btn-outline-primary <?php echo ($period=='overall' ? 'active' : ''); ?>">Overall</a>
        </div>
    </div>
    
    <!-- Filter Info -->
    <?php if ($filterBy && $filterValue): ?>
    <div class="alert alert-info">
        <strong>Filter applied:</strong>
        <?php echo htmlspecialchars(ucfirst($filterBy)); ?> = <?php echo htmlspecialchars($filterValue); ?> 
        (<a href="analytics.php?stats=1&period=<?php echo urlencode($period); ?>">Clear filter</a>)
    </div>
    <?php endif; ?>
    
    <!-- Overall Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Total Visits</h5>
                    <p class="card-text display-6"><?php echo $totalVisits; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Unique Visitors</h5>
                    <p class="card-text display-6"><?php echo $overallUniqueCount; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Recurring Visitors</h5>
                    <p class="card-text display-6"><?php echo $recurringVisitorsCount; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Chart Card -->
    <div class="card mb-4">
        <div class="card-header">Data (Grouped by <?php echo ($period === 'day' ? 'Hour' : ($period === 'year' ? 'Month' : ($period === 'overall' ? 'Year' : 'Day'))); ?>)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="analyticsChart"></canvas>
            </div>
        </div>
    </div>
    <script>
        const labels = <?php echo json_encode($completeLabels); ?>;
        const visitsData = <?php echo json_encode($visitsComplete); ?>;
        const uniqueData = <?php echo json_encode($uniqueComplete); ?>;
        const recurringData = <?php echo json_encode($recurringComplete); ?>;
        
        const data = {
            labels: labels,
            datasets: [
                {
                    label: 'Visits',
                    data: visitsData,
                    borderColor: 'rgba(54, 162, 235, 1)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    fill: false,
                    tension: 0.1
                },
                {
                    label: 'Unique Visitors',
                    data: uniqueData,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    fill: false,
                    tension: 0.1
                },
                {
                    label: 'Recurring Visitors',
                    data: recurringData,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: false,
                    tension: 0.1
                }
            ]
        };
        
        const config = {
            type: 'line',
            data: data,
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        precision: 0
                    }
                }
            }
        };
        
        new Chart(document.getElementById('analyticsChart'), config);
    </script>
    
    <!-- Grouped Logs -->
    <div class="row">
        <!-- Visits by Language -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header">Visits by Language</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Language</th>
                                    <th>Visits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByLanguage as $lang => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?php echo urlencode($period); ?>&filter_by=language&filter_value=<?php echo urlencode($lang); ?>">
                                            <?php echo htmlspecialchars($lang); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $count; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Visits by URL -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header">Visits by URL</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>URL</th>
                                    <th>Visits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByUrl as $url => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?php echo urlencode($period); ?>&filter_by=url&filter_value=<?php echo urlencode($url); ?>">
                                            <?php echo htmlspecialchars($url); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $count; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Visits by Browser -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header">Visits by Browser</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Browser</th>
                                    <th>Visits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByBrowser as $browser => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?php echo urlencode($period); ?>&filter_by=browser&filter_value=<?php echo urlencode($browser); ?>">
                                            <?php echo htmlspecialchars($browser); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $count; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit();
}

// ==============================================
// TRACKING MODE (Log each visit as a separate file)
// ==============================================
$time = time();
$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
$url = $_SERVER['REQUEST_URI'];
$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'Direct';
$language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : 'unknown';

$entry = array(
    'time'       => $time,
    'ip'         => $ip,
    'user_agent' => $userAgent,
    'url'        => $url,
    'referrer'   => $referrer,
    'language'   => $language
);

// Create a unique filename for this visit.
$filename = $dataFolder . "/" . date("Y-m-d_H-i-s_") . uniqid() . ".json";
file_put_contents($filename, json_encode($entry, JSON_PRETTY_PRINT));
?>
