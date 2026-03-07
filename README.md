# SYR Connect Device Emulator (Local JSON API)

A PHP-based emulator for SYR water treatment devices (Neosoft, Trio) for testing the **local JSON API** integration.

> **Note:** This emulator is specifically designed for the local JSON API only. It does not support the XML API used by cloud-connected devices.

## Features

- ✅ Emulates SYR devices with local JSON API (Neosoft 2500, Trio DFR/LS)
- ✅ Supports GET and SET operations via JSON endpoints
- ✅ Uses real fixture data from tests
- ✅ Logs all SET operations to logfile
- ✅ CORS support for local testing
- ✅ URL rewriting for clean API endpoints

## Installation

### Prerequisites

- Apache 2.4+ with mod_rewrite enabled
- PHP 8.0+
- Write permissions for logfile directory

### Setup

1. **Copy files to web server:**

   ```bash
   # Copy the emulator/ directory to your web server
   cp -r emulator/ /var/www/html/syr-emulator/
   ```

2. **Enable Apache mod_rewrite (if not already enabled):**

   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

3. **Set directory permissions:**

   ```bash
   cd /var/www/html/syr-emulator
   chmod 755 .
   chmod 666 set_operations.log  # Or create the file on first SET
   ```

4. **Apache Virtual Host configuration (optional):**

   For `/etc/apache2/sites-available/syr-emulator.conf`:

   ```apache
   <VirtualHost *:5333>
       ServerName localhost
       DocumentRoot /var/www/html/syr-emulator
       
       <Directory /var/www/html/syr-emulator>
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       ErrorLog ${APACHE_LOG_DIR}/syr-emulator-error.log
       CustomLog ${APACHE_LOG_DIR}/syr-emulator-access.log combined
   </VirtualHost>
   ```

## Quick Test

After installation, you can use the test scripts:

### Bash (Linux/macOS)

```bash
cd emulator/
chmod +x test_api.sh
./test_api.sh

# Or test specific device:
DEVICE=trio ./test_api.sh
```

### Windows (Batch)

```cmd
cd emulator
test_api.bat

REM Or specific device:
set DEVICE=trio
test_api.bat
```

### Python (recommended for integration)

```bash
cd emulator/
pip install aiohttp  # If not already installed
python test_api.py

# Test specific device:
python test_api.py http://localhost:5333 trio
```

The test scripts verify:

- ✓ Login endpoint
- ✓ GET all values
- ✓ SET operations (boolean, string, integer, float)
- ✓ Error handling
- ✓ State verification

## Usage

### Device Type Selection

Devices are automatically selected via URL prefix:

- `/neosoft/*` → Neosoft 2500
- `/trio/*` → Trio DFR LS

No environment variables required!

### API Endpoints

#### 1. Login (required before GET)

```bash
# Neosoft
curl -X GET "http://localhost:5333/neosoft/set/ADM/(2)f"

# Trio
curl -X GET "http://localhost:5333/trio/set/ADM/(2)f"
```

Response:

```json
{"status": "ok"}
```

#### 2. Get All Values (GET)

```bash
# Neosoft
curl -X GET "http://localhost:5333/neosoft/get/all"

# Trio
curl -X GET "http://localhost:5333/trio/get/all"
```

Response:

```json
{
    "getALA": "FF",
    "getALD": 600,
    "getAB": false,
    "getFLO": 0,
    ...
}
```

#### 3. Set Value (SET)

```bash
# Close valve (Neosoft)
curl -X GET "http://localhost:5333/neosoft/set/AB/true"

# Change regeneration time (Neosoft) - URL encoded value e.g. "03:30"
curl -X GET "http://localhost:5333/neosoft/set/RTM/03%3A30"

# Set salt amount (Neosoft)
curl -X GET "http://localhost:5333/neosoft/set/SV1/25"

# Switch profile (Trio)
curl -X GET "http://localhost:5333/trio/set/PRF/2"
```

Response:

```json
{
    "status": "ok",
    "key": "getAB",
    "old_value": false,
    "new_value": true
}
```

### Example: Testing Home Assistant Integration

This emulator is designed to work with the **local JSON API client** (`api_json.py`).

1. **Config Flow Test:**

   ```python
   # In test_config_flow.py
   MOCK_HOST = "http://localhost:5333"
   ```

