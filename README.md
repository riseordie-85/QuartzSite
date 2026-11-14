# Quartz site

The homepage for the Quartz apps. It's built with [Astro](https://astro.build), which turns Markdown files and templates into a plain HTML site. The pages are plain HTML. The one exception is the Extras upload page (see below), a single PHP file with no database.

## Astro in five minutes

You need Node.js 22 or newer.

```bash
npm install        # once, after unzipping
npm run dev        # live preview at http://localhost:4321, reloads as you save
npm run dev:mock   # same, but with a fake release so you can see the download box
npm run build      # writes the finished site to dist/
npm run preview    # serves dist/ so you can check the real build
```

An `.astro` file is HTML with a block of JavaScript at the top, between two `---` lines. That block runs **at build time**, on your computer or in GitHub Actions, never in the visitor's browser. It's where pages load app data and ask GitHub for releases. `<style>` tags in an `.astro` file only affect that file.

## Where things are

```
src/
  content/apps/        one Markdown file per app — this is where you edit content
  content.config.ts    the fields an app file can have (a typo fails the build with a clear message)
  pages/index.astro    the hub page
  pages/[slug].astro   the template for every app page (notes.md becomes /notes/)
  components/          Crystal, AppMark (icons), AppList, DownloadBox
  layouts/Base.astro   header, footer, <head>
  lib/github.ts        reads the latest GitHub release at build time
  styles/global.css    colors, type scale, shared styles
  site.ts              Ko-fi, charity and GitHub links
public/                copied to the site as-is (favicon, icons, screenshots)
```

## Editing an app

Open `src/content/apps/notes.md`. The part between the `---` lines holds the structured data: status, features, privacy lines and so on. The text below it is the "about" paragraph.

- **Status:** `released`, `beta`, `in-development` or `planned`. The dot next to the status changes to match.
- **Stone:** the app's color (`rose`, `citrine`, `blue`, `amethyst`, `aventurine`, `clear`). It also sets which crystal facet the app lights.
- **Order:** the app's position in the list and its facet on the crystal (1–6).
- **Features:** add `planned: true` to put a feature under "Coming later".
- **Icon:** put your icon at `public/icons/notes.png` and add `icon: /icons/notes.png`. Without one, the app gets a crystal with its facet lit.
- **Screenshots:** put images in `public/screenshots/notes/` and list them:

  ```yaml
  screenshots:
    - src: /screenshots/notes/shelves.png
      alt: Two shelves of notebooks
      device: phone   # phone, fold or tablet
  ```

  The dashed placeholder frames only show in `npm run dev`. The live site never shows them.

To add an app, copy one of the files, rename it (the file name becomes the URL) and change the fields.

The wording is a first draft based on what each app does today. Read it over, especially the feature lists and the privacy lines, because those are promises.

## Downloads come from GitHub Releases

When a repo has a published release, the app page shows the version, date, size, checksum and a download button for the release's `.apk` (or `.bin`/`.uf2`/`.zip` for firmware). The changelog comes from the release description, written in Markdown.

To publish a version: in the app repo, go to **Releases → Draft a new release**, create a tag like `v1.0.0`, attach your signed APK, write the notes and publish. The site picks it up the next time it builds.

If an app's code is **private**, visitors can't download its release files or open its repo. Keep the code private and create a small **public** repo just for releases (for example `riseordie-85/QuartzGallery-Releases`). Publish releases there, and name it in the app's file:

```yaml
releases: riseordie-85/QuartzGallery-Releases
```

Leave `repo:` out for private code. It's only for public source links.

## Deploying to GoDaddy

1. In cPanel, create the subdomain `quartz.timcordova.com` and note its folder. Create an FTP account for that folder too.
2. The site lives at `riseordie-85/QuartzSite`. Clone it, make changes, and push.
3. In the repo, go to **Settings → Secrets and variables → Actions** and add `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` and `FTP_DIR`. The workflow file explains each one.
4. Push to `main`. The **Actions** tab shows the build, then the upload.

The site rebuilds on every push, once a day, and from **Actions → Build and deploy → Run workflow**. If the upload fails with a certificate error, change `protocol: ftps` to `protocol: ftp` in `.github/workflows/deploy.yml`.

**Optional: update the site the moment you publish a release.** Add this workflow to an app repo. It needs a secret `SITE_TOKEN` that's allowed to trigger workflows on the site repo.

```yaml
name: Update Quartz site
on:
  release:
    types: [published]
jobs:
  ping:
    runs-on: ubuntu-latest
    steps:
      - run: |
          curl -X POST \
            -H "Authorization: Bearer ${{ secrets.SITE_TOKEN }}" \
            -H "Accept: application/vnd.github+json" \
            https://api.github.com/repos/riseordie-85/QuartzSite/dispatches \
            -d '{"event_type":"app-released"}'
```

## Extras: wallpapers, overlays and fonts for QuartzInk

`/extras/` is a gallery of wallpapers, overlays and fonts for QuartzInk e-readers. The reader's own web dashboard
(**Extras** at `http://quartzink.local`) reads the same list and installs items onto the device.

You add things at **`/extras/admin/`**, a password-protected upload page. Pick the type, give it a name and a credit,
choose the file (or, for a font, all of its style files plus any `.cpfont` packs for the X3) and press Add. It shows up
in the gallery and on every reader straight away; there's no deploy.

- **Password:** add a repository secret named `EXTRAS_ADMIN_PASSWORD` (Settings > Secrets and variables > Actions),
  then run the deploy. Only a hash of it is sent to the server. To change it, update the secret and deploy again.
- **Where things live:** uploads go to `/extras/files/` and the list to `/extras/catalog.json` on the server. They are
  not in this repo, and the deploy never touches them, so download a copy of that folder now and then as a backup.
- **Previews** are made on upload: a small JPEG for wallpapers, a PNG for overlays and a sample image for fonts.
- **Sizes:** the upload page accepts up to 24 MB per file (`public/extras/admin/.user.ini`).
- **Overlays** made at 480 × 800 fit the X4, X4 Pro and Picco and stretch to the X3.

The gallery page (`src/pages/extras.astro`) and the readers load `catalog.json` in the browser.
`public/extras/.htaccess` lets readers' dashboards fetch it from another address.


## Install page for QuartzInk (/flash/)

`src/pages/flash.astro` installs QuartzInk over USB from Chrome or Edge, using Espressif's
esptool-js (Web Serial). It supports the Xteink X3/X4, X4 Pro and Boox Picco.

- The firmware comes from QuartzInk's `latest-build` release, which its CI replaces on every
  push to `main`. GitHub release files can't be fetched by a browser (no CORS), so
  `public/flash/firmware.php` downloads them on the server, keeps one copy per device in
  `public/flash/cache/` (created on first use), and serves them from this site. It checks
  GitHub for a new build at most every five minutes.
- Before writing anything, the page reads the reader's chip and partition table and stops
  unless they match the reader picked. X3/X4 and X4 Pro get the app at 0x10000 and a cleared
  otadata (so they boot it even after an SD update). The Picco gets the app at 0xE0000 only;
  its otadata is left alone, because a cleared one would boot its recovery partition.
