#!/usr/bin/env python3

import argparse
import base64
import hashlib
import json
import mimetypes
import os
import re
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import urljoin, urlparse, unquote
from threading import Lock

import requests
from bs4 import BeautifulSoup

UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
TIMEOUT = (10, 30)
DATA_URI_RE = re.compile(r"^data:(?P<mime>[^;,]+);base64,(?P<data>.+)$", re.IGNORECASE)

# CSS helpers for @font-face url(...)
FONT_FACE_BLOCK_RE = re.compile(r"@font-face\s*\{.*?\}", re.IGNORECASE | re.DOTALL)
URL_FUNC_RE = re.compile(r"url\(\s*(?P<q>['\"]?)(?P<u>[^)'\"]+)(?P=q)\s*\)", re.IGNORECASE)

WAYBACK_HOSTS = {"web.archive.org", "wayback.archive.org"}
WIN_RESERVED = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}


def ensure_dir(p: Path) -> None:
    p.mkdir(parents=True, exist_ok=True)


def guess_ext(url: str, mime: Optional[str]) -> str:
    if mime:
        ext = mimetypes.guess_extension(mime.split(";")[0].strip())
        if ext:
            return ext
    suffix = Path(urlparse(url).path).suffix
    return suffix if suffix else ".bin"


def sanitize_filename(name: str) -> str:
    name = os.path.basename(name)
    name = unquote(name, encoding="utf-8", errors="replace")
    name = re.sub(r"[\x00-\x1f\x7f]", "", name)
    name = name.replace("/", "_").replace("\\", "_").strip()
    if not name or name in {".", ".."}:
        name = "file"
    stem, ext = os.path.splitext(name)
    if stem.upper() in WIN_RESERVED:
        stem += "_"
    if not stem:
        stem = "file"
    stem = stem[:150]
    ext = ext[:20]
    return stem + ext


def parse_cd_filename(cd: str) -> Optional[str]:
    m = re.search(r'filename\*\s*=\s*([^;\r\n]+)', cd, flags=re.IGNORECASE)
    if m:
        v = m.group(1).strip()
        if v.startswith('"') and v.endswith('"'):
            v = v[1:-1]
        try:
            charset, _, enc = v.split("'", 2)
            return unquote(enc, encoding=charset or "utf-8", errors="replace")
        except Exception:
            pass
    m = re.search(r'filename\s*=\s*"([^"]+)"', cd, flags=re.IGNORECASE)
    if m:
        return m.group(1)
    m = re.search(r'filename\s*=\*?([^";\r\n]+)', cd, flags=re.IGNORECASE)
    if m:
        return m.group(1).strip()
    return None


def content_sha1(data: bytes) -> str:
    return hashlib.sha1(data).hexdigest()


def parse_srcset(srcset: str) -> List[Tuple[str, str]]:
    out: List[Tuple[str, str]] = []
    for part in [p.strip() for p in srcset.split(",") if p.strip()]:
        segs = part.split()
        url = segs[0]
        desc = segs[1] if len(segs) > 1 else ""
        out.append((url, desc))
    return out


def decode_data_uri(uri: str) -> Optional[Tuple[bytes, str]]:
    m = DATA_URI_RE.match(uri)
    if not m:
        return None
    mime = m.group("mime")
    b64 = m.group("data")
    try:
        raw = base64.b64decode(b64, validate=True)
    except Exception:
        return None
    return raw, guess_ext("data:", mime)


def download(session: requests.Session, abs_url: str) -> Tuple[bytes, Optional[str], Optional[str]]:
    r = session.get(abs_url, timeout=TIMEOUT, stream=True)
    r.raise_for_status()
    data = r.content
    cd = r.headers.get("Content-Disposition")
    ct = r.headers.get("Content-Type")
    return data, cd, ct


def derive_filename(abs_url: str, cd: Optional[str], ct: Optional[str], default_prefix: str) -> str:
    name: Optional[str] = None
    if cd:
        name = parse_cd_filename(cd)
    if not name:
        base = os.path.basename(urlparse(abs_url).path)
        name = base or default_prefix
    name = sanitize_filename(name)
    if not Path(name).suffix:
        ext_guess = guess_ext(abs_url, ct)
        if ext_guess:
            name += ext_guess
    return name


