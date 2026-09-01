# WordPress.org listing assets

Images shown on the plugin's wordpress.org page — banner, icon, and screenshots.
The **Update WordPress.org assets** workflow pushes everything in this folder to the
WordPress.org SVN `/assets` directory. These are **not** bundled in the plugin zip
(the whole `.github/` folder is excluded from the release).

## Expected files (use these exact names)

| File | Purpose |
|---|---|
| `banner-772x250.png`  | listing banner (standard) |
| `banner-1544x500.png` | listing banner (retina / 2x) |
| `icon-256x256.png`    | plugin icon (or use `icon.svg`) |
| `icon-128x128.png`    | plugin icon fallback (optional) |
| `screenshot-1.png` … `screenshot-6.png` | screenshots, numbered to match the `== Screenshots ==` captions in `readme.txt` |

PNG or JPG are both fine. The screenshot numbers map to these captions in `readme.txt`:

1. Features Overview
2. Real-time Notification Settings
3. Updates & Rollback (Real-time Notifications)
4. Dashboard Overview
5. Backup Vault
6. Malware Guard

## How to publish

Add or update the images here, commit, then run **Actions → Update WordPress.org assets → Run workflow**.
