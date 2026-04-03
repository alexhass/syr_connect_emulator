# SYR Connect Device Emulator (Local JSON API)

A PHP-based emulator for SYR water treatment devices (Neosoft, Trio) for testing the **local JSON API** integration.

> **Note:** This emulator is specifically designed for the local JSON API only. It does not support the XML API used by cloud-connected devices.

## Features

- ✅ Emulates SYR devices with local JSON API (Neosoft 2500, Trio DFR/LS)
- ✅ Supports GET and SET operations via JSON endpoints
- ✅ Uses real fixture data from tests
- ✅ Logs all SET operations to logfile
- ✅ **Exact HTTP header matching** (via PHP built-in server)
- ✅ Compatible with Apache (with minor header differences)
- ✅ URL rewriting for clean API endpoints

## Installation

### Prerequisites

- Apache 2.4+ with mod_rewrite and mod_headers enabled
- PHP 8.0+
- Write permissions for logfile directory

> ⚠️ **Note on HTTP Headers**: Apache automatically adds `Date`, `Server`, and `Content-Type` headers which the real device doesn't send. This is a known Apache limitation. However, HTTP headers are **case-insensitive** (RFC 7230), so your integration will work perfectly despite these additional headers.

### Setup

1. **Copy files to web server:**

   ```bash
   # Copy the emulator/ directory to your web server
   cp -r emulator/ /var/www/html/syr-emulator/
   ```

2. **Enable Apache modules (if not already enabled):**

   ```bash
   # Linux
   sudo a2enmod rewrite
   sudo a2enmod headers
   sudo systemctl restart apache2
   ```

   ```apache
   # Windows (XAMPP): Edit httpd.conf and uncomment:
   LoadModule rewrite_module modules/mod_rewrite.so
   LoadModule headers_module modules/mod_headers.so
   ```

3. **Set directory permissions:**

   ```bash
   cd /var/www/html/syr-emulator
   chmod 755 .
    chmod 666 logs/set_operations.log  # Or create the file on first SET
   ```

4. **Apache Virtual Host configuration (recommended for port 5333):**

   **Linux**: Create `/etc/apache2/sites-available/syr-emulator.conf`:
   **Windows (XAMPP)**: Edit `conf/extra/httpd-vhosts.conf`:

   ```apache
   Listen 5333

   <VirtualHost *:5333>
       ServerName localhost
       DocumentRoot "/var/www/html/syr-emulator"
       
       # Suppress server signature
       ServerSignature Off
       
       # Minimize headers (best effort - some headers remain due to Apache/RFC)
       <IfModule mod_headers.c>
           Header always unset Server
           Header always unset X-Powered-By
       </IfModule>
       
       <Directory "/var/www/html/syr-emulator">
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
           
           # PHP settings
           php_flag display_errors off
           php_flag expose_php off
       </Directory>
       
       # Turn off ETags
       FileETag None
       
       ErrorLog ${APACHE_LOG_DIR}/syr-emulator-error.log
       CustomLog ${APACHE_LOG_DIR}/syr-emulator-access.log combined
   </VirtualHost>
   ```

   **Linux**: Enable the site:

   ```bash
   sudo a2ensite syr-emulator.conf
   sudo systemctl reload apache2
   ```

   **Windows (XAMPP)**: Restart Apache from XAMPP Control Panel

5. **Optional: PHP configuration (php.ini) for minimal headers:**

   ```ini
   ; Disable PHP signature in headers
   expose_php = Off
   
   ; Prevent default MIME type
   default_mimetype = ""
   ```

6. **Verify header configuration (optional):**

   ```bash
   # Windows
   curl -I http://localhost:5333/neosoft/set/ADM/(2)f
   
   # Linux/macOS
   curl -I http://localhost:5333/neosoft/set/ADM/(2)f
   ```

## Quick Test

After installation, you can use the test scripts:

### Bash (Linux/macOS)

```bash
cd tests/
chmod +x test_api.sh
./test_api.sh

# Or test specific device:
DEVICE=trio ./test_api.sh
```

### Windows (Batch)

```cmd
cd tests
test_api.bat

REM Or specific device:
set DEVICE=trio
test_api.bat
```

### Python (recommended for integration)

```bash
cd tests/
pip install aiohttp  # If not already installed
python test_api.py

# Test specific device:
python test_api.py http://localhost:5333 trio
```

The test scripts verify:

- ✓ Login endpoint
- ✓ GET all values
- ✓ GET single values (with NSC response for invalid keys)
- ✓ SET operations (boolean, string, integer, float)
- ✓ Validation (MIMA response for invalid ranges)
- ✓ Error handling
- ✓ State verification

**Additional test scripts:**

