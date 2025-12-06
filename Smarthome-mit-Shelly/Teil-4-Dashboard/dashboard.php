<?php
/**
 * Shelly Dashboard - Lokale Visualisierung
 * Teil 5 der Shelly Smart Home Serie
 * https://diytechadventures.de
 */
// Fehler-Logging aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/dasboard_errors.log');
date_default_timezone_set('Europe/Berlin');

// Datenbank-Konfiguration
define('DB_HOST', '192.168.2.190');  // ANPASSEN!
define('DB_USER', 'shelly_user');
define('DB_PASS', 'Ostsee.012');  // ANPASSEN!
define('DB_NAME', 'shelly_data');

// Datenbankverbindung
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// ============================================
// Hilfsfunktionen
// ============================================

/**
 * Holt den letzten Datensatz eines Geräts aus einer Tabelle
 */
function getLatestData($pdo, $table, $deviceId) {
    $stmt = $pdo->prepare(
        "SELECT * FROM {$table} 
         WHERE device_id = ? 
         ORDER BY timestamp DESC 
         LIMIT 1"
    );
    $stmt->execute([$deviceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Holt Zeitreihendaten für Diagramme
 */
function getTimeSeriesData($pdo, $table, $deviceId, $column, $hours = 24) {
    $stmt = $pdo->prepare(
        "SELECT {$column} as value, timestamp 
         FROM {$table} 
         WHERE device_id = ? 
           AND timestamp > NOW() - INTERVAL ? HOUR
         ORDER BY timestamp ASC"
    );
    $stmt->execute([$deviceId, $hours]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Holt die letzten Switch-Events
 */
function getLastSwitchEvents($pdo, $deviceId, $limit = 10) {
    $limit = (int) $limit;
    $stmt = $pdo->prepare(
        "SELECT switch_state, event_type, timestamp 
         FROM switch_events 
         WHERE device_id = ? 
         ORDER BY timestamp DESC 
         LIMIT {$limit}"
    );
    $stmt->execute([$deviceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Holt alle bekannten Geräte-IDs aus einer Tabelle
 */
function getDeviceIds($pdo, $table) {
    $stmt = $pdo->query(
        "SELECT DISTINCT device_id, device_name 
         FROM {$table} 
         ORDER BY device_name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// Geräte-IDs ermitteln (BITTE ANPASSEN!)
// ============================================

// Diese IDs findest du in deiner Datenbank
// SELECT DISTINCT device_id, device_name FROM sensor_data;
// SELECT DISTINCT device_id, device_name FROM power_data;
// SELECT DISTINCT device_id, device_name FROM switch_events;

$DEVICE_BLU_HT = '7c:c6:b6:97:33:ac';           // MAC-Adresse des BLU H&T
$DEVICE_WIFI_HT = 'shellyhtg3-wz';    // ID des H&T WiFi
$DEVICE_1PM_MINI = 'shelly1pmminig3-d0cf13cb5dd8'; // ID des 1PM Mini

// ============================================
// Daten abrufen
// ============================================

// Sensor-Daten (H&T BLU und H&T WiFi)
$bluHT = getLatestData($pdo, 'sensor_data', $DEVICE_BLU_HT);
$wifiHT = getLatestData($pdo, 'sensor_data', $DEVICE_WIFI_HT);

// Power-Daten (1PM Mini)
$powerData = getLatestData($pdo, 'power_data', $DEVICE_1PM_MINI);

// Switch-Events (1PM Mini)
$switchEvents = getLastSwitchEvents($pdo, $DEVICE_1PM_MINI, 10);
$lastSwitch = $switchEvents[0] ?? null;

// Zeitreihen für Diagramme (24 Stunden)
$tempBluData = getTimeSeriesData($pdo, 'sensor_data', $DEVICE_BLU_HT, 'temperature', 24);
$tempWifiData = getTimeSeriesData($pdo, 'sensor_data', $DEVICE_WIFI_HT, 'temperature', 24);
$powerChartData = getTimeSeriesData($pdo, 'power_data', $DEVICE_1PM_MINI, 'power', 24);

// Für JSON-Übergabe an JavaScript
$chartData = [
    'tempBlu' => $tempBluData,
    'tempWifi' => $tempWifiData,
    'power' => $powerChartData
];

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shelly Dashboard</title>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-primary: #0f1419;
            --bg-secondary: #1a1f26;
            --bg-card: #232b35;
            --bg-card-hover: #2a3441;
            --text-primary: #e7e9ea;
            --text-secondary: #8b98a5;
            --text-muted: #5c6975;
            --accent-blue: #1d9bf0;
            --accent-green: #00ba7c;
            --accent-orange: #ff7a00;
            --accent-red: #f4212e;
            --accent-purple: #7856ff;
            --border-color: #2f3943;
            --shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Sans', -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
        }

        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header */
        .header {
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header h1::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 28px;
            background: linear-gradient(180deg, var(--accent-blue), var(--accent-purple));
            border-radius: 2px;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .last-update {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        /* Grid für Kacheln */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }

        /* Kacheln */
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .card:hover {
            background: var(--bg-card-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .card-title {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .card-icon.temp { background: rgba(29, 155, 240, 0.15); }
        .card-icon.power { background: rgba(255, 122, 0, 0.15); }
        .card-icon.switch { background: rgba(0, 186, 124, 0.15); }

        /* Werte in Kacheln */
        .value-row {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .value-main {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .value-unit {
            font-size: 1rem;
            color: var(--text-secondary);
        }

        .value-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .stats-row {
            display: flex;
            gap: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            margin-top: 1rem;
        }

        .stat-item {
            flex: 1;
        }

        .stat-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Status-Anzeige für Switch */
        .switch-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .switch-status.on {
            background: rgba(0, 186, 124, 0.15);
            color: var(--accent-green);
        }

        .switch-status.off {
            background: rgba(139, 152, 165, 0.15);
            color: var(--text-secondary);
        }

        .switch-status::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        /* Event-Liste */
        .event-list {
            margin-top: 1rem;
            max-height: 180px;
            overflow-y: auto;
        }

        .event-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
        }

        .event-item:last-child {
            border-bottom: none;
        }

        .event-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .event-dot.on { background: var(--accent-green); }
        .event-dot.off { background: var(--text-muted); }

        .event-time {
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .event-type {
            color: var(--text-secondary);
            margin-left: auto;
        }

        /* Diagramm-Bereich */
        .charts-section {
            margin-top: 2rem;
        }

        .chart-container {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        .chart-wrapper {
            position: relative;
            height: 250px;
        }

        /* Batterie-Anzeige */
        .battery-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .battery-bar {
            flex: 1;
            height: 6px;
            background: var(--bg-secondary);
            border-radius: 3px;
            overflow: hidden;
        }

        .battery-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .battery-fill.high { background: var(--accent-green); }
        .battery-fill.medium { background: var(--accent-orange); }
        .battery-fill.low { background: var(--accent-red); }

        .battery-text {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            color: var(--text-secondary);
            min-width: 35px;
        }

        /* Keine Daten */
        .no-data {
            color: var(--text-muted);
            font-style: italic;
            padding: 1rem 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard {
                padding: 1rem;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .value-main {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header -->
        <header class="header">
            <h1>Shelly Dashboard</h1>
            <p>Lokale Visualisierung deiner Smart Home Sensoren</p>
            <div class="last-update">
                Letzte Aktualisierung: <?= date('d.m.Y H:i:s') ?>
            </div>
        </header>

        <!-- Kacheln -->
        <div class="cards-grid">
            
            <!-- Kachel 1: H&T BLU -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Shelly H&T BLU</span>
                    <div class="card-icon temp">🌡️</div>
                </div>
                
                <?php if ($bluHT): ?>
                    <div class="value-row">
                        <span class="value-main"><?= number_format($bluHT['temperature'], 1, ',', '') ?></span>
                        <span class="value-unit">°C</span>
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-value">💧 <?= number_format($bluHT['humidity'], 0) ?>%</div>
                            <div class="stat-label">Luftfeuchtigkeit</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Batterie</div>
                            <?php 
                            $battery = $bluHT['battery'] ?? 0;
                            $batteryClass = $battery > 50 ? 'high' : ($battery > 20 ? 'medium' : 'low');
                            ?>
                            <div class="battery-indicator">
                                <div class="battery-bar">
                                    <div class="battery-fill <?= $batteryClass ?>" style="width: <?= $battery ?>%"></div>
                                </div>
                                <span class="battery-text"><?= $battery ?>%</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">Keine Daten verfügbar</div>
                <?php endif; ?>
            </div>

            <!-- Kachel 2: H&T WiFi -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Shelly H&T WiFi</span>
                    <div class="card-icon temp">🌡️</div>
                </div>
                
                <?php if ($wifiHT): ?>
                    <div class="value-row">
                        <span class="value-main"><?= number_format($wifiHT['temperature'], 1, ',', '') ?></span>
                        <span class="value-unit">°C</span>
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-value">💧 <?= number_format($wifiHT['humidity'], 0) ?>%</div>
                            <div class="stat-label">Luftfeuchtigkeit</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-label">Batterie</div>
                            <?php 
                            $battery = $wifiHT['battery'] ?? 0;
                            $batteryClass = $battery > 50 ? 'high' : ($battery > 20 ? 'medium' : 'low');
                            ?>
                            <div class="battery-indicator">
                                <div class="battery-bar">
                                    <div class="battery-fill <?= $batteryClass ?>" style="width: <?= $battery ?>%"></div>
                                </div>
                                <span class="battery-text"><?= $battery ?>%</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">Keine Daten verfügbar</div>
                <?php endif; ?>
            </div>

            <!-- Kachel 3: 1PM Mini Power -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Shelly 1PM Mini – Leistung</span>
                    <div class="card-icon power">⚡</div>
                </div>
                
                <?php if ($powerData): ?>
                    <div class="value-row">
                        <span class="value-main"><?= number_format($powerData['power'], 1, ',', '') ?></span>
                        <span class="value-unit">Watt</span>
                    </div>
                    
                    <div class="stats-row">
                        <div class="stat-item">
                            <div class="stat-value">🔌 <?= number_format($powerData['voltage'], 0) ?> V</div>
                            <div class="stat-label">Spannung</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">📊 <?php if ($powerData['energy_total'] < 1000): ?><?= number_format($powerData['energy_total'], 1, ',', '') ?> Wh<?php else: ?><?= number_format($powerData['energy_total'] / 1000, 2, ',', '') ?> kWh<?php endif; ?></div>
                            <div class="stat-label">Gesamt</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">Keine Daten verfügbar</div>
                <?php endif; ?>
            </div>

            <!-- Kachel 4: 1PM Mini Switch -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Shelly 1PM Mini – Schalter</span>
                    <div class="card-icon switch">💡</div>
                </div>
                
                <?php if ($lastSwitch): ?>
                    <div class="switch-status <?= $lastSwitch['switch_state'] ? 'on' : 'off' ?>">
                        <?= $lastSwitch['switch_state'] ? 'Eingeschaltet' : 'Ausgeschaltet' ?>
                    </div>
                    
                    <div class="event-list">
                        <?php foreach ($switchEvents as $event): ?>
                            <div class="event-item">
                                <span class="event-dot <?= $event['switch_state'] ? 'on' : 'off' ?>"></span>
                                <span><?= $event['switch_state'] ? 'Ein' : 'Aus' ?></span>
                                <span class="event-type"><?= htmlspecialchars($event['event_type']) ?></span>
                                <span class="event-time"><?= date('d.m. H:i', strtotime($event['timestamp'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">Keine Events verfügbar</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Diagramme -->
        <section class="charts-section">
            
            <!-- Temperatur-Diagramm -->
            <div class="chart-container">
                <h3 class="chart-title">📈 Temperaturverlauf (24 Stunden)</h3>
                <div class="chart-wrapper">
                    <canvas id="tempChart"></canvas>
                </div>
            </div>

            <!-- Leistungs-Diagramm -->
            <div class="chart-container">
                <h3 class="chart-title">⚡ Leistungsverlauf (24 Stunden)</h3>
                <div class="chart-wrapper">
                    <canvas id="powerChart"></canvas>
                </div>
            </div>

        </section>
    </div>

    <script>
        // Daten aus PHP
        const chartData = <?= json_encode($chartData) ?>;

        // Chart.js Grundkonfiguration
        Chart.defaults.color = '#8b98a5';
        Chart.defaults.borderColor = '#2f3943';
        Chart.defaults.font.family = "'IBM Plex Sans', sans-serif";

        // Hilfsfunktion: Daten für Chart.js formatieren
        function formatChartData(data) {
            return data.map(item => ({
                x: new Date(item.timestamp),
                y: parseFloat(item.value)
            }));
        }

        // Temperatur-Diagramm
        const tempCtx = document.getElementById('tempChart').getContext('2d');
        new Chart(tempCtx, {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'H&T BLU',
                        data: formatChartData(chartData.tempBlu),
                        borderColor: '#1d9bf0',
                        backgroundColor: 'rgba(29, 155, 240, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'H&T WiFi',
                        data: formatChartData(chartData.tempWifi),
                        borderColor: '#7856ff',
                        backgroundColor: 'rgba(120, 86, 255, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'hour',
                            displayFormats: {
                                hour: 'HH:mm'
                            }
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return value.toFixed(1) + ' °C';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: '#232b35',
                        titleColor: '#e7e9ea',
                        bodyColor: '#8b98a5',
                        borderColor: '#2f3943',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' °C';
                            }
                        }
                    }
                }
            }
        });

        // Leistungs-Diagramm
        const powerCtx = document.getElementById('powerChart').getContext('2d');
        new Chart(powerCtx, {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'Leistung',
                        data: formatChartData(chartData.power),
                        borderColor: '#ff7a00',
                        backgroundColor: 'rgba(255, 122, 0, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'hour',
                            displayFormats: {
                                hour: 'HH:mm'
                            }
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value + ' W';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#232b35',
                        titleColor: '#e7e9ea',
                        bodyColor: '#8b98a5',
                        borderColor: '#2f3943',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Leistung: ' + context.parsed.y.toFixed(1) + ' W';
                            }
                        }
                    }
                }
            }
        });

        // Automatische Aktualisierung alle 5 Minuten
        setTimeout(function() {
            location.reload();
        }, 5 * 60 * 1000);
    </script>
</body>
</html>

