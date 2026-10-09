(function () {
  'use strict';

  var destination = '/tableros/index';
  var transitionKey = 'tableros-transition-to';
  var mascotPath = '/_assets/images/mascota-agujita.png';
  var script = document.currentScript;
  var transitionStylesheet = script && script.getAttribute('data-transition-stylesheet');
  var root = document.documentElement;
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var supportsTransitions = !reducedMotion && window.CSS && typeof CSS.supports === 'function' && CSS.supports('view-transition-name: tableros-mascot') && typeof document.startViewTransition === 'function';
  var mascotImage = null;
  var mascotReady = null;
  var transitionStylesheetReady = null;
  var navigationPending = false;
  var outgoingStylesheet = null;
  var outgoingMascot = null;

  function preloadMascot() {
    if (mascotReady || !supportsTransitions) return mascotReady;
    mascotImage = new Image();
    mascotImage.decoding = 'async';
    mascotImage.src = mascotPath;
    mascotReady = typeof mascotImage.decode === 'function'
      ? mascotImage.decode().then(function () { return mascotImage; })
      : new Promise(function (resolve, reject) {
          mascotImage.onload = function () { resolve(mascotImage); };
          mascotImage.onerror = reject;
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

  if (root.classList.contains('tableros-transition-incoming')) {
    var incomingMascot = document.getElementById('tablerosTransitionMascot');
    if (!supportsTransitions || !document.body) {
      root.classList.remove('tableros-transition-incoming');
      if (incomingMascot) incomingMascot.remove();
      return;
    }

    if (!incomingMascot) {
      incomingMascot = document.createElement('img');
      incomingMascot.className = 'tableros-transition-mascot';
      incomingMascot.src = mascotPath;
      incomingMascot.alt = '';
      incomingMascot.setAttribute('aria-hidden', 'true');
      document.body.appendChild(incomingMascot);
    }
    window.setTimeout(function () {
      incomingMascot.remove();
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
      timeoutId = window.setTimeout(function () { reject(new Error('Mascot decode timed out')); }, 1200);
    });

    Promise.race([Promise.all([ready, stylesReady]), timeout]).then(function (prepared) {
      window.clearTimeout(timeoutId);
      var image = prepared[0];
      var stylesheet = prepared[1];
      try {
        var marker = String(Date.now());
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