```bash
# Test GET single value functionality
tests/test_get_single.bat         # Windows
tests/test_get_single.sh          # Linux/macOS

# Test validation (MIMA responses)
tests/test_validation.bat         # Windows
tests/test_validation.sh          # Linux/macOS
```

## Usage

### Device Type Selection

Devices are automatically selected via URL prefix:

- `/pontos-base/*` → Hansgrohe Pontos Base
- `/neosoft/*` → Syr Neosoft 2500
- `/trio/*` → Syr Trio DFR LS

### API Endpoints

#### 1. Login (required before GET)

The emulator exposes the legacy ADM login endpoint used by some device firmwares. Behavior depends on the selected JSON fixture:

- For Trio fixtures whose filename starts with `safetech_v4` and for `pontos.json` the ADM endpoint exists and returns HTTP 200 with the JSON body `{"setADM(2)f":"OK"}`.
- For other fixtures the ADM endpoint is not present and the emulator returns HTTP 404 with a plain `File Not Found` body (to match real device behavior).

Examples:

```bash
# Neosoft (neosoft fixtures do NOT support ADM -> return 404)
curl -I "http://localhost:5333/neosoft/set/ADM/(2)f"

# Trio (safetech_v4* supports ADM and returns 200)
curl -I "http://localhost:5333/trio/set/ADM/(2)f"
```

Success response (when supported):

```json
{"setADM(2)f": "OK"}
```

Not supported / missing ADM response:

```bash
HTTP/1.1 404 Not Found
Not Found
```

#### 2. Get All Values (GET)

```bash
# Pontos
curl -X GET "http://localhost:5333/pontos-base/set/ADM/(2)f"
curl -X GET "http://localhost:5333/pontos-base/get/all"

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

#### 3. Get Single Value (GET)

```bash
# Get specific value (e.g., valve state)
curl -X GET "http://localhost:5333/neosoft/get/ab"

# Get flow rate
curl -X GET "http://localhost:5333/neosoft/get/flo"

# Get salt amount
curl -X GET "http://localhost:5333/neosoft/get/sv1"
```

**Success response (key exists):**

```json
{
    "getAB": false
}
```

**Error response (key doesn't exist):**

```json
{
    "getXYZ": "NSC"
}
```

> **NSC** = "Not a valid command" - The requested key doesn't exist in the device data.

#### 4. Set Value (SET)

```bash
# Close valve (Neosoft)
curl -X GET "http://localhost:5333/neosoft/set/ab/true"

# Change regeneration time (Neosoft)
curl -X GET "http://localhost:5333/neosoft/set/rtm/03:30"

# Set salt amount (Neosoft)
curl -X GET "http://localhost:5333/neosoft/set/sv1/25"

# Switch profile (Trio)
curl -X GET "http://localhost:5333/trio/set/prf/2"
```

> **Note:** Values are passed through as-is and are **not** URL-decoded by the emulator. Send the raw value directly in the URL path.

**Response format:**

The response key is generated from the path: `set` + `{key}` + `{value}` (slashes removed)

- Example: `/set/sir/0` → `{"setSIR0": "OK"}`
- Example: `/set/ab/true` → `{"setABtrue": "OK"}`

**Success response:**

```json
{
    "setSIR0": "OK"
}
```

**Validation error response (value outside valid range):**

```json
{
    "setRPD5": "MIMA"
}
```

**Validation rules:**

- `RPD` (Neosoft only): Values must be between 1-3
  - Valid: `/set/rpd/1`, `/set/rpd/2`, `/set/rpd/3` → Returns `"OK"`
  - Invalid: `/set/rpd/0`, `/set/rpd/4` → Returns `"MIMA"`

**Testing validation:**

```bash
# Windows
tests\test_validation.bat

# Linux/macOS
tests/test_validation.sh

curl http://localhost:5333/neosoft/set/rpd/2   # Returns: {"setRPD2":"OK"}
curl http://localhost:5333/neosoft/set/rpd/5   # Returns: {"setRPD5":"MIMA"}
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

All SET operations are logged to `logs/set_operations.log`:

```log
[2026-03-06 15:30:45] SET | Device: neosoft | Client: 192.168.1.100 | Key: AB | Value: true | Changed getAB from false to true | User-Agent: python-aiohttp/3.9.1
[2026-03-06 15:31:12] SET | Device: neosoft | Client: 192.168.1.100 | Key: RTM | Value: 03:30 | Changed getRTM from "02:00" to "03:30" | User-Agent: python-aiohttp/3.9.1
[2026-03-06 15:35:20] LOGIN | Device: neosoft | Client: 192.168.1.100 | Key: ADM | Value: (2)f |  | User-Agent: Mozilla/5.0
```

### View Logfile

