/*
 * "Verifying your browser" (server side: api/lib/proof.php).
 *
 * ResokProof.fetch(url, options) behaves like fetch(). When the server answers 428 with
 * powRequired, it fetches a puzzle, solves it in a background worker (about a second) and
 * sends the request again with the solution in the X-Resok-Proof header. When the check is
 * switched off on the server, nothing extra happens.
 *
 * The solver finds a number n such that sha256(challenge + ":" + n) starts with `bits` zero
 * bits. SHA-256 is written out here so it runs synchronously in a worker: crypto.subtle is
 * asynchronous per hash, which makes a search of a few hundred thousand hashes far slower.
 */
(function () {
  'use strict';
  if (window.ResokProof) return;

  var script = document.currentScript;
  var CHALLENGE_URL = new URL('../api/index.php?route=proof/challenge', script ? script.src : location.href).href;

  // Runs in the worker (and on the page as a fallback). Self-contained: no outside names.
  function solver(challenge, bits) {
    var K = [0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
      0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
      0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
      0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
      0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
      0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
      0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
      0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2];
    var W = new Int32Array(64);
    function firstWord(msg) {
      // msg is ASCII. Returns the first 32 bits of sha256(msg).
      var len = msg.length, total = ((len + 9 + 63) >> 6) << 6;
      var bytes = new Uint8Array(total);
      for (var i = 0; i < len; i++) bytes[i] = msg.charCodeAt(i);
      bytes[len] = 0x80;
      var bitLen = len * 8;
      bytes[total - 4] = bitLen >>> 24; bytes[total - 3] = bitLen >>> 16; bytes[total - 2] = bitLen >>> 8; bytes[total - 1] = bitLen;
      var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a, h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
      for (var off = 0; off < total; off += 64) {
        for (var t = 0; t < 16; t++) {
          var j = off + t * 4;
          W[t] = (bytes[j] << 24) | (bytes[j + 1] << 16) | (bytes[j + 2] << 8) | bytes[j + 3];
        }
        for (t = 16; t < 64; t++) {
          var x = W[t - 15], y = W[t - 2];
          var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
          var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
          W[t] = (W[t - 16] + s0 + W[t - 7] + s1) | 0;
        }
        var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
        for (t = 0; t < 64; t++) {
          var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
          var t1 = (h + S1 + ((e & f) ^ (~e & g)) + K[t] + W[t]) | 0;
          var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
          var t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
          h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
        }
        h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
        h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
      }
      return h0 >>> 0;
    }
    // bits is at most 24 on the server, so the first word is enough to check it.
    var limit = Math.pow(2, 32 - bits);
    var prefix = challenge + ':';
    for (var n = 0; n < 1e9; n++) {
      if (firstWord(prefix + n) < limit) return String(n);
    }
    return null;
  }

  function solveInWorker(challenge, bits) {
    return new Promise(function (resolve, reject) {
      var worker;
      try {
        var src = 'var solver = ' + solver.toString() + ';\nonmessage = function (e) { postMessage(solver(e.data[0], e.data[1])); };';
        worker = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
      } catch (e) {
        // Workers unavailable (old browser, strict settings): solve on the page instead.
        setTimeout(function () { resolve(solver(challenge, bits)); }, 0);
        return;
      }
      var timer = setTimeout(function () { worker.terminate(); reject(new Error('Verification took too long. Please try again.')); }, 60000);
      worker.onmessage = function (e) { clearTimeout(timer); worker.terminate(); resolve(e.data); };
      worker.onerror = function () { clearTimeout(timer); worker.terminate(); resolve(solver(challenge, bits)); };
      worker.postMessage([challenge, bits]);
    });
  }

  function showVerifying(on) {
    var id = 'resok-proof-note';
    var el = document.getElementById(id);
    if (!on) { if (el) el.remove(); return; }
    if (el) return;
    el = document.createElement('div');
    el.id = id;
    el.setAttribute('role', 'status');
    el.textContent = 'Verifying your browser…';
    el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;background:#087539;color:#fff;'
      + 'padding:10px 18px;border-radius:999px;font:600 14px/1.3 system-ui,Segoe UI,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.2)';
    document.body.appendChild(el);
  }

  function solution() {
    return fetch(CHALLENGE_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (c) {
        if (!c || !c.enabled || !c.challenge) return null;
        return solveInWorker(c.challenge, c.bits).then(function (n) { return n === null ? null : c.challenge + '|' + n; });
      });
  }

  function proofFetch(url, options) {
    options = options || {};
    return fetch(url, options).then(function (response) {
      if (response.status !== 428) return response;
      return response.clone().json().catch(function () { return null; }).then(function (data) {
        if (!data || !data.powRequired) return response;
        showVerifying(true);
        return solution().then(function (proof) {
          if (!proof) return response;
          var headers = new Headers(options.headers || {});
          headers.set('X-Resok-Proof', proof);
          return fetch(url, Object.assign({}, options, { headers: headers }));
        }).finally(function () { showVerifying(false); });
      });
    });
  }

  window.ResokProof = { fetch: proofFetch, _solve: solver };
})();
