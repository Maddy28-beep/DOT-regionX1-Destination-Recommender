(function () {
    'use strict';
    var box = document.querySelector('[data-catalog-nearby]');
    if (!box) return;
    var origin = box.querySelector('[data-nearby-origin]');
    var lat = box.querySelector('[data-nearby-lat]');
    var lng = box.querySelector('[data-nearby-lng]');
    var locate = box.querySelector('[data-nearby-locate]');
    var clear = box.querySelector('[data-nearby-clear]');
    var status = box.querySelector('[data-nearby-status]');
    var radii = box.querySelectorAll('input[name="nearby_km"]');
    var requestId = 0;
    function update() {
        var active = !!origin.value;
        radii.forEach(function (radio) { radio.disabled = !active; });
        lat.disabled = lng.disabled = origin.value !== 'current';
        if (origin.value !== 'current') { lat.value = ''; lng.value = ''; }
        clear.hidden = !active;
        box.closest('form').dispatchEvent(new Event('input', { bubbles: true }));
    }
    origin.addEventListener('change', function () {
        requestId++;
        locate.disabled = false;
        locate.removeAttribute('aria-busy');
        status.textContent = '';
        update();
    });
    clear.addEventListener('click', function () {
        origin.value = '';
        origin.dispatchEvent(new Event('change', { bubbles: true }));
    });
    locate.addEventListener('click', function () {
        if (!navigator.geolocation) {
            status.textContent = 'Location is unavailable. Choose a starting point instead.';
            return;
        }
        var attempt = ++requestId;
        locate.disabled = true;
        locate.setAttribute('aria-busy', 'true');
        status.textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(function (position) {
            if (attempt !== requestId) return;
            var latitude = position.coords.latitude, longitude = position.coords.longitude;
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
                fail({ code: 2 }); return;
            }
            if (!origin.querySelector('option[value="current"]')) {
                var option = document.createElement('option');
                option.value = 'current'; option.textContent = 'Your current location';
                origin.appendChild(option);
            }
            lat.value = latitude; lng.value = longitude;
            origin.value = 'current';
            origin.dispatchEvent(new Event('change', { bubbles: true }));
            status.textContent = 'Location ready. Choose a distance, then show results.';
        }, fail, { timeout: 10000, maximumAge: 60000 });
        function fail(error) {
            if (attempt !== requestId) return;
            locate.disabled = false;
            locate.removeAttribute('aria-busy');
            status.textContent = error.code === 1
                ? 'Location permission was not granted. Choose a starting point instead.'
                : 'We could not find your location. Choose a starting point or try again.';
        }
    });
    update();
})();
