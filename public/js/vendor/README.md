# Third-party scripts (self-hosted, because the CSP only allows our own origin)

| File | What | Version | Source | SHA-256 |
|---|---|---|---|---|
| forge-1.3.1.min.js | node-forge (BSD-3-Clause / GPL-2.0 dual licence), pure-JS RSA-OAEP + AES-GCM. Loaded by `js/secure-form.js` only when the browser has no WebCrypto (Internet Explorer 11) | 1.3.1 | https://cdn.jsdelivr.net/npm/node-forge@1.3.1/dist/forge.min.js (identical bytes on cdnjs) | dc67fd132427ad96c9666c844b39565413c40ddb1f2d063c53512fbf6d387dfd |

Update by downloading the new pinned version from two sources, comparing the hashes, and updating this table.
