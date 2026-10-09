(function () {
  'use strict';

  var destination = '/tableros/index';
  var transitionKey = 'tableros-transition-to';
  var mascotPath = '/_assets/images/mascota-agujita.webp';
  var fallbackMascotPath = '/_assets/images/mascota-agujita.png';
  var script = document.currentScript;
  var transitionStylesheet = script && script.getAttribute('data-transition-stylesheet');
  var root = document.documentElement;
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var supportsTransitions = !reducedMotion && window.CSS && typeof CSS.supports === 'function' && CSS.supports('view-transition-name: tableros-mascot') && typeof document.startViewTransition === 'function';
  var mascotImage = null;
  var mascotReady = null;
  var loadedMascotPath = mascotPath;
  var incomingMascotPath = mascotPath;
  var transitionStylesheetReady = null;
  var navigationPending = false;
  var outgoingStylesheet = null;
  var outgoingMascot = null;
  var incoming = false;

  try {
    var marker = sessionStorage.getItem(transitionKey);
    if (marker !== null) {
      sessionStorage.removeItem(transitionKey);
      var markerParts = marker.split('|');
      var timestamp = Number(markerParts[0]);
      incomingMascotPath = markerParts[1] === 'png' ? fallbackMascotPath : mascotPath;
      incoming = window.location.pathname.replace(/\/$/, '') === destination && Number.isFinite(timestamp) && Date.now() - timestamp >= 0 && Date.now() - timestamp < 8000;
    }
  } catch (error) {
    // The page still loads normally when storage is unavailable.
  }
  if (incoming) root.classList.add('tableros-transition-incoming');

  function preloadMascot() {
    if (mascotReady || !supportsTransitions) return mascotReady;
    function decodeMascot(path) {
      return new Promise(function (resolve, reject) {
        var image = new Image();
        image.decoding = 'async';
        image.onload = function () { mascotImage = image; loadedMascotPath = path; resolve(image); };
        image.onerror = reject;
        image.src = path;
        if (typeof image.decode === 'function') {
          image.decode().then(function () { mascotImage = image; loadedMascotPath = path; resolve(image); }, reject);
        }
      });
    }
    mascotReady = decodeMascot(mascotPath).catch(function () {
      loadedMascotPath = fallbackMascotPath;
      return decodeMascot(fallbackMascotPath);
    });
    mascotReady.catch(function () { /* The click path falls back to ordinary navigation. */ });
    return mascotReady;
  }

  function navigateWithFallback(url) {
    window.location.assign(url);
  }

  function loadTransitionStylesheet() {
    if (transitionStylesheetReady) return transitionStylesheetReady;
    transitionStylesheetReady = new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = transitionStylesheet || '/_assets/css/tableros_transition.css';
      link.onload = function () { resolve(link); };
      link.onerror = reject;
      document.head.appendChild(link);
    });
    return transitionStylesheetReady;
  }

  function appendOutgoingMascot(image, stylesheet) {
    outgoingStylesheet = stylesheet;
    outgoingMascot = image;
    outgoingMascot.className = 'tableros-transition-mascot';
    outgoingMascot.alt = '';
    outgoingMascot.setAttribute('aria-hidden', 'true');
    document.body.appendChild(outgoingMascot);
  }

  function cleanupOutgoing() {
    if (outgoingMascot) outgoingMascot.remove();
    if (outgoingStylesheet) outgoingStylesheet.remove();
    outgoingMascot = null;
    outgoingStylesheet = null;
  }

  function isTablerosLink(link) {
    if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download') || !link.closest('.app-launcher-menu')) return false;
    try {
      var target = new URL(link.href, window.location.href);
      return target.origin === window.location.origin && target.pathname.replace(/\/$/, '') === destination && window.location.pathname.replace(/\/$/, '') !== destination;
    } catch (error) {
      return false;
    }
  }

  if (incoming) {
    if (!supportsTransitions) {
      root.classList.remove('tableros-transition-incoming');
      return;
    }

    function activateIncomingMascot() {
      var image = document.getElementById('tablerosTransitionMascot');
      if (!image) return false;
      if (!image.getAttribute('src')) image.src = incomingMascotPath || image.getAttribute('data-transition-src') || mascotPath;
      image.hidden = false;
      return true;
    }

    var incomingObserver = null;
    if (!activateIncomingMascot()) {
      incomingObserver = new MutationObserver(function () {
        if (activateIncomingMascot()) incomingObserver.disconnect();
      });
      incomingObserver.observe(document.documentElement, { childList: true, subtree: true });
    }
    window.setTimeout(function () {
      if (incomingObserver) incomingObserver.disconnect();
      var image = document.getElementById('tablerosTransitionMascot');
      if (image) image.remove();
      root.classList.remove('tableros-transition-incoming');
    }, 1200);
    return;
  }

  if (!supportsTransitions) return;

  window.addEventListener('pageswap', function (event) {
    if (!navigationPending || !outgoingStylesheet || !outgoingMascot) return;
    if (event.viewTransition && event.viewTransition.ready) {
      event.viewTransition.ready.then(cleanupOutgoing, cleanupOutgoing);
    } else {
      cleanupOutgoing();
    }
  });

  // Warm only when the user points to or focuses the Tableros app tile.
  document.addEventListener('pointerover', function (event) {
    var link = event.target.closest && event.target.closest('a[href]');
    if (isTablerosLink(link)) preloadMascot();
  }, { passive: true });
  document.addEventListener('focusin', function (event) {
    var link = event.target.closest && event.target.closest('a[href]');
    if (isTablerosLink(link)) preloadMascot();
  });
  document.addEventListener('touchstart', function (event) {
    var link = event.target.closest && event.target.closest('a[href]');
    if (isTablerosLink(link)) preloadMascot();
  }, { passive: true });

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var link = event.target.closest && event.target.closest('a[href]');
    if (!isTablerosLink(link)) return;
    if (navigationPending) {
      event.preventDefault();
      return;
    }

    navigationPending = true;
    event.preventDefault();
    var targetUrl = link.href;
    var ready = preloadMascot();
    var stylesReady = loadTransitionStylesheet();
    var timeoutId;
    var timeout = new Promise(function (_, reject) {
      timeoutId = window.setTimeout(function () { reject(new Error('Mascot decode timed out')); }, 2000);
    });

    Promise.race([Promise.all([ready, stylesReady]), timeout]).then(function (prepared) {
      window.clearTimeout(timeoutId);
      var image = prepared[0];
      var stylesheet = prepared[1];
      try {
        var marker = String(Date.now()) + '|' + (loadedMascotPath === fallbackMascotPath ? 'png' : 'webp');
        sessionStorage.setItem(transitionKey, marker);
        window.setTimeout(function () {
          try {
            if (sessionStorage.getItem(transitionKey) === marker) sessionStorage.removeItem(transitionKey);
          } catch (error) { /* The destination also expires and consumes the marker. */ }
        }, 8000);
      } catch (error) {
        navigateWithFallback(targetUrl);
        return;
      }

      appendOutgoingMascot(image, stylesheet);
      navigateWithFallback(targetUrl);
    }).catch(function () {
      window.clearTimeout(timeoutId);
      // A slow or unavailable image should not hold up the native destination.
      navigateWithFallback(targetUrl);
    });
  });
}());
