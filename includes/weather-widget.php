<?php
?>
<section class="emis-weather-widget" data-weather-widget aria-label="Campus weather">
    <button class="emis-weather-trigger" type="button" data-weather-open aria-haspopup="dialog" aria-expanded="false">
        <span class="emis-weather-copy">
            <span class="emis-weather-eyebrow"><i class="bi bi-cloud-sun"></i> LOCAL WEATHER</span>
            <strong class="emis-weather-temperature" data-weather-temperature>--°</strong>
            <span class="emis-weather-description" data-weather-description>Loading today's weather...</span>
            <span class="emis-weather-location"><i class="bi bi-geo-alt"></i> <span data-weather-location-label>Detecting location...</span> <span class="emis-weather-updated" data-weather-updated></span></span>
        </span>
        <span class="emis-weather-scene" data-weather-scene data-weather="loading" aria-hidden="true">
            <span class="emis-weather-sun">☀</span>
            <span class="emis-weather-cloud">☁</span>
            <span class="emis-weather-drop drop-one"></span>
            <span class="emis-weather-drop drop-two"></span>
            <span class="emis-weather-drop drop-three"></span>
            <span class="emis-weather-drop drop-four"></span>
        </span>
        <span class="emis-weather-view">View weather <i class="bi bi-arrow-up-right"></i></span>
    </button>

    <div class="emis-weather-dialog" data-weather-dialog hidden>
        <div class="emis-weather-backdrop" data-weather-close></div>
        <section class="emis-weather-panel" role="dialog" aria-modal="true" aria-labelledby="emisWeatherTitle">
            <header class="emis-weather-panel-header">
                <div>
                    <span class="emis-weather-panel-kicker">WEATHER REPORT</span>
                    <h2 id="emisWeatherTitle">Local weather report</h2>
                    <p><i class="bi bi-geo-alt"></i> <span data-weather-location-label>Detecting location...</span></p>
                </div>
                <button class="emis-weather-close" type="button" data-weather-close aria-label="Close weather"><i class="bi bi-x-lg"></i></button>
            </header>

            <div class="emis-weather-current">
                <div class="emis-weather-current-copy">
                    <span data-weather-date>Today's conditions</span>
                    <strong data-weather-modal-temperature>--°</strong>
                    <b data-weather-modal-description>Loading weather...</b>
                    <small>Feels like <span data-weather-feels-like>--°</span></small>
                </div>
                <div class="emis-weather-scene emis-weather-scene-large" data-weather-modal-scene data-weather="loading" aria-hidden="true">
                    <span class="emis-weather-sun">☀</span>
                    <span class="emis-weather-cloud">☁</span>
                    <span class="emis-weather-drop drop-one"></span>
                    <span class="emis-weather-drop drop-two"></span>
                    <span class="emis-weather-drop drop-three"></span>
                    <span class="emis-weather-drop drop-four"></span>
                </div>
            </div>

            <div class="emis-weather-stats">
                <div><i class="bi bi-droplet-half"></i><span>Rain chance</span><strong data-weather-rain>--%</strong></div>
                <div><i class="bi bi-wind"></i><span>Wind</span><strong data-weather-wind>-- km/h</strong></div>
                <div><i class="bi bi-moisture"></i><span>Humidity</span><strong data-weather-humidity>--%</strong></div>
                <div><i class="bi bi-thermometer-half"></i><span>Today's range</span><strong data-weather-range>--° / --°</strong></div>
            </div>

            <div class="emis-weather-hourly-heading">
                <div><span class="emis-weather-panel-kicker">PLAN AHEAD</span><h3>Coming hours</h3></div>
                <button type="button" class="emis-weather-refresh" data-weather-refresh><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
            <div class="emis-weather-hourly" data-weather-hourly>
                <p class="emis-weather-loading">Fetching the latest forecast...</p>
            </div>
            <footer class="emis-weather-attribution">Weather data by <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a></footer>
        </section>
    </div>
</section>
