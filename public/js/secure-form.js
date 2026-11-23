/* Sign-in forms: the e-mail and password are encrypted in the browser and posted as ONE hidden field (`enc`), never as readable form fields.
   A random AES-256-GCM key encrypts {f: fields, n: nonce}; the key is wrapped with the VOB public key (RSA-OAEP, SHA-1).
   The server (App\Services\Security\ClientEncryption) opens it. This is in addition to HTTPS, not instead of it. No inline script (CSP).
   Two ways to do the same thing, with the same output:
     - WebCrypto (every current browser, needs HTTPS or localhost);
     - node-forge (pure JS, public/js/vendor/forge-*.min.js, loaded only when WebCrypto is missing: Internet Explorer 11 and very old browsers).
   The public key in the page is meant to be public: it can only encrypt, never decrypt.
   The form carries <input type="hidden" name="enc" data-secure-key="<base64 SPKI>" data-secure-nonce="<one-time nonce>" data-secure-forge="<url>">;
   the fields to protect are named in data-secure-fields (default: email,password). Without that hidden input the form posts as usual.
   Written in ES5 on purpose (Internet Explorer 11 has no arrow functions, fetch, NodeList.forEach or TypedArray.forEach). */
(function () {
  'use strict';

  function each(list, fn) { Array.prototype.forEach.call(list, fn); }

  function b64(bytes) {
    var view = new Uint8Array(bytes), s = '';
    for (var i = 0; i < view.length; i++) s += String.fromCharCode(view[i]);
    return btoa(s);
  }
  function unb64(text) {
    var s = atob(text), out = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
    return out;
  }
  function b64url(text) { return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }

  // ---- WebCrypto ---------------------------------------------------------------------------------------------------------
  function encryptWebCrypto(publicKeyB64, payload) {
    var subtle = window.crypto.subtle;
    var iv = window.crypto.getRandomValues(new Uint8Array(12));
    return Promise.all([
      subtle.importKey('spki', unb64(publicKeyB64), { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['encrypt']),
      subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt'])
    ]).then(function (keys) {
      return Promise.all([
        subtle.encrypt({ name: 'AES-GCM', iv: iv }, keys[1], new TextEncoder().encode(JSON.stringify(payload))),
        subtle.exportKey('raw', keys[1]).then(function (raw) { return subtle.encrypt({ name: 'RSA-OAEP' }, keys[0], raw); })
      ]);
    }).then(function (parts) {
      // WebCrypto returns ciphertext || 16-byte tag, which is what the server expects as `ct`
      return b64url(JSON.stringify({ ek: b64(parts[1]), iv: b64(iv), ct: b64(parts[0]) }));
    });
  }

  // ---- node-forge (no WebCrypto) -----------------------------------------------------------------------------------------
  function encryptForge(forge, publicKeyB64, payload) {
    var publicKey = forge.pki.publicKeyFromAsn1(forge.asn1.fromDer(forge.util.decode64(publicKeyB64)));
    var aesKey = forge.random.getBytesSync(32);
    var iv = forge.random.getBytesSync(12);

    var cipher = forge.cipher.createCipher('AES-GCM', aesKey);
    cipher.start({ iv: iv, tagLength: 128 });
    cipher.update(forge.util.createBuffer(forge.util.encodeUtf8(JSON.stringify(payload))));
    cipher.finish();
    var ct = cipher.output.getBytes() + cipher.mode.tag.getBytes(); // ciphertext || tag, same as WebCrypto

    var wrapped = publicKey.encrypt(aesKey, 'RSA-OAEP'); // SHA-1 + MGF1-SHA-1: what PHP's openssl expects
    return b64url(JSON.stringify({ ek: forge.util.encode64(wrapped), iv: forge.util.encode64(iv), ct: forge.util.encode64(ct) }));
  }

  function loadForge(src, done, failed) {
    if (window.forge) { done(window.forge); return; }
    var script = document.createElement('script');
    script.src = src;
    script.onload = function () { window.forge ? done(window.forge) : failed(); };
    script.onerror = failed;
    document.head.appendChild(script);
  }

  /** encrypt(...) -> calls ok(enc) or failed() */
  function encrypt(hidden, payload, ok, failed) {
    var key = hidden.getAttribute('data-secure-key');
    var subtle = window.crypto && window.crypto.subtle;

    if (subtle && window.Promise && window.TextEncoder) {
      encryptWebCrypto(key, payload).then(ok, failed);
      return;
    }
    var forgeSrc = hidden.getAttribute('data-secure-forge');
    if (!forgeSrc) { failed(); return; }
    loadForge(forgeSrc, function (forge) {
      var enc;
      try { enc = encryptForge(forge, key, payload); } catch (e) { failed(); return; }
      ok(enc);
    }, failed);
  }

  each(document.querySelectorAll('input[type="hidden"][data-secure-key]'), function (hidden) {
    var form = hidden.form;
    if (!form) return;
    var names = (hidden.getAttribute('data-secure-fields') || 'email,password').split(',');
    var fields = [];
    each(names, function (n) { if (form.elements[n]) fields.push(form.elements[n]); });
    var submit = form.querySelector('[type="submit"]');
    var notice = form.querySelector('[data-secure-error]');
    var sending = false;

    function fail(text) {
      sending = false;
      if (submit) submit.disabled = false;
      each(fields, function (f) { f.disabled = false; });
      if (notice) { notice.textContent = text; notice.hidden = !text; }
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (sending) return;
      sending = true;
      if (submit) submit.disabled = true;
      if (notice) notice.hidden = true;

      var values = {};
      each(fields, function (f) { values[f.name] = f.value; });

      encrypt(hidden, { f: values, n: hidden.getAttribute('data-secure-nonce') }, function (enc) {
        hidden.value = enc;
        each(fields, function (f) { f.disabled = true; }); // disabled fields are not posted: only `enc` (and the CSRF token) leaves the browser
        form.submit();
      }, function () {
        fail('We could not securely sign-in using this browser. Please update your browser and try again.');
      });
    });

    // Back / forward cache: bring the form back to a usable state
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) { hidden.value = ''; fail(''); }
    });
  });
})();
