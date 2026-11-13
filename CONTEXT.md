# WordPress Karaoke Queue Manager

A WordPress plugin with three components and a REST API layer for managing a karaoke venue queue.

---

## First Component: The Admin Tool

A page used by the KJ (karaoke jockey) to manage the queue (list of singers).

### Access & Setup
1. Only available to logged-in WordPress users with the `administrator` role.
2. Accessed via a "Manage Queue" column link on the `karaoke-venue` post type list screen — not a standalone menu item.
3. Class: `WPKQ\Admin\Queue_Admin`.
4. The `karaoke-venue` post type stores all queue data in a single post meta field (`_wpkq_singer_queue`) as a JSON array.

### Singer Data
Each singer entry contains:
- `id` — random 10-character alphanumeric string, generated client-side in the admin and server-side for public signups.
- `singer_name`, `song`, `artist` — text fields, max 50 characters each.
- `youtube_url` — URL of the singer's chosen YouTube video (optional, KJ-assigned).
- `signup_time`, `stage_time`, `completed_time` — Unix timestamps. `stage_time` is set server-side whenever the singer is moved to `on-stage` (admin On Stage button or monitor advance) and is used for wait estimates.
- `queued_time` — Unix timestamp set server-side the first time the singer is moved to `in-the-queue` (or added directly by the KJ).
- `status` — one of: `kj-received` → `in-the-queue` → `on-stage` → `finished`.

### Admin Page Layout
The Manage Queue page has four sections rendered by JavaScript after fetching the queue:

1. **On Stage** (top, amber highlight) — shows the single singer currently performing. Displayed only when a singer has `on-stage` status. No Edit button; has Finished and Delete buttons. Auto-refreshes on the same interval as New Sign-Ups.
2. **In The Queue** — singers with `in-the-queue` status. Has a Refresh button. Singers can be reordered via drag-and-drop (SortableJS, touch-friendly) or ↑/↓ move buttons. Buttons: Edit, On Stage, Finished, Delete.
3. **New Sign-Ups** — singers with `kj-received` status (submitted via the public signup form or added directly by the KJ). Auto-refreshes every `refreshInterval` ms (localized via `wpkqAdmin.refreshInterval`, default 1000ms); refresh is suppressed while a drag is in progress. Buttons: Add to Queue, Edit, Delete.
4. **Finished** — singers with `finished` status, retained for the session (read-only).

A **Reset Queue** button clears all singers from the queue (requires confirmation). It calls `DELETE /wpkq/v1/queue`.

### Modals
- **Add Singer modal**: Name (required), Song, Artist, YouTube URL. A random 10-character ID is generated at submission time. Status is set to `in-the-queue`.
- **Edit Singer modal**: Same fields (including YouTube URL). Available for singers in In The Queue and New Sign-Ups; not available for On Stage.

### REST API Interactions (Admin)
All mutations use individual REST endpoints rather than a bulk queue save:

| Action | Method | Endpoint |
|--------|--------|----------|
| Fetch queue | GET | `/wpkq/v1/queue?slug=` |
| Add singer (KJ) | POST | `/wpkq/v1/queue/singer` |
| Edit singer details | PUT | `/wpkq/v1/queue/singer/{id}` |
| Change status | PUT | `/wpkq/v1/queue/singer/{id}` |
| Delete singer | DELETE | `/wpkq/v1/queue/singer/{id}` |
| Reorder queue | POST | `/wpkq/v1/queue/reorder` |
| Reset queue | DELETE | `/wpkq/v1/queue` |

All admin endpoints require `manage_options` and a WordPress REST nonce (`X-WP-Nonce` header).

---

## Night History

An admin-only page per venue, opened with the **History** button next to **Manage Queue** on the venue list (`admin.php?page=wpkq-night-history&slug=`). Class: `WPKQ\Admin\HistoryAdmin`.

