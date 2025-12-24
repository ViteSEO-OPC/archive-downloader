# Archive Downloader (Simple Guide)

This tool saves web pages so you can use them later, or bring them into WordPress. It grabs the page, pulls down images and fonts, and puts everything into a tidy folder.

You run it from your computer and it creates a new folder with your saved pages.

---

## What it does (in plain language)

- Saves a web page to a file.
- Copies images used on the page.
- Copies fonts used on the page.
- Puts everything into a WordPress‑style folder layout.
- Keeps a log file so you can see what it saved.

---

## Before you start (one-time setup)

You need Python and two small helper packages.

### 1) Install Python

If you already have Python, you can skip this. If not, install Python 3 from the official site or your system package manager.

### 2) Install the helper packages

Open a terminal and run:

```bash
python -m venv .venv
source .venv/bin/activate
pip install requests beautifulsoup4
```

---

## Example 1 — Save a single page

Command:

```bash
python childtheme-base/download-pages.py \
  --url "https://example.com/page" \
  --output my-archive
```

What you get:

```
my-archive/
  page-example.html
  wp-content/
  manifest.jsonl
```

---

## Example 2 — Save multiple pages

### Step A: Make a list of URLs

Create a file named `urls.txt` like this:

```
https://example.com/
https://example.com/about
https://example.com/contact
```

### Step B: Run the tool

```bash
python childtheme-base/download-pages.py \
  --list urls.txt \
  --output my-archive \
  --workers 8 \
  --verbose
```

What you get:

```
my-archive/
  templates/
    page-example.html
    page-about.html
    page-contact.html
  wp-content/
  manifest.jsonl
```

---

## How to open the saved pages

The saved pages expect images to be at `/wp-content/uploads/...`, so you should open them through a simple local web server.

### Example (recommended)

```bash
cd my-archive
python -m http.server 8000
```

Then open:

- `http://localhost:8000/page-example.html` (single page)
- `http://localhost:8000/templates/page-about.html` (multi page)

---

## Use with WordPress (simple copy steps)

If you want to use the saved pages inside WordPress:

1) Copy your saved page file(s) into your theme’s `templates/` folder  
   Example: `templates/page-about.html`

2) Copy the images and fonts into your WordPress uploads folder  
   Example: `wp-content/uploads/2025/12/...`

This works because the saved pages point to `/wp-content/uploads/...` just like WordPress does.

---

## What the tool names files

The tool creates a **slug** from the page URL (basically a short name).

Examples:

- `https://example.com/about-us` → `page-about-us.html`
- `https://example.com/` → `page-example.html`

If two pages create the same slug, the last one will overwrite the first.

---

## What the log file is

The file `manifest.jsonl` is a log of everything saved.

Example line:

```json
{"kind":"image","source_type":"http","source":"https://example.com/logo.png","saved_url":"/wp-content/uploads/2025/12/logo.png","saved_path":"wp-content/uploads/2025/12/logo.png","bytes":12345,"sha1":"...","filename":"logo.png"}
```

You usually don’t need this file, but it’s helpful for audits or troubleshooting.

---

## Things to know (limitations in plain language)

- It only saves **what’s in the HTML**. If a page is built by JavaScript after it loads, that extra content may be missing.
- It saves images in `<img>` and `<source>` tags. Images used in CSS backgrounds are not downloaded.
- It only pulls font files used in `@font-face`.
- If an image fails to download, the page still saves (but that image may be missing).

---

## Troubleshooting (simple fixes)

- **Images look broken**: open the page through the local web server (see “How to open the saved pages”).
- **Page looks different**: the site may load content with JavaScript after the page loads.
- **Two pages became one**: their filenames matched; edit one slug or rename the file.

---

## Where the tool lives in this repo

- `childtheme-base/download-pages.py` → the actual archiving tool
- `childtheme-base/` → optional WordPress child‑theme starter (not required)
