# Contributing

Thanks for your interest in the SmartlyQ PHP SDK!

## How this repo works

Most of this SDK is **generated** from the [SmartlyQ OpenAPI spec](https://docs.smartlyq.com):

- `src/Resources/` - one class per API tag, emitted by `scripts/generate-client.php`. Never edit by hand.
- `src/SmartlyQ.php` - the client facade, emitted by `scripts/generate-client.php`. Never edit by hand.
- `tests/EndpointsGeneratedTest.php` - endpoint tests, emitted by `scripts/generate-tests.php`. Never edit by hand.
- The README's API Reference section is emitted by `scripts/generate-readme.php`.

Hand-written code lives in `src/CoreClient.php`, `src/SmartlyQError.php`, `scripts/`, and `tests/CoreClientTest.php`. Fixes to generated output belong in the generator scripts, or in the OpenAPI spec itself.

```bash
composer install
composer generate           # regenerate from openapi.json
vendor/bin/phpunit
```

## Never commit secrets

This is a **public** repository. Never commit real API keys (`sqk_live_...` / `sqk_test_...`), credentials, tokens, internal hostnames, or customer data. Use placeholders like `sqk_live_xxxxxxxxxxxx` or `YOUR_API_KEY` in examples.

Enable the local pre-commit scan once per clone:

```bash
git config core.hooksPath .githooks
```

CI also runs a gitleaks scan on every push and pull request. If you believe a secret has been exposed, rotate it immediately in your Developer Dashboard.
