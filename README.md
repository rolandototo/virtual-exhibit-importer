# Virtual Exhibit Importer

A WordPress admin tool that imports posts from a remote WordPress site's REST API into a local `virtual_exhibit` custom post type, with excerpts and featured images. It shows a progress bar and offers a downloadable report.

It was built for the [Louis Armstrong House Museum](https://rolandowp.com/project/louis-armstrong-house/). It brings the posts from the museum's separate Virtual Exhibits site (`virtualexhibits.louisarmstronghouse.org`) into its main site, so the team doesn't have to copy them by hand.

![Version](https://img.shields.io/badge/version-7.1.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

## Features

- **Admin screen:** a top-level **Exhibit Importer** menu with three buttons: **Start Import**, **Force Reimport** and **Delete All Exhibits**. It shows which source site it imports from.
- **Step-by-step AJAX import:** the importer reads the total from the source's `X-WP-Total` header, then imports one remote post per request. The import runs as many short requests instead of one long one, so it doesn't hit PHP time limits.
- **Duplicate detection:** each imported post stores the source post ID in the `original_id` meta. Posts that already exist, including trashed ones, are skipped.
- **Force reimport:** overwrites the title, content and excerpt of posts that already exist.
- **Featured images:** downloads the source post's featured image into the Media Library and sets it as the thumbnail, if the local post doesn't have one.
- **Source link:** stores the original permalink in the `original_url` meta and shows it in a read-only **Original URL** box on the edit screen.
- **Feedback:** a progress bar, a live log and a summary (imported, already existed, updated, errors). A failed request is logged and the import moves on. **Download Report** saves the log as `import-report.txt`.
- **Sanitized content:** titles go through `sanitize_text_field()`, and content and excerpts go through `wp_kses_post()`. Remote messages are shown as text, not HTML.
- **Admins only:** the page and both AJAX handlers require `manage_options`, and every request checks a nonce.
- **Clean-up:** **Delete All Exhibits** permanently deletes every `virtual_exhibit` post, in any status including the trash, after a confirmation prompt.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A **`virtual_exhibit` post type registered by your theme or another plugin**. This plugin doesn't register it.
- A user with the `manage_options` capability (administrators).
- The server must be able to make outbound HTTP requests to the source site.
- The source site must expose the standard WordPress REST API (`/wp-json/wp/v2/posts`) publicly.

## Installation

1. Download this repository as a ZIP (**Code > Download ZIP**).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, upload the ZIP and activate **Virtual Exhibit Importer**.

Or clone it into `wp-content/plugins/`.

## Usage

1. Open **Exhibit Importer** in the admin menu.
2. Click one of the buttons:
   - **Start Import:** counts the remote posts and the local exhibits, then imports the remote posts one at a time. Posts that already exist (matched by `original_id`) are skipped; only their `original_url` is refreshed.
   - **Force Reimport:** runs the same process, but overwrites the title, content and excerpt of posts that already exist.
   - **Delete All Exhibits:** permanently deletes all `virtual_exhibit` posts. This can't be undone.
3. When the import finishes, review the summary and click **Download Report** if you need a copy of the log.

New posts are created as **published**, with the same slug as on the source site.

## Using another source site

The default source is the Louis Armstrong House Virtual Exhibits site. To import from another WordPress site, set the base URL in `wp-config.php`:

```php
define( 'VEI_SOURCE_URL', 'https://example.org' );
```

Or use the `vei_source_url` filter:

```php
add_filter( 'vei_source_url', function () {
    return 'https://example.org';
} );
```

## Stored data

| Meta key       | Value                                        |
|----------------|----------------------------------------------|
| `original_id`  | Post ID on the source site                   |
| `original_url` | Permalink of the post on the source site     |

Imported featured images are attached to the post they belong to.

## Technical notes

- AJAX actions: `vei_start_import_step` (steps `count`, `compare` and `import`) and `vei_delete_all_exhibits`. Both check the nonce `vei_nonce` and the `manage_options` capability.
- Each import step returns a `status` (`imported`, `skipped` or `updated`), which the summary counts.
- The admin CSS and JS load only on the importer's own screen.

## Limitations

- It imports the source's `posts` endpoint only, one post per request, which is slow for thousands of posts.
- Taxonomies, authors and images inside the post content aren't imported; content images still point to the source site.
- The admin interface is in English only.

## Changelog

### 7.1.0

- Fixed: **Start Import** behaved like **Force Reimport**, because the `force` flag arrived as the string `"false"`.
- Fixed: the **Delete All Exhibits** button did nothing (its handler was outside the jQuery `ready` wrapper).
- Fixed: the **Updated** count in the summary always showed 0.
- Security: both AJAX handlers now require `manage_options`, not just a valid nonce.
- The source site is configurable (`VEI_SOURCE_URL` or the `vei_source_url` filter).
- New posts keep the source slug. Trashed exhibits are detected and deleted too.
- Buttons are disabled while a job runs, and failed requests are logged instead of stopping the import silently.
- Removed an unused sample API response (`import.json`).

The main file is still named `virtual-exhibit-importer-v7.php`, because renaming a plugin's main file deactivates it on sites that already use it.

## File structure

```text
virtual-exhibit-importer-v7.php   Main plugin file (admin page, AJAX import/delete, meta box)
js/importer.js                    Import flow, progress bar, summary and report download
css/admin-style.css               Progress bar styles
```

## Author

**Rolando Escobar**, WordPress developer. [rolandowp.com](https://rolandowp.com)
Built at Counterintuity for the Louis Armstrong House Museum.

## License

No open-source license is granted. This plugin was built for the Louis Armstrong House Museum and is shared here as a work sample.
