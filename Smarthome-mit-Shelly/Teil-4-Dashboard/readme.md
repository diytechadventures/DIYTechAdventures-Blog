*Zum vollständigen Beitrag bei diytechadventures.de: [Shelly Smart Home Serie](https://diytechadventures.de/projekte)*

# Shelly Dashboard – Sensordaten visualisieren

*Teil 5: Lokale Visualisierung mit PHP und Chart.js*

In den bisherigen Teilen dieser Serie hast du gelernt, wie du Shelly-Geräte einrichtest, Skripte erstellst und alle Sensordaten in einer MySQL-Datenbank speicherst. Jetzt wird es Zeit, diese Rohdaten in aussagekräftige Visualisierungen zu verwandeln.

Nach diesem Artikel hast du ein **eigenes Dashboard**, das dir auf einen Blick zeigt:
- Aktuelle Temperatur und Luftfeuchtigkeit deiner Sensoren
- Leistungsdaten und Energieverbrauch
- Schaltereignisse mit Verlaufshistorie
- Interaktive Diagramme für Zeitverläufe

---

## 1. Voraussetzungen

Bevor du loslegst, stelle sicher, dass folgendes läuft:

- **Webserver mit PHP** (Apache oder nginx)
- **MySQL/MariaDB-Datenbank** mit den Tabellen aus Teil 3
- **Daten in der Datenbank** – mindestens ein paar Testeinträge

Falls du die vorherigen Teile noch nicht durchgearbeitet hast:
- [Teil 3: Shelly Datenbank-Integration](https://diytechadventures.de/shelly-datenbank-integration/)

---

## 2. Das Dashboard im Überblick

Unser Dashboard besteht aus einer einzigen PHP-Datei, die:
1. Daten aus der MySQL-Datenbank abruft
2. Aktuelle Werte in übersichtlichen Kacheln darstellt
3. Zeitverläufe als interaktive Diagramme mit Chart.js anzeigt

### Architektur

```
[MySQL-Datenbank]
       ↓ SQL-Abfragen
[dashboard.php]
       ↓ PHP verarbeitet Daten
       ↓ HTML/CSS für Layout
       ↓ Chart.js für Diagramme
[Browser] ← Responsive Darstellung
```

### Was wird angezeigt?

| Kachel | Gerät | Daten |
|--------|-------|-------|
| 1 | Shelly H&T BLU | Temperatur, Feuchtigkeit, Batterie |
| 2 | Shelly H&T WiFi | Temperatur, Feuchtigkeit, Batterie |
| 3 | Shelly 1PM Mini | Leistung (Watt), Spannung, Energieverbrauch |
| 4 | Shelly 1PM Mini | Schaltstatus, Event-Historie |

Dazu kommen zwei Diagramme: Temperaturverlauf (24h) und Leistungsverlauf (24h).

---

## 3. Die dashboard.php erstellen

Erstelle die Datei `/var/www/html/dashboard.php` mit folgendem Inhalt. Ich erkläre die wichtigsten Abschnitte im Anschluss.

### 3.1 Datenbank-Konfiguration und Verbindung

```php
<?php
date_default_timezone_set('Europe/Berlin');

// Datenbank-Konfiguration
define('DB_HOST', '192.168.2.xxx');  // Deine DB-IP
define('DB_USER', 'shelly_user');
define('DB_PASS', 'dein_passwort');  // ANPASSEN!
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
```

Die PDO-Verbindung mit `ERRMODE_EXCEPTION` sorgt dafür, dass Fehler sauber angezeigt werden – hilfreich beim Debugging.

### 3.2 Hilfsfunktionen für Datenbankabfragen

```php
/**
 * Holt den letzten Datensatz eines Geräts
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
```

**💡 Prepared Statements:** Alle Abfragen nutzen Prepared Statements mit Platzhaltern (`?`). Das schützt vor SQL-Injection und ist Best Practice für sichere Datenbankzugriffe.

### 3.3 Geräte-IDs konfigurieren

Hier trägst du die IDs deiner Shelly-Geräte ein:

```php
// Diese IDs findest du in deiner Datenbank:
// SELECT DISTINCT device_id, device_name FROM sensor_data;

$DEVICE_BLU_HT = '7c:c6:b6:97:33:ac';              // MAC-Adresse des BLU H&T
$DEVICE_WIFI_HT = 'shellyhtg3-xxxxxxxxxxxx';       // ID des H&T WiFi
$DEVICE_1PM_MINI = 'shelly1pmminig3-d0cf13cb5dd8'; // ID des 1PM Mini
```

**🔍 Geräte-IDs ermitteln:** Führe folgende SQL-Abfrage aus, um die IDs deiner Geräte zu finden:

```sql
SELECT DISTINCT device_id, device_name FROM sensor_data;
SELECT DISTINCT device_id, device_name FROM power_data;
SELECT DISTINCT device_id, device_name FROM switch_events;
```

### 3.4 Daten abrufen

```php
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
```

---

## 4. Das HTML-Grundgerüst

```html
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shelly Dashboard</title>
    
    <!-- Chart.js für Diagramme -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
            <!-- Hier kommen die 4 Kacheln -->
        </div>
        
        <!-- Diagramme -->
        <section class="charts-section">
            <!-- Hier kommen die Charts -->
        </section>
    </div>
</body>
</html>
```

**Chart.js:** Wir laden Chart.js und den Date-Adapter von einem CDN. Keine lokale Installation nötig.

---

## 5. Die Kacheln im Detail

### 5.1 Sensor-Kachel (H&T BLU)

```php
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
```

Die Batterieanzeige wechselt automatisch die Farbe: Grün über 50%, Orange über 20%, Rot darunter.

### 5.2 Power-Kachel (1PM Mini)

```php
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
```

**💡 Automatische Einheit:** Der Energieverbrauch wird unter 1000 Wh in Wh angezeigt, darüber automatisch in kWh umgerechnet.

### 5.3 Switch-Kachel (1PM Mini)

```php
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
```

Die Event-Liste zeigt die letzten 10 Schaltvorgänge mit Quelle (App, Taster, Timer usw.) und Zeitstempel.

---

## 6. Diagramme mit Chart.js

### 6.1 Chart.js einbinden und konfigurieren

```javascript
// Daten aus PHP übernehmen
const chartData = <?= json_encode($chartData) ?>;

// Chart.js Grundkonfiguration (Dark Theme)
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
```

Die PHP-Variable `$chartData` wird per `json_encode()` direkt ins JavaScript übergeben – eine elegante Methode, Daten vom Server zum Client zu transportieren.

### 6.2 Temperatur-Diagramm

```javascript
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
                pointRadius: 2
            },
            {
                label: 'H&T WiFi',
                data: formatChartData(chartData.tempWifi),
                borderColor: '#7856ff',
                backgroundColor: 'rgba(120, 86, 255, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                type: 'time',
                time: {
                    unit: 'hour',
                    displayFormats: { hour: 'HH:mm' }
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
        }
    }
});
```

**Wichtige Optionen:**
- `type: 'time'` für die X-Achse ermöglicht echte Zeitdarstellung
- `tension: 0.3` glättet die Kurven leicht
- `fill: true` füllt den Bereich unter der Linie

### 6.3 Leistungs-Diagramm

Das Leistungs-Diagramm ist analog aufgebaut, nur mit einer Datenserie und orangefarbener Darstellung.

---

## 7. Das CSS-Styling

Das komplette CSS für ein modernes Dark-Theme:

```css
:root {
    --bg-primary: #0f1419;
    --bg-secondary: #1a1f26;
    --bg-card: #232b35;
    --text-primary: #e7e9ea;
    --text-secondary: #8b98a5;
    --accent-blue: #1d9bf0;
    --accent-green: #00ba7c;
    --accent-orange: #ff7a00;
    --border-color: #2f3943;
}

body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.25rem;
}

.card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid var(--border-color);
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
}
```

Das Grid-Layout mit `auto-fit` und `minmax()` sorgt dafür, dass die Kacheln auf allen Bildschirmgrößen gut aussehen – vom Smartphone bis zum Desktop.

---

## 8. Automatische Aktualisierung

Das Dashboard aktualisiert sich automatisch alle 5 Minuten:

```javascript
setTimeout(function() {
    location.reload();
}, 5 * 60 * 1000);
```

Für Echtzeit-Updates könntest du später auf WebSockets oder Server-Sent Events umsteigen – aber für ein Sensor-Dashboard ist ein 5-Minuten-Intervall völlig ausreichend.

---

## 9. Installation und Anpassung

### 9.1 Datei erstellen

```bash
sudo nano /var/www/html/dashboard.php
```

Füge den kompletten Code ein (Download-Link am Ende des Artikels).

### 9.2 Berechtigungen setzen

```bash
sudo chown www-data:www-data /var/www/html/dashboard.php
sudo chmod 644 /var/www/html/dashboard.php
```

### 9.3 Geräte-IDs anpassen

1. Ermittle deine Device-IDs aus der Datenbank:
```sql
SELECT DISTINCT device_id, device_name FROM sensor_data;
```

2. Trage die IDs in der dashboard.php ein (ca. Zeile 63-65)

### 9.4 Testen

Öffne im Browser: `http://deine-server-ip/dashboard.php`

---

## 10. Troubleshooting

### Keine Daten in den Kacheln?

**Problem:** Kachel zeigt "Keine Daten verfügbar"

**Lösung:** 
1. Prüfe die Device-ID – stimmt sie mit der Datenbank überein?
2. Achte auf Groß-/Kleinschreibung bei MAC-Adressen
3. Prüfe ob Daten in der richtigen Tabelle liegen

### Diagramm zeigt seltsame Zahlen?

**Problem:** Y-Achse zeigt `18.400000000000006 °C`

**Lösung:** Das ist ein JavaScript-Floating-Point-Problem. Im `ticks.callback` der Y-Achse `.toFixed(1)` verwenden:

```javascript
ticks: {
    callback: function(value) {
        return value.toFixed(1) + ' °C';
    }
}
```

### Verbindung fehlgeschlagen?

**Problem:** Browser zeigt "Verbindung fehlgeschlagen"

**Lösung:**
1. Läuft Apache? `sudo systemctl status apache2`
2. Richtige IP? `ip addr | grep inet`
3. HTTPS vs HTTP? Der Browser macht manchmal automatisch HTTPS draus

---

## 11. Vollständiger Code zum Download

Die komplette `dashboard.php` mit allen Features findest du hier:

[📥 dashboard.php herunterladen](#) *(Link zu deinem GitHub oder Download)*

---

## 12. Ausblick: Grafana als Erweiterung

Das PHP-Dashboard ist perfekt für den Einstieg und volle Kontrolle. Wenn du noch professionellere Visualisierungen möchtest, zeige ich dir im nächsten Teil, wie du **Grafana** einrichtest und mit deiner Shelly-Datenbank verbindest.

Grafana bietet:
- Drag-and-Drop Dashboard-Editor
- Vorgefertigte Visualisierungen
- Alerting bei Schwellwerten
- Mobile App

---

## Fazit

Du hast jetzt ein funktionierendes Dashboard, das alle deine Shelly-Daten übersichtlich visualisiert – komplett lokal, ohne Cloud, mit voller Kontrolle über Design und Funktionen.

Die Kombination aus PHP für die Datenverarbeitung und Chart.js für die Visualisierung ist leichtgewichtig und läuft auf jedem Webserver. Perfekt für Smart Home Enthusiasten, die Wert auf Datenschutz und Unabhängigkeit legen.

**Was kommt als Nächstes?**
- Teil 5b: Grafana-Integration für professionelle Dashboards
- Teil 6: BLU Gateway Scripting – Bluetooth-Sensoren auswerten

Schreib mir in den Kommentaren, wie dein Dashboard aussieht!

---

*Zurück zur Übersicht: [Shelly Smart Home Serie](https://diytechadventures.de/projekte)*
