# LiveChat JSON API v1

Base path (both wrappers expose the same shape): `/livechat/api/v1`
All requests/responses are JSON. This contract is what the **widget** and **dashboard** are built against.
Neither of them knows (or cares) whether PHP-standalone or Laravel is behind it.

## Conventions

| Thing | Rule |
|---|---|
| Visitor auth | Header `X-Chat-Token: <visitor_token>` (issued once by `POST /conversations`; server stores only its SHA-256) |
| Agent auth | Standalone: session cookie or `Authorization: Bearer <token>`. Laravel: the host app's auth. Same endpoints either way. |
| Cursor | `after=<message id>`; responses return `next_after`. Clients store it and send it on the next poll. |
| Idempotency | Clients generate `client_id` (UUID) per outgoing message. Retrying the same `client_id` never duplicates. |
| Message body | Plain text, max 2000 chars. Stored raw. **Clients must render with `textContent`, never `innerHTML`.** |
| Errors | `{ "error": { "code": "rate_limited", "message": "..." } }` with the matching HTTP status |
| Timestamps | ISO 8601 (`2026-10-13T14:03:00+00:00`) |

Error codes: `invalid_token` 401, `unauthenticated` 401, `not_found` 404, `conversation_closed` 409,
`empty_message` 422, `message_too_long` 422, `invalid_email` 422, `rate_limited` 429.

## Visitor endpoints

### `GET /config`
```json
{ "transport": { "mode": "polling", "interval_ms": 3000 }, "agent_online": true }
```
`mode` may later be `"reverb"` with `{ "host": "...", "key": "..." }`; the widget switches transport automatically.

### `POST /conversations`
Request: `{ "name": "Sam", "email": "sam@x.com", "page_url": "https://shop.com/pricing" }` (all optional)
Response `201`: `{ "conversation_id": 12, "visitor_token": "9f2c...64 hex" }`
Widget keeps the token in `localStorage` so a page reload resumes the same chat.

### `POST /messages`   (needs `X-Chat-Token`)
Request: `{ "body": "Hi, how much is shipping?", "client_id": "c0a8-..." }`
Response `201`: `{ "message": { "id": 41, "sender": "visitor", "body": "...", "client_id": "...", "created_at": "..." } }`

### `GET /messages?after=40`   (needs `X-Chat-Token`)  <- the polling endpoint
```json
{ "messages": [ { "id": 41, "sender": "agent", "agent_id": 1, "body": "...", "created_at": "..." } ],
  "next_after": 41, "agent_online": true, "status": "open" }
```

### `POST /offline`   (use when `agent_online` is false)
Request: `{ "name": "Sam", "email": "sam@x.com", "body": "Do you ship to Ghana?" }`  ->  `202 { "ok": true }`
Emails the owner; no conversation is created.

## Agent endpoints (all under `/agent`)

| Method & path | Purpose |
|---|---|
| `GET /agent/events?after=N` | Dashboard's main poll: new messages across **all** conversations. Also refreshes presence. |
| `GET /agent/conversations?status=open` | List conversations (`open` / `closed`) |
| `GET /agent/conversations/{id}/messages?after=N` | Thread history |
| `POST /agent/conversations/{id}/messages` | Reply: `{ "body": "...", "client_id": "..." }` |
| `POST /agent/conversations/{id}/close` | Close a conversation |
| `POST /agent/presence` | Heartbeat ("I'm online"), every ~30s while dashboard is open |

An agent counts as **online** if they sent a heartbeat/events poll in the last 60 seconds. That drives the widget's
"chat vs leave a message" switch.

## Planned for v1.1 (not implemented yet)
`POST /typing`, `PATCH /agent/conversations/{id}` (assign), `GET /agent/visitors/{id}` (page history), canned replies, file upload.
