# Owned diff

## Both auth modes supported — 2026-08-28, MME-1563

SEAM: borrowed — Cloudflare's authentication surface.

PAYS WHEN: an existing account that still uses the global API key can be inventoried without first
migrating it to a scoped token.

CHARGES WHEN: the global key path keeps a credential in play that cannot be scoped and can delete
zones. The connector cannot make that safe; it can only make it visible, which is why
`scoped_token: false` is reported on every discovered source.

TRIGGER: fired now — Landing's provider accounts carry an `auth_mode` column with both values in it,
so both exist in practice.

## TLS mode read as a separate call — 2026-08-28, MME-1563

SEAM: authored — the boundary between a zone's record set and its edge configuration.

PAYS WHEN: a desired-state plan needs the TLS mode, which C-07 requires it to, because Flexible in
front of a proxied HTTPS origin is a rejection rather than a warning.

CHARGES WHEN: it is one extra request per zone, and it is the request most likely to be refused by a
correctly-scoped read token. `include_tls: false` turns it off, and the metadata names every zone
whose mode went unobserved.

TRIGGER: fired now — the zone list does not carry the SSL setting, and the DNS boundary decision
depends on it.
