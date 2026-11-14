(function () {
    const widget = document.querySelector('[data-weather-widget]');
    if (!widget) return;

    const openButton = widget.querySelector('[data-weather-open]');
    const dialog = widget.querySelector('[data-weather-dialog]');
    const refreshButton = widget.querySelector('[data-weather-refresh]');
    const hourlyContainer = widget.querySelector('[data-weather-hourly]');
    const fallbackLocation = async function () {
        const response = await fetchWithTimeout(
            'https://geocoding-api.open-meteo.com/v1/search?name=Dau&count=10&language=en&format=json&countryCode=PH'
        );
        if (!response.ok) throw new Error('Campus location lookup failed');
        const data = await response.json();
        const place = (data.results || []).find(item =>
            item.country_code === 'PH' && item.name.toLowerCase() === 'dau'
        );
        if (!place) throw new Error('Campus location not found');
        return {
            latitude: place.latitude,
            longitude: place.longitude,
            label: 'Dau, Mabalacat (campus fallback)',
            source: 'fallback'
        };
    };

    let cachedWeather = null;
    let lastLoadedAt = 0;
    let loadingPromise = null;
    let detectedLocation = null;

    let activeRequestController = null;

    async function fetchWithTimeout(url, options = {}, timeoutMs = 12000) {
        const controller = new AbortController();
        const parentSignal = activeRequestController?.signal;
        const abortFromParent = () => controller.abort();

        if (parentSignal) {
            if (parentSignal.aborted) controller.abort();
            else parentSignal.addEventListener('abort', abortFromParent, { once: true });
        }

        const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs);

        try {
            return await fetch(url, { ...options, signal: controller.signal });
        } catch (error) {
            if (controller.signal.aborted) {
                throw new Error('Request timed out or was cancelled.');
            }
            throw error;
        } finally {
            window.clearTimeout(timeoutId);
            if (parentSignal) parentSignal.removeEventListener('abort', abortFromParent);
        }
    }


    const weatherLabels = {
        0: 'Clear sky', 1: 'Mainly clear', 2: 'Partly cloudy', 3: 'Overcast',
        45: 'Fog', 48: 'Rime fog', 51: 'Light drizzle', 53: 'Drizzle',
        55: 'Dense drizzle', 56: 'Freezing drizzle', 57: 'Heavy freezing drizzle',
        61: 'Light rain', 63: 'Rain', 65: 'Heavy rain', 66: 'Freezing rain',
        67: 'Heavy freezing rain', 71: 'Light snow', 73: 'Snow', 75: 'Heavy snow',
        77: 'Snow grains', 80: 'Rain showers', 81: 'Rain showers',
        82: 'Heavy rain showers', 85: 'Snow showers', 86: 'Heavy snow showers',
        95: 'Thunderstorm', 96: 'Thunderstorm with hail', 99: 'Heavy thunderstorm'
    };

    function weatherType(code, isDay) {
        if (code >= 95) return 'storm';
        if ([51, 53, 55, 56, 57].includes(code)) return 'drizzle';
        if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) return 'rain';
        if ([45, 48].includes(code)) return 'fog';
        if ([1, 2, 3, 71, 73, 75, 77, 85, 86].includes(code)) return 'cloudy';
        return isDay ? 'clear' : 'cloudy';
    }

    function labelFor(code) {
        return weatherLabels[code] || 'Weather unavailable';
    }

    function setText(selector, value) {
        widget.querySelectorAll(selector).forEach(function (element) {
            element.textContent = value;
        });
    }

    function setScene(selector, type) {
        const scene = widget.querySelector(selector);
        if (scene) scene.dataset.weather = type;
    }

    function setWeatherBackground(type, temperature) {
        let backgroundType = type;
        if (type === 'clear') {
            if (temperature <= 20) backgroundType = 'cold';
            else if (temperature <= 25) backgroundType = 'cool';
            else if (temperature >= 32) backgroundType = 'hot';
            else if (temperature >= 28) backgroundType = 'warm';
        }
        const trigger = widget.querySelector('.emis-weather-trigger');
        const currentPanel = widget.querySelector('.emis-weather-current');
        const weatherPanel = widget.querySelector('.emis-weather-panel');
        if (trigger) trigger.dataset.weather = backgroundType;
        if (currentPanel) currentPanel.dataset.weather = backgroundType;
        if (weatherPanel) weatherPanel.dataset.weather = backgroundType;
        widget.querySelectorAll('.emis-weather-scene').forEach(scene => {
            scene.dataset.weather = backgroundType;
        });
    }

    function formatHour(value) {
        const hour = Number((value.split('T')[1] || '00:00').split(':')[0]);
        return new Date(2000, 0, 1, hour).toLocaleTimeString('en-PH', {
            hour: 'numeric', hour12: true
        });
    }

    function renderHourly(hourly, currentTime) {
        if (!hourly || !Array.isArray(hourly.time)) {
            hourlyContainer.innerHTML = '<p class="emis-weather-error">Hourly forecast is unavailable.</p>';
            return;
        }
        let startIndex = hourly.time.findIndex(time => time >= currentTime);
        if (startIndex < 0) startIndex = 0;
        const items = [];
        for (let i = startIndex; i < Math.min(startIndex + 6, hourly.time.length); i++) {
            const code = hourly.weather_code?.[i] ?? 0;
            const temperature = hourly.temperature_2m?.[i];
            const rainChance = hourly.precipitation_probability?.[i];
            const symbol = code >= 95 ? '⛈' :
                ([61, 63, 65, 80, 81, 82, 51, 53, 55].includes(code) ? '🌧' :
                ([1, 2, 3, 45, 48].includes(code) ? '☁️' : '☀️'));
            items.push(
                '<div class="emis-weather-hour">' +
                    '<span>' + formatHour(hourly.time[i]) + '</span>' +
                    '<strong aria-label="' + labelFor(code) + '">' + symbol + '</strong>' +
                    '<small>' + (temperature == null ? '--' : Math.round(temperature) + '°') + '</small>' +
                    '<b>' + (rainChance == null ? '--' : rainChance + '%') + ' rain</b>' +
                '</div>'
            );
        }
        hourlyContainer.innerHTML = items.length ? items.join('') :
            '<p class="emis-weather-loading">No more hourly forecast available today.</p>';
    }

    function renderWeather(data) {
        const current = data.current;
        const daily = data.daily;
        const temperature = Math.round(current.temperature_2m);
        const feelsLike = Math.round(current.apparent_temperature);
        const code = Number(current.weather_code);
        const type = weatherType(code, current.is_day === 1);
        const rainChance = daily.precipitation_probability_max?.[0];
        const updated = new Date().toLocaleTimeString('en-PH', {
            hour: 'numeric', minute: '2-digit'
        });

        setText('[data-weather-temperature]', temperature + '°C');
        setText('[data-weather-description]', labelFor(code));
        setText('[data-weather-updated]', '· Updated ' + updated);
        setText('[data-weather-modal-temperature]', temperature + '°C');
        setText('[data-weather-modal-description]', labelFor(code));
        setText('[data-weather-feels-like]', feelsLike + '°C');
        setText('[data-weather-rain]', rainChance == null ? '--' : rainChance + '%');
        setText('[data-weather-wind]', Math.round(current.wind_speed_10m) + ' km/h');
        setText('[data-weather-humidity]', current.relative_humidity_2m + '%');

        const high = daily.temperature_2m_max?.[0];
        const low = daily.temperature_2m_min?.[0];
        setText('[data-weather-range]', high == null || low == null
            ? '--° / --°' : Math.round(high) + '° / ' + Math.round(low) + '°');
        setText('[data-weather-date]', new Date().toLocaleDateString('en-PH', {
            weekday: 'long', month: 'long', day: 'numeric'
        }));
        setScene('[data-weather-scene]', type);
        setScene('[data-weather-modal-scene]', type);
        setWeatherBackground(type, temperature);
        renderHourly(data.hourly, current.time);
    }

    async function getLocationName(latitude, longitude) {
        try {
            const url = new URL('https://api.bigdatacloud.net/data/reverse-geocode-client');
            url.search = new URLSearchParams({
                latitude: latitude,
                longitude: longitude,
                localityLanguage: 'en'
            }).toString();
            const response = await fetchWithTimeout(url.toString());
            if (!response.ok) throw new Error('Reverse geocoding unavailable');
            const data = await response.json();
            return data.city || data.locality || data.principalSubdivision || 'Your location';
        } catch (error) {
            return 'Your current location';
        }
    }

    function getBrowserCoordinates() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                position => resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    source: 'device'
                }),
                error => reject(error),
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 5 * 60 * 1000 }
            );
        });
    }

    async function detectLocation() {
        try {
            // Start the forecast as soon as device coordinates are available.
            // Reverse geocoding only improves the label and must not block weather.
            const coordinates = await getBrowserCoordinates();
            const location = {
                ...coordinates,
                label: 'Your current location'
            };

            getLocationName(coordinates.latitude, coordinates.longitude)
                .then(label => {
                    location.label = label;
                    if (detectedLocation === location) {
                        setText('[data-weather-location-label]', label);
                        setText('[data-weather-description]', 'Weather for ' + label);
                    }
                })
                .catch(() => {});

            return location;
        } catch (error) {
            // If device location is unavailable, use IP-based locality.
            try {
                const response = await fetchWithTimeout(
                    'https://api.bigdatacloud.net/data/reverse-geocode-client?localityLanguage=en',
                    {},
                    8000
                );
                if (!response.ok) throw new Error('IP location unavailable');
                const data = await response.json();
                if (Number.isFinite(Number(data.latitude)) &&
                    Number.isFinite(Number(data.longitude)) &&
                    (data.city || data.locality || data.principalSubdivision)) {
                    return {
                        latitude: Number(data.latitude),
                        longitude: Number(data.longitude),
                        label: (data.city || data.locality || data.principalSubdivision) + ' (approximate)',
                        source: 'ip'
                    };
                }
            } catch (fallbackError) {
                // Continue to the campus fallback below.
            }
            return await fallbackLocation();
        }
    }

    async function loadWeather(force) {
        if (!force && cachedWeather && Date.now() - lastLoadedAt < 15 * 60 * 1000) {
            renderWeather(cachedWeather);
            return cachedWeather;
        }
        if (loadingPromise) return loadingPromise;

        setText('[data-weather-description]', 'Detecting your location...');
        if (hourlyContainer) hourlyContainer.innerHTML =
            '<p class="emis-weather-loading">Finding your location and forecast...</p>';

        activeRequestController = new AbortController();
        setText('[data-weather-description]', 'Detecting your location...');
        let requestTimeoutId;
        const requestTimeout = new Promise((_, reject) => {
            requestTimeoutId = window.setTimeout(() => {
                if (activeRequestController) activeRequestController.abort();
                reject(new Error('Weather request timed out after 15 seconds. Please try again.'));
            }, 15000);
        });

        loadingPromise = (async function () {
            if (!detectedLocation) {
                detectedLocation = await detectLocation();
                setText('[data-weather-location-label]', detectedLocation.label);
            }

            setText('[data-weather-description]', 'Fetching weather for ' + detectedLocation.label + '...');
            setText('[data-weather-temperature]', '--°');
            setText('[data-weather-modal-description]', 'Fetching current weather...');
            if (hourlyContainer) hourlyContainer.innerHTML =
                '<p class="emis-weather-loading">Loading the latest forecast...</p>';

            const params = new URLSearchParams({
                latitude: detectedLocation.latitude,
                longitude: detectedLocation.longitude,
                current: 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m',
                hourly: 'temperature_2m,precipitation_probability,weather_code',
                daily: 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
                timezone: 'Asia/Manila',
                forecast_days: '1'
            });
            const forecastResponse = await fetchWithTimeout(
                'https://api.open-meteo.com/v1/forecast?' + params.toString(),
                {},
                18000
            );
            if (!forecastResponse.ok) {
                throw new Error('Weather service returned HTTP ' + forecastResponse.status + '.');
            }
            const forecast = await forecastResponse.json();
            if (!forecast.current || !forecast.daily || !forecast.hourly) {
                throw new Error('The weather service returned incomplete data.');
            }
            cachedWeather = forecast;
            lastLoadedAt = Date.now();
            renderWeather(forecast);
            return forecast;
        })();

        try {
            return await Promise.race([loadingPromise, requestTimeout]);
        } catch (error) {
            setText('[data-weather-description]', error.message.includes('timed out')
                ? 'Weather request timed out' : 'Weather is temporarily unavailable');
            if (hourlyContainer) hourlyContainer.innerHTML =
                '<p class="emis-weather-error">' + error.message + ' Please try refreshing.</p>';
            throw error;
        } finally {
            window.clearTimeout(requestTimeoutId);
            loadingPromise = null;
            activeRequestController = null;
            if (refreshButton) refreshButton.disabled = false;
        }
    }

    function openDialog() {
        dialog.hidden = false;
        openButton.setAttribute('aria-expanded', 'true');
        document.body.classList.add('emis-weather-open');
        const closeButton = dialog.querySelector('[data-weather-close]');
        if (closeButton && closeButton.tagName === 'BUTTON') closeButton.focus();
        loadWeather(false).catch(() => {});
    }

    function closeDialog() {
        dialog.hidden = true;
        openButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('emis-weather-open');
        openButton.focus();
    }

    openButton.addEventListener('click', openDialog);
    dialog.addEventListener('click', event => {
        if (event.target.closest('[data-weather-close]')) closeDialog();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !dialog.hidden) closeDialog();
    });
    if (refreshButton) {
        refreshButton.addEventListener('click', function () {
            refreshButton.disabled = true;
            loadWeather(true).catch(() => {});
        });
    }

    loadWeather(false).catch(() => {});
})();
