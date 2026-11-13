# wp-karaoke — Claude Instructions

## Project Overview
This is a WordPress plugin project (wp-karaoke) to manage a karaoke queue and host karaoke for singers. Key technologies: PHP, JavaScript (vanilla), CSS. Block-based architecture using WordPress block editor.

## Basic Rules
* Do not delete documentation files (requirements.md, CONTEXT.md) without explicit permission.
* Do not automatically run build commands.
* Always use the Read tool instead of `cat`, Glob instead of `ls`/`find`,and Grep instead of `grep`. Never use Bash for file scanning unless absolutely necessary.

## Project Architecture

- **Post type:** `karaoke-venue` — stores the singer queue in a single post meta field as a JSON array.
- **Singer fields:** `id` (10-char alphanumeric), `singer_name`, `song`, `artist`, `youtube_url`, `signup_time`, `queued_time` (set when moved into the queue), `stage_time` (set when the singer goes on stage), `completed_time`, `status`.
- **Night history:** hidden `karaoke-night` post type (child of the venue). On Reset Queue, a permanent summary of the night's finished performances is saved to the night post's meta (`_wpkq_night_summary`). Fake singers (`fake: true`) are excluded.
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
| POST | `/wpkq/v1/queue/signup` | Public | Public singer self-signup; always sets `kj-received`; returns `{ success: true, id, status_token }` |
| GET | `/wpkq/v1/queue/status` | Public | Status, queue position, and wait estimate for private status tokens (`tokens`, comma-separated, max 5). Public singer IDs are not accepted |
| GET | `/wpkq/v1/queue/report` | `manage_options` | Analysis dump: full queue, pending signups, wait-estimate log, and queue snapshots saved on reset |
| POST | `/wpkq/v1/reactions` | Public | Audience emoji reaction (`reaction`: clap, heart, fire, laugh, mic, rock; `visitor`: random browser ID). Only while someone is on stage (409 otherwise). Rate limited per visitor, per IP, and per venue (429) |
| GET | `/wpkq/v1/actions` | Cookie | Monitor poll: pending admin actions, plus new audience reactions after `reactions_after` |
| POST | `/wpkq/v1/queue/advance` | Cookie | Monitor: mark current on-stage as finished, promote singer by ID |

## Key Files

- `wp-karaoke.php` — plugin entry point
- `includes/post-type/class-venue-post-type.php` — `Venue_Post_Type`, `get_queue_by_slug()`, `save_queue()`
- `includes/rest/class-queue-rest-controller.php` — all REST endpoints
- `includes/admin/class-queue-admin.php` — admin queue page HTML
- `includes/PostType/Night.php` — night summaries, backfill from snapshots, and history stats
- `includes/Admin/HistoryAdmin.php` — admin Night History page (`?page=wpkq-night-history&slug=`)
- `assets/js/admin/queue-admin.js` — admin JS (SortableJS, modals, REST calls)
- `includes/REST/Reactions.php` — audience reactions endpoint and per-venue reaction buffer
- `includes/blocks/now-playing/` — Now Playing block (view.js, markup.php, style.css)
- `includes/blocks/singer-signup/` — Singer Signup block (view.js, markup.php, style.css)
