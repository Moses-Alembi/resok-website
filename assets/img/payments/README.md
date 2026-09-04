# Payment method logos

Drop official logo files here and reference them from the `comingSoon` list in
`resok-portal/public/api/index.php` by adding a `logo` key to the entry:

    ['name' => 'Pesapal', 'note' => '...', 'logo' => 'assets/img/payments/pesapal.svg']

A `logo` takes precedence over the Font Awesome glyph and the wordmark fallback, so the
tile switches to the real mark with no other change. SVG is preferred; the tile renders it
at up to 32px tall and 110px wide.

Take the files from each brand's own press kit rather than a search result - the marks are
trademarks, and their owners publish exact artwork and usage rules:

- Visa: usa.visa.com brand centre
- Mastercard: brand.mastercard.com
- Apple Pay: developer.apple.com/apple-pay/marketing (usage is governed by Apple's
  guidelines, which require you to actually accept Apple Pay before displaying the mark)
- Pesapal: pesapal.com/pesapal-logos-pantone
- M-Pesa / Safaricom: request from Safaricom

Until then the tiles use Font Awesome brand glyphs tinted with each brand's colour, and
plain wordmarks for M-Pesa and Pesapal, which Font Awesome does not carry.
