(function () {
  'use strict';

  var settings = window.LogHighTtfbSettings || {};
  if (!settings.restUrl || !settings.nonce) {
    return;
  }

  var sent = false;

  function getNavigationEntry() {
    if (typeof performance === 'undefined') {
      return null;
    }

    if (performance.getEntriesByType) {
      var entries = performance.getEntriesByType('navigation');
      if (entries && entries.length) {
        return entries[0];
      }
    }

    if (performance.timing) {
      return {
        requestStart: performance.timing.requestStart,
        responseStart: performance.timing.responseStart,
      };
    }

    return null;
  }

  function calculateTtfb(entry) {
    if (!entry) {
      return 0;
    }
    var start = entry.requestStart || 0;
    var responseStart = entry.responseStart || 0;

    if (!start || !responseStart) {
      return 0;
    }

    return responseStart - start;
  }

  function detectDeviceType() {
    var ua = navigator.userAgent || '';
    if (/Mobi|Android/i.test(ua)) {
      return 'mobile';
    }
    if (/Tablet|iPad/i.test(ua)) {
      return 'tablet';
    }
    return 'desktop';
  }

  function detectBrowser() {
    var ua = navigator.userAgent || '';
    if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) {
      return 'Chrome';
    }
    if (/Edg\//.test(ua)) {
      return 'Edge';
    }
    if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) {
      return 'Safari';
    }
    if (/Firefox\//.test(ua)) {
      return 'Firefox';
    }
    return 'Other';
  }

  function unique(list) {
    var seen = Object.create(null);
    var result = [];
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      if (!item || seen[item]) {
        continue;
      }
      seen[item] = true;
      result.push(item);
      if (result.length >= 20) {
        break;
      }
    }
    return result;
  }

  function collectQueryKeys() {
    try {
      var url = new URL(window.location.href);
      var keys = [];
      url.searchParams.forEach(function (value, key) {
        if (key) {
          keys.push(key);
        }
      });
      return unique(keys);
    } catch (e) {
      return [];
    }
  }

  function collectCookieNames() {
    var all = document.cookie ? document.cookie.split(';') : [];
    var names = [];
    for (var i = 0; i < all.length; i++) {
      var parts = all[i].split('=');
      if (!parts.length) {
        continue;
      }
      var name = parts[0].trim();
      if (name) {
        names.push(name);
      }
    }
    return unique(names);
  }

  function sendMeasurement(entry) {
    if (sent) {
      return;
    }

    var ttfb = calculateTtfb(entry);
    if (!ttfb || ttfb <= (settings.warningThreshold || 800)) {
      return;
    }

    sent = true;

    var payload = {
      ttfb: Math.round(ttfb),
      url: window.location.href,
      timestamp: new Date().toISOString(),
      queryParamKeys: collectQueryKeys(),
      cookieNames: collectCookieNames(),
      deviceType: detectDeviceType(),
      browser: detectBrowser(),
      referrer: document.referrer || '',
    };

    window.fetch(settings.restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Log-High-Ttfb-Nonce': settings.nonce,
        'X-WP-Nonce': settings.restNonce || '',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    }).catch(function () {
      // Swallow network errors; logging is best-effort.
    });
  }

  function handleNavigationEntries(list) {
    if (!list || !list.getEntries) {
      return;
    }
    var entries = list.getEntries();
    if (entries && entries.length) {
      sendMeasurement(entries[0]);
    }
  }

  if (window.PerformanceObserver && window.PerformanceObserver.supportedEntryTypes && window.PerformanceObserver.supportedEntryTypes.indexOf('navigation') !== -1) {
    try {
      var observer = new window.PerformanceObserver(handleNavigationEntries);
      observer.observe({ type: 'navigation', buffered: true });
    } catch (e) {
      // Fall back to onload below.
      window.addEventListener('load', function () {
        sendMeasurement(getNavigationEntry());
      });
    }
  } else {
    window.addEventListener('load', function () {
      sendMeasurement(getNavigationEntry());
    });
  }
})();
