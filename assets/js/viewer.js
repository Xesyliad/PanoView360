(() => {
    const lightbox = document.getElementById('lightbox');
    const viewerRoot = document.getElementById('viewerStage');
    const closeButton = document.getElementById('lightboxClose');
    const cards = document.querySelectorAll('.thumb-card');
    const locationOverlay = document.getElementById('locationOverlay');
    const locationLink = document.getElementById('locationLink');
    const locationMap = document.getElementById('locationMap');

    if (!lightbox || !viewerRoot || !closeButton || !cards.length) {
        return;
    }

    let panoramaViewer = null;
    let locationMapInstance = null;
    let openTrigger = null;

    function destroyPanoramaViewer() {
        if (panoramaViewer && typeof panoramaViewer.destroy === 'function') {
            panoramaViewer.destroy();
        }
        panoramaViewer = null;
        viewerRoot.innerHTML = '';
    }

    function destroyLocationMap() {
        if (locationMapInstance && typeof locationMapInstance.remove === 'function') {
            locationMapInstance.remove();
        }
        locationMapInstance = null;
        if (locationMap) {
            locationMap.innerHTML = '';
        }
        if (locationLink) {
            locationLink.removeAttribute('href');
            locationLink.hidden = true;
        }
        if (locationOverlay) {
            locationOverlay.classList.remove('has-location');
        }
    }

    function googleMapsSatelliteUrl(lat, lng) {
        return `https://www.google.com/maps?q=${lat},${lng}&t=k&z=18`;
    }

    function renderLocation(card) {
        const rawLat = (card.dataset.lat || '').trim();
        const rawLng = (card.dataset.lng || '').trim();
        const lat = rawLat === '' ? null : Number(rawLat);
        const lng = rawLng === '' ? null : Number(rawLng);

        destroyLocationMap();

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        if (locationOverlay) {
            locationOverlay.classList.add('has-location');
        }

        if (locationLink) {
            locationLink.hidden = false;
            locationLink.href = googleMapsSatelliteUrl(lat, lng);
            locationLink.onclick = (event) => {
                event.preventDefault();
                window.open(googleMapsSatelliteUrl(lat, lng), '_blank', 'noopener,noreferrer');
            };
        }

        if (!locationMap || typeof window.L === 'undefined') {
            return;
        }

        locationMapInstance = window.L.map(locationMap, {
            zoomControl: false,
            attributionControl: false,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            tap: false,
            touchZoom: false,
            zoomSnap: 1,
            center: [lat, lng],
            zoom: 13,
        });

        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(locationMapInstance);

        window.L.circleMarker([lat, lng], {
            radius: 7,
            color: '#ffffff',
            weight: 2,
            fillColor: '#000000',
            fillOpacity: 1,
        }).addTo(locationMapInstance);
        requestAnimationFrame(() => {
            if (locationMapInstance && typeof locationMapInstance.invalidateSize === 'function') {
                locationMapInstance.invalidateSize();
            }
        });
    }

    function initPanorama(card) {
        if (typeof window.pannellum === 'undefined' || !window.pannellum.viewer) {
            viewerRoot.innerHTML = '<div class="viewer-fallback">Panorama viewer is still loading.</div>';
            return;
        }

        panoramaViewer = window.pannellum.viewer(viewerRoot, {
            type: 'equirectangular',
            panorama: card.dataset.image,
            autoLoad: true,
            pitch: Number(card.dataset.pitch ?? 0),
            yaw: Number(card.dataset.yaw ?? 0),
            hfov: Number(card.dataset.fov ?? 100),
            minHfov: 30,
            maxHfov: 120,
            compass: false,
            showControls: true,
            showFullscreenCtrl: false,
            showZoomCtrl: true,
            hotSpotDebug: false,
            backgroundColor: [0, 0, 0],
        });
    }

    function setViewportAspect() {
        const aspect = window.innerWidth / Math.max(window.innerHeight, 1);
        document.documentElement.style.setProperty('--viewport-aspect', String(aspect));
    }

    function openLightbox(card) {
        openTrigger = document.activeElement;
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        setViewportAspect();
        destroyPanoramaViewer();
        destroyLocationMap();

        requestAnimationFrame(() => {
            initPanorama(card);
            renderLocation(card);
        });

        closeButton.focus();
    }

    function closeLightbox() {
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        destroyPanoramaViewer();
        destroyLocationMap();
        if (openTrigger && typeof openTrigger.focus === 'function') {
            openTrigger.focus();
        }
    }

    cards.forEach((card) => {
        card.addEventListener('click', () => openLightbox(card));
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openLightbox(card);
            }
        });
    });

    closeButton.addEventListener('click', closeLightbox);
    lightbox.querySelector('[data-close]')?.addEventListener('click', closeLightbox);

    window.addEventListener('keydown', (event) => {
        if (!lightbox.classList.contains('is-open')) {
            return;
        }
        if (event.key === 'Escape') {
            closeLightbox();
        }
    });

    window.addEventListener('resize', () => {
        if (lightbox.classList.contains('is-open')) {
            setViewportAspect();
            if (locationMapInstance && typeof locationMapInstance.invalidateSize === 'function') {
                locationMapInstance.invalidateSize();
            }
        }
    });

    setViewportAspect();
})();
