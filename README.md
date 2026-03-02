# WP Karaoke Queue Manager

A WordPress plugin to manage a karaoke singer queue and signups.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Node.js 18+
- Composer (optional, for PHP dev tools)

## Installation

1. Copy the `wp-karaoke` folder to your `wp-content/plugins/` directory.
2. Install Node dependencies and build assets:

```bash
npm install
npm run build
```

3. Activate the plugin in the WordPress admin.

## Development

```bash
# Watch for changes during development
npm run watch

# Build for development (with source maps)
npm run dev

# Build for production (minified)
npm run build
```

## Usage

### Setting Up a Venue

1. In the WordPress admin, navigate to **Karaoke → Add New**.
2. Enter a title for your venue (e.g., "Friday Night Karaoke") and publish it.
3. In the Venues list, click **Manage Queue** to open the queue manager for that venue.

### Managing the Queue

The queue manager has two sections:

- **In The Queue** — Active singers. Drag rows to reorder. Use the action buttons to edit, mark as finished, or remove singers.
- **New Sign-Ups** — Singers with `kj-received` status. Click **Add to Queue** to move them to the active queue.

### REST API

**GET** `/wp-json/wpkq/v1/queue?slug={venue-slug}`

Returns the singer queue. Logged-in users receive the full list; public users receive only `in-the-queue` singers.

**POST** `/wp-json/wpkq/v1/queue`

Updates the full queue. Requires administrator authentication (`X-WP-Nonce` header).

Body:
```json
{
  "slug": "your-venue-slug",
  "queue": [ ... ]
}
```

### Singer Object

| Field            | Type      | Max Length | Default       |
|------------------|-----------|------------|---------------|
| `singer_name`    | string    | 50         | `""`          |
| `song`           | string    | 50         | `""`          |
| `artist`         | string    | 50         | `""`          |
| `signup_time`    | timestamp | —          | `0`           |
| `completed_time` | timestamp | —          | `0`           |
| `status`         | string    | —          | `kj-received` |

#### Status Values

| Status        | Description                        |
|---------------|------------------------------------|
| `kj-received` | Signed up; not yet in active queue |
| `in-the-queue`| Active queue                       |
| `finished`    | Has performed                      |

## Plugin Structure

```
wp-karaoke/
├── assets/
│   ├── css/
│   │   ├── admin/
│   │   │   └── queue-admin.css
│   │   └── frontend/
│   │       └── frontend.css
│   └── js/
│       ├── admin/
│       │   └── queue-admin.js
│       └── frontend/
│           └── frontend.js
├── dist/              ← compiled assets (generated)
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
├── includes/
│   ├── admin/
│   │   └── class-queue-admin.php
│   ├── post-type/
│   │   └── class-venue-post-type.php
│   ├── rest/
│   │   └── class-queue-rest-controller.php
│   └── taxonomy/      ← reserved for future use
├── composer.json
├── package.json
├── webpack.config.js
└── wp-karaoke.php
```
