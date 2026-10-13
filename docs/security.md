# Security

Templates and incoming invoices are untrusted input in many applications. This page lists what belegwerk guarantees
and what stays your job.

## Gotenberg settings

Start Gotenberg with these flags (as in [`compose.yaml`](../compose.yaml)):

| Flag | Why |
| --- | --- |
| `--chromium-disable-javascript=true` | Scripts in templates do not run. |
| `--chromium-allow-list=^file:///tmp/.*` | Chromium only loads files from `/tmp` in the Gotenberg container, where Gotenberg puts the files belegwerk sends. Requests to other URLs (internet, internal network, other local files) are blocked without an error; the PDF is rendered with these resources left empty. |
| `--api-disable-download-from=true` | Gotenberg does not download files from URLs given in requests. |

Fonts and images go in as `data:` URIs, so no network access is needed. Without these flags, a template can run
JavaScript and make Chromium request internal URLs.

Without `--api-enable-basic-auth`, Gotenberg accepts every request. Keep it reachable only from your application, never
publicly; `compose.yaml` binds it to `127.0.0.1`. If other hosts can reach it, enable basic auth (credentials from
`GOTENBERG_API_BASIC_AUTH_USERNAME` and `GOTENBERG_API_BASIC_AUTH_PASSWORD`) and put the credentials into the URL,
percent-encoded with `rawurlencode()`: `new Belegwerk('http://user:password@gotenberg:3000')`.

## Templates

What the Twig sandbox guarantees:

- A template cannot run PHP code, call functions, call methods or read properties of objects, extend other templates or
  define macros. Only the tags and filters listed in [What templates may use](templates.md#what-templates-may-use) work.
  A violation throws a `Twig\Sandbox\SecurityError` before anything is rendered.
- `include` only loads `partials/<name>.twig` from the template itself. A partial name must match
  `partials/[a-zA-Z0-9_-]+.twig`, so paths like `../x` cannot be registered. Partials and sources are held in
  memory, belegwerk reads no other files while rendering.
- The `asset` filter only returns the template's own assets as `data:` URIs. Asset names are plain file names with one
  of the supported extensions. An unknown name throws an error.
- Template `defaults.json` reach the template only, never the e-invoice XML.
- Output is HTML-escaped automatically. `raw` is allowed, so a template author can write arbitrary HTML, but only
  within what the Gotenberg flags above permit.

What the sandbox does not limit:

- CPU time and memory. A template with huge loops can block a PHP process. Render with PHP's `max_execution_time` and
  `memory_limit` in place.
- Rendering time in Gotenberg. Limit it with Gotenberg's `--api-timeout` (default 30s). The `timeout` of the
  `Belegwerk` constructor (default 60s) only limits how long PHP waits.

## Reading untrusted invoices

`read()` is built for files from outside:

- XML is parsed without network access and without loading external entities or DTDs; libxml2's limits against
  entity expansion bombs stay active. Keep libxml2 up to date, the protection comes from the library.
- `read()` runs no external program and renders nothing. A large or crafted PDF can still use a lot of CPU and memory,
  so limit the upload size and run `read()` under `max_execution_time` and `memory_limit`.
- Attachment contents are decoded but never written to disk. `filename` and `mime` are what the sender states,
  unchecked and unsanitized; see [Attachments](reading-e-invoices.md#attachments).
- The result contains text from the invoice. Escape it when you print it. Templates do that automatically.
