#!/usr/bin/env python3
"""Converts an Extras gallery font to .cpfont files for the X3.

Run by .github/workflows/convert-fonts.yml. The admin page starts the workflow
with a job id; this script fetches the font's TTF/OTF files through
/extras/admin/convert.php, runs QuartzInk's own converter
(lib/EpdFont/scripts/fontconvert_sdcard.py, the one that makes the
downloadable reading fonts), and posts the .cpfont files back. Every request
carries an HMAC of the job id keyed by the token, so convert.php knows the
request comes from this job.

Environment: JOB, TOKEN, SITE (https://quartz.timcordova.com), CONVERTER
(path to fontconvert_sdcard.py), FALLBACK (a font for glyphs the font lacks).
"""

import hashlib
import hmac
import os
import subprocess
import sys
import tempfile
import urllib.parse
from pathlib import Path

import requests
from fontTools.ttLib import TTFont

# The same character set and sizes as QuartzInk's downloadable Latin fonts.
INTERVALS = "latin-ext"
SIZES = "12,14,16,18"
STYLES = ("regular", "bold", "italic", "bolditalic")

JOB = os.environ["JOB"]
TOKEN = os.environ["TOKEN"]
SITE = os.environ["SITE"].rstrip("/")
CONVERTER = os.environ["CONVERTER"]
FALLBACK = os.environ.get("FALLBACK", "")


def endpoint() -> str:
    sig = hmac.new(TOKEN.encode(), JOB.encode(), hashlib.sha256).hexdigest()
    return f"{SITE}/extras/admin/convert.php?" + urllib.parse.urlencode({"job": JOB, "sig": sig})


def style_of(font: TTFont) -> str:
    """regular, bold, italic or bolditalic, from the font's own flags."""
    bold = italic = False
    if "OS/2" in font:
        selection = font["OS/2"].fsSelection
        italic = bool(selection & 0x01)
        bold = bool(selection & 0x20)
    elif "head" in font:
        mac = font["head"].macStyle
        bold = bool(mac & 0x01)
        italic = bool(mac & 0x02)
    return ("bold" if bold else "") + ("italic" if italic else "") or "regular"


def pinned(path: Path, font: TTFont, weight: int, workdir: Path) -> Path:
    """A static copy of a variable font at one weight; FreeType can't pick one."""
    from fontTools.varLib.instancer import instantiateVariableFont

    axes = {axis.axisTag: axis for axis in font["fvar"].axes}
    location = {tag: axis.defaultValue for tag, axis in axes.items()}
    if "wght" in axes:
        location["wght"] = max(axes["wght"].minValue, min(axes["wght"].maxValue, weight))
    static = instantiateVariableFont(TTFont(path), location, updateFontNames=False)
    out = workdir / f"{path.stem}-{weight}.ttf"
    static.save(out)
    return out


def assign_styles(paths: list[Path], workdir: Path) -> dict[str, Path]:
    styles: dict[str, Path] = {}
    variable: list[tuple[Path, TTFont, bool]] = []
    for path in paths:
        font = TTFont(path, lazy=True)
        if "fvar" in font:
            variable.append((path, font, style_of(font).endswith("italic")))
            continue
        style = style_of(font)
        if style in styles:
            print(f"{path.name}: another file is already {style}; skipping it")
            continue
        styles[style] = path
        print(f"{path.name}: {style}")

    # A variable font fills the regular and bold slots it covers (and the
    # italic ones for an italic variable font) that no static file filled.
    for path, font, italic in variable:
        wanted = {("italic" if italic else "regular"): 400, ("bolditalic" if italic else "bold"): 700}
        axes = {axis.axisTag: axis for axis in font["fvar"].axes}
        for style, weight in wanted.items():
            if style in styles:
                continue
            if weight == 700 and ("wght" not in axes or axes["wght"].maxValue < 600):
                continue
            styles[style] = pinned(path, font, weight, workdir)
            print(f"{path.name}: {style} (weight {weight})")

    if "regular" not in styles:
        # Use the first style there is as the regular one.
        first = next(style for style in STYLES if style in styles)
        styles["regular"] = styles.pop(first)
        print(f"No regular style; using {first} as the regular one")
    return styles


def convert(family: str, styles: dict[str, Path], outdir: Path) -> list[Path]:
    cmd = [sys.executable, CONVERTER, "--intervals", INTERVALS, "--sizes", SIZES,
           "--name", family, "--output-dir", str(outdir) + "/"]
    if len(styles) > 1:
        for style, path in styles.items():
            cmd += [f"--{style}", str(path)]
            if FALLBACK:
                cmd += [f"--fallback-{style}", FALLBACK]
    else:
        cmd += [str(styles["regular"]), "--style", "regular"]
        if FALLBACK:
            cmd += ["--fallback-regular", FALLBACK]
    result = subprocess.run(cmd, capture_output=True, text=True)
    print(result.stdout + result.stderr)
    if result.returncode != 0:
        lines = [line for line in result.stderr.splitlines() if line.strip()]
        raise RuntimeError(lines[-1] if lines else f"the converter exited with {result.returncode}")
    files = sorted(outdir.glob(f"{family}_*.cpfont"))
    if not files:
        raise RuntimeError("the converter made no files")
    return files


def main() -> None:
    url = endpoint()
    response = requests.get(url, timeout=60)
    if response.status_code != 200:
        # Nothing to report to: the job is unknown, done, or the font is gone.
        sys.exit(f"convert.php said {response.status_code}: {response.text[:200]}")
    manifest = response.json()
    family = manifest["family"]

    try:
        with tempfile.TemporaryDirectory() as tmp:
            workdir = Path(tmp)
            sources = workdir / "sources"
            sources.mkdir()
            paths = []
            for file in manifest["files"]:
                if not file["url"].startswith(SITE + "/"):
                    raise RuntimeError(f"{file['name']} is not on {SITE}")
                name = Path(file["name"]).name
                download = requests.get(file["url"], timeout=120)
                download.raise_for_status()
                (sources / name).write_bytes(download.content)
                paths.append(sources / name)
            if not paths:
                raise RuntimeError("the font has no TTF or OTF files")

            styles = assign_styles(paths, workdir)
            outdir = workdir / "out"
            outdir.mkdir()
            made = convert(family, styles, outdir)

            handles = [("files[]", (p.name, p.open("rb"), "application/octet-stream")) for p in made]
            try:
                saved = requests.post(url, files=handles, timeout=300)
            finally:
                for _, (_, handle, _) in handles:
                    handle.close()
            if saved.status_code != 200:
                raise RuntimeError(f"the site would not take the files ({saved.status_code}: {saved.text[:200]})")
            print("Saved:", ", ".join(saved.json().get("saved", [])))
    except Exception as error:  # Report every failure to the admin page.
        message = str(error) or type(error).__name__
        requests.post(url, data={"error": message}, timeout=60)
        sys.exit(f"Failed: {message}")


if __name__ == "__main__":
    main()
