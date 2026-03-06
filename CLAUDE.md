# wp-karaoke — Claude Instructions

## Project Architecture

- **Post type:** `karaoke-venue` — stores the singer queue in a single post meta field as a JSON array.
- **Singer fields:** `id` (10-char alphanumeric), `singer_name`, `song`, `artist`, `youtube_url`, `signup_time`, `completed_time`, `status`.
- **Singer statuses:** `kj-received` → `in-the-queue` → `on-stage` → `finished`.
- **REST namespace:** `wpkq/v1`. All queue routes require a `slug` param to identify the venue.
- **Auth:** Admin mutations use `manage_options`. The monitor (Now Playing block) uses a venue-specific cookie (`wpkq_auth_{slug}` = `md5(password)`).
- **Blocks:** All blocks live in `includes/blocks/{block-name}/` and use the namespace `wp-karaoke`.
- **Compiled Assets:** Source JS and CSS live in `assets/js/` and `assets/css/`, with `frontend/` and `admin/` sub-folders. Webpack compiles assets to `dist/`. The main plugin file enqueues assets based on context (frontend vs. admin).

Refer to [10up Engineering Best Practices](https://10up.github.io/Engineering-Best-Practices/) for all other engineering decisions.

## REST Endpoints

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/wpkq/v1/queue` | Public | Fetch queue (logged-in: all singers; public: `in-the-queue` only, on-stage first) |
| POST | `/wpkq/v1/queue/singer` | `manage_options` | Add a singer |
| PUT | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Update singer fields (partial update; setting `on-stage` auto-reverts prior on-stage singer) |
| DELETE | `/wpkq/v1/queue/singer/{id}` | `manage_options` | Delete a singer |
| POST | `/wpkq/v1/queue/reorder` | `manage_options` | Reorder `in-the-queue` singers by ID array |
| POST | `/wpkq/v1/queue/signup` | Public | Public singer self-signup; always sets `kj-received`; returns `{ success: true }` only |
| POST | `/wpkq/v1/queue/advance` | Cookie | Monitor: mark current on-stage as finished, promote singer by ID |

## Key Files

- `wp-karaoke.php` — plugin entry point
- `includes/post-type/class-venue-post-type.php` — `Venue_Post_Type`, `get_queue_by_slug()`, `save_queue()`
- `includes/rest/class-queue-rest-controller.php` — all REST endpoints
- `includes/admin/class-queue-admin.php` — admin queue page HTML
- `assets/js/admin/queue-admin.js` — admin JS (SortableJS, modals, REST calls)
- `includes/blocks/now-playing/` — Now Playing block (view.js, markup.php, style.css)
- `includes/blocks/singer-signup/` — Singer Signup block (view.js, markup.php, style.css)
