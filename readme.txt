=== Auto Backup Gdrive ===
Contributors: raju
Tags: backup, google drive, auto backup, restore, migration, cloud backup
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically back up your WordPress site and database to Google Drive with ease and modern UI.

== Description ==

Auto Backup Gdrive is a premium-feel, lightweight backup solution for WordPress. It allows you to schedule automatic backups of your entire site (files + database) and upload them directly to your Google Drive account.

Features:
* **Automatic Backups**: Schedule backups daily, weekly, or monthly.
* **Google Drive Integration**: Securely store your backups in the cloud.
* **One-Click Restore**: Restore your site directly from Google Drive or local storage.
* **Auto Domain Sync**: Migrating to a new domain? The plugin automatically detects domain changes and updates your database URLs.
* **Modern Dashboard**: A clean, responsive, and professional user interface.
* **Manual Upload**: Upload existing backup archives to restore them instantly.
* **Retention Policy**: Automatically keep only the latest 5 backups to save cloud space.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/auto-backup-gdrive` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to 'GDrive Backup' in your admin menu.
4. Follow the 'Integration Guide' to connect your Google Drive account.

== Frequently Asked Questions ==

= How do I get a Client ID and Client Secret? =
Follow the Integration Guide inside the plugin settings. You will need to create a project in the Google Cloud Console and enable the Google Drive API.

= Does it back up my database? =
Yes, it performs a full database export along with your files.

= Can I migrate my site to a new domain? =
Yes! Just restore a backup on the new domain, and the plugin will automatically handle the URL replacement in your database.

== Screenshots ==

1. The modern Dashboard with Google Drive connectivity status.
2. The Recovery Center showing cloud and local backups.
3. The API Configuration and Automation Engine settings.

== Changelog ==

= 1.1.2 =
* Fixed cache issues and UI refinements.
* Added local delete option.
* Implemented Google Drive retention policy.

= 1.1.1 =
* Improved UI/UX with premium dashboard design.
* Added auto-save for backup toggle.

= 1.0.0 =
* Initial release.
