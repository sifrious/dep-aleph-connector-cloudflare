# Fixtures

**Synthetic**, shape-derived from the Cloudflare v4 API and from the reader that has been calling it
in Landing since 2026-06-06. Not captured responses.

The zone names are real portfolio domains so that the golden path exercises the cases that matter:
one active proxied zone, one pending zone with no original registrar reported, and one paused zone.

`ttl: 1` on the apex record is not a one-second TTL — it is Cloudflare's sentinel for "automatic",
and a plan that compared it numerically would propose a change that is not a change.

MME-1563 requires a captured, sanitized response before this connector is trusted against a live
account.