def iter_image_tags(soup: BeautifulSoup):
    for img in soup.find_all("img"):
        for attr in ("src", "data-src", "data-original"):
            if img.get(attr):
                yield img, attr, img[attr]
        if img.get("srcset"):
            yield img, "srcset", img["srcset"]
    for src in soup.find_all("source"):
        typ = (src.get("type") or "").lower()
        if typ.startswith("image/") and src.get("srcset"):
            yield src, "srcset", src["srcset"]


def iter_stylesheets(soup: BeautifulSoup):
    for link in soup.find_all("link", rel=lambda v: v and "stylesheet" in v):
        href = link.get("href")
        if href:
            yield ("external", link, href)
    for style in soup.find_all("style"):
        css = style.string if style.string is not None else style.get_text()
        if css and css.strip():
            yield ("inline", style, css)


def iter_scripts(soup: BeautifulSoup):
    for script in soup.find_all("script", src=True):
        src = script.get("src")
        if src:
            yield script, src


def extract_wayback_original(abs_url: str) -> Optional[str]:
    p = urlparse(abs_url)
    if p.netloc not in WAYBACK_HOSTS:
        return None
    if not p.path.startswith("/web/"):
        return None
    rest = p.path[len("/web/"):]
    m = re.search(r"(https?://.+)$", rest)
    if not m:
        return None
    return m.group(1)


def to_internal_root_path(original_abs_url: str) -> str:
    op = urlparse(original_abs_url)
    path = op.path or "/"
    if not path.startswith("/"):
        path = "/" + path
    if op.query:
        path += "?" + op.query
    if op.fragment:
        path += "#" + op.fragment
    return path


def rewrite_wayback_links_to_internal(soup: BeautifulSoup, base_url: str) -> int:
    count = 0
    targets = [("a", "href"), ("area", "href"), ("link", "href"), ("form", "action"), ("iframe", "src")]
    for tag_name, attr in targets:
        for el in soup.find_all(tag_name):
            if tag_name == "link":
                rel = el.get("rel") or []
                if any(r.lower() == "stylesheet" for r in (rel if isinstance(rel, list) else [rel])):
                    continue
            val = el.get(attr)
            if not val:
                continue
            abs_val = urljoin(base_url, val)
            orig = extract_wayback_original(abs_val)
            if not orig:
                continue
            el[attr] = to_internal_root_path(orig)
            count += 1
    return count


SLUG_CLEAN_RE = re.compile(r"[^a-z0-9]+")


def slugify_url(url: str) -> str:
    parsed = urlparse(url)
    path = parsed.path or ""
    slug_source = ""
    if path and path != "/":
        segs = [s for s in path.split("/") if s]
        if segs:
            last = segs[-1]
            if "." in last:
                last = last.split(".")[0]
            slug_source = last
    if not slug_source:
        slug_source = parsed.netloc or "page"
    slug = SLUG_CLEAN_RE.sub("-", slug_source.lower()).strip("-")
    return slug or "page"


