(function () {
  'use strict';

  var config = window.wpncNewsletterFrontend || {};
  var messages = config.messages || {};
  var hcaptchaFallbackRequested = false;
  var openCaptchaForm = null;

  function captchaModalMode(form) {
    var box = captchaBox(form);
    return !!(box && (box.getAttribute('data-mode') || '') === 'modal');
  }

  function captchaTheme() {
    var root = document.documentElement;
    var body = document.body;
    return (root && root.classList.contains('is-dark-theme')) ||
      (body && body.classList.contains('is-dark-theme'))
      ? 'dark'
      : 'light';
  }

  function modalCaptchaSize() {
    return window.innerWidth && window.innerWidth < 360 ? 'compact' : 'normal';
  }

  function ensureCaptchaModal(form) {
    if (form.wpncCaptchaModal && document.body.contains(form.wpncCaptchaModal)) {
      return form.wpncCaptchaModal;
    }

    var originalHost = form.querySelector('[data-wpnc-hcaptcha][data-sitekey], .wp-newslatter-campaigns-captcha [data-sitekey]');
    if (!originalHost) return null;

    form.wpncHcaptchaHost = originalHost;

    var modal = document.createElement('div');
    var modalId = 'wpnc-hcaptcha-modal-' + Math.random().toString(36).slice(2, 10);
    var titleId = modalId + '-title';
    var helpId = modalId + '-help';
    modal.className = 'wpnc-hcaptcha-modal';
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="wpnc-hcaptcha-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '" aria-describedby="' + helpId + '">' +
        '<button type="button" class="wpnc-hcaptcha-modal__close" aria-label="' + escapeHtml(messages.captchaCancel || 'Close verification') + '">&times;</button>' +
        '<div class="wpnc-hcaptcha-modal__heading">' +
          '<h2 id="' + titleId + '">' + escapeHtml(messages.captchaTitle || 'Verify you are human') + '</h2>' +
          '<p id="' + helpId + '">' + escapeHtml(messages.captchaHelp || 'Complete the hCaptcha check to subscribe.') + '</p>' +
        '</div>' +
        '<div class="wpnc-hcaptcha-modal__widget"></div>' +
        '<div class="wpnc-hcaptcha-modal__status" role="status" aria-live="polite"></div>' +
      '</div>';

    var widgetWrap = modal.querySelector('.wpnc-hcaptcha-modal__widget');
    widgetWrap.appendChild(originalHost);
    document.body.appendChild(modal);
    form.wpncCaptchaModal = modal;

    var closeButton = modal.querySelector('.wpnc-hcaptcha-modal__close');
    closeButton.addEventListener('click', function () {
      closeCaptchaModal(form, true);
    });
    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeCaptchaModal(form, true);
    });
    modal.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeCaptchaModal(form, true);
      }
    });

    return modal;
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
  }

  function setCaptchaModalStatus(form, text) {
    var modal = form && form.wpncCaptchaModal;
    if (!modal) return;
    var status = modal.querySelector('.wpnc-hcaptcha-modal__status');
    if (status) status.textContent = text || '';
  }

  function openCaptchaModalForForm(form) {
    var modal = ensureCaptchaModal(form);
    if (!modal) return false;

    if (openCaptchaForm && openCaptchaForm !== form) {
      closeCaptchaModal(openCaptchaForm, false);
    }

    openCaptchaForm = form;
    form.wpncCaptchaPreviousFocus = document.activeElement;
    setCaptchaModalStatus(form, '');
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('wpnc-hcaptcha-modal-open');
    if (document.body) document.body.classList.add('wpnc-hcaptcha-modal-open');

    window.requestAnimationFrame(function () {
      var closeButton = modal.querySelector('.wpnc-hcaptcha-modal__close');
      if (closeButton) closeButton.focus();
    });
    return true;
  }

  function closeCaptchaModal(form, cancelled) {
    if (!form || !form.wpncCaptchaModal) return;
    var modal = form.wpncCaptchaModal;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('wpnc-hcaptcha-modal-open');
    if (document.body) document.body.classList.remove('wpnc-hcaptcha-modal-open');
    if (openCaptchaForm === form) openCaptchaForm = null;

    if (cancelled) {
      resetCaptcha(form);
      setBusy(form, false);
      setCaptchaModalStatus(form, '');
    }

    var previous = form.wpncCaptchaPreviousFocus;
    if (previous && typeof previous.focus === 'function') {
      try { previous.focus({ preventScroll: true }); } catch (e) { try { previous.focus(); } catch (ignore) {} }
    }
    form.wpncCaptchaPreviousFocus = null;
  }

  function allForms() {
    return Array.prototype.slice.call(
      document.querySelectorAll('form.wp-newslatter-campaigns-form, form.wordpress-signup')
    ).filter(function (form) {
      return form.querySelector('[name="_wp_newslatter_campaigns_nonce"]') ||
        form.querySelector('[name="action"][value="wp_newslatter_campaigns_subscribe"]') ||
        form.getAttribute('data-wp-newslatter-campaigns-ajax') === '1';
    });
  }

  function isNewsletterForm(form) {
    return !!(form && form.matches && allForms().indexOf(form) !== -1);
  }

  function findEmail(form) {
    return form.querySelector('input[type="email"], input[name="email"]');
  }

  function setMessage(form, text, state) {
    var el = form.querySelector('.wp-newslatter-campaigns-message');
    if (!el) {
      el = document.createElement('div');
      el.className = 'wp-newslatter-campaigns-message';
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('role', 'status');
      form.appendChild(el);
    }
    el.textContent = text || '';
    el.dataset.state = state || '';
    el.hidden = !text;
    form.classList.toggle('has-newsletter-message', !!text);
    if (text) el.removeAttribute('hidden');
  }

  function setBusy(form, busy) {
    form.classList.toggle('is-submitting', !!busy);
    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn) return;
    btn.disabled = !!busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  function focusIfPossible(control) {
    if (!control) return;
    try {
      if (typeof control.scrollIntoView === 'function') {
        control.scrollIntoView({ block: 'center', inline: 'nearest' });
      }
      if (typeof control.focus === 'function' && control.offsetParent !== null) {
        control.focus({ preventScroll: true });
      }
    } catch (e) {}
  }

  function hiddenInput(form, name) {
    var input = form.querySelector('[name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    return input;
  }

  function setCaptchaToken(form, token) {
    hiddenInput(form, 'wpnc_hcaptcha_response').value = token || '';
    hiddenInput(form, 'h-captcha-response').value = token || '';
  }

  function clearCaptchaToken(form) {
    var inputs = form.querySelectorAll('[name="wpnc_hcaptcha_response"], [name="h-captcha-response"]');
    Array.prototype.forEach.call(inputs, function (input) { input.value = ''; });
  }

  function captchaBox(form) {
    if (form && form.wpncHcaptchaHost) return form.wpncHcaptchaHost;
    return form.querySelector('[data-wpnc-hcaptcha][data-sitekey], .wp-newslatter-campaigns-captcha [data-sitekey]');
  }

  function captchaSiteKey(form) {
    var box = captchaBox(form);
    return box ? (box.getAttribute('data-sitekey') || '') : '';
  }

  function captchaSize(form) {
    var box = captchaBox(form);
    if (box && (box.getAttribute('data-mode') || '') === 'modal') return modalCaptchaSize();
    return box ? (box.getAttribute('data-size') || 'invisible') : 'invisible';
  }

  function widgetId(form) {
    var id = form.dataset.wpncHcaptchaWidget;
    if (id === undefined || id === '') return null;
    return id;
  }

  function replaceCaptchaBox(form, box) {
    var host = document.createElement('div');
    host.setAttribute('data-wpnc-hcaptcha', '1');
    host.setAttribute('data-sitekey', captchaSiteKey(form));
    host.setAttribute('data-size', captchaSize(form));
    if (captchaModalMode(form)) host.setAttribute('data-mode', 'modal');
    if (box && box.parentNode) box.parentNode.replaceChild(host, box);
    form.wpncHcaptchaHost = host;
    delete form.dataset.wpncHcaptchaWidget;
    delete form.dataset.wpncHcaptchaRendered;
    return host;
  }

  function resetCaptcha(form) {
    var id = widgetId(form);
    if (window.hcaptcha && id !== null) {
      try { window.hcaptcha.reset(id); } catch (e) {}
    }
    clearCaptchaToken(form);
    delete form.dataset.wpncCaptchaPending;
    delete form.dataset.wpncCaptchaSubmitting;
  }

  function newsletterHCaptchaUsesOnload() {
    if (hcaptchaFallbackRequested) return true;
    return !!document.querySelector(
      'script[src*="js.hcaptcha.com/1/api.js"][src*="onload=wpncNewsletterCaptchaReady"]'
    );
  }

  function hcaptchaIsReady() {
    if (!window.hcaptcha || typeof window.hcaptcha.render !== 'function') return false;

    // hCaptcha exposes window.hcaptcha before its explicit API is fully ready.
    // When our loader uses an onload callback, never call render() until that
    // callback has fired; doing so triggers hCaptcha's "should not render before
    // js api is fully loaded" warning and can produce an unusable widget/token.
    if (newsletterHCaptchaUsesOnload()) {
      return window.wpncNewsletterHCaptchaLoaded === true;
    }

    if (config.deferHCaptchaToWsForm) {
      return window.wsf_hcaptcha_loaded === true || window.wpncNewsletterHCaptchaLoaded === true;
    }
    return true;
  }

  function renderHCaptchaForForm(form) {
    if (!hcaptchaIsReady()) return false;
    if (!captchaSiteKey(form)) return false;
    if (form.dataset.wpncHcaptchaRendered === '1' && widgetId(form) !== null) return true;

    var host = captchaBox(form);
    if (!host) return false;
    var options = {
      sitekey: captchaSiteKey(form),
      size: captchaSize(form),
      theme: captchaTheme(),
      callback: function (token) {
        if (!isNewsletterForm(form)) return;
        setCaptchaToken(form, token || '');
        delete form.dataset.wpncCaptchaPending;
        setCaptchaModalStatus(form, '');
        closeCaptchaModal(form, false);
        if (form.dataset.wpncCaptchaSubmitting === '1') return;
        form.dataset.wpncCaptchaSubmitting = '1';
        submitAjax(form);
      },
      'error-callback': function () {
        delete form.dataset.wpncCaptchaPending;
        delete form.dataset.wpncCaptchaSubmitting;
        clearCaptchaToken(form);
        setBusy(form, false);
        var errorText = messages.captcha || 'Please complete the captcha check.';
        setCaptchaModalStatus(form, errorText);
        setMessage(form, errorText, 'error');
      },
      'expired-callback': function () {
        resetCaptcha(form);
        setBusy(form, false);
        setCaptchaModalStatus(form, messages.captchaExpired || 'The verification expired. Please try again.');
      },
      'close-callback': function () {
        delete form.dataset.wpncCaptchaPending;
        if (!hiddenInput(form, 'wpnc_hcaptcha_response').value) {
          setBusy(form, false);
          setCaptchaModalStatus(form, messages.captcha || 'Please complete the captcha check.');
        }
      }
    };

    try {
      var id = window.hcaptcha.render(host, options);
      form.dataset.wpncHcaptchaWidget = String(id);
      form.dataset.wpncHcaptchaRendered = '1';
      return true;
    } catch (e) {
      try {
        var freshHost = replaceCaptchaBox(form, host);
        var freshId = window.hcaptcha.render(freshHost, options);
        form.dataset.wpncHcaptchaWidget = String(freshId);
        form.dataset.wpncHcaptchaRendered = '1';
        return true;
      } catch (secondError) {
        delete form.dataset.wpncHcaptchaWidget;
        delete form.dataset.wpncHcaptchaRendered;
        return false;
      }
    }
  }

  function renderHCaptchas() {
    allForms().forEach(function (form) {
      if (form.querySelector('[name="wpbb_captcha_provider"][value="hcaptcha"]')) {
        if (captchaModalMode(form)) ensureCaptchaModal(form);
        else renderHCaptchaForForm(form);
      }
    });
  }

  function hasNewsletterHCaptcha() {
    return allForms().some(function (form) {
      return !!form.querySelector('[name="wpbb_captcha_provider"][value="hcaptcha"]');
    });
  }

  function hasWsFormHCaptcha() {
    return !!document.querySelector('[data-hcaptcha]');
  }

  function wsFormConfigHasHCaptcha() {
    var forms = window.wsf_form_json;
    if (!forms || typeof forms !== 'object') return false;

    for (var formKey in forms) {
      if (!Object.prototype.hasOwnProperty.call(forms, formKey)) continue;
      var groups = forms[formKey] && forms[formKey].groups;
      if (!Array.isArray(groups)) continue;

      for (var groupIndex = 0; groupIndex < groups.length; groupIndex += 1) {
        var sections = groups[groupIndex] && groups[groupIndex].sections;
        if (!Array.isArray(sections)) continue;

        for (var sectionIndex = 0; sectionIndex < sections.length; sectionIndex += 1) {
          var fields = sections[sectionIndex] && sections[sectionIndex].fields;
          if (!Array.isArray(fields)) continue;

          for (var fieldIndex = 0; fieldIndex < fields.length; fieldIndex += 1) {
            if (fields[fieldIndex] && fields[fieldIndex].type === 'hcaptcha') return true;
          }
        }
      }
    }
    return false;
  }

  function wsFormIsStillRendering() {
    return Array.prototype.some.call(document.querySelectorAll('form.wsf-form'), function (form) {
      return form.childElementCount === 0;
    });
  }

  function syncWsFormHCaptchaReady() {
    if (!config.deferHCaptchaToWsForm || !window.wpncNewsletterHCaptchaLoaded || !window.hcaptcha) return;
    if (document.getElementById('wsf-hcaptcha-script-head')) {
      window.wsf_hcaptcha_loaded = true;
    }
  }

  function requestDeferredHCaptcha() {
    if (!config.deferHCaptchaToWsForm || hcaptchaFallbackRequested || window.hcaptcha) return;
    if (document.readyState === 'loading' || !hasNewsletterHCaptcha()) return;
    if (hasWsFormHCaptcha() || wsFormConfigHasHCaptcha() || wsFormIsStillRendering()) return;
    if (document.querySelector('script[src*="js.hcaptcha.com/1/api.js"]')) return;

    hcaptchaFallbackRequested = true;
    window.wpncNewsletterCaptchaReady = function () {
      window.wpncNewsletterHCaptchaLoaded = true;
      syncWsFormHCaptchaReady();
      renderHCaptchas();
    };

    var script = document.createElement('script');
    script.src = 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=wpncNewsletterCaptchaReady&recaptchacompat=off';
    script.async = true;
    script.defer = true;
    document.body.appendChild(script);
  }

  window.wpncNewsletterRenderHCaptchas = renderHCaptchas;
  if (window.wpncNewsletterHCaptchaLoaded || hcaptchaIsReady()) {
    window.setTimeout(renderHCaptchas, 0);
  }

  function validate(form) {
    form.setAttribute('novalidate', 'novalidate');
    form.noValidate = true;

    var email = findEmail(form);
    if (email && (!email.value || (email.checkValidity && !email.checkValidity()))) {
      focusIfPossible(email);
      setMessage(form, messages.invalid || 'Please enter a valid email address.', 'error');
      return false;
    }

    var privacy = form.querySelector('input[name="privacy_consent"][required]');
    if (privacy && !privacy.checked) {
      focusIfPossible(privacy);
      setMessage(form, messages.privacy || 'Please accept the privacy consent.', 'error');
      return false;
    }

    if (!form.querySelector('[name="_wp_newslatter_campaigns_nonce"]')) {
      setMessage(form, messages.notConnected || 'Newsletter form is not connected. Please refresh the page and try again.', 'error');
      return false;
    }
    return true;
  }

  function submitAjax(form) {
    if (!validate(form)) {
      setBusy(form, false);
      delete form.dataset.wpncCaptchaSubmitting;
      return;
    }

    var data = new FormData(form);
    data.set('action', 'wp_newslatter_campaigns_ajax_subscribe');
    setBusy(form, true);

    fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
      .then(function (res) {
        return res.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { return { success: false, data: { message: messages.error || 'Please try again.' } }; }
        });
      })
      .then(function (payload) {
        var ok = !!(payload && payload.success);
        var text = payload && payload.data && payload.data.message
          ? payload.data.message
          : (ok ? messages.success : messages.error);
        setMessage(form, text || '', ok ? 'success' : 'error');
        if (ok) form.reset();
      })
      .catch(function () {
        setMessage(form, messages.error || 'Please try again.', 'error');
      })
      .finally(function () {
        resetCaptcha(form);
        setBusy(form, false);
      });
  }

  function executeHCaptcha(form) {
    if (!window.hcaptcha || typeof window.hcaptcha.render !== 'function') return false;

    if (captchaModalMode(form)) {
      if (!openCaptchaModalForForm(form)) return false;
      if (!renderHCaptchaForForm(form)) {
        closeCaptchaModal(form, false);
        return false;
      }
      form.dataset.wpncCaptchaPending = '1';
      delete form.dataset.wpncCaptchaSubmitting;
      clearCaptchaToken(form);
      setBusy(form, true);
      return true;
    }

    if (typeof window.hcaptcha.execute !== 'function') return false;
    if (!renderHCaptchaForForm(form)) return false;

    var id = widgetId(form);
    if (id === null) return false;
    form.dataset.wpncCaptchaPending = '1';
    delete form.dataset.wpncCaptchaSubmitting;
    clearCaptchaToken(form);
    setBusy(form, true);

    try {
      var result = window.hcaptcha.execute(id, { async: true });
      if (result && typeof result.then === 'function') {
        result.then(function (response) {
          var token = typeof response === 'string'
            ? response
            : (response && typeof response.response === 'string' ? response.response : '');
          if (!token) return;
          setCaptchaToken(form, token);
          delete form.dataset.wpncCaptchaPending;
          if (form.dataset.wpncCaptchaSubmitting === '1') return;
          form.dataset.wpncCaptchaSubmitting = '1';
          submitAjax(form);
        }).catch(function () {
          delete form.dataset.wpncCaptchaPending;
          delete form.dataset.wpncCaptchaSubmitting;
          setBusy(form, false);
          setMessage(form, messages.captcha || 'Please complete the captcha check.', 'error');
        });
      }
      return true;
    } catch (e) {
      delete form.dataset.wpncCaptchaPending;
      delete form.dataset.wpncCaptchaSubmitting;
      setBusy(form, false);
      return false;
    }
  }

  function executeHCaptchaWhenReady(form, attempt) {
    if (!isNewsletterForm(form)) return;
    if (executeHCaptcha(form)) {
      delete form.dataset.wpncCaptchaWaiting;
      return;
    }

    attempt = attempt || 0;
    if (attempt >= 40) {
      delete form.dataset.wpncCaptchaWaiting;
      setBusy(form, false);
      setMessage(form, messages.captcha || 'Please complete the captcha check.', 'error');
      return;
    }

    form.dataset.wpncCaptchaWaiting = '1';
    setBusy(form, true);
    window.setTimeout(function () {
      syncWsFormHCaptchaReady();
      executeHCaptchaWhenReady(form, attempt + 1);
    }, 250);
  }

  function handleSubmit(event) {
    var form = event.target;
    if (!isNewsletterForm(form)) return;

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    setMessage(form, '', '');

    if (!validate(form)) return;

    var provider = form.querySelector('[name="wpbb_captcha_provider"]');
    if (provider && provider.value === 'hcaptcha') {
      var token = form.querySelector('[name="wpnc_hcaptcha_response"]');
      if (!token || !token.value) {
        executeHCaptchaWhenReady(form, 0);
        return;
      }
    }

    submitAjax(form);
  }

  function init() {
    allForms().forEach(function (form) {
      form.setAttribute('data-wp-newslatter-campaigns-ajax', '1');
      form.setAttribute('novalidate', 'novalidate');
      form.noValidate = true;
      hiddenInput(form, 'wpnc_hcaptcha_response');
      if (form.querySelector('[name="wpbb_captcha_provider"][value="hcaptcha"]') && captchaModalMode(form)) {
        ensureCaptchaModal(form);
      }
      if (!form.querySelector('.wp-newslatter-campaigns-message')) {
        var message = document.createElement('div');
        message.className = 'wp-newslatter-campaigns-message';
        message.setAttribute('aria-live', 'polite');
        message.setAttribute('role', 'status');
        message.hidden = true;
        form.appendChild(message);
      }
    });
  }

  document.addEventListener('submit', handleSubmit, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }

  var tries = 0;
  var timer = window.setInterval(function () {
    tries += 1;
    init();
    syncWsFormHCaptchaReady();
    if (hcaptchaIsReady()) renderHCaptchas();
    else requestDeferredHCaptcha();
    if (tries > 40 || (hcaptchaIsReady() && allForms().every(function (form) {
      return !form.querySelector('[name="wpbb_captcha_provider"][value="hcaptcha"]') || form.dataset.wpncHcaptchaRendered;
    }))) {
      window.clearInterval(timer);
    }
  }, 250);
}());
