<?php
declare(strict_types=1);

/**
 * Analytics class encapsulates the tracking and statistics logic using SQLite.
 */
class Analytics
{
    private const DATA_FOLDER = 'analytics_data';
    private const DB_FILE = self::DATA_FOLDER . '/analytics.sqlite';
    private static ?\PDO $pdo = null;

    /**
     * Main entry point.
     */
    public static function run(): void {
        if (isset($_GET['stats']) && $_GET['stats'] === '1') {
            self::runStats();
        } else {
            self::trackVisit();
        }
    }

    /**
     * Initializes the SQLite database connection and ensures the table exists.
     *
     * @return \PDO
     */
    private static function initDb(): \PDO {
        if (self::$pdo === null) {
            // Ensure data folder exists.
            if (!is_dir(self::DATA_FOLDER)) {
                mkdir(self::DATA_FOLDER, 0777, true);
            }
            self::$pdo = new \PDO('sqlite:' . self::DB_FILE);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $createTableSQL = "CREATE TABLE IF NOT EXISTS visits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                time INTEGER NOT NULL,
                ip TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                url TEXT NOT NULL,
                referrer TEXT NOT NULL,
                language TEXT NOT NULL
            )";
            self::$pdo->exec($createTableSQL);
        }
        return self::$pdo;
    }

    /**
     * Tracks the current visit by writing it to the SQLite database.
     */
    private static function trackVisit(): void {
        $pdo = self::initDb();
        $stmt = $pdo->prepare("INSERT INTO visits (time, ip, user_agent, url, referrer, language) VALUES (:time, :ip, :user_agent, :url, :referrer, :language)");
        $stmt->execute([
            ':time'       => time(),
            ':ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            ':url'        => $_SERVER['REQUEST_URI'] ?? '',
            ':referrer'   => $_SERVER['HTTP_REFERER'] ?? 'Direct',
            ':language'   => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown'
        ]);
    }

    /**
     * Loads all visit entries from the SQLite database.
     *
     * @return array<int, array{time: int, ip: string, user_agent: string, url: string, referrer: string, language: string}>
     */
    private static function loadEntries(): array {
        $pdo = self::initDb();
        $stmt = $pdo->query("SELECT time, ip, user_agent, url, referrer, language FROM visits");
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($results) ? $results : [];
    }

    /**
     * Runs the statistics view.
     */
    private static function runStats(): void {
        $entries = self::loadEntries();
        // Filter out crawler entries.
        $entries = array_filter($entries, fn(array $entry): bool => !self::isCrawler((string)$entry['user_agent']));
        $entries = array_values($entries);

        // Determine period.
        $period = $_GET['period'] ?? 'overall';
        $now = time();
        switch ($period) {
            case 'day':
                $startTime = strtotime("today midnight");
                $groupFormat = "H:00"; // group by hour
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
        // Filter entries by period.
        $entries = array_filter($entries, fn(array $entry): bool => (int)$entry['time'] >= $startTime);
        $entries = array_values($entries);

        // Apply additional filtering.
        $filterBy = $_GET['filter_by'] ?? null;
        $filterValue = $_GET['filter_value'] ?? null;
        if (is_string($filterBy) && is_string($filterValue)) {
            $entries = array_filter($entries, function (array $entry) use ($filterBy, $filterValue): bool {
                if ($filterBy === 'language') {
                    return self::getReadableLanguage((string)$entry['language']) === $filterValue;
                } elseif ($filterBy === 'browser') {
                    return self::getBrowserName((string)$entry['user_agent']) === $filterValue;
                } elseif ($filterBy === 'url') {
                    return ((string)$entry['url']) === $filterValue;
                }
                return true;
            });
            $entries = array_values($entries);
        }

        // Aggregate statistics.
        $totalVisits = count($entries);
        $overallUniqueIPs = [];
        $aggregated = [];       // e.g. [ '2025-04-15' => count, ... ]
        $aggregatedUnique = []; // e.g. [ '2025-04-15' => [ip => true, ...], ... ]
        foreach ($entries as $entry) {
            $dateKey = date($groupFormat, (int)$entry['time']);
            if (!isset($aggregated[$dateKey])) {
                $aggregated[$dateKey] = 0;
                $aggregatedUnique[$dateKey] = [];
            }
            $aggregated[$dateKey]++;
            $ip = (string)$entry['ip'];
            $aggregatedUnique[$dateKey][$ip] = true;
            $overallUniqueIPs[$ip] = true;
        }
        $aggregatedUniqueCounts = [];
        foreach ($aggregatedUnique as $key => $ips) {
            $aggregatedUniqueCounts[$key] = count($ips);
        }
        ksort($aggregated);
        ksort($aggregatedUniqueCounts);
        $overallUniqueCount = count($overallUniqueIPs);

        // Compute overall recurring visitors.
        $ipCounts = [];
        foreach ($entries as $entry) {
            $ip = (string)$entry['ip'];
            if (!isset($ipCounts[$ip])) {
                $ipCounts[$ip] = 0;
            }
            $ipCounts[$ip]++;
        }
        $recurringVisitorsCount = 0;
        foreach ($ipCounts as $cnt) {
            if ($cnt > 1) {
                $recurringVisitorsCount++;
            }
        }

        // Generate complete timeline labels.
        $completeLabels = [];
        switch ($period) {
            case 'day':
                for ($h = 0; $h < 24; $h++) {
                    $completeLabels[] = sprintf("%02d:00", $h);
                }
                break;
            case 'week':
                $startDate = new \DateTime();
                $startDate->modify('-6 days');
                for ($i = 0; $i < 7; $i++) {
                    $completeLabels[] = $startDate->format("Y-m-d");
                    $startDate->modify('+1 day');
                }
                break;
            case 'month':
                $startDate = new \DateTime();
                $startDate->modify('-29 days');
                for ($i = 0; $i < 30; $i++) {
                    $completeLabels[] = $startDate->format("Y-m-d");
                    $startDate->modify('+1 day');
                }
                break;
            case 'year':
                $currentMonth = new \DateTime();
                $startMonth = clone $currentMonth;
                $startMonth->modify('-11 months');
                for ($i = 0; $i < 12; $i++) {
                    $completeLabels[] = $startMonth->format("Y-m");
                    $startMonth->modify('+1 month');
                }
                break;
            case 'overall':
            default:
                if (count($entries) > 0) {
                    $years = array_map(fn(array $entry): string => date("Y", (int)$entry['time']), $entries);
                    $minYear = min($years);
                    $maxYear = date("Y");
                    for ($year = (int)$minYear; $year <= (int)$maxYear; $year++) {
                        $completeLabels[] = (string)$year;
                    }
                } else {
                    $completeLabels[] = date("Y");
                }
                break;
        }

        // Create complete data series.
        $visitsComplete = [];
        $uniqueComplete = [];
        foreach ($completeLabels as $label) {
            $visitsComplete[] = $aggregated[$label] ?? 0;
            $uniqueComplete[] = $aggregatedUniqueCounts[$label] ?? 0;
        }

        // Compute recurring visitors per time bucket.
        $recurringComplete = [];
        foreach ($completeLabels as $label) {
            $bucketIPs = [];
            foreach ($entries as $entry) {
                if (date($groupFormat, (int)$entry['time']) === $label) {
                    $ip = (string)$entry['ip'];
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

        // Group logs for display.
        $groupByLanguage = [];
        $groupByUrl = [];
        $groupByBrowser = [];
        foreach ($entries as $entry) {
            $lang = self::getReadableLanguage((string)$entry['language']);
            if (!isset($groupByLanguage[$lang])) {
                $groupByLanguage[$lang] = 0;
            }
            $groupByLanguage[$lang]++;
            $urlVal = (string)$entry['url'];
            if (!isset($groupByUrl[$urlVal])) {
                $groupByUrl[$urlVal] = 0;
            }
            $groupByUrl[$urlVal]++;
            $browser = self::getBrowserName((string)$entry['user_agent']);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" crossorigin="anonymous" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { padding-top: 20px; background-color: #f8f9fa; }
        .container { max-width: 1200px; }
        .card { margin-bottom: 20px; }
        .chart-container { position: relative; height: 400px; }
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
            $extra = '';
            if (is_string($filterBy) && is_string($filterValue)) {
                $extra .= "&filter_by=" . urlencode($filterBy) . "&filter_value=" . urlencode($filterValue);
            }
        ?>
        <div class="btn-group" role="group">
            <a href="analytics.php?stats=1&period=day<?= $extra ?>" class="btn btn-outline-primary <?= ($period === 'day' ? 'active' : '') ?>">Today</a>
            <a href="analytics.php?stats=1&period=week<?= $extra ?>" class="btn btn-outline-primary <?= ($period === 'week' ? 'active' : '') ?>">Past Week</a>
            <a href="analytics.php?stats=1&period=month<?= $extra ?>" class="btn btn-outline-primary <?= ($period === 'month' ? 'active' : '') ?>">Past Month</a>
            <a href="analytics.php?stats=1&period=year<?= $extra ?>" class="btn btn-outline-primary <?= ($period === 'year' ? 'active' : '') ?>">Past Year</a>
            <a href="analytics.php?stats=1&period=overall<?= $extra ?>" class="btn btn-outline-primary <?= ($period === 'overall' ? 'active' : '') ?>">Overall</a>
        </div>
    </div>

    <!-- Filter Info -->
    <?php if (is_string($filterBy) && is_string($filterValue)): ?>
    <div class="alert alert-info">
        <strong>Filter applied:</strong> <?= htmlspecialchars(ucfirst($filterBy)) ?> = <?= htmlspecialchars($filterValue) ?>
        (<a href="analytics.php?stats=1&period=<?= urlencode($period) ?>">Clear filter</a>)
    </div>
    <?php endif; ?>

    <!-- Overall Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Total Visits</h5>
                    <p class="card-text display-6"><?= $totalVisits ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Unique Visitors</h5>
                    <p class="card-text display-6"><?= $overallUniqueCount ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Recurring Visitors</h5>
                    <p class="card-text display-6"><?= $recurringVisitorsCount ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart Card -->
    <div class="card mb-4">
        <div class="card-header">Data (Grouped by <?= ($period === 'day' ? 'Hour' : ($period === 'year' ? 'Month' : ($period === 'overall' ? 'Year' : 'Day'))) ?>)</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="analyticsChart"></canvas>
            </div>
        </div>
    </div>
    <script>
        const labels = <?= json_encode($completeLabels) ?>;
        const visitsData = <?= json_encode($visitsComplete) ?>;
        const uniqueData = <?= json_encode($uniqueComplete) ?>;
        const recurringData = <?= json_encode($recurringComplete) ?>;

        const chartData = {
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
            data: chartData,
            options: {
                maintainAspectRatio: false,
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
                                <tr><th>Language</th><th>Visits</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByLanguage as $lang => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?= urlencode($period) ?>&filter_by=language&filter_value=<?= urlencode($lang) ?>">
                                            <?= htmlspecialchars($lang) ?>
                                        </a>
                                    </td>
                                    <td><?= $count ?></td>
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
                                <tr><th>URL</th><th>Visits</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByUrl as $url => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?= urlencode($period) ?>&filter_by=url&filter_value=<?= urlencode($url) ?>">
                                            <?= htmlspecialchars($url) ?>
                                        </a>
                                    </td>
                                    <td><?= $count ?></td>
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
                                <tr><th>Browser</th><th>Visits</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupByBrowser as $browser => $count): ?>
                                <tr>
                                    <td>
                                        <a href="analytics.php?stats=1&period=<?= urlencode($period) ?>&filter_by=browser&filter_value=<?= urlencode($browser) ?>">
                                            <?= htmlspecialchars($browser) ?>
                                        </a>
                                    </td>
                                    <td><?= $count ?></td>
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

    /**
     * Converts an HTTP_ACCEPT_LANGUAGE string into a human‑readable language.
     *
     * @param string $langHeader
     * @return string
     */
    public static function getReadableLanguage(string $langHeader): string {
        $parts = explode(',', $langHeader);
        $code = strtolower(substr(trim($parts[0]), 0, 2));
        $languages = [
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
        ];
        return $languages[$code] ?? ucfirst($code);
    }

    /**
     * Returns a simple browser name based on the user agent.
     *
     * @param string $userAgent
     * @return string
     */
    public static function getBrowserName(string $userAgent): string {
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

    /**
     * Determines whether the given user agent represents a crawler.
     *
     * @param string $userAgent
     * @return bool
     */
    public static function isCrawler(string $userAgent): bool {
        $bots = [
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'ahrefs'
        ];
        foreach ($bots as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Execute the analytics logic.
Analytics::run();
?>
