# sheaf

WordPress plugin development workspace. Targets **WordPress 7.0** / **PHP 8.3**.

Plugins live under [`plugins/`](plugins/); each subfolder is a self-contained
plugin that can be deployed by copying it into a production
`wp-content/plugins/` directory.

## Deploying Sheaf to a WordPress site

Sheaf has **no build step** — no npm, no compiled assets. Blocks register from
their `block.json` + PHP render callbacks, and the editor scripts use the
`wp.*` globals already shipped by WordPress. Deployment is just dropping the
plugin folder into place.

**Requirements on the target site:**

- WordPress **7.0** or newer
- PHP **8.3** or newer

### 1. Install it (first time)

Download the latest **`sheaf.zip`** from the releases page and upload it
through wp-admin:

> **[github.com/BiviaBen/sheaf/releases/latest](https://github.com/BiviaBen/sheaf/releases/latest)**

Each release has a ready-to-install `sheaf.zip` attached under **Assets** (it
already contains the plugin at the archive root, i.e. `sheaf/sheaf.php`). In
wp-admin go to **Plugins → Add New → Upload Plugin**, choose that zip, and
**Install**.

On a server with shell access you can instead copy the folder straight from a
clone of this repo:

```bash
rsync -a plugins/sheaf/ /path/to/wordpress/wp-content/plugins/sheaf/
```

### 2. Activate it

Via wp-admin: **Plugins → Sheaf → Activate**. Or with WP-CLI:

```bash
wp plugin activate sheaf
```

Activation sets the site to pretty permalinks (`/%postname%/`) **if no
permalink structure is set yet**, then flushes the rewrite rules so the nested
chapter URLs resolve immediately. Nested chapter URLs require pretty
permalinks — if the site already uses a custom structure, just make sure it is
not "Plain", then visit **Settings → Permalinks** once and save to flush.

### 3. Keep it updated (automatic)

After the first install you never have to hand-upload a zip again. Sheaf checks
this GitHub repo for newer releases and surfaces them in wp-admin exactly like
a plugin from the WordPress.org directory — look under **Dashboard → Updates**
or the **Plugins** screen for "Sheaf — update now", then click to update. (The
check is powered by the bundled
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
library; the repo is public, so no token or account is needed on the site.)

### 4. (Optional) Seed sample content

For a demo / smoke test, the idempotent seeder creates a few fictional books,
series Pages, and chapters:

```bash
wp eval-file wp-content/plugins/sheaf/tools/seed.php
```

It is safe to re-run; it updates the same fixtures rather than duplicating
them. Skip this on a real site.

### Using it

There is no database-driven page template. The author builds **books and
series as ordinary Pages**, then writes each chapter as a *Chapter* post
(**Sheafs → New Chapter**) assigned to its book. Reading order is the page
"Order" field (`menu_order`). Add a table of contents with the **Sheaf TOC**
block or `[sheaf_toc]`; breadcrumbs are added to chapter views automatically.

## Releasing a new version

Updates are driven by GitHub Releases. Each release carries a prebuilt
`sheaf.zip` that installed sites pull automatically (see step 3 above). Cutting
a release is just a tag:

```bash
# on master, after merging whatever you want to ship:
# 1. bump the version in plugins/sheaf/sheaf.php  (both the `Version:` header
#    and the SHEAF_VERSION constant), then commit it.
# 2. tag that commit and push the tag:
git tag v0.3.0
git push origin v0.3.0
```

Pushing the tag triggers [`.github/workflows/release.yml`](.github/workflows/release.yml),
which builds `sheaf.zip` and publishes the release. The workflow refuses to
release unless:

- the tagged commit is **already merged into `master`** (a tag on a feature
  branch produces no artifact), and
- the tag (`v0.3.0`) **matches the `Version:` header** in `sheaf.php`.

So `master` is the only branch that ever ships a zip, and the version on the
tin always matches the code inside. Use [semver](https://semver.org/): bump the
patch for fixes, the minor for backward-compatible features.

## Local development

A full WordPress 7.0 instance runs in Docker via
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env), configured in
[`.wp-env.json`](.wp-env.json).

```bash
wp-env start      # boot WordPress 7.0 at http://localhost:8888
wp-env stop       # shut it down
wp-env clean all  # wipe the database back to a fresh install
```

- **Site:** http://localhost:8888  (admin: `admin` / `password`)
- **Database:** managed by wp-env (not committed)

### Viewing from your machine

wp-env binds to `localhost:8888` on the server. Forward it over SSH:

```bash
ssh -L 8888:localhost:8888 <user>@<server>
```

then open http://localhost:8888 locally.

## Requirements (on the server)

- Docker (running)
- Node.js + `@wordpress/env` (`npm install -g @wordpress/env`)
