<?php
?>
<section class="zentrix-weather-widget" data-weather-widget aria-label="Campus weather">
    <button class="zentrix-weather-trigger" type="button" data-weather-open aria-haspopup="dialog" aria-expanded="false">
        <span class="zentrix-weather-copy">
            <span class="zentrix-weather-eyebrow"><i class="bi bi-cloud-sun"></i> LOCAL WEATHER</span>
            <strong class="zentrix-weather-temperature" data-weather-temperature>--°</strong>
            <span class="zentrix-weather-description" data-weather-description>Loading today's weather...</span>
            <span class="zentrix-weather-location"><i class="bi bi-geo-alt"></i> <span data-weather-location-label>Detecting location...</span> <span class="zentrix-weather-updated" data-weather-updated></span></span>
        </span>
        <span class="zentrix-weather-scene" data-weather-scene data-weather="loading" aria-hidden="true">
            <span class="zentrix-weather-sun">☀</span>
            <span class="zentrix-weather-cloud">☁</span>
            <span class="zentrix-weather-drop drop-one"></span>
            <span class="zentrix-weather-drop drop-two"></span>
            <span class="zentrix-weather-drop drop-three"></span>
            <span class="zentrix-weather-drop drop-four"></span>
        </span>
        <span class="zentrix-weather-view">View weather <i class="bi bi-arrow-up-right"></i></span>
    </button>

    <div class="zentrix-weather-dialog" data-weather-dialog hidden>
        <div class="zentrix-weather-backdrop" data-weather-close></div>
        <section class="zentrix-weather-panel" role="dialog" aria-modal="true" aria-labelledby="emisWeatherTitle">
            <header class="zentrix-weather-panel-header">
                <div>
                    <span class="zentrix-weather-panel-kicker">WEATHER REPORT</span>
                    <h2 id="emisWeatherTitle">Local weather report</h2>
                    <p><i class="bi bi-geo-alt"></i> <span data-weather-location-label>Detecting location...</span></p>
                </div>
                <button class="zentrix-weather-close" type="button" data-weather-close aria-label="Close weather"><i class="bi bi-x-lg"></i></button>
            </header>

            <div class="zentrix-weather-current">
                <div class="zentrix-weather-current-copy">
                    <span data-weather-date>Today's conditions</span>
                    <strong data-weather-modal-temperature>--°</strong>
                    <b data-weather-modal-description>Loading weather...</b>
                    <small>Feels like <span data-weather-feels-like>--°</span></small>
                </div>
                <div class="zentrix-weather-scene zentrix-weather-scene-large" data-weather-modal-scene data-weather="loading" aria-hidden="true">
                    <span class="zentrix-weather-sun">☀</span>
                    <span class="zentrix-weather-cloud">☁</span>
                    <span class="zentrix-weather-drop drop-one"></span>
                    <span class="zentrix-weather-drop drop-two"></span>
                    <span class="zentrix-weather-drop drop-three"></span>
                    <span class="zentrix-weather-drop drop-four"></span>
                </div>
            </div>

            <div class="zentrix-weather-stats">
                <div><i class="bi bi-droplet-half"></i><span>Expected rain</span><strong data-weather-rain>-- mm</strong></div>
                <div><i class="bi bi-wind"></i><span>Wind</span><strong data-weather-wind>-- km/h</strong></div>
                <div><i class="bi bi-moisture"></i><span>Humidity</span><strong data-weather-humidity>--%</strong></div>
                <div><i class="bi bi-thermometer-half"></i><span>Today's range</span><strong data-weather-range>--° / --°</strong></div>
            </div>

            <div class="zentrix-weather-hourly-heading">
                <div><span class="zentrix-weather-panel-kicker">PLAN AHEAD</span><h3>Coming hours</h3></div>
                <button type="button" class="zentrix-weather-refresh" data-weather-refresh><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
            <div class="zentrix-weather-hourly" data-weather-hourly>
                <p class="zentrix-weather-loading">Fetching the latest forecast...</p>
            </div>
            <footer class="zentrix-weather-attribution">Weather data by <a href="https://www.met.no/en" target="_blank" rel="noopener noreferrer">MET Norway</a></footer>
        </section>
    </div>
</section>
