# Aleph connector — Cloudflare

Read-only zone and DNS observation. This connector enumerates the zones on a Cloudflare account,
their DNS records, and (where the token can read it) each zone's TLS mode. It emits
provenance-bearing observation envelopes and changes nothing.

Implements MME-1563 (ALEPH-DNS-002).

## Read-only is structural

There is no code path in this package that builds a POST, PUT, PATCH or DELETE. A token scoped to
Zone:Read and DNS:Read is sufficient, and that is the token this connector is meant to be given.

## Configuration

| Field | | |
|---|---|---|
| `token` | required, secret | A scoped API token, or the legacy global API key |
| `auth_mode` | optional | `token` (default) or `key` |
| `email` | required with `key` | Account email for the legacy header pair |
| `account_id` | optional | Used for the source reference |

A scoped token and the global API key are not equivalent. The key cannot be scoped at all — it can
do anything the account can do, including delete zones. Both are supported because accounts exist
that still use the key, and the discovered source records `scoped_token: false` when one is in play
so that the weaker posture is visible rather than assumed away.

## Capabilities

`health.check`, `sources.discover`, `history.backfill`, `sync.incremental`. As with the Namecheap
connector, incremental sync is checkpointed re-listing rather than a delta feed.

## What comes out

One envelope per zone and one per DNS record, carrying stable identity, the exact provider fields,
the observed time, the raw JSON as payload, the connector version and the normalizer version.
Extensions: `cloudflare.zone` and `cloudflare.dns_record`, version 1.

## Three normalizations that matter

- **`strict` becomes `full_strict`.** Cloudflare's SSL setting spells Full (strict) as `strict`;
  every other part of this system spells it `full_strict`. The translation happens once, here.
- **TTL 1 is not one second.** It is Cloudflare's sentinel for "automatic". A plan that compared it
  numerically against a real TTL would propose a change that is not a change, so it normalizes to a
  null TTL with `ttl_automatic: true` beside it.
- **An unreadable TLS setting is not "off".** A token without the settings scope gets a 403; that is
  a missing scope, not a missing setting. The zone comes back with `tls_mode: null` and
  `tls_mode_observed: false`, and the run metadata names every zone in that state.

Nameservers are sorted on the way in, so an unchanged delegation reported in a different order never
looks like a change.

## Failure

`CloudflareError` separates `unauthorized` (the credential is wrong), `forbidden` (the credential is
right and too narrow), `rate_limited`, `transport`, `rejected` and `malformed`. Only the retryable
kinds are retried; `Retry-After` is honoured when Cloudflare sends it, and exponential backoff
applies when it does not. Cloudflare returns HTTP 200 with `"success": false` for application
errors, so the envelope is asserted before anything inside it is read.

## Fixtures

**Synthetic**, shape-derived from the v4 API and from the reader that has been calling it in Landing
since 2026-06-06. Replacing them with captured, sanitized responses is a prerequisite before this
connector is trusted against a live account.
