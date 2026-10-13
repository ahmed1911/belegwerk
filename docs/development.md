# Development

Requirements:

- PHP 8.2 or newer with the extensions listed in the [README](../README.md#installation), and Composer
- Docker, for Gotenberg and veraPDF from [`compose.yaml`](../compose.yaml) (the veraPDF image is `linux/amd64` only)
- Java, only for the KoSIT validator in the `schematron` tests

```sh
composer install
docker compose up -d
composer test
```

## Running tests

`composer test` runs `phpunit` (configured in `phpunit.xml.dist`; warnings and risky tests fail the run). Some tests
need services and skip themselves if they are not reachable:

| Variable | Service | Default | Without the service |
| --- | --- | --- | --- |
| `GOTENBERG_URL` | Gotenberg, for the rendering tests in `BelegwerkTest` | `http://127.0.0.1:3000` | Skipped |
| `VERAPDF_URL` | veraPDF REST API, for the PDF/A-3b check | `http://127.0.0.1:3001` | Skipped |
| `KOSIT_URL` | A running KoSIT validator daemon, for the `schematron` tests | not set | The tests start Java and download the validator |

The tests for the normalizer, the XML builder, the reader and the Twig sandbox need no service and always run.

The `schematron` group (`SchematronTest`) validates the [tested cases](validation.md#tested-with) with the KoSIT
validator. Without `KOSIT_URL` it uses horstoeko's `ZugferdKositValidator`, which needs Java, downloads the validator
and the XRechnung configuration from GitHub and caches them in the system temp directory. If the validator cannot run
(no Java, no network), the tests are skipped. With `KOSIT_URL` set, a daemon that is unreachable fails the tests. How
to start a daemon: [Validating e-invoices](validation.md#what-needs-the-kosit-validator).

Useful variations:

```sh
vendor/bin/phpunit --exclude-group schematron   # everything except the KoSIT tests
vendor/bin/phpunit --group schematron           # only the KoSIT tests
vendor/bin/phpunit --fail-on-skipped            # fail if a service was missing, as in CI
```

## CI

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) has two jobs. `unit` runs the tests without services on
every supported PHP version (the oldest also with the lowest dependency versions), skipping the `schematron` group.
`integration` starts Gotenberg, veraPDF and a KoSIT daemon and runs the complete suite with `--fail-on-skipped`, so
nothing may be skipped.