2. **API Client Test (JSON API only):**

   ```python
   from custom_components.syr_connect.api_json import SyrConnectJsonAPI
   
   async with aiohttp.ClientSession() as session:
       # Neosoft device
       client = SyrConnectJsonAPI(
           session=session,
           base_url="http://localhost:5333/neosoft/"
       )
       
       # Login
       await client.login()
       
       # Get data
       data = await client.get_device_status("test_device")
       print(data)
       
       # Set value
       await client.set_device_status("test_device", "setAB", "true")
       
       # Test Trio device
       client_trio = SyrConnectJsonAPI(
           session=session,
           base_url="http://localhost:5333/trio/"
       )
       trio_data = await client_trio.get_device_status("test_trio")
   ```

## Logfile

All SET operations are logged to `set_operations.log`:

```log
[2026-03-06 15:30:45] SET | Device: neosoft | Client: 192.168.1.100 | Key: AB | Value: true | Changed getAB from false to true | User-Agent: python-aiohttp/3.9.1
[2026-03-06 15:31:12] SET | Device: neosoft | Client: 192.168.1.100 | Key: RTM | Value: 03:30 | Changed getRTM from "02:00" to "03:30" | User-Agent: python-aiohttp/3.9.1
[2026-03-06 15:35:20] LOGIN | Device: neosoft | Client: 192.168.1.100 | Key: ADM | Value: (2)f |  | User-Agent: Mozilla/5.0
```

### View Logfile

```bash
# Live monitoring
tail -f set_operations.log

# Last 20 entries
tail -n 20 set_operations.log

# Filter by client
grep "192.168.1.100" set_operations.log

# Filter by SET type
grep "SET |" set_operations.log

# Filter by device
grep "| neosoft |" set_operations.log
grep "| trio |" set_operations.log
```

## Customize Device Data

Device data is stored in JSON files under `devices/`:

- `devices/neosoft2500.json` - Neosoft 2500
- `devices/trio.json` - Trio DFR/LS

You can edit these files to simulate different values:

```json
{
    "getAB": true,      // Valve closed
    "getFLO": 150,      // Flow 150 L/h
    "getSV1": 10,       // Salt 10 kg
    ...
}
```

After changes: No restarts necessary, changes are loaded immediately.

## Troubleshooting

### Problem: 404 Not Found

**Solution:** Check if mod_rewrite is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Check `.htaccess` permissions and AllowOverride:

```apache
<Directory /var/www/html/syr-emulator>
    AllowOverride All
</Directory>
```

### Problem: 500 Internal Server Error

**Solution:** Check Apache error log:

```bash
tail -f /var/log/apache2/error.log
```

Check PHP errors:

```bash
# Enable in index.php:
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Problem: Logfile not being written

**Solution:** Check write permissions:

```bash
ls -la set_operations.log
sudo chown www-data:www-data set_operations.log
chmod 666 set_operations.log
```

### Problem: JSON fixtures not found

**Solution:** Check paths:

```bash
ls -la devices/
# Should show:
# neosoft2500.json
# trio.json
```

## Development

### Adding a New Device

1. Create JSON file: `devices/new_device.json`
2. Extend `$fixtureMap` in `DeviceEmulator.php`:

   ```php
   $fixtureMap = [
       'neosoft' => __DIR__ . '/devices/neosoft2500.json',
       'trio' => __DIR__ . '/devices/trio.json',
       'new_device' => __DIR__ . '/devices/new_device.json',  // NEW
   ];
   ```

3. Adjust regex in `index.php`:

   ```php
   if (preg_match('#^(neosoft|trio|new_device)/#', $path, $matches)) {
   ```

4. Extend RewriteRule in `.htaccess`:

   ```apache
   RewriteRule ^(neosoft|trio|new_device)/(.*)$ index.php [QSA,L]
   ```

5. Test:

   ```bash
   curl http://localhost:5333/new_device/get/all
   ```

### Enable Debug Mode

In `index.php` or `.htaccess`:

```php
// In index.php (at the top):
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
```

## Security Notes

⚠️ **IMPORTANT:** This emulator is for development and testing ONLY!

- Do NOT use in production environments
- Do NOT set real device credentials
- Remove CORS headers before public deployment
- Protect logfile from public access

## License

MIT License - see LICENSE file
