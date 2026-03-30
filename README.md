# Uptime Kuma Dashboard Widget for Unraid

An Unraid plugin that displays your [Uptime Kuma](https://github.com/louislam/uptime-kuma) monitor statuses directly on the Unraid dashboard.

<!-- TODO: Add a screenshot here -->
<!-- ![Dashboard Screenshot](docs/screenshot.png) -->

## Features

- Real-time monitor status display (Up / Down / Pending / Maintenance)
- Uptime percentage with configurable time periods (1 hour to 180 days)
- Reads directly from Uptime Kuma's SQLite database (no API keys or background services needed)
- Down monitors highlighted and sorted to top
- Collapsible dashboard tile matching Unraid's native style
- Configurable refresh interval and display limits

## Prerequisites

- **Unraid 6.11.0 or later**
- **Uptime Kuma** running as a Docker container on the same Unraid server
- The Uptime Kuma Docker container must have its data directory volume-mapped to the Unraid filesystem (this is the default when installed via Community Applications)

## Installation

### Via Unraid Plugin Manager (Recommended)

1. In the Unraid WebGUI, go to the **Plugins** tab
2. Click the **Install Plugin** sub-tab
3. Paste the following URL:
   ```
   https://raw.githubusercontent.com/drohack/UptimeKumaPlugin/main/uptime-kuma.plg
   ```
4. Click **Install**

### Manual Installation

1. Download `uptime-kuma.plg` from this repository
2. Copy it to `/boot/config/plugins/` on your Unraid server
3. Run: `installplg /boot/config/plugins/uptime-kuma.plg`

## Configuration

### Step 1: Find Your Database Path

The plugin needs the path to Uptime Kuma's SQLite database on the Unraid filesystem.

**To find it:**
1. Go to the **Docker** tab in Unraid
2. Click on your Uptime Kuma container name
3. Look at the volume mappings — find the one that maps to `/app/data` inside the container
4. Your database path is: `<host_path>/kuma.db`

**Common paths:**
- `/mnt/user/appdata/uptime-kuma/kuma.db` (default from Community Applications)
- `/mnt/cache/appdata/uptime-kuma/kuma.db` (if using cache drive)

### Step 2: Configure the Plugin

1. Go to **Settings** > **Uptime Kuma** in the Unraid WebGUI
2. Set the **Database Path** to your `kuma.db` file location
3. Click **Test Connection** to verify it works
4. Set **Enable Dashboard Widget** to **Enabled**
5. Adjust refresh interval, max monitors, and default time period as desired
6. Click **Apply**

### Step 3: View the Dashboard

Navigate to the Unraid **Dashboard**. You should see an "Uptime Kuma" tile showing your monitors.

Use the dropdown in the widget header to switch between time periods (1h, 12h, 24h, 7d, 30d, 90d, 180d).

## Settings Reference

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Dashboard Widget | Disabled | Show/hide the widget on the dashboard |
| Database Path | `/mnt/user/appdata/uptime-kuma/kuma.db` | Path to Kuma's SQLite database |
| Refresh Interval | 30 seconds | How often the widget refreshes data |
| Max Monitors | 50 | Maximum number of monitors to display |
| Default Time Period | 24 Hours | Default period for uptime % calculation |

## Troubleshooting

### "Database file not found"
- Double-check the database path in Settings > Uptime Kuma
- Ensure the Uptime Kuma Docker container is running
- Verify the volume mapping in your Docker container settings

### "Not a valid Uptime Kuma database"
- Make sure the path points to `kuma.db`, not the directory
- The file may be corrupted — check if Uptime Kuma itself is working

### "Database file not readable"
- The Unraid webserver (emhttp) needs read access to the file
- Check permissions: `ls -la /mnt/user/appdata/uptime-kuma/kuma.db`
- If needed: `chmod 644 /mnt/user/appdata/uptime-kuma/kuma.db`

### Widget shows "Loading..." indefinitely
- Open browser dev tools (F12) and check the Console/Network tabs for errors
- Verify the plugin backend is accessible: visit `http://<your-unraid-ip>/plugins/uptime-kuma/UptimeKumaData.php?action=test` in your browser

### Uptime percentages show as "-"
- This means no heartbeat data exists for the selected time period
- Try a shorter time period, or wait for Uptime Kuma to collect more data

## Building from Source

To build the `.txz` package yourself:

1. Clone this repository to your Unraid server (or any Linux system with Slackware `makepkg`)
2. Replace `src/uptime-kuma/usr/local/emhttp/plugins/uptime-kuma/images/uptime-kuma.png` with a proper 48x48 icon
3. Run the build script:
   ```bash
   cd src
   bash mkpkg.sh
   ```
4. The package will be output to the `pkg/` directory
5. Update the version in `uptime-kuma.plg` to match
6. Commit and push to your GitHub repository

## Uninstalling

1. Go to **Plugins** > **Installed Plugins** in the Unraid WebGUI
2. Click the delete icon next to "Uptime Kuma"
3. Reboot (or the plugin will be removed on next reboot)

Your Uptime Kuma data is never modified — the plugin only reads the database.

## How It Works

This plugin reads Uptime Kuma's own SQLite database file directly from the Unraid filesystem. Since Uptime Kuma stores all monitoring history in this database, the plugin can calculate uptime percentages for any time period without needing to collect its own data.

The database is opened in **read-only mode** — the plugin never writes to or modifies Uptime Kuma's data.

## License

MIT License — see [LICENSE](LICENSE).

## Credits

- [Uptime Kuma](https://github.com/louislam/uptime-kuma) by Louis Lam
- Built for the [Unraid](https://unraid.net/) community
