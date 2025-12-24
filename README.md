# Archive Downloader

Archive one or more web pages into a WordPress-like folder structure with all CSS/JS inlined and images/fonts saved locally. The core tool is `childtheme-base/download-pages.py`. This repo also contains a WordPress child-theme scaffold (`childtheme-base/`), which is compatible with the output structure.

## What the tool does

- Fetches a page (or a list of pages) over HTTP.
- Inlines external CSS and JS into the HTML.
- Downloads images referenced by `<img>` and `<source>` tags (including `srcset`, `data-src`, `data-original`).
- Downloads fonts referenced in `@font-face` and embeds them as `data:` URLs.
- Writes output into a WordPress-like structure:
  - `templates/page-{slug}.html` for multi-page runs
  - `page-{slug}.html` for single-page runs
  - `wp-content/uploads/YYYY/MM/` for images
  - `wp-content/uploads/fonts/` for fonts
- Produces a `manifest.jsonl` describing everything it saved.

## Requirements

- Python 3.8+ (tested by inspection; no Python 3.10+ specific features are used)
- Python packages:
  - `requests`
  - `beautifulsoup4`

Install deps (virtualenv recommended):

```bash
python -m venv .venv
source .venv/bin/activate
pip install requests beautifulsoup4
```

## Usage

### Single page

```bash
python childtheme-base/download-pages.py \
  --url "https://example.com/page" \
  --output output-archive
```

### Multiple pages (URL list)

Create a text file with one URL per line (blank lines and `#` comments are ignored), e.g. `urls.txt`:

```
https://example.com/
https://example.com/about
https://example.com/contact
```

Run:

```bash
python childtheme-base/download-pages.py \
  --list urls.txt \
  --output output-archive \
  --workers 8 \
  --verbose
```

### Arguments

- `--url` (required unless `--list` is used): single page URL.
- `--list` (required unless `--url` is used): path to a text file containing URLs.
- `-o, --output` (required): output root folder.
- `--workers`: number of parallel workers for pages (default: 10).
- `--manifest`: custom path for JSONL manifest (default: `<output>/manifest.jsonl`).
- `-v, --verbose`: log each saved file and skip.

Run `python childtheme-base/download-pages.py --help` for the full CLI description.

## Output layout

For `--url`:

```
output-archive/
  page-{slug}.html
  wp-content/
    uploads/
      YYYY/MM/
        image-files...
      fonts/
        font-files...
  manifest.jsonl
```

For `--list`:

```
output-archive/
  templates/
    page-{slug}.html
  wp-content/
    uploads/
      YYYY/MM/
        image-files...
      fonts/
        font-files...
  manifest.jsonl
```

## Viewing output locally

Image URLs are written as absolute paths like `/wp-content/uploads/...`. To preview locally, serve the output root with a local web server so those paths resolve:

```bash
cd output-archive
python -m http.server 8000
```

Then open `http://localhost:8000/page-{slug}.html` (single) or `http://localhost:8000/templates/page-{slug}.html` (list).

## Using output with WordPress

The output structure is compatible with WordPress conventions:

- Copy `templates/page-{slug}.html` into your theme’s `templates/`.
- Copy `wp-content/uploads/...` into your WordPress installation’s `wp-content/uploads/`.

Because the HTML references `/wp-content/uploads/...` as absolute paths, the files must exist under the WordPress site root.

## Manifest format (JSON Lines)

Each line is a JSON object describing a saved artifact. Common `kind` values:

- `page`: saved HTML page.
- `image`: downloaded image (from HTTP or data URI).
- `font`: downloaded font (also embedded as `data:` URL).
- `css-inline-external`: external stylesheet inlined into `<style>`.
- `css-inline`: existing `<style>` rewritten (fonts embedded).
- `js-inline`: external JS inlined into `<script>`.
- `image-reuse`: image reused based on filename across pages.

Example line:

```json
{"kind":"image","source_type":"http","source":"https://example.com/logo.png","saved_url":"/wp-content/uploads/2025/12/logo.png","saved_path":"wp-content/uploads/2025/12/logo.png","bytes":12345,"sha1":"...","filename":"logo.png"}
```

## Important behavior and limitations (audit notes)

- Only HTML is parsed. There is no JavaScript execution, so client-rendered content may be missing.
- CSS is inlined, but **only font URLs inside `@font-face` are downloaded and rewritten**. Other CSS `url(...)` assets (background images, etc.) are not fetched.
- Only `<img>` and `<source>` tags are scanned for images. `<video>`, `<audio>`, `<picture>` without `srcset`, and CSS background images are not downloaded.
- Cross-page image reuse is keyed by filename, not content hash. If two different files share a filename, the first one wins.
- If two URLs produce the same slug, the later page can overwrite the earlier `page-{slug}.html`.
- Wayback Machine URLs (`web.archive.org`) are rewritten to site-root paths for `a`, `area`, `link` (non-stylesheet), `form`, and `iframe` tags.
- Network failures skip individual assets but the page still saves.

## Repository layout

- `childtheme-base/download-pages.py`: the archiving tool.
- `childtheme-base/`: WordPress child theme base (optional, but pairs well with the tool’s output).
- `childtheme-base/README.md`: WordPress theme metadata.

## Troubleshooting

- **Missing images**: confirm they are in `<img>`/`<source>` tags and not loaded via CSS or JS.
- **Fonts not showing**: verify the CSS uses `@font-face` and the generated HTML includes embedded `data:` URLs.
- **Broken image paths locally**: serve the output root with a local web server (see above).
- **Slugs collide**: rename URLs or edit the output filenames manually.

