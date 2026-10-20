# WordPress.org listing assets

`.github/wordpress-org/` holds the images shown on the plugin's wordpress.org page.
The **Update WordPress.org assets** workflow mirrors that folder into the WordPress.org
SVN `/assets` directory. They are not in the plugin zip: `scripts/build-plugin.js`
ships an allowlist that leaves `.github/` out.

## Rules

- The folder is published as it is: an image missing here is deleted from WordPress.org.
  The workflow refuses to run while that would happen, unless `allow_removal` is ticked.
- Only images go in the folder (`.png`, `.jpg`, `.gif`, `.svg`); anything else stops the
  run. That is why this file sits next to the folder, not in it.

## Files (use these exact names)

| File | Purpose |
|---|---|
| `banner-772x250.png`  | listing banner (standard) |
| `banner-1544x500.png` | listing banner (retina / 2x) |
| `icon-256x256.png`    | plugin icon (or `icon.svg`) |
| `icon-128x128.png`    | plugin icon fallback |
| `screenshot-1.png` … `screenshot-8.png` | screenshots, numbered to match the `== Screenshots ==` captions in `readme.txt` |

The screenshot numbers map to these captions in `readme.txt`:

1. Features Overview
2. Real-time Notification Settings
3. Updates & Rollback (Real-time Notifications)
4. Dashboard Overview
5. Backup Vault
6. Malware Guard (Tailwatch Pro)
7. System Settings
8. Connect License

## How to publish

Change the images here, push to `main`, then run **Actions → Update WordPress.org
assets → Run workflow** from `main`.
