/* Depersp: "Raddrizza le foto" checkbox in every WordPress uploader.
 * The choice is saved as a per-user preference on the server (AJAX), so it
 * applies to any uploader: Media > Add New, Media Library, "Add media"
 * window, block editor. The checkbox can appear several times in a page
 * (it is also part of the media modal template): all copies are kept in sync.
 */
(function () {
  'use strict';
  var cfg = window.deperspSettings;
  if (!cfg) { return; }

  function boxes() { return document.querySelectorAll('.depersp-toggle'); }

  function setAll(checked, disabled) {
    boxes().forEach(function (b) { b.checked = checked; b.disabled = !!disabled; });
    // Media > Add New (multi-file uploader): also send the field with the upload.
    if (window.uploader && window.uploader.settings) {
      window.uploader.settings.multipart_params = window.uploader.settings.multipart_params || {};
      window.uploader.settings.multipart_params[cfg.field] = checked ? '1' : '';
    }
  }

  document.addEventListener('change', function (ev) {
    var box = ev.target;
    if (!box.classList || !box.classList.contains('depersp-toggle')) { return; }
    var checked = box.checked;
    setAll(checked, true);

    var body = new URLSearchParams({ action: 'depersp_set_pref', nonce: cfg.nonce, enabled: checked ? '1' : '0' });
    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) { throw new Error('save failed'); }
        setAll(checked, false);
      })
      .catch(function () {
        setAll(!checked, false);
        window.alert('Depersp: impossibile salvare la preferenza. Ricaricate la pagina e riprovate.');
      });
  });
})();
