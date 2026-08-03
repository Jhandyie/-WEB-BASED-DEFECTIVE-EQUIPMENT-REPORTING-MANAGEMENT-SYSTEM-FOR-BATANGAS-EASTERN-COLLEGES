"""
Subset the Font Awesome solid webfont down to the icons this project actually uses.

The full fa-solid-900.woff2 is ~153 KB and carries well over a thousand glyphs;
this system draws a few dozen. Every visitor downloads the rest for nothing.

The icon list is collected from the source, not guessed:

  * every literal ``fa-name`` written in a class attribute, and
  * every name that can reach a class attribute through a template
    (``fa-${cond ? 'check' : 'clock'}``, a lookup table like nIcon(), a toast
    helper). Those are listed in DYNAMIC below, because a subsetter cannot see
    them and an icon that goes missing at runtime shows as nothing at all.

Names are mapped to codepoints by reading the ``.fa-x:before{content:"\\fxxx"}``
rules out of the vendored all.min.css, so the mapping always matches the font
that ships with the project.

    python scripts/subset_icons.py            # report what would be kept
    python scripts/subset_icons.py --apply    # write the subset

The original is kept beside it as fa-solid-900.full.woff2. Re-run this after
adding icons to a page; if a glyph is missing the icon renders blank.
"""
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CSS = os.path.join(ROOT, "assets", "vendor", "fontawesome", "css", "all.min.css")
FONT = os.path.join(ROOT, "assets", "vendor", "fontawesome", "webfonts", "fa-solid-900.woff2")
BACKUP = os.path.join(ROOT, "assets", "vendor", "fontawesome", "webfonts", "fa-solid-900.full.woff2")

# Names that only ever appear inside a template expression. Keep this in step
# with the code: grep for "fa-${" and "fa-' +" before trusting it.
DYNAMIC = {
    # admin_work_orders.php, api/student_dashboard_api.php — progress steps
    "check", "wrench", "clock",
    # api/student_dashboard_api.php — nIcon() notification map
    "exclamation-triangle", "calendar-check", "info-circle", "check-circle",
    "times-circle", "reply", "bell",
    # toast() helpers
    "exclamation-circle",
}

SKIP_DIRS = {"backups", "uploads", "node_modules", ".git", "_originals"}


def used_names():
    names = set(DYNAMIC)
    pattern = re.compile(r"fa-([a-z0-9-]+)")
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        if "vendor" in base.replace("\\", "/").split("/"):
            continue
        for name in files:
            if not name.endswith((".php", ".html", ".js", ".css")):
                continue
            path = os.path.join(base, name)
            try:
                text = open(path, encoding="utf-8", errors="ignore").read()
            except OSError:
                continue
            for m in pattern.finditer(text):
                names.add(m.group(1))
    return names


def css_map():
    text = open(CSS, encoding="utf-8", errors="ignore").read()
    mapping = {}
    for m in re.finditer(r"\.fa-([a-z0-9-]+):before\s*\{\s*content:\s*[\"']\\([0-9a-fA-F]+)[\"']", text):
        mapping[m.group(1)] = int(m.group(2), 16)
    return mapping


def main():
    apply = "--apply" in sys.argv
    names = used_names()
    mapping = css_map()

    keep, unknown = {}, []
    for n in sorted(names):
        if n in mapping:
            keep[n] = mapping[n]
        else:
            unknown.append(n)

    print(f"{len(names)} fa-* names found in the source")
    print(f"{len(keep)} map to a glyph in all.min.css")
    if unknown:
        print(f"{len(unknown)} are not icon names (style/size/utility classes), ignored")

    size_before = os.path.getsize(FONT)
    print(f"\nfa-solid-900.woff2 is {size_before/1024:.0f} KB")
    if not apply:
        print("\ndry run — re-run with --apply to write the subset")
        return 0

    if not os.path.exists(BACKUP):
        with open(FONT, "rb") as src, open(BACKUP, "wb") as dst:
            dst.write(src.read())
        print(f"kept the full font as {os.path.basename(BACKUP)}")

    unicodes = ",".join(f"U+{cp:04X}" for cp in sorted(set(keep.values())))
    out = FONT + ".subset"
    cmd = [sys.executable, "-m", "fontTools.subset", BACKUP,
           f"--unicodes={unicodes}", "--flavor=woff2",
           "--layout-features=", "--no-hinting", "--desubroutinize",
           f"--output-file={out}"]
    subprocess.run(cmd, check=True)

    size_after = os.path.getsize(out)
    os.replace(out, FONT)
    print(f"\n{len(set(keep.values()))} glyphs kept")
    print(f"{size_before/1024:.0f} KB -> {size_after/1024:.0f} KB "
          f"({100 - size_after*100//size_before}% smaller)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
