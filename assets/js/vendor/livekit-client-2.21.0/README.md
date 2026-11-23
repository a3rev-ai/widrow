# LiveKit browser client 2.21.0

Used by Customer Chat's conversation mode, and loaded only on a site whose voice status says
the mode is on (see `loadRoomClient` in `assets/js/widrow-ai-customer-chat.js`).

For maintainers:

- It runs in the site's own page, not in a frame, so it can read what the page can (the page's
  storage, the chat's stream token). That is why it is pinned and checked.
- It is pinned by version, and `livekit-client.umd.js` must be byte-identical to the build the
  platform's Tenant Portal ships at the same version (compare the sha256).
- At every version bump, its network calls are read again before the new copy is shipped.
- `LICENSE` is LiveKit's Apache License 2.0, shipped beside it.
