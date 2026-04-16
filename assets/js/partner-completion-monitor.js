(function () {
  if (window.console && typeof window.console.log === 'function') {
    window.console.log('[SOS DEBUG] MONITOR VERSION 2026-04-09-2');
  }

  if (window.__sosPgPartnerCompletionMonitorLoaded) {
    return;
  }
  window.__sosPgPartnerCompletionMonitorLoaded = true;

  var config = window.SOSPGCompletionMonitor || {};
  var popupPartnerWordpressFlow = config.popupPartnerWordpressFlow === true;
  var cafPartnerFlow = String(config.partnerId || '').toLowerCase() === 'caf';
  if (window.console && typeof window.console.log === 'function') {
    window.console.log('[SOSPG completion] NEW RETURN PATCH LOADED HARD', {
      patchMarker: String(config.patchMarker || ''),
      scriptVersion: String(config.scriptVersion || ''),
      completionReturnUrlPresent: !!config.completionReturnUrl,
      partnerReturnUrlPresent: !!config.partnerReturnUrl,
      popupPartnerWordpressFlow: popupPartnerWordpressFlow
    });
    window.console.log('[SOSPG completion] NEW RETURN PATCH LOADED', {
      patchMarker: String(config.patchMarker || ''),
      scriptVersion: String(config.scriptVersion || ''),
      completionReturnUrlPresent: !!config.completionReturnUrl,
      partnerReturnUrlPresent: !!config.partnerReturnUrl,
      popupPartnerWordpressFlow: popupPartnerWordpressFlow
    });
    window.console.log('[SOSPG completion] completionReturnUrl value', String(config.completionReturnUrl || ''));
  }
  if (!config.completionUrlEndpoint) {
    return;
  }

  var state = {
    bookingId: '',
    bookingSource: '',
    completionUiDetected: false,
    completionTriggered: false,
    completionRequested: false,
    finalRedirectStarted: false,
    completionFlowScheduled: false,
    successDetectedAt: 0,
    successLogged: false,
    completionUrlRequestedLogged: false,
    completionRedirectLogged: false,
    fallbackLogged: false,
    bookingSourceLogged: {},
  };

  function logEvent(key, message, details) {
    if (!window.console || typeof window.console.log !== 'function' || state[key]) {
      return;
    }
    state[key] = true;
    if (typeof details !== 'undefined') {
      window.console.log('[SOSPG completion] ' + message, details);
      return;
    }
    window.console.log('[SOSPG completion] ' + message);
  }

  function logStep(message, details) {
    if (!window.console || typeof window.console.log !== 'function') {
      return;
    }
    if (typeof details !== 'undefined') {
      window.console.log('[SOSPG completion] ' + message, details);
      return;
    }
    window.console.log('[SOSPG completion] ' + message);
  }

  function hasConfirmedBookingId() {
    return !!normalizeBookingId(state.bookingId);
  }

  function hasStrongBookingId() {
    return bookingSourcePriority(state.bookingSource) >= 3 && !!normalizeBookingId(state.bookingId);
  }

  function getStrongBookingId() {
    if (!hasStrongBookingId()) {
      return '';
    }
    return normalizeBookingId(state.bookingId);
  }

  function canAttemptAutoRedirect() {
    if (!state.completionUiDetected || !hasConfirmedBookingId()) {
      logStep('auto redirect skipped because completion not confirmed', {
        completionUiDetected: state.completionUiDetected,
        hasConfirmedBookingId: hasConfirmedBookingId(),
        bookingId: state.bookingId || ''
      });
      return false;
    }

    return true;
  }

  function normalizeBookingId(value) {
    var stringValue = String(typeof value === 'undefined' || value === null ? '' : value).trim();
    if (!/^\d+$/.test(stringValue)) {
      return '';
    }
    return String(parseInt(stringValue, 10));
  }

  function bookingSourcePriority(source) {
    switch (String(source || '')) {
      case 'fetch':
      case 'xhr':
        return 3;
      case 'dom':
        return 1;
      default:
        return 0;
    }
  }

  function rememberBookingId(value, source) {
    var normalized = normalizeBookingId(value);
    var existing = normalizeBookingId(state.bookingId);
    var existingSource = String(state.bookingSource || '');
    var incomingSource = String(source || '');
    if (!normalized) {
      return '';
    }

    if (existing && bookingSourcePriority(existingSource) > bookingSourcePriority(incomingSource)) {
      if (incomingSource === 'dom' && typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
        if (cafPartnerFlow) {
          window.console.log('[SOS DEBUG] CAF weak booking_id refused:', normalized);
        } else {
          window.console.log('[SOS DEBUG] weak booking_id detected from DOM/query:', normalized);
        }
      }
      logStep('booking_id overwrite skipped', {
        existingBookingId: existing,
        existingSource: existingSource,
        incomingBookingId: normalized,
        incomingSource: incomingSource
      });
      return existing;
    }

    if (incomingSource === 'dom' && typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
      if (cafPartnerFlow) {
        window.console.log('[SOS DEBUG] CAF weak booking_id refused:', normalized);
        return existing && bookingSourcePriority(existingSource) >= 3 ? existing : '';
      } else {
        window.console.log('[SOS DEBUG] weak booking_id detected from DOM/query:', normalized);
      }
    }

    state.bookingId = normalized;
    if (incomingSource) {
      state.bookingSource = incomingSource;
    }
    if ((incomingSource === 'fetch' || incomingSource === 'xhr') && typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
      if (cafPartnerFlow) {
        window.console.log('[SOS DEBUG] CAF strong booking_id used:', normalized);
      } else {
        window.console.log('[SOS DEBUG] strong booking_id detected from fetch/xhr:', normalized);
      }
    }
    if (source && !state.bookingSourceLogged[source]) {
      state.bookingSourceLogged[source] = true;
      logEvent('bookingIdFrom' + source, 'booking_id trovato da ' + source, normalized);
    }
    if (state.completionUiDetected && !state.completionFlowScheduled) {
      state.completionFlowScheduled = true;
      logStep('completion detected', { bookingId: normalized, source: source || '' });
      window.setTimeout(function () {
        triggerCompletionFlow();
      }, 0);
    }
    return normalized;
  }

  function extractBookingIdFromString(value) {
    var stringValue = String(typeof value === 'undefined' || value === null ? '' : value);
    if (!stringValue) {
      return '';
    }

    var direct = normalizeBookingId(stringValue);
    if (direct) {
      return direct;
    }

    var patterns = [
      /(?:booking_id|bookingId|data-booking-id|booking["'\]\s:=_-]{1,12}id)[^\d]{0,20}(\d{1,12})/i,
      /(?:"booking"\s*:\s*\{[^{}]{0,200}?"id"\s*:\s*)(\d{1,12})/i,
      /(?:'booking'\s*:\s*\{[^{}]{0,200}?'id'\s*:\s*)(\d{1,12})/i,
      /(?:booking[^\d]{0,30})(\d{1,12})/i
    ];

    for (var index = 0; index < patterns.length; index += 1) {
      var match = stringValue.match(patterns[index]);
      if (match && match[1]) {
        return normalizeBookingId(match[1]);
      }
    }

    return '';
  }

  function searchBookingId(value, depth) {
    if (depth > 8 || typeof value === 'undefined' || value === null) {
      return '';
    }

    if (typeof value === 'number') {
      return normalizeBookingId(value);
    }

    if (typeof value === 'string') {
      return extractBookingIdFromString(value);
    }

    if (Array.isArray(value)) {
      for (var arrayIndex = 0; arrayIndex < value.length; arrayIndex += 1) {
        var arrayResult = searchBookingId(value[arrayIndex], depth + 1);
        if (arrayResult) {
          return arrayResult;
        }
      }
      return '';
    }

    if (typeof value === 'object') {
      if (Object.prototype.hasOwnProperty.call(value, 'booking_id')) {
        var objectDirect = normalizeBookingId(value.booking_id);
        if (objectDirect) {
          return objectDirect;
        }
      }

      if (Object.prototype.hasOwnProperty.call(value, 'bookingId')) {
        var camelDirect = normalizeBookingId(value.bookingId);
        if (camelDirect) {
          return camelDirect;
        }
      }

      if (Object.prototype.hasOwnProperty.call(value, 'id') && (Object.prototype.hasOwnProperty.call(value, 'booking') || Object.prototype.hasOwnProperty.call(value, 'booking_id') || Object.prototype.hasOwnProperty.call(value, 'bookingId'))) {
        var objectId = normalizeBookingId(value.id);
        if (objectId) {
          return objectId;
        }
      }

      if (value.booking && typeof value.booking === 'object') {
        var nestedBooking = searchBookingId(value.booking, depth + 1);
        if (nestedBooking) {
          return nestedBooking;
        }
      }

      var keys = Object.keys(value);
      for (var keyIndex = 0; keyIndex < keys.length; keyIndex += 1) {
        var nestedResult = searchBookingId(value[keys[keyIndex]], depth + 1);
        if (nestedResult) {
          return nestedResult;
        }
      }
    }

    return '';
  }

  function inspectPayloadForBookingId(payload, source) {
    var bookingId = '';
    if (typeof payload === 'string') {
      try {
        bookingId = searchBookingId(JSON.parse(payload), 0);
      } catch (error) {
        bookingId = searchBookingId(payload, 0);
      }
    } else {
      bookingId = searchBookingId(payload, 0);
    }

    if (bookingId) {
      rememberBookingId(bookingId, source);
    }

    return bookingId;
  }

  function extractBookingIdFromElement(element) {
    if (!element) {
      return '';
    }

    var attributeNames = element.getAttributeNames ? element.getAttributeNames() : [];
    for (var attributeIndex = 0; attributeIndex < attributeNames.length; attributeIndex += 1) {
      var attributeName = attributeNames[attributeIndex];
      var attributeValue = element.getAttribute(attributeName);
      var lowerName = String(attributeName || '').toLowerCase();
      if (lowerName.indexOf('booking') !== -1 || lowerName === 'href' || lowerName === 'data-route' || lowerName === 'data-url' || lowerName === 'data-step-data' || lowerName === 'data-summary' || lowerName === 'data-lp-response') {
        var bookingId = extractBookingIdFromString(attributeValue);
        if (bookingId) {
          return bookingId;
        }

        if (attributeValue && (attributeValue.charAt(0) === '{' || attributeValue.charAt(0) === '[')) {
          bookingId = inspectPayloadForBookingId(attributeValue, 'dom');
          if (bookingId) {
            return bookingId;
          }
        }
      }
    }

    if (element.dataset) {
      var datasetKeys = Object.keys(element.dataset);
      for (var datasetIndex = 0; datasetIndex < datasetKeys.length; datasetIndex += 1) {
        var datasetValue = element.dataset[datasetKeys[datasetIndex]];
        var datasetBookingId = extractBookingIdFromString(datasetValue);
        if (datasetBookingId) {
          return datasetBookingId;
        }
        datasetBookingId = inspectPayloadForBookingId(datasetValue, 'dom');
        if (datasetBookingId) {
          return datasetBookingId;
        }
      }
    }

    return '';
  }

  function extractBookingIdFromDom() {
    var selectors = [
      '[data-booking-id]',
      '[data-latepoint-booking-id]',
      '[data-booking_id]',
      'input[name="booking_id"]',
      'input[name="booking[id]"]',
      'input[data-booking-id]',
      '.latepoint-booking-confirmation',
      '.os-booking-confirmation',
      '.os-step-confirmation-w',
      '.latepoint-step-confirmation-w'
    ];

    for (var index = 0; index < selectors.length; index += 1) {
      var element = document.querySelector(selectors[index]);
      if (!element) {
        continue;
      }

      var explicitValue = element.getAttribute('data-booking-id')
        || element.getAttribute('data-latepoint-booking-id')
        || element.getAttribute('data-booking_id')
        || element.value
        || '';
      var explicitBookingId = rememberBookingId(explicitValue, 'dom');
      if (explicitBookingId) {
        return explicitBookingId;
      }

      var elementBookingId = extractBookingIdFromElement(element);
      if (elementBookingId) {
        return rememberBookingId(elementBookingId, 'dom');
      }
    }

    var broadElements = document.querySelectorAll('[data-booking-id], [data-latepoint-booking-id], [data-booking_id], [href*="booking"], [data-route*="booking"], [data-url*="booking"], [data-step-data], [data-summary], [data-lp-response], .latepoint-booking-confirmation *, .os-booking-confirmation *, .os-step-confirmation-w *, .latepoint-step-confirmation-w *');
    for (var broadIndex = 0; broadIndex < broadElements.length; broadIndex += 1) {
      var broadBookingId = extractBookingIdFromElement(broadElements[broadIndex]);
      if (broadBookingId) {
        return rememberBookingId(broadBookingId, 'dom');
      }
    }

    var inlineScripts = document.querySelectorAll('script:not([src])');
    for (var scriptIndex = 0; scriptIndex < inlineScripts.length; scriptIndex += 1) {
      var scriptBookingId = inspectPayloadForBookingId(inlineScripts[scriptIndex].textContent || '', 'dom');
      if (scriptBookingId) {
        return scriptBookingId;
      }
    }

    return state.bookingId;
  }

  function isLikelyLatePointRequest(url) {
    var stringUrl = String(url || '');
    return stringUrl.indexOf('admin-ajax.php') !== -1 || stringUrl.indexOf('route_name=') !== -1;
  }

  function installXhrHook() {
    if (!window.XMLHttpRequest) {
      return;
    }

    var originalOpen = window.XMLHttpRequest.prototype.open;
    var originalSend = window.XMLHttpRequest.prototype.send;

    window.XMLHttpRequest.prototype.open = function (method, url) {
      this.__sosPgRequestUrl = String(url || '');
      return originalOpen.apply(this, arguments);
    };

    window.XMLHttpRequest.prototype.send = function () {
      this.addEventListener('load', function () {
        var requestUrl = this.__sosPgRequestUrl || '';
        if (!isLikelyLatePointRequest(requestUrl)) {
          return;
        }
        if (requestUrl.indexOf('steps__reload_booking_form_summary_panel') !== -1) {
          return;
        }
        inspectPayloadForBookingId(this.responseText || '', 'xhr');
      });

      return originalSend.apply(this, arguments);
    };
  }

  function installFetchHook() {
    if (typeof window.fetch !== 'function') {
      return;
    }

    var originalFetch = window.fetch;
    window.fetch = function () {
      var requestUrl = arguments[0];
      var url = typeof requestUrl === 'string' ? requestUrl : (requestUrl && requestUrl.url) || '';

      return originalFetch.apply(window, arguments).then(function (response) {
        if (isLikelyLatePointRequest(url)) {
          response.clone().text().then(function (text) {
            inspectPayloadForBookingId(text, 'fetch');
          }).catch(function () {});
        }
        return response;
      });
    };
  }

  function findSuccessElementByText() {
    var candidates = document.querySelectorAll('.latepoint-booking-form-element, .latepoint-w, .latepoint-book-form-wrapper');
    var textPattern = /(appuntamento\s+prenotato|prenotazione\s+completata|booking\s+confirmed|appointment\s+confirmed)/i;

    for (var index = 0; index < candidates.length; index += 1) {
      var candidate = candidates[index];
      var text = candidate && candidate.innerText ? String(candidate.innerText) : '';
      if (text && textPattern.test(text)) {
        return candidate;
      }
    }

    return null;
  }

  function getSuccessElement() {
    return document.querySelector('.latepoint-booking-confirmation, .os-booking-confirmation, .os-step-confirmation-w, .latepoint-step-confirmation-w') || findSuccessElementByText();
  }

  function hasAppointmentConfirmedText() {
    if (!document.body) {
      return false;
    }

    var text = document.body.innerText || '';
    return /(appointment\s+confirmed|booking\s+confirmed|appuntamento\s+prenotato|prenotazione\s+completata)/i.test(text);
  }

  function getOpenerOrigin() {
    if (typeof config.openerOrigin === 'string' && config.openerOrigin) {
      return config.openerOrigin;
    }

    if (!document.referrer) {
      return '';
    }

    try {
      return new URL(document.referrer).origin;
    } catch (error) {
      return '';
    }
  }

  function buildCompletionPageFallbackUrl(bookingId) {
    var strongBookingId = getStrongBookingId();
    if (typeof config.completionPageUrl !== 'string' || !config.completionPageUrl) {
      return '';
    }

    if (!strongBookingId) {
      if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
        window.console.warn(cafPartnerFlow ? '[SOS DEBUG] CAF fallback blocked: missing strong booking_id' : '[SOS DEBUG] Fallback blocked: missing strong booking_id', {
          requestedBookingId: bookingId || '',
          currentBookingId: state.bookingId || '',
          bookingSource: state.bookingSource || ''
        });
      }
      logStep('abort final redirect because strong booking_id missing', {
        requestedBookingId: bookingId || '',
        currentBookingId: state.bookingId || '',
        bookingSource: state.bookingSource || ''
      });
      return '';
    }

    try {
      var fallbackUrl = new URL(String(config.completionPageUrl), window.location.origin);
      fallbackUrl.searchParams.set('booking_id', String(strongBookingId));
      if (typeof config.partnerId === 'string' && config.partnerId) {
        fallbackUrl.searchParams.set('partner_id', String(config.partnerId));
      }

      var openerOrigin = getOpenerOrigin();
      if (openerOrigin) {
        fallbackUrl.searchParams.set('opener_origin', openerOrigin);
      }

      fallbackUrl.searchParams.set('source', 'latepoint_success_monitor_fallback');
      if (typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
        if (cafPartnerFlow) {
          window.console.log('[SOS DEBUG] CAF strong booking_id used:', strongBookingId);
        }
        window.console.log('[SOS DEBUG] final booking_id selected for fallback:', strongBookingId);
      }
      return fallbackUrl.toString();
    } catch (error) {
      return '';
    }
  }

  function resolveFinalReturnTarget() {
    if (typeof config.completionReturnUrl === 'string' && config.completionReturnUrl) {
      return {
        url: String(config.completionReturnUrl),
        source: 'completionReturnUrl'
      };
    }

    if (typeof config.partnerReturnUrl === 'string' && config.partnerReturnUrl) {
      return {
        url: String(config.partnerReturnUrl),
        source: 'partnerReturnUrl'
      };
    }

    return {
      url: '',
      source: 'none'
    };
  }

  function getFinalReturnUrl() {
    return resolveFinalReturnTarget().url;
  }

  function buildCompletionMessagePayload(bookingId) {
    var returnTarget = resolveFinalReturnTarget();
    return {
      type: 'sos_partner_login_complete',
      legacyType: 'sos_pg_completion',
      state: 'booking_completed',
      bookingId: bookingId || '',
      booking_id: bookingId || '',
      partnerId: String(config.partnerId || ''),
      partner_id: String(config.partnerId || ''),
      source: String(config.source || 'latepoint_success_monitor'),
      returnUrl: returnTarget.url || ''
    };
  }

  function ensureFallbackMessage() {
    var existing = document.getElementById('sos-pg-completion-fallback');
    var host = getSuccessElement() || document.body;
    if (!host) {
      return null;
    }

    var box = existing || document.createElement('div');
    var text = document.getElementById('sos-pg-completion-fallback-text');
    var actions = document.getElementById('sos-pg-completion-fallback-actions');
    var link = document.getElementById('sos-pg-completion-fallback-link');
    var returnTarget = resolveFinalReturnTarget();

    if (!existing) {
      box.id = 'sos-pg-completion-fallback';
      box.style.margin = '16px 0';
      box.style.padding = '14px 16px';
      box.style.border = '1px solid #cbd5e1';
      box.style.borderRadius = '10px';
      box.style.background = '#f8fafc';
      box.style.color = '#1f2937';
      box.style.fontSize = '14px';
      box.style.lineHeight = '1.5';

      text = document.createElement('p');
      text.id = 'sos-pg-completion-fallback-text';
      text.style.margin = '0';
      box.appendChild(text);

      actions = document.createElement('p');
      actions.id = 'sos-pg-completion-fallback-actions';
      actions.style.margin = '10px 0 0';
      box.appendChild(actions);

      link = document.createElement('a');
      link.id = 'sos-pg-completion-fallback-link';
      link.textContent = 'Torna al sito';
      link.style.display = 'inline-block';
      link.style.padding = '10px 14px';
      link.style.borderRadius = '8px';
      link.style.background = '#0f766e';
      link.style.color = '#ffffff';
      link.style.fontWeight = '600';
      link.style.textDecoration = 'none';
      actions.appendChild(link);
    }

    text.textContent = 'Prenotazione completata. Se il ritorno automatico non parte, usa il pulsante qui sotto.';

    if (returnTarget.url) {
      link.href = returnTarget.url;
      link.style.visibility = 'visible';
      link.style.pointerEvents = 'auto';
      logStep('fallback button href set', {
        href: returnTarget.url,
        source: returnTarget.source
      });
    } else {
      link.removeAttribute('href');
      link.style.visibility = 'hidden';
      link.style.pointerEvents = 'none';
      logStep('fallback button href set', {
        href: '',
        source: returnTarget.source
      });
    }

    if (!existing) {
      if (host === document.body) {
        host.insertBefore(box, host.firstChild);
      } else if (host.parentNode) {
        host.parentNode.insertBefore(box, host);
      }
    }

    logStep('fallback UI rendered', {
      source: returnTarget.source,
      href: returnTarget.url
    });

    return box;
  }

  function redirectToFinalReturn(trigger, details) {
    var returnTarget = resolveFinalReturnTarget();
    var finalReturnUrl = returnTarget.url;
    logStep('final return url resolved', {
      trigger: trigger,
      finalReturnUrl: finalReturnUrl,
      completionReturnUrl: String(config.completionReturnUrl || ''),
      partnerReturnUrl: String(config.partnerReturnUrl || ''),
      source: returnTarget.source
    });

    if (!canAttemptAutoRedirect()) {
      return false;
    }

    if (state.finalRedirectStarted || !finalReturnUrl) {
      logStep('redirect finale non avviato', {
        alreadyStarted: state.finalRedirectStarted,
        finalReturnUrlPresent: !!finalReturnUrl,
        trigger: trigger
      });
      return false;
    }

    state.finalRedirectStarted = true;
    ensureFallbackMessage();
    window.setTimeout(function () {
      var fallbackText = document.getElementById('sos-pg-completion-fallback-text');
      if (fallbackText) {
        fallbackText.textContent = 'Prenotazione completata. Se il ritorno automatico non parte, usa il pulsante per tornare al sito.';
      }
      ensureFallbackMessage();
      logStep('auto redirect failed or fallback retained', {
        returnUrl: finalReturnUrl,
        source: returnTarget.source
      });
    }, 1000);
    logEvent('completionRedirectLogged', 'redirect finale verso completion_return_url', {
      trigger: trigger,
      returnUrl: finalReturnUrl,
      details: details || null
    });

    logStep('redirect scheduled', {
      trigger: trigger,
      returnUrl: finalReturnUrl,
      details: details || null
    });

    try {
      logStep('auto redirect attempted', {
        returnUrl: finalReturnUrl,
        source: returnTarget.source,
        trigger: trigger
      });
      logStep('window.location.assign executed', finalReturnUrl);
      window.location.assign(finalReturnUrl);
    } catch (error) {
      logStep('window.location.assign failed', String(error && error.message ? error.message : error));
      logStep('auto redirect failed or fallback retained', {
        returnUrl: finalReturnUrl,
        source: returnTarget.source,
        reason: String(error && error.message ? error.message : error)
      });
      return false;
    }

    return true;
  }

  function fallbackClose(bookingId) {
    var finalBookingId = getStrongBookingId();
    logStep('fallbackClose entered', {
      bookingId: finalBookingId || '',
      finalReturnUrl: getFinalReturnUrl()
    });
    if (!finalBookingId || !hasStrongBookingId()) {
      if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
        window.console.warn(cafPartnerFlow ? '[SOS DEBUG] CAF fallback blocked: missing strong booking_id' : '[SOS DEBUG] Fallback blocked: missing strong booking_id', {
          requestedBookingId: bookingId || '',
          currentBookingId: state.bookingId || '',
          bookingSource: state.bookingSource || ''
        });
      }
      logStep('abort final redirect because strong booking_id missing', {
        requestedBookingId: bookingId || '',
        currentBookingId: state.bookingId || '',
        bookingSource: state.bookingSource || ''
      });
      return;
    }

    if (!popupPartnerWordpressFlow && canAttemptAutoRedirect() && redirectToFinalReturn('fallback_close', { bookingId: finalBookingId })) {
      return;
    }

    ensureFallbackMessage();

    var origin = getOpenerOrigin();
    var payload = buildCompletionMessagePayload(finalBookingId || '');
    var completionPageFallbackUrl = buildCompletionPageFallbackUrl(finalBookingId || '');
    logEvent('fallbackLogged', 'fallback completion attivato', { popup: !!window.opener, bookingId: finalBookingId || '' });

    try {
      if (window.opener && origin) {
        if (typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
          window.console.log('[SOS DEBUG] postMessage payload:', payload);
        }
        window.opener.postMessage(payload, origin);
      }
    } catch (error) {}

    try {
      if (window.opener) {
        window.close();
      }
    } catch (error) {}

    if (popupPartnerWordpressFlow && completionPageFallbackUrl) {
      logStep('popup fallback redirect to completion page', {
        bookingId: bookingId || '',
        completionPageFallbackUrl: completionPageFallbackUrl
      });
      try {
        window.location.assign(completionPageFallbackUrl);
        return;
      } catch (error) {
        logStep('popup fallback redirect failed', String(error && error.message ? error.message : error));
      }
    }

    if (!window.opener) {
      ensureFallbackMessage();
    }
  }

  function requestCompletionUrl(bookingId) {
    if (state.completionRequested) {
      logStep('completion_url request skipped', {
        bookingId: bookingId,
        reason: 'already_requested'
      });
      return Promise.resolve('');
    }

    state.completionRequested = true;
    logEvent('completionUrlRequestedLogged', 'richiesta completion_url signed', bookingId);

    var requestUrl = new URL(config.completionUrlEndpoint, window.location.origin);
    requestUrl.searchParams.set('booking_id', bookingId);
    requestUrl.searchParams.set('partner_id', String(config.partnerId || ''));
    requestUrl.searchParams.set('source', String(config.source || 'latepoint_success_monitor'));

    var openerOrigin = getOpenerOrigin();
    if (openerOrigin) {
      requestUrl.searchParams.set('opener_origin', openerOrigin);
    }

    logStep('requestCompletionUrl starting', {
      bookingId: bookingId,
      popupPartnerWordpressFlow: popupPartnerWordpressFlow,
      openerOriginPresent: !!openerOrigin
    });
    logStep('completion_url request start', {
      bookingId: bookingId,
      requestUrl: requestUrl.toString()
    });

    return window.fetch(requestUrl.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    }).then(function (response) {
      return response.json().catch(function () {
        logStep('completion_url response parse failed', {
          bookingId: bookingId,
          status: response.status
        });
        return null;
      }).then(function (data) {
        logStep('completion_url response received', {
          bookingId: bookingId,
          status: response.status,
          ok: response.ok,
          success: !!(data && data.success === true),
          completionUrlPresent: !!(data && data.data && data.data.completion_url),
          completionUrl: data && data.data && data.data.completion_url ? String(data.data.completion_url) : ''
        });
        if (!response.ok || !data || data.success !== true || !data.data || !data.data.completion_url) {
          return '';
        }
        return String(data.data.completion_url || '');
      });
    }).catch(function () {
      logStep('completion_url request failed', { bookingId: bookingId });
      return '';
    });
  }

  function triggerCompletionFlow() {
    if (state.completionTriggered) {
      logStep('completion detected but trigger skipped', { reason: 'already_triggered' });
      return;
    }

    if (!canAttemptAutoRedirect()) {
      return;
    }

    extractBookingIdFromDom();

    var bookingId = getStrongBookingId();
    if (!bookingId) {
      if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
        window.console.warn(cafPartnerFlow ? '[SOS DEBUG] CAF fallback blocked: missing strong booking_id' : '[SOS DEBUG] Fallback blocked: missing strong booking_id', {
          currentBookingId: state.bookingId || '',
          bookingSource: state.bookingSource || ''
        });
      }
      logStep('abort final redirect because strong booking_id missing', {
        currentBookingId: state.bookingId || '',
        bookingSource: state.bookingSource || ''
      });
      return;
    }

    if (typeof window.console !== 'undefined' && typeof window.console.log === 'function') {
      if (cafPartnerFlow) {
        window.console.log('[SOS DEBUG] CAF strong booking_id used:', bookingId);
      }
      window.console.log('[SOS DEBUG] final booking_id selected for fallback:', bookingId);
    }

    state.completionTriggered = true;
    requestCompletionUrl(bookingId).then(function (completionUrl) {
      if (completionUrl) {
        logStep('completion backend confirmed', {
          bookingId: bookingId,
          completionUrl: completionUrl
        });
        // Popup partner WordPress uses /partner-completion as the single return channel.
        if (popupPartnerWordpressFlow) {
          logEvent('completionRedirectPopupLogged', 'redirect popup flow verso partner-completion', completionUrl);
          try {
            window.location.assign(completionUrl);
            return;
          } catch (error) {
            logStep('popup completion redirect failed', String(error && error.message ? error.message : error));
            fallbackClose(bookingId);
            return;
          }
        }

        if (redirectToFinalReturn('monitor_confirmed', { bookingId: bookingId, completionUrl: completionUrl })) {
          return;
        }

        logStep('completionReturnUrl unavailable, fallback to legacy completion_url', {
          bookingId: bookingId,
          completionUrl: completionUrl
        });
        if (!canAttemptAutoRedirect()) {
          return;
        }
        logEvent('completionRedirectLegacyLogged', 'redirect finale verso partner-completion', completionUrl);
        window.location.assign(completionUrl);
        return;
      }

      logStep('completion backend not confirmed, fallback path', { bookingId: bookingId });

      if (popupPartnerWordpressFlow) {
        var completionPageFallbackUrl = buildCompletionPageFallbackUrl(bookingId);
        if (completionPageFallbackUrl) {
          logEvent('completionRedirectPopupFallbackLogged', 'redirect popup fallback verso partner-completion', completionPageFallbackUrl);
          try {
            window.location.assign(completionPageFallbackUrl);
            return;
          } catch (error) {
            logStep('popup fallback completion redirect failed', String(error && error.message ? error.message : error));
          }
        }
      }

      fallbackClose(bookingId);
    });
  }

  function monitorSuccessState() {
    if (state.completionTriggered) {
      return;
    }

    extractBookingIdFromDom();

    var successElement = getSuccessElement();
    var hasConfirmedText = hasAppointmentConfirmedText();

    if (!successElement && !hasConfirmedText) {
      return;
    }

    if (successElement) {
      logEvent('successUiMatchedBySelectorLogged', 'success ui matched by selector', successElement.className || successElement.tagName || 'unknown');
    }

    if (hasConfirmedText) {
      logEvent('successUiMatchedByTextLogged', 'success ui matched by text', 'appointment confirmed | booking confirmed | appuntamento prenotato | prenotazione completata');
    }

    state.completionUiDetected = true;
    ensureFallbackMessage();
    logEvent('successLogged', 'success step LatePoint rilevato');
    logStep('monitor loaded and success UI detected', {
      bookingId: state.bookingId || '',
      hasSuccessElement: !!successElement,
      hasConfirmedText: hasConfirmedText
    });

    if (!state.successDetectedAt) {
      state.successDetectedAt = Date.now();
    }

    if (state.bookingId) {
      if (!state.completionFlowScheduled) {
        state.completionFlowScheduled = true;
        logStep('completion detected', { bookingId: state.bookingId, source: 'success_ui' });
      }
      triggerCompletionFlow();
      return;
    }

    if ((Date.now() - state.successDetectedAt) > 8000) {
      state.completionTriggered = true;
      fallbackClose('');
    }
  }

  installXhrHook();
  installFetchHook();

  document.addEventListener('DOMContentLoaded', monitorSuccessState);
  window.setInterval(monitorSuccessState, 500);

  if (window.MutationObserver && document.documentElement) {
    var observer = new window.MutationObserver(function () {
      monitorSuccessState();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }
}());