### Storage
- Each night is a hidden `karaoke-night` post (`WPKQ\PostType\Night`) whose `post_parent` is the venue and whose `post_date` is the night's start. It is not public, has no admin UI or REST, and is never loaded with the venue's meta.
- The summary JSON lives in the night post's `_wpkq_night_summary` meta: `version`, `archived_time`, `started`, `ended`, `signups`, `unfinished` (signups still waiting at reset), and `performances` (finished singers in stage order: `singer_name`, `song`, `artist`, `signup_time`, `queued_time`, `stage_time`, `completed_time`, `used_status_card`). Summaries are kept permanently, unlike the 30-day queue snapshots.
- A night is created on **Reset Queue** from the same snapshot that is archived. Opening the History page also backfills nights from any saved snapshots without one. `_wpkq_snapshot_key` (hash of snapshot time + contents) prevents duplicates.
- Nights with no finished performances are skipped. Fake singers loaded by the Now Playing block are flagged `fake: true` and excluded.

### Page
- Totals: nights, performances, different singers, average song length.
- Top 10 songs, top 10 artists, and regulars (singers on more than one night). Grouping ignores case and extra spaces, and shows the most common spelling.
- Nights table: date and time range, expandable song list, songs, singers, average song length, average wait in queue (approval → on stage), average time to approve (signup → approval), and status card users. Each night has a **Delete** button (with confirmation) for removing test nights.

---

## Second Component: The "Now Playing" Block

A Gutenberg block displayed on a laptop connected to a TV, showing the current singer and upcoming queue as a ticker.

### Block Editor
- Block name: `wp-karaoke/now-playing`.
- Settings panel: venue picker (published `karaoke-venue` posts) + plain-text password.
- The password is stored in the block attributes and synced to venue post meta (`_wpkq_monitor_password`) on save.

### Password Gate
- If no valid auth cookie is present, the frontend renders a password form.
- The form POSTs to `admin_post_wpkq_verify_password`.
- On success, a venue-specific cookie (`wpkq_auth_{slug}` = `md5(password)`) is set for 6 hours.

### Authorized Monitor View
- Full-width YouTube IFrame Player API embed. The KJ can paste a YouTube URL into a URL bar to load a video in the embed.
- A welcome overlay (`#wpkq-welcome`) is shown until the first video loads; it hides automatically when `loadVideoById()` is called.
- The welcome message reads: *"Welcome to {venue name} karaoke night!"*
- An injected `<style>` tag resets `html { margin-top: 0 !important; }` to ensure full-viewport height even when the admin bar is present.
- At the bottom: a horizontal ticker showing the current singer and upcoming queue.

### YouTube URL Parsing
The `parseVideoId()` utility accepts:
- `https://www.youtube.com/watch?v=ID`
- `https://youtu.be/ID`
- `https://www.youtube.com/embed/ID`
- `https://www.youtube.com/shorts/ID`
- A bare 11-character video ID

### Ticker Behaviour
- Fetches queue from `GET /wpkq/v1/queue?slug=` (public endpoint). Returns singers with `in-the-queue` and `on-stage` status, with `on-stage` first.
- The singer with `on-stage` status is displayed with a cyan badge and "On Stage:" prefix.
- Scroll is a one-shot CSS transition at 100px/s. When the last item scrolls off the left edge, the queue is refreshed and the ticker restarts.
- Only singers in `in-the-queue` status with a `youtube_url` are clickable (stored as `data-singer-id` and `data-youtube-url` on the span). On-stage items are not clickable.

### Ticker Click → Advance Singer
When the KJ clicks a singer's name in the ticker:
1. POSTs to `POST /wpkq/v1/queue/advance` with `{ slug, id }` (authenticated via the monitor cookie).
2. The server marks the current `on-stage` singer as `finished` and promotes the clicked singer to `on-stage`.
3. The ticker re-renders with the updated queue.
4. If the promoted singer has a `youtube_url`, that video is automatically loaded in the iframe.

---

## Third Component: The Singer Signup Block

A public-facing Gutenberg block that lets audience members sign up to sing.

