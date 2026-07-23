  <a href="https://wptailwatch.com/?utm_source=github&utm_medium=readme&utm_campaign=free&utm_content=wptailwatch_logo"><img src=".github/assets/logo.png" alt="WP Tailwatch – Security, Backups & Monitoring" width="320"></a>
  # Contributing to WP Tailwatch
Thank you for considering contributing to WP Tailwatch! We welcome bug fixes, feature enhancements, and documentation improvements.
This repository contains the **WP Tailwatch Plugin** — including the core PHP backend running inside WordPress and the React application powering the admin dashboard (`admin-app/`).

---
## 🚀 Quick Start & Local Setup

### 1. Environment Requirements
- Node.js (v18 or higher)
- PHP (v7.4 or higher)
- A local WordPress development environment (e.g., LocalWP, Docker, or XAMPP)

### 2. Installation
1. **Fork** this repository and clone your fork to your local machine.
2. Symlink or copy the repository folder into your local WordPress plugins directory:
   `wp-content/plugins/tailwatch`
3. Activate **WP Tailwatch** inside your WordPress Admin dashboard under **Plugins**.

---

## 🛠️ Development & Build Commands

All build scripts are managed from the root of the repository:

- `npm install` — Install dependencies for the React admin dashboard (`admin-app/`).
- `npm run dev:playground` — Spin up a local throwaway WordPress instance for quick testing.
- `npm run watch` — Watch source files and automatically recompile + sync bundles on every save.
- `npm run build` — Build the production React bundle and place assets into `Admin/View/Static/`.

> 💡 **Detailed Guides:** For complete architecture notes and build specs, see [`README.md`](../README.md).

---

## 🎨 Code Standards

To keep the codebase maintainable, please follow these guidelines:

- **PHP Backend:** Follow the official [WordPress PHP Coding Standards (WPCS)](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- **React Admin App:** Ensure clean component patterns, proper prop typing, and formatted Tailwind CSS classes.

---

## 🔄 Submitting Changes

1. **Create a topic branch:**
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/your-bug-fix
   ```
2. **Make your changes:** Keep commits focused, clean, and write clear commit messages.
3. **Build before committing:** If you modified files in `admin-app/`, run `npm run build` to ensure compiled assets in `Admin/View/Static/` are updated.
4. **Push and submit:** Push your branch to your fork and open a **Pull Request** targeting the `main` branch of this repository.

---

## 🐛 Reporting Issues

If you encounter a bug or have a feature request, please use [GitHub Issues](https://github.com/wptailwatch/tailwatch/issues).

For **security vulnerabilities**, please refer to our [`SECURITY.md`](SECURITY.md) to report them responsibly rather than opening a public issue.

---

## 📄 License

By contributing to WP Tailwatch, you agree that your contributions will be licensed under the **GNU General Public License v2 or later (GPLv2+)**. See [`LICENSE`](../LICENSE) for details.
