<?php
// includes/footer.php

// Calculate page load time
$startTime = $_SERVER["REQUEST_TIME_FLOAT"];
$pageLoadTime = round((microtime(true) - $startTime) * 1000, 2); // Time in milliseconds

// Set default timezone to America/New_York for Putnam County, Ohio
date_default_timezone_set('America/New_York');

// Current time
$currentTime = new DateTime();
$currentTimeFormatted = $currentTime->format('l, F j, Y H:i:s');  // Day, Month Day, Year Time

// Check if DST is in effect
$isDST = $currentTime->format('I'); // Returns '1' for DST, '0' for Standard Time
$timeZoneName = $isDST ? 'EDT' : 'EST';

// Get UTC time (Zulu time)
$utcTime = $currentTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
?>

</div><!-- End main-content -->
<footer class="footer py-3" style="background-color: #2c3e50; color: white;">
    <div class="container text-center">
        <hr class="my-3" style="border-color: #ff5722;">
        <p class="mb-1 fw-bold" style="color: #ff5722;">
            <i class="bi bi-broadcast text-warning"></i> Timothy Kramer | <span style="color: #3498db;">KR8MER</span>
        </p>
        <p class="mb-1">
            <a href="https://ballcock.us" class="text-decoration-none" style="color: #1abc9c;" target="_blank" rel="noopener">ballcock.us</a>
        </p>
        <div class="d-flex justify-content-center flex-wrap small">
            <div class="mx-2" style="color: #ecf0f1;">
                <strong>Current Time:</strong> 
                <span id="current-time"><?= $currentTimeFormatted ?> <?= $timeZoneName ?></span>
            </div>
            <div class="mx-2" style="color: #ecf0f1;">
                <strong>Time Zone:</strong> 
                <span>America/New_York</span>
            </div>
            <div class="mx-2" style="color: #ecf0f1;">
                <strong>DST:</strong> 
                <span><?= $isDST ? "Yes" : "No" ?></span>
            </div>
            <div class="mx-2" style="color: #ecf0f1;">
                <strong>Zulu (UTC):</strong> 
                <span><?= $utcTime ?></span>
            </div>
        </div>
        <p class="mt-1 mb-0 small" style="color: #bdc3c7;">Page loaded in <span style="color: #e74c3c;"><?= $pageLoadTime ?> ms</span></p>
        <p class="mb-0 small" style="color: #95a5a6;">&copy; <?= date('Y') ?> All rights reserved.</p>
    </div>

    <!-- Scrolling weather data -->
    <div id="weather-update" style="background-color: #34495e; color: #ecf0f1; font-size: 1.2rem; padding: 0.5rem 0; white-space: nowrap; overflow: hidden; margin-top: 10px;">
        <div id="weather-data" style="display: inline-block; animation: scrollWeather 20s linear infinite;">
            Loading weather data...
        </div>
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">

<!-- Time Scripts -->
<script>
    function updateTime() {
        const now = new Date();

        // Format Current Time (local time)
        const currentTime = now.toLocaleString('en-US', {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            hour12: false
        });
        document.getElementById('current-time').textContent = currentTime + " EST";  // Add timezone suffix
    }

    // Update time immediately and then every second
    updateTime();
    setInterval(updateTime, 1000);

    // Function to convert wind direction in degrees to compass direction
    function getWindDirection(degrees) {
        const directions = [
            'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'
        ];
        const index = Math.round((degrees % 360) / 22.5);
        return directions[index];
    }

    // Fetch and display weather data from clientraw.txt
    function updateWeather() {
        fetch('https://ballcock.us/clientraw.txt')
            .then(response => response.text())
            .then(data => {
                // Split the data by spaces
                const weatherData = data.split(' ');

                // Extract weather metrics (based on the known position in the clientraw.txt)
                const temperatureC = parseFloat(weatherData[4]);  // Temp in Celsius
                const temperatureF = (temperatureC * 9/5) + 32;  // Convert Celsius to Fahrenheit
                const humidity = weatherData[5];  // Humidity
                const windSpeed = weatherData[2];  // Wind Speed
                const windGust = weatherData[71];  // Wind Gust (correct index)
                const windDirection = parseFloat(weatherData[3]);  // Wind Direction

                // Convert wind direction to compass direction
                const windDirectionCompass = getWindDirection(windDirection);

                // Create a formatted message with the correct symbols and wind gust
                const weatherMessage = `Temp: ${temperatureF.toFixed(1)} | Humidity: ${humidity}% | Wind: ${windSpeed} mph | Gusts: ${windGust} mph | Direction: ${windDirectionCompass}`;

                // Update the scrolling message
                document.getElementById('weather-data').textContent = weatherMessage;
            })
            .catch(error => {
                console.error('Error fetching weather data:', error);
                document.getElementById('weather-data').textContent = 'Error fetching weather data';
            });
    }

    // Update weather data every 10 seconds
    updateWeather();
    setInterval(updateWeather, 10000);
</script>

<!-- CSS for Scrolling -->
<style>
    @keyframes scrollWeather {
        0% {
            transform: translateX(100%);
        }
        100% {
            transform: translateX(-100%);
        }
    }
</style>

<?php if (isset($additionalScripts)) echo $additionalScripts; ?>
</body>
</html>
