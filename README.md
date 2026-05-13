# 🚀 Auto Backup Gdrive

[![WordPress Version](https://img.shields.io/badge/WordPress-5.0+-blue.svg)](https://wordpress.org/plugins/auto-backup-gdrive/)
[![Stable Version](https://img.shields.io/badge/Version-1.1.2-orange.svg)](https://wordpress.org/plugins/auto-backup-gdrive/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](LICENSE)

**Auto Backup Gdrive** is a powerful, lightweight, and modern WordPress plugin designed to securely back up your entire website and database directly to your Google Drive. Featuring a premium dashboard and a seamless recovery system, it’s the ultimate tool for site peace of mind.

---

## ✨ Key Features

- **📂 Full Site Backup**: Packs your entire WordPress installation (Core, Plugins, Themes, Uploads) into a single optimized `.zip`.
- **🗄️ Database Export**: Memory-efficient SQL export ensuring your data is safe.
- **☁️ Google Drive Integration**: Resumable chunked uploads for reliable transfers, even on slow servers.
- **🕒 Smart Scheduling**: Set it and forget it! Automatic backups daily, weekly, or monthly.
- **🔄 One-Click Restore**: Restore your site instantly from the cloud or local storage.
- **🌍 Auto Domain Sync**: Moving to a new host? The plugin automatically detects domain changes and fixes your database URLs.
- **🧹 Storage Management**: Automatically keeps only the last 5 backups to save space on your Google Drive.
- **🗑️ Local Cleanup**: Permanently delete old backups from your server to free up disk space.

---

## 🎨 Premium Dashboard Experience

Designed with a focus on UX, our dashboard provides:
- **Interactive Upload Zones**: Drag and drop or browse with real-time feedback.
- **Progress Tracking**: See exactly what's happening during backup and restoration.
- **Glassmorphism Design**: A modern, sleek interface built with Vanilla CSS and Inter typography.

---

## 🛠️ Installation

1. **Upload**: Place the `auto-backup-gdrive` folder in your `/wp-content/plugins/` directory.
2. **Activate**: Enable the plugin via the WordPress 'Plugins' menu.
3. **Configure**: Go to **GDrive Backup** in your sidebar.
4. **Connect**: Follow the built-in **Integration Guide** to link your Google Cloud Project.

---

## ⚙️ Google Drive Setup

To enable cloud backups, you'll need:
1. A **Google Cloud Project**.
2. **Google Drive API** enabled.
3. **OAuth 2.0 Credentials** (Client ID & Client Secret).
4. Add the **Authorized Redirect URI** provided in the plugin settings.

---

## 🔒 Security & Reliability

- **Nonce Verification**: All actions are secured against CSRF attacks.
- **Capability Checks**: Only administrators (`manage_options`) can access backup features.
- **Chunked Processing**: Uses batching for scanning, zipping, and uploading to prevent PHP timeouts and memory exhaustion.
- **Path Protection**: Backups are stored in protected directories with `.htaccess` and `index.php` guards.

---

## 📜 License

Distributed under the GPLv2 License. See `LICENSE` for more information.

---

## 👨‍💻 Developed By

**Raju** - [PNS Code](https://pnscode.com)

---

> [!TIP]
> Always keep a fresh backup before performing major WordPress updates or theme changes!
