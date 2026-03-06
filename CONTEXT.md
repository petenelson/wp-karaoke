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
- `signup_time`, `completed_time` — Unix timestamps.
- `status` — one of: `kj-received` → `in-the-queue` → `on-stage` → `finished`.

### Admin Page Layout
The Manage Queue page has four sections rendered by JavaScript after fetching the queue:

1. **On Stage** (top, amber highlight) — shows the single singer currently performing. Displayed only when a singer has `on-stage` status. No Edit button; has Finished and Delete buttons.
2. **In The Queue** — singers with `in-the-queue` status. Has a Refresh button. Singers can be reordered via drag-and-drop (SortableJS, touch-friendly). Buttons: Edit, On Stage, Finished, Delete.
3. **New Sign-Ups** — singers with `kj-received` status (submitted via the public signup form or added directly by the KJ). Buttons: Add to Queue, Edit, Delete.
4. **Finished** — singers with `finished` status, retained for the session (read-only).

### Modals
- **Add Singer modal**: Name (required), Song, Artist, YouTube URL. A random 10-character ID is generated at submission time.
- **Edit Singer modal**: Same fields. Available for singers in In The Queue and New Sign-Ups; not available for On Stage.

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

All admin endpoints require `manage_options` and a WordPress REST nonce (`X-WP-Nonce` header).

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
- Full-width YouTube IFrame Player API embed. The KJ can search and play videos directly.
- A URL input bar lets the KJ paste a YouTube link to load it in the embed.
- At the bottom: a horizontal ticker showing the current singer and upcoming queue.

### Ticker Behaviour
- Fetches queue from `GET /wpkq/v1/queue?slug=` (public endpoint). Returns singers with `in-the-queue` and `on-stage` status, with `on-stage` first.
- The singer with `on-stage` status is displayed with a cyan badge and "On Stage:" prefix.
- Scroll is a one-shot CSS transition at 100px/s. When the last item scrolls off the left edge, the queue is refreshed and the ticker restarts.
- All singer items with an ID are clickable.

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
Fields: **Your Name** (required), **Song Title**, **Artist**.

- Submission is handled client-side via `fetch` — no page reload.
- On success: the form is replaced with a confirmation message (*"You're signed up! Watch for your name on screen."*) and a "Sign Up Another Singer" button that resets the form.
- On error: an inline error message is shown; the form stays filled in.

### REST API Interaction
Submissions POST to `POST /wpkq/v1/queue/signup` (no auth required):
- A singer ID is generated server-side.
- Status is always set to `kj-received`.
- Returns `{ "success": true }` only — queue data is not exposed publicly.
- The KJ sees the submission in the New Sign-Ups section and promotes it with "Add to Queue".

---

## REST API Reference

All routes use the namespace `wpkq/v1`. Routes that operate on a venue require a `slug` parameter identifying the published `karaoke-venue` post.

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/wpkq/v1/queue` | Public | Fetch queue. Logged-in: all singers. Public: `in-the-queue` + `on-stage` only, with `on-stage` first. |
| POST | `/wpkq/v1/queue/singer` | `manage_options` | KJ adds a singer with full data (name, song, artist, YouTube URL, status, ID). |
| PUT | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Partial update of any singer field. Setting `status: on-stage` auto-reverts the prior on-stage singer to `in-the-queue`. |
| DELETE | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Remove a singer from the queue entirely. |
| POST | `/wpkq/v1/queue/reorder` | `manage_options` | Reorder `in-the-queue` singers. Body: `{ slug, order: [id, id, ...] }`. |
| POST | `/wpkq/v1/queue/signup` | Public | Public singer self-signup. Always sets `kj-received`. Returns `{ success: true }` only. |
| POST | `/wpkq/v1/queue/advance` | Monitor cookie | Mark the current `on-stage` singer as `finished` and promote a singer by ID to `on-stage`. Used by the monitor ticker click handler. |