def archive_page(
    url: str,
    root: Path,
    *,
    slug: str,
    shared_state: dict,
    single_page: bool,
    verbose: bool = False,
) -> Tuple[Path, List[dict]]:
    ensure_dir(root)

    now = datetime.now()
    year = f"{now.year:04d}"
    month = f"{now.month:02d}"

    # Images: standard WP uploads year/month
    uploads_rel_dir = f"wp-content/uploads/{year}/{month}"
    uploads_fs_dir = root / uploads_rel_dir
    ensure_dir(uploads_fs_dir)

    # Fonts: WP Font Library-like location (no year/month partition)
    fonts_rel_dir = "wp-content/uploads/fonts"
    fonts_fs_dir = root / fonts_rel_dir
    ensure_dir(fonts_fs_dir)

    if not single_page:
        ensure_dir(root / "templates")

    session = requests.Session()
    session.headers.update({"User-Agent": UA})

    resp = session.get(url, timeout=TIMEOUT)
    resp.raise_for_status()
    html = resp.text
    base_url = resp.url

    soup = BeautifulSoup(html, "html.parser")

    key_to_upload_url: Dict[str, str] = {}  # images only
    name_to_sha1: Dict[str, str] = {}
    events: List[dict] = []

    fname_map: Dict[str, str] = shared_state["image_fname_to_urlpath"]
    fname_lock: Lock = shared_state["lock"]

    def uniquify_name(name: str, sha1: str) -> str:
        if name not in name_to_sha1:
            name_to_sha1[name] = sha1
            return name
        if name_to_sha1[name] == sha1:
            return name
        stem, ext = os.path.splitext(name)
        i = 1
        while True:
            cand = f"{stem}-{i}{ext}"
            if cand not in name_to_sha1:
                name_to_sha1[cand] = sha1
                return cand
            i += 1

    def save_image(data: bytes, desired_name: str) -> Tuple[str, str, str]:
        sha1 = content_sha1(data)
        final_name = uniquify_name(desired_name, sha1)
        dest = uploads_fs_dir / final_name
        if not dest.exists():
            with open(dest, "wb") as f:
                f.write(data)
        url_path = f"/{uploads_rel_dir}/{final_name}"
        return url_path, str(dest), sha1

    def save_font(data: bytes, desired_name: str) -> Tuple[str, str, str]:
        sha1 = content_sha1(data)
        final_name = uniquify_name(desired_name, sha1)
        dest = fonts_fs_dir / final_name
        if not dest.exists():
            with open(dest, "wb") as f:
                f.write(data)
        url_path = f"/{fonts_rel_dir}/{final_name}"
        return url_path, str(dest), sha1

    def reuse_or_mark_image(abs_url: str) -> Optional[str]:
        base = os.path.basename(urlparse(abs_url).path) or "image"
        filename_key = sanitize_filename(base)
        with fname_lock:
            existing = fname_map.get(filename_key)
        if existing:
            if verbose:
                print(f"image: reuse {abs_url} -> {existing} (by filename)")
            events.append({
                "kind": "image-reuse",
                "source_type": "http",
                "source": abs_url,
                "saved_url": existing,
                "filename_key": filename_key,
            })
            return existing
        return None

    def record_new_image(abs_url: str, saved_url: str) -> None:
        base = os.path.basename(urlparse(abs_url).path) or "image"
        filename_key = sanitize_filename(base)
        with fname_lock:
            fname_map.setdefault(filename_key, saved_url)

    def choose_font_mime(ct: Optional[str], filename: str) -> str:
        if ct:
            mime = ct.split(";", 1)[0].strip()
            if mime:
                return mime
        ext = Path(filename).suffix.lower()
        if ext == ".woff2":
            return "font/woff2"
        if ext == ".woff":
            return "font/woff"
        if ext == ".ttf":
            return "font/ttf"
        if ext == ".otf":
            return "font/otf"
        if ext == ".eot":
            return "application/vnd.ms-fontobject"
        if ext == ".svg":
            return "image/svg+xml"
        return "application/octet-stream"

    def rewrite_fonts_in_css(css_text: str, css_base_url: str) -> str:
        """
        Only touch @font-face blocks. For each url(...) inside them:
        - Download/save the font file into wp-content/uploads/fonts
        - Embed it via data:...;base64,... in the CSS, so the HTML is standalone.
        """
        font_data_cache: Dict[str, str] = {}  # abs_url or data-URI -> data URL string

        def repl(murl):
            u = murl.group("u").strip()

            # If already a data: URL, just save to disk (once) and keep as-is.
            if u.lower().startswith("data:"):
                dd = decode_data_uri(u)
                if not dd:
                    return murl.group(0)
                data, ext = dd
                desired = f"inline-font{len(events)}{ext}"
                key = u
                if key not in font_data_cache:
                    saved_url, fs_path, sha1 = save_font(data, sanitize_filename(desired))
                    events.append({
                        "kind": "font",
                        "source_type": "data",
                        "source": "data:",
                        "saved_url": saved_url,
                        "saved_path": str(Path(fs_path).relative_to(root)),
                        "bytes": len(data),
                        "sha1": sha1,
                        "filename": Path(fs_path).name,
                    })
                    if verbose:
                        print(f"font: data: -> {saved_url} ({len(data)} bytes; embedded)")
                    font_data_cache[key] = u  # reuse same data URL if seen again
                return f'url("{u}")'

            absu = urljoin(css_base_url, u)

            if absu in font_data_cache:
                return f'url("{font_data_cache[absu]}")'

            try:
                data, cd, ct = download(session, absu)
            except Exception as e:
                if verbose:
                    print(f"skip font: {absu} ({e})")
                return murl.group(0)

            desired_name = derive_filename(absu, cd, ct, default_prefix="font")
            saved_url, fs_path, sha1 = save_font(data, desired_name)
            mime = choose_font_mime(ct, desired_name)
            b64 = base64.b64encode(data).decode("ascii")
            data_url = f"data:{mime};base64,{b64}"

            font_data_cache[absu] = data_url
            events.append({
                "kind": "font",
                "source_type": "http",
                "source": absu,
                "saved_url": saved_url,
                "saved_path": str(Path(fs_path).relative_to(root)),
                "bytes": len(data),
                "sha1": sha1,
                "filename": Path(fs_path).name,
            })
            if verbose:
                print(f"font: {absu} -> {saved_url} ({len(data)} bytes; embedded as data URL)")
            return f'url("{data_url}")'

        new_css = css_text
        out_parts: List[str] = []
        pos = 0
        for m in FONT_FACE_BLOCK_RE.finditer(new_css):
            start, end = m.span()
            out_parts.append(new_css[pos:start])

            block = m.group(0)
            block_rewritten = URL_FUNC_RE.sub(repl, block)
            out_parts.append(block_rewritten)

            pos = end

        out_parts.append(new_css[pos:])
        return "".join(out_parts)

    # === Images ===
    for tag, attr, raw in iter_image_tags(soup):
        if attr == "srcset":
            entries = parse_srcset(raw)
            new_entries: List[str] = []
            first_url: Optional[str] = None
            for u, desc in entries:
                dd = decode_data_uri(u)
                if dd:
                    data, ext = dd
                    desired = f"inline{len(events)}{ext}"
                    key = u
                    url_path = key_to_upload_url.get(key)
                    if not url_path:
                        url_path, fs_path, sha1 = save_image(data, sanitize_filename(desired))
                        key_to_upload_url[key] = url_path
                        events.append({
                            "kind": "image",
                            "source_type": "data",
                            "source": "data:",
                            "saved_url": url_path,
                            "saved_path": str(Path(fs_path).relative_to(root)),
                            "bytes": len(data),
                            "sha1": sha1,
                            "filename": Path(fs_path).name,
                        })
                        if verbose:
                            print(f"image: data: -> {url_path} ({len(data)} bytes)")
                else:
                    abs_url = urljoin(base_url, u)
                    url_path = key_to_upload_url.get(abs_url)
                    if not url_path:
                        reused = reuse_or_mark_image(abs_url)
                        if reused:
                            url_path = reused
                        else:
                            try:
                                data, cd, ct = download(session, abs_url)
                            except Exception as e:
                                if verbose:
                                    print(f"skip: {abs_url} ({e})")
                                continue
                            desired_name = derive_filename(abs_url, cd, ct, default_prefix="image")
                            url_path, fs_path, sha1 = save_image(data, desired_name)
                            key_to_upload_url[abs_url] = url_path
                            record_new_image(abs_url, url_path)
                            events.append({
                                "kind": "image",
                                "source_type": "http",
                                "source": abs_url,
                                "saved_url": url_path,
                                "saved_path": str(Path(fs_path).relative_to(root)),
                                "bytes": len(data),
                                "sha1": sha1,
                                "filename": Path(fs_path).name,
                            })
                            if verbose:
                                print(f"image: {abs_url} -> {url_path} ({len(data)} bytes)")
                if first_url is None:
                    first_url = url_path
                new_entries.append(f"{url_path} {desc}".strip())
            if new_entries:
                tag[attr] = ", ".join(new_entries)
                if tag.name == "img" and not tag.get("src") and first_url:
                    tag["src"] = first_url
            continue

        val = raw
        dd = decode_data_uri(val)
        if dd:
            data, ext = dd
            desired = f"inline{len(events)}{ext}"
            key = val
            url_path = key_to_upload_url.get(key)
            if not url_path:
                url_path, fs_path, sha1 = save_image(data, sanitize_filename(desired))
                key_to_upload_url[key] = url_path
                events.append({
                    "kind": "image",
                    "source_type": "data",
                    "source": "data:",
                    "saved_url": url_path,
                    "saved_path": str(Path(fs_path).relative_to(root)),
                    "bytes": len(data),
                    "sha1": sha1,
                    "filename": Path(fs_path).name,
                })
                if verbose:
                    print(f"image: data: -> {url_path} ({len(data)} bytes)")
            tag[attr] = url_path
            continue

        abs_url = urljoin(base_url, val)
        url_path = key_to_upload_url.get(abs_url)
        if not url_path:
            reused = reuse_or_mark_image(abs_url)
            if reused:
                url_path = reused
            else:
                try:
                    data, cd, ct = download(session, abs_url)
                except Exception as e:
                    if verbose:
                        print(f"skip: {abs_url} ({e})")
                    continue
                desired_name = derive_filename(abs_url, cd, ct, default_prefix="image")
                url_path, fs_path, sha1 = save_image(data, desired_name)
                key_to_upload_url[abs_url] = url_path
                record_new_image(abs_url, url_path)
                events.append({
                    "kind": "image",
                    "source_type": "http",
                    "source": abs_url,
                    "saved_url": url_path,
                    "saved_path": str(Path(fs_path).relative_to(root)),
                    "bytes": len(data),
                    "sha1": sha1,
                    "filename": Path(fs_path).name,
                })
                if verbose:
                    print(f"image: {abs_url} -> {url_path} ({len(data)} bytes)")
        tag[attr] = url_path

    # === CSS + Fonts ===
    for kind, node, payload in list(iter_stylesheets(soup)):
        if kind == "external":
            href = payload
            abs_css_url = urljoin(base_url, href)
            try:
                r = session.get(abs_css_url, timeout=TIMEOUT)
                r.raise_for_status()
                css_text = r.text
            except Exception as e:
                if verbose:
                    print(f"skip stylesheet: {abs_css_url} ({e})")
                continue
            rewritten = rewrite_fonts_in_css(css_text, abs_css_url)
            style_tag = soup.new_tag("style")
            style_tag.string = rewritten
            node.replace_with(style_tag)
            events.append({
                "kind": "css-inline-external",
                "source": abs_css_url,
                "bytes": len(rewritten.encode("utf-8")),
            })
            if verbose:
                print(f"css-inline: {abs_css_url} -> <style> inlined")
        else:
            css_text = payload
            rewritten = rewrite_fonts_in_css(css_text, base_url)
            node.clear()
            node.append(rewritten)
            events.append({
                "kind": "css-inline",
                "bytes": len(rewritten.encode("utf-8")),
            })
            if verbose:
                print("css-inline: rewritten existing <style>")

    # === JS ===
    for script_tag, src in list(iter_scripts(soup)):
        abs_js_url = urljoin(base_url, src)
        try:
            data, cd, ct = download(session, abs_js_url)
        except Exception as e:
            if verbose:
                print(f"skip script: {abs_js_url} ({e})")
            continue
        try:
            js_text = data.decode("utf-8")
        except UnicodeDecodeError:
            js_text = data.decode("latin-1", errors="replace")
        if "src" in script_tag.attrs:
            del script_tag["src"]
        script_tag.string = js_text
        events.append({
            "kind": "js-inline",
            "source": abs_js_url,
            "bytes": len(data),
        })
        if verbose:
            print(f"js-inline: {abs_js_url} -> inline <script>")

    rewritten_count = rewrite_wayback_links_to_internal(soup, base_url)
    if verbose:
        print(f"wayback->internal: {rewritten_count} link(s)")

    slug_safe = slug or slugify_url(base_url)
    if single_page:
        outfile = root / f"page-{slug_safe}.html"
    else:
        templates_dir = root / "templates"
        ensure_dir(templates_dir)
        outfile = templates_dir / f"page-{slug_safe}.html"

    outfile.write_text(str(soup), encoding="utf-8")
    events.append({
        "kind": "page",
        "source": base_url,
        "saved_url": str(outfile.relative_to(root)),
        "saved_path": str(outfile.relative_to(root)),
        "bytes": len(str(soup).encode("utf-8")),
    })

    return outfile, events