```bash
# Live monitoring
tail -f logs/set_operations.log

# Last 20 entries

## Persisted Runtime State

- **What:** SET operations are persisted per-device so values remain across requests and restarts.
- **Location:** Persisted state files are written to `configs/persisted_<fixture_basename>` (for example `configs/persisted_neosoft2500.json`).
- **Behavior:** On startup the emulator merges the persisted state into the fixture JSON so `GET /.../get/all` returns the updated values.
- **Errors:** Read/write failures are logged to `logs/emulator_internal.log`.

### Quick test

1. Set a value:

```bash
curl -i "http://localhost:5333/neosoft/set/ab/true"
```

2. Confirm persisted value is returned by GET all:

```bash
curl -i "http://localhost:5333/neosoft/get/all"
# Check that "getAB": true appears in the JSON body
```

3. Inspect persisted file on disk:

```bash
cat configs/persisted_neosoft.json
```

To clear persisted state for a device remove its `persisted_<device>.json` file.
tail -n 20 logs/set_operations.log

```bash
# Filter by client
grep "192.168.1.100" logs/set_operations.log

# Filter by SET type
grep "SET |" logs/set_operations.log

# Filter by device
grep "| neosoft |" logs/set_operations.log
grep "| trio |" logs/set_operations.log
```

## Switching Device Data (config parameter)

You can use the URL parameter `config` to switch the JSON device file for each device type. The selection is persistent until changed again.

**Neosoft Examples:**

```bash
# Activate Neosoft 2500 (NeoSoft) - Default
curl "http://localhost:5333/neosoft/get/all?config=default"
curl "http://localhost:5333/neosoft/get/all?config=neosoft2500.json"

# Activate Neosoft 5000 (NeoSoft)
curl "http://localhost:5333/neosoft/get/all?config=neosoft5000.json"

# Activate Sanibel Softwater UNO (NeoSoft 2500)
curl "http://localhost:5333/neosoft/get/all?config=sanibel_softwater_uno.json"
```

**Trio Examples:**

```bash
# Activate trio.json (Trio) - Default
curl "http://localhost:5333/trio/get/all?config=default"
curl "http://localhost:5333/trio/get/all?config=trio.json"

# Activate safetech.json (Trio)
curl "http://localhost:5333/trio/get/all?config=safetech.json"

# Activate safetechplus.json (Trio)
curl "http://localhost:5333/trio/get/all?config=safetechplus.json"

# Activate Sanibel Leak protection module A25 (Trio)
curl "http://localhost:5333/trio/get/all?config=sanibel_leakprotection.json"
```

**Safe-Tech v4 Examples:**

```bash
# Activate Safetech V4 (Trio) - Default
curl "http://localhost:5333/safe-tec/get/all?config=safetech_v4_copy.json"

# Activate Safetech V4 older firmware (Trio)
curl "http://localhost:5333/safe-tec/get/all?config=safetech_v4.json"
```

**Pontos-Base Examples:**

```bash
# Activate Safetech V4 (Trio) - Default
curl "http://localhost:5333/pontos-base/get/all?config=pontos.json"
```

After calling with ?config=... once, the selection will be used for all following requests (without parameter) until changed again.

**Default:**
If no parameter is set, the default file is used (`neosoft2500.json` or `safetechplus.json`), unless another selection is saved.

**Note:** The JSON file must exist in the `devices/` directory.

---

## Customize Device Data

Device data is stored in JSON files under `devices/`:

- `devices/pontos.json` - Pontos Base
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

### Problem: Additional HTTP headers (Date, Server, Content-Type)

**Expected behavior:** Apache automatically adds these headers due to RFC 7231 requirements and internal architecture. This is normal and **does not affect functionality**.

**Why it works anyway:**

- HTTP headers are **case-insensitive** (RFC 7230)
- HTTP clients ignore unknown/extra headers
- `Content-Length` vs `content-length` are treated identically

**Verification:**

```bash
# Check current headers
curl -I http://localhost:5333/neosoft/set/ADM/(2)f
```

```bash
# Real device sends:
# HTTP/1.1 200 OK
# content-length: 19
```

```bash
# Apache sends (still compatible):
# HTTP/1.1 200 OK
# Date: ...
# Server: Apache/...
# Content-Length: 23
# Content-Type: text/html
```

**If exact header matching is required:** Use PHP built-in server (`start_server.bat`) instead of Apache.

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
       'pontos-base' => __DIR__ . '/devices/pontos.json',
       'trio' => __DIR__ . '/devices/trio.json',
       'new_device' => __DIR__ . '/devices/new_device.json',  // NEW
   ];
   ```

3. Adjust regex in `index.php`:

   ```php
   if (preg_match('#^(neosoft|pontos-base|trio|new_device)/#', $path, $matches)) {
   ```

4. Extend RewriteRule in `.htaccess`:

   ```apache
   RewriteRule ^(neosoft|pontos-base|trio|new_device)/(.*)$ index.php [QSA,L]
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
