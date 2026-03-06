```markdown
# WordPress Karaoke Queue Manager

This WordPress plugin will consist of three major components, and the necessary REST API endpoints to support the required functionality.

## Coding Specs
1. All PHP code must use the WordPress PHP Coding Standards: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/
2. PHP classes will have a root namespace of `WPKQ`, which stands for WordPress Karaoke Queue.
3. PHP code will live in the `includes` folder, with sub-folders for `post-type`, `taxonomy`, `rest` (for REST API code), and `admin`
4. Create package.json and composer.json files for the project.
5. All compiled Javascript and CSS files will live in the `dist` folder at the root of the plugin.
6. Use [webpack](https://webpack.js.org/) to compile JS and CSS files to the `dist` folder, with filenames for frontend and admin compiled assets.
7. Source JS and CSS files will live in the `assets` folder, with subfolders for `js` and `css`, then another level of subfolders for `frontend` and `admin`.
8. The plugin will enqueue the necessary assets based on frontend or admin context.
9. The plugin folder will be called `wp-karaoke`.
10. The main plugin file will check for `vendor/autoload.php` and load it if it exists.
11. All blocks will have a namespace of `wp-karaoke`
12. Refer to the [10up Block Editor Best Practices](https://gutenberg.10up.com/) when generating WordPress blocks for the block editor.
13. Refer to the [10up Engineering Best Practices](https://10up.github.io/Engineering-Best-Practices/) for any other engineering.
14. All blocks will be created in the `includes/blocks` directory, with a subdirectory for each block.


## First Component: The admin tool

This will be a page used by the KJ (karaoke jockey) to manage the queue (list of singers).

### Requirements
1. This will only be available for a logged-in WordPress user with the administrator role.
2. This will be an admin page in the menu.
3. Class Name: `QueueAdmin`
4. This will require a post type of `karaoke-venue` to be created. The menu label will be "Karaoke", the singular label will be `Venue`, and the plural label will be `Venues`. The post type will not be publicly accessible on the frontend.
5. There will be an admin column in the list of posts labeled `Manage Queue`. This will load a custom admin page to manage the queue.
6. The list of singers at a venue will be stored in a single post meta field as an array containing the `singer_name`, `song`, `artist`, `signup_time`, `completed_time`, and `status` fields.
7. The default status will be `kj-received`.
8. The time fields are timestamps. The other fields are text fields with a max length of 50 characters.
9. We will need a PHP class for REST endpoints and a REST endpoint called `queue` with a namespace of `wpkq/v1`.
10. The `queue` endpoint will require a `?slug=` query string parameter to identify the post that is storing the post meta. The post slug must be validated to a published karaoke venue.
11. The list of singers will always be fetched from the `queue` endpoint.
12. The `queue` GET endpoint will return all singers for logged-in users, and only singers with a status of `in-the-queue` for public (non-logged-in) users.
13. Only logged-in administrators can update the queue via a POST request to the `queue` endpoint.
14. Upon the `Manage Queue` page loading, a table will show the singers that are in the queue with a `status` of `in-the-queue`.
15. Show columns for Name, Song, Artist, the time they signed up, then a column for action buttons.
16. A second table will show singers with a status of `kj-received`. There will be a button with `Add to Queue` text, which will change their `status` to `in-the-queue`.
17. Using Javascript and SortableJS, the KJ can drag and drop the singers vertically to change their order in the queue. Drag and drop must be touch-friendly for mobile use.
18. There will be a button to edit the singer which opens a mobile-friendly modal, allowing editing of their name, song, or artist.
19. There will be a button to mark a singer as "Finished" which will change their status to `finished` and retain them in the array. An alert will confirm before the status change is made.
20. There will be a button to delete a singer from the queue, with an alert to confirm. Deleted singers will be removed from the array entirely.
21. After any edits are done in the admin, the complete list of singers will be sent back to the `queue` REST endpoint via a POST request. This will update the list of singers back to the post meta for the venue.
22. The list of singers in the queue will be managed from a phone, so the UI must be mobile-friendly (drag and drop, buttons, etc).
23. No auto-refresh is needed; updates are only triggered by KJ actions.
```

## Second Component: The "Now Playing" Block

This will be a WordPress block for the block editor. When it is displayed on a page, it will show the song being played for the singer, and will have a scrolling list of singers at the bottom. It will be shown on a laptop that is hooked up to a TV. This image https://i.imgur.com/WdMBkGY.png is a good reference for what will be displayed on the page.

### Requirements
1. Call the block "Now Playing".
2. In the block editor, it will need a way to pick the `karaoke-venue` post, as well as a plain-text password.
3. When rendering the block on the frontend, show a password form if no valid auth cookie is present.
4. The password form submits via a standard HTML form POST to WordPress, which validates the password server-side, then redirects back to the current page.
5. If the password is correct, set a venue-specific cookie that expires in six hours. As long as the cookie is present, the page will not require a password. This will be considered "authorized".
6. If the block is authorized, display a full-width iframe pointing to `youtube.com`. The KJ will search for and play videos directly in the iframe.
7. At the bottom of the authorized view, display a horizontal ticker that scrolls right-to-left (like a news crawl), showing upcoming singers.
8. Only singers with a status of `in-the-queue` are shown in the ticker. The first singer is always labeled "On Stage:", followed by the next three singers in queue order.
9. Fetch the list of singers from the `queue` REST endpoint using the [Infinite Horizontal Scroll](https://github.com/constgenius/InfiniteScroll) library.
10. At the end of each scroll cycle, refresh the singer list from the `queue` REST endpoint.

## Third Component: The Singer Signup Block

This will be a public-facing WordPress block for the block editor. It will allow audience members to sign up to sing at a karaoke venue.

### Requirements

*To be defined.*