### Block Editor
- Block name: `wp-karaoke/singer-signup`.
- Settings panel: venue picker (published `karaoke-venue` posts). No password required.

### Frontend Form
Fields: **Your Name** (required), **Song Title** (required), **Artist** (required). All three fields are client-side validated before submission.

- Submission is handled client-side via `fetch` — no page reload.
- On success: the form is replaced with a confirmation message (*"You're signed up! Watch for your name on screen."*) and a "Sign Up Again" button that resets the form.
- On error: an inline error message is shown; the form stays filled in.

### Cookie-Based Name Autofill
- On successful submission, the singer's name is saved to a cookie (`wpkq_singer_name`, 6-hour TTL, `SameSite=Lax`).
- On page load and after clicking "Sign Up Again", the Name field is pre-filled from the cookie if present.
- Smart focus: if the cookie has a saved name, focus moves to the Song field; otherwise the Name field is focused.

### Singer Status & Wait Estimate
Controlled by the block's **Show singer status & wait estimate** toggle (`showStatus` attribute, default on). When off, the block behaves exactly as before: no status card, no cookie, no polling.

- Signup stores a private random `status_token` (20 chars) on the singer record and returns it. It is never included in the public `GET /queue` response.
- The returned token is added to a cookie `wpkq_signups_{slug}` (JSON array of up to 5 tokens, 6-hour TTL, `SameSite=Lax`). After each refresh, only tokens that request checked and found gone or finished are dropped; the cookie is re-read first so a signup made during the request is kept.
- A status area (`.wpkq-signup__status`, `aria-live="polite"`) above the form shows one card per signup, fetched from `GET /wpkq/v1/queue/status`. Cards return after a page reload.
- Card messages by status: `kj-received` → waiting for the KJ; `in-the-queue` → "You're #N in line, about M min" (position 1 → "You're up next!"); `on-stage` → on stage; `finished` → thanks (shown once, then the ID is dropped from the cookie). Tokens missing from the response (deleted or queue reset) are dropped.
- Polls every 20 seconds while any signups are active, skips ticks while the tab is hidden, and refreshes immediately when the tab becomes visible again.

**Estimate log:** the first time a singer is shown a given queue position, the status endpoint adds a `_wpkq_estimate_log` meta row (`id`, `time`, `position`, `wait_minutes`, `avg_seconds`, `on_stage_remaining`). A 12-hour transient per singer/position prevents duplicate rows from polling. Each row is its own meta entry, so logging never writes to the queue meta.

**Reset archive:** `DELETE /wpkq/v1/queue` saves a snapshot of the full queue plus pending signups as a `_wpkq_queue_archive` meta row (`archived_time`, `queue`) before clearing, then deletes estimate-log rows and snapshots older than 30 days (filter `wpkq_history_retention_days`). All JSON meta (queue, pending signups, log, archives) is written with `wp_slash()` so escaped quotes and backslashes survive WordPress's unslashing.

**Wait estimate:** average song length = mean of `completed_time − stage_time` for the 10 most recent finished singers with plausible durations (60–900s); falls back to 300s (filter `wpkq_default_song_seconds`). Wait for position N = time remaining for the current on-stage singer (average minus elapsed, min 0) + (N − 1) × average, rounded up to minutes.

### REST API Interaction
Submissions POST to `POST /wpkq/v1/queue/signup` (no auth required):
- A singer ID is generated server-side (`wp_generate_password(10, false, false)`).
- Status is always set to `kj-received`.
- Returns `{ "success": true, "id": "...", "status_token": "..." }`. The token is used by the status card. No other queue data is exposed.
- The KJ sees the submission in the New Sign-Ups section and promotes it with "Add to Queue".

---

## Audience Reactions

Audience members tap emoji buttons in the Singer Signup block, and the reactions float up the right side of the Now Playing monitor.

