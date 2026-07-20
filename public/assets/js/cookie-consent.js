(function () {
    var consentCookieName = 'ple_cookie_consent';
    var consentAcceptedValue = 'accepted';
    var consentDeclinedValue = 'declined';
    var consentCookieMaxAge = 60 * 60 * 24 * 365; // 1 year

    function getCookie(name) {
        var cookies = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < cookies.length; i += 1) {
            var cookie = cookies[i];
            if (cookie.indexOf(name + '=') === 0) {
                return decodeURIComponent(cookie.substring(name.length + 1));
            }
        }
        return null;
    }

    function setCookie(name, value, maxAge) {
        var cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
        document.cookie = cookie;
    }

    function hasConsent() {
        return getCookie(consentCookieName) === consentAcceptedValue;
    }

    function hasDeclined() {
        return getCookie(consentCookieName) === consentDeclinedValue;
    }

    function showBanner() {
        var banner = document.getElementById('cookie-consent-banner');
        if (!banner) {
            return;
        }
        banner.hidden = false;
        banner.classList.remove('cookie-consent-banner--hidden');
    }

    function hideBanner() {
        var banner = document.getElementById('cookie-consent-banner');
        if (!banner) {
            return;
        }
        banner.hidden = true;
        banner.classList.add('cookie-consent-banner--hidden');
    }

    function loadGoogleAnalytics(measurementId) {
        if (!measurementId || window.__ple_ga_loaded) {
            return;
        }

        window.__ple_ga_loaded = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () {
            window.dataLayer.push(arguments);
        };
        window.gtag('js', new Date());
        window.gtag('config', measurementId, { anonymize_ip: true });

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
        document.head.appendChild(script);
    }

    function acceptCookies() {
        setCookie(consentCookieName, consentAcceptedValue, consentCookieMaxAge);
        hideBanner();
        if (window.PLE_GA_MEASUREMENT_ID) {
            loadGoogleAnalytics(window.PLE_GA_MEASUREMENT_ID);
        }
    }

    function declineCookies() {
        setCookie(consentCookieName, consentDeclinedValue, consentCookieMaxAge);
        hideBanner();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var acceptButton = document.getElementById('cookie-consent-accept');
        var declineButton = document.getElementById('cookie-consent-decline');
        if (acceptButton) {
            acceptButton.addEventListener('click', acceptCookies);
        }
        if (declineButton) {
            declineButton.addEventListener('click', declineCookies);
        }

        if (hasConsent() || hasDeclined()) {
            hideBanner();
        } else {
            showBanner();
        }

        if (hasConsent() && window.PLE_GA_MEASUREMENT_ID) {
            loadGoogleAnalytics(window.PLE_GA_MEASUREMENT_ID);
        }
    });
})();