def read_urls_from_file(path: Path) -> List[str]:
    urls: List[str] = []
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            urls.append(line)
    return urls


def main():
    parser = argparse.ArgumentParser(
        description=(
            "Archive one or more pages into a WordPress-like folder structure:\n"
            " - images -> wp-content/uploads/YYYY/MM\n"
            " - fonts  -> wp-content/uploads/fonts (also embedded as data: URLs)\n"
            " - single URL: page-{slug}.html in <foldername>\n"
            " - list: templates/page-{slug}.html in <foldername>/templates\n"
            "CSS and JS are inlined into the HTML."
        )
    )
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--url", help="Single page URL to archive")
    group.add_argument("--list", help="Path to a text file with one URL per line")
    parser.add_argument("-o", "--output", required=True, help="Output root folder name")
    parser.add_argument("-v", "--verbose", action="store_true", help="Log each saved file")
    parser.add_argument(
        "--workers",
        type=int,
        default=10,
        help="Number of parallel workers for pages",
    )
    parser.add_argument(
        "--manifest",
        help="Path to JSONL manifest (default: <output>/manifest.jsonl)",
    )

    args = parser.parse_args()

    root = Path(args.output).resolve()
    if root.suffix.lower() == ".html":
        root = root.with_suffix("")
    ensure_dir(root)

    single_page = bool(args.url)

    shared_state = {
        "image_fname_to_urlpath": {},
        "lock": Lock(),
    }

    if args.url:
        urls = [args.url]
    else:
        urls = read_urls_from_file(Path(args.list))

    if not urls:
        print("No URLs to process.", file=sys.stderr)
        sys.exit(1)

    manifest_path = (
        Path(args.manifest).resolve()
        if args.manifest
        else (root / "manifest.jsonl")
    )

    all_events: List[dict] = []
    max_workers = max(1, min(args.workers, len(urls)))
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        future_to_url = {}
        for u in urls:
            slug = slugify_url(u)
            future = executor.submit(
                archive_page,
                u,
                root,
                slug=slug,
                shared_state=shared_state,
                single_page=single_page,
                verbose=args.verbose,
            )
            future_to_url[future] = u
        for future in as_completed(future_to_url):
            u = future_to_url[future]
            try:
                html_path, events = future.result()
            except Exception as e:
                print(f"Error archiving {u}: {e}", file=sys.stderr)
                continue
            all_events.extend(events)
            print(f"Saved: {html_path}")

    try:
        with open(manifest_path, "w", encoding="utf-8") as mf:
            for e in all_events:
                mf.write(json.dumps(e, ensure_ascii=False) + "\n")
    except Exception as e:
        print(f"Error writing manifest: {e}", file=sys.stderr)
        sys.exit(1)

    print(f"Output root: {root}")
    if not single_page:
        print(f"Templates dir: {root / 'templates'}")
    print(f"Images dir: {root / 'wp-content' / 'uploads'}")
    print(f"Fonts dir: {root / 'wp-content' / 'uploads' / 'fonts'}")
    print(f"Manifest: {manifest_path}")


if __name__ == "__main__":
    main()

