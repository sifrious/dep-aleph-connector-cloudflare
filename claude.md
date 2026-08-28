# Cloudflare connector — working notes

## Things that will look like bugs and are not

- `tls_mode` null with `tls_mode_observed` false means the token could not read the zone's SSL
  setting. It does not mean TLS is off, and code that conflates the two will propose TLS changes
  that are not needed.
- `ttl` null with `ttl_automatic` true means Cloudflare's automatic TTL (wire value 1).
- A zone with `paused: true` still reports records. Paused means Cloudflare is not proxying, not
  that the zone is gone.

## Deliberate choices

- Errors are classified by HTTP status first and by the `success` flag second, because Cloudflare
  returns application errors with HTTP 200.
- `sslMode()` swallows only `forbidden` and `unauthorized`. Every other error propagates, because a
  rate limit while reading settings is not evidence about the setting.
- Nameservers are sorted at normalization time. Cloudflare's order is not stable and is not
  meaningful.

## Testing

`vendor/bin/pest`. Everything is driven through `Http::fake()` against the fixtures. Note that
`Http::fakeSequence()` cannot be re-armed mid-test, so a test that performs two full reads arms one
sequence containing both.