### Signup block
- Controlled by the **Show audience reactions** toggle (`showReactions` attribute, default on). When off, the buttons and `data-reactions-url` are left out.
- Six buttons: 👏 ❤️ 🔥 😂 🎤 🤘 (keys `clap`, `heart`, `fire`, `laugh`, `mic`, `rock`), each with an accessible label. They show in both the form and success states.
- A random `wpkq_visitor` cookie (6-hour TTL) identifies the browser for rate limiting. The ID is also kept in memory for the page view, so it stays the same if cookies are blocked. After each tap the buttons cool down for 1.5s (`aria-disabled`).
- When nobody is on stage, the server returns 409 and the block shows "Reactions work while someone is singing." for 5 seconds. Rate-limit (429) responses are ignored silently.

### Server (`WPKQ\REST\Reactions`)
- `POST /wpkq/v1/reactions` (public): `slug`, `reaction` (enum), `visitor`. Only accepted while a singer is `on-stage`.
- Limits, checked before the queue is loaded: one reaction per 1.5s per visitor (keyed on the visitor ID, or IP + user agent if missing — not IP alone, since everyone on a bar's Wi-Fi shares one); 10 per second per IP address, so one source can't use up the venue's allowance by inventing visitor IDs; and 30 per second per venue. Sites behind a proxy or CDN can supply the real client IP with the `wpkq_reaction_client_ip` filter.
- If the buffer expires and its counter restarts below the monitor's last `seq`, the next poll returns everything in the buffer, so no reactions are skipped.
- Accepted reactions go into a per-venue transient buffer (`wpkq_reactions_{slug}`) with an increasing `seq`. Items expire after 15 seconds. Writes are read-modify-write, so a few reactions can be lost when many arrive in the same instant; reactions are cosmetic, so this is accepted.

### Monitor
- Reactions ride on the existing 1-second `GET /wpkq/v1/actions` poll: the monitor sends `reactions_after` (its last `seq`, `-1` on first poll so old reactions aren't replayed) and gets back `reactions` and `reactions_seq`.
- Each emoji floats up in a random lane on the right with random size, drift, and speed. Each poll's batch is spread evenly over the following second (at most 150ms apart), and at most 30 are on screen at once. With `prefers-reduced-motion`, emoji fade in and out without moving.

---

## REST API Reference

All routes use the namespace `wpkq/v1`. Routes that operate on a venue require a `slug` parameter identifying the published `karaoke-venue` post.

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/wpkq/v1/queue` | Public | Fetch queue. Logged-in: all singers + all fields. Public: `in-the-queue` + `on-stage` only (on-stage first), limited to `id`, `singer_name`, `youtube_url`, `status`. |
| DELETE | `/wpkq/v1/queue` | `manage_options` | Reset the entire queue to empty. |
| POST | `/wpkq/v1/queue/singer` | `manage_options` | KJ adds a singer with full data (name, song, artist, YouTube URL, status, ID). |
| PUT | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Partial update of any singer field. Setting `status: on-stage` auto-reverts the prior on-stage singer to `in-the-queue`. |
| DELETE | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Remove a singer from the queue entirely. |
| POST | `/wpkq/v1/queue/reorder` | `manage_options` | Reorder `in-the-queue` singers. Body: `{ slug, order: [id, id, ...] }`. |
| POST | `/wpkq/v1/queue/signup` | Public | Public singer self-signup. Always sets `kj-received`. Returns `{ success: true, id, status_token }`. |
| GET | `/wpkq/v1/queue/status` | Public | Status for private status tokens. Query: `slug`, `tokens` (comma-separated, max 5). Returns `{ success, singers: [ { token, song, artist, status, position, wait_minutes } ] }`. Unknown tokens are omitted. Public singer IDs are not accepted. |
| GET | `/wpkq/v1/queue/report` | `manage_options` | Analysis dump for a venue: `queue` (all fields), `pending` signups, `estimate_log`, and `archives` (queue snapshots saved on reset). Works with application passwords. |
| POST | `/wpkq/v1/queue/advance` | Monitor cookie | Mark the current `on-stage` singer as `finished` and promote a singer by ID to `on-stage`. Used by the monitor ticker click handler. |
