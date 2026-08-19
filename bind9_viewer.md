# bind9_viewer.php

## Overview

`bind9_viewer.php` is a lightweight PHP viewer for BIND9 zone files and `/etc/hosts`-style files. It parses DNS and hosts records and generates a responsive HTML dashboard with card view, list view, clickable hostnames, HTTP/HTTPS links, configurable protocol buttons, collapsible aliases, RDP file downloads, optional server-side ping tests, external Aruba Gateway REST API sections, and light/dark mode support.

The viewer supports:

- BIND9 `A` records
- BIND9 `CNAME` records
- `/etc/hosts` entries displayed as `HOSTS` records
- Card view and list view
- Clickable card-view hostnames with HTTPS-first and HTTP-fallback behavior
- Collapsible and clickable alias lists
- Per-protocol ports using `proto=[protocol:port]`
- Custom protocols for BIND zone records and hosts-file records
- Custom protocols for external Aruba Gateway rows
- RDP connection-file downloads generated in the browser
- Optional server-side Ping connection tests
- External data-source sections, currently Aruba Gateway REST API support
- Light and dark mode styling with manual theme toggle
- CLI generation of static HTML files
- Web rendering from a file path or pasted content

Comment delimiters are format-specific:

- BIND zone files use `;`
- `/etc/hosts` files use `#`

---

## Installation

### 1. Install PHP

Install PHP with the standard extensions and cURL support if Aruba external sources are required.

Debian or Ubuntu example:

```bash
sudo apt update
sudo apt install php php-cli php-fpm php-curl
```

RHEL, Rocky, AlmaLinux, or Fedora example:

```bash
sudo dnf install php php-cli php-fpm php-curl
```

### 2. Copy project files

Place the project files in a PHP-enabled web directory, for example:

```bash
sudo mkdir -p /var/www/bind9-viewer
sudo cp bind9_viewer.php bind9_viewer.css bind9_viewer.md README.md /var/www/bind9-viewer/
sudo cp bind9_help.php bind9_help.css /var/www/bind9-viewer/
```

Optional Parsedown dependency for help rendering:

```bash
cd /var/www/bind9-viewer
sudo mkdir -p lib
sudo curl -L -o lib/Parsedown.php https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php
```

If `lib/Parsedown.php` is missing, `bind9_help.php` displays a clear Parsedown missing-module error and points to the Parsedown GitHub repository:

```text
https://github.com/erusev/parsedown
```

### 3. Set ownership

Example for nginx with PHP-FPM running as `www-data`:

```bash
sudo chown -R root:www-data /var/www/bind9-viewer
sudo find /var/www/bind9-viewer -type f -exec chmod 640 {} \;
sudo find /var/www/bind9-viewer -type d -exec chmod 750 {} \;
```

### 4. Configure nginx

Example nginx server block:

```nginx
server {
    listen 80;
    server_name bind9-viewer.example.local;

    root /var/www/bind9-viewer;
    index bind9_viewer.php index.php index.html;

    location / {
        try_files $uri $uri/ /bind9_viewer.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Test and reload nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 5. Validate PHP syntax

Run this on the server:

```bash
php -l bind9_viewer.php
php -l bind9_help.php
```

---

## Requirements

- PHP 7.4 or newer recommended
- PHP `filter` extension, normally enabled by default
- PHP `curl` extension when Aruba Gateway REST API sources are used
- PHP-FPM or another PHP-enabled web server for browser access
- OS-level `ping` command if using the Ping button
- `bind9_viewer.css` in the same directory as `bind9_viewer.php`
- `lib/Parsedown.php` only if using `bind9_help.php`

No Composer packages, database, BIND libraries, JavaScript packages, or external CSS frameworks are required.

---

## Project Files

```text
bind9_viewer.php   Main parser, renderer, ping endpoint, external-source loader, Aruba API integration
bind9_viewer.css   Card view, list view, form, aliases, buttons, Aruba sections, responsive and theme styling
bind9_viewer.md    Detailed user documentation/help content
bind9_help.php     Optional Markdown help renderer using Parsedown
bind9_help.css     Optional Markdown help page styles with light/dark mode
README.md          Project overview and quick start
lib/Parsedown.php  Optional dependency for Markdown help rendering
```

---

## Quick Start

### CLI: Parse a BIND zone file

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com
```

This generates an HTML file named after the input file, for example:

```text
db.example.html
```

### CLI: Parse a hosts file

Files named `hosts` are detected automatically:

```bash
php bind9_viewer.php /etc/hosts
```

Force hosts parsing when the file is not named `hosts`:

```bash
php bind9_viewer.php /path/to/hosts.backup --hosts
```

### CLI: Generate list view

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com list=true
php bind9_viewer.php /etc/hosts list=true --hosts
```

### Web file-path mode

BIND zone file:

```text
bind9_viewer.php?zone=/etc/bind/db.example.com&origin=example.com
```

Hosts file:

```text
bind9_viewer.php?zone=/etc/hosts&type=hosts
```

List view:

```text
bind9_viewer.php?zone=/etc/hosts&type=hosts&list=true
```

### Paste form mode

Open `bind9_viewer.php` without parameters. The form lets the user:

- select BIND zone mode or hosts-file mode
- paste file content
- optionally provide a zone origin
- render the dashboard directly

---

## Supported BIND Zone Syntax

The parser supports common BIND zone syntax, including:

- `$ORIGIN`
- `$TTL`
- `A` records
- `CNAME` records
- optional TTL values
- optional DNS class values such as `IN`
- relative names expanded with the zone origin
- `@` origin references
- inline comments using `;`
- area comment blocks using `; --- START` and `; --- END`

Example:

```zone
$ORIGIN example.com.
$TTL 3600

; --- START
; Production web servers
; --- END

web01  IN A      192.168.0.10 ; proto=[ssh:8022,http:8080,https:8443] Primary web server
web02  IN A      192.168.0.11 ; proto=[ssh,https] Secondary web server
www    IN CNAME  web01
app    IN CNAME  www
```

### CNAME Alias Merging

If a CNAME points to an existing A record, the CNAME is displayed as an alias of the target A record.

Example:

```zone
web01 IN A      192.168.0.10
www   IN CNAME  web01
app   IN CNAME  www
```

The viewer displays `www` and `app` as aliases of `web01` after resolving the CNAME chain.

If a CNAME target cannot be resolved to an existing A record, the CNAME is displayed as a standalone CNAME item.

---

## Supported Hosts File Syntax

Hosts files use this format:

```text
IP-address primary-hostname [alias1 alias2 alias3 ...] # optional comment
```

Important: hosts-file comments use `#`.

Example:

```text
# --- START
# Local infrastructure
# --- END

192.168.0.10 web01 web01.example.com web01.local # proto=[ssh:8022,http:8080,https:8443] Primary web server
192.168.0.20 db01 db01.example.com # proto=[ssh] Database server
192.168.0.30 jump jump.example.com # proto=[rdp:3390] user=domain\admin Jump server
```

The first non-ignored hostname is used as the primary name. Additional non-ignored names on the same line are displayed as aliases.

---

## Ignored Hosts

The following common localhost and IPv6 helper names are skipped when parsing hosts files:

```text
localhost
localhost.localdomain
localhost6
localhost6.localdomain
localhost6.localdomain6
ip6-localhost
ip6-loopback
ip6-localnet
ip6-allnodes
ip6-allrouters
ip6-allhosts
```

If a hosts line contains only ignored names, the line is skipped. If a line contains both ignored and non-ignored names, only ignored names are removed.

---

## Area Separators

Area separator blocks create visual grouping cards or list rows.

BIND zone format:

```zone
; --- START
; Production servers
; Web tier
; --- END
```

Hosts file format:

```text
# --- START
# Production hosts
# Web tier
# --- END
```

Standalone full-line comments outside an area block are ignored.

---

## Inline Metadata

Inline comments can include metadata. Metadata is parsed and removed from the visible comment text.

Supported metadata:

```text
proto=[protocol1,protocol2]
proto=[protocol:port,protocol:port]
user=USERNAME
```

### Removed legacy metadata

The legacy directive below is no longer used for link generation:

```text
port:XXXX
```

If an old file still contains `port:XXXX`, the value is removed from the visible comment text for cleanup, but it does not affect generated buttons. Use per-protocol ports instead:

```text
proto=[http:8080,https:8443,ssh:8022]
```

---

## Protocol Support

Protocol shortcuts can be defined in comments using:

```text
proto=[ssh,http,https,rdp]
```

or with per-protocol ports:

```text
proto=[ssh:8022,http:8080,https:8443,rdp:3390,vnc:5901]
```

Built-in protocols and default ports:

```text
ssh     22
telnet  23
http    80
https   443
ftp     21
sftp    22
rdp     3389
vnc     5900
```

Removed from built-in shortcut handling:

```text
smtp
pop3
imap
```

Example BIND record:

```zone
server01 IN A 192.168.0.10 ; proto=[ssh:8022,http:8080,https:8443,rdp:3390]
```

Generated actions:

```text
SSH:8022    -> ssh:server01.example.com:8022
HTTP:8080   -> http://server01.example.com:8080
HTTPS:8443  -> https://server01.example.com:8443
RDP:3390    -> downloadable RDP file using port 3390
```

---

## Custom Protocols for BIND Zone and Hosts Records

Custom protocols can be configured globally and then used in BIND zone comments or hosts-file comments.

Supported configuration variables:

```text
BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE
BIND9_VIEWER_CUSTOM_PROTOCOLS_JSON
```

The custom protocol configuration can be either:

- a JSON file path
- a raw JSON string

### Custom protocol object fields

```json
{
  "label": "Display label",
  "scheme": "protocol-scheme",
  "port": 1234,
  "mode": "protocol"
}
```

Field meanings:

- `label`: button text shown in card and list view
- `scheme`: URL scheme used in the generated link
- `port`: default port if no per-protocol port is specified
- `mode`: either `protocol` or `url`

Modes:

```text
protocol -> scheme:host:port
url      -> scheme://host:port
```

### Example custom protocols JSON file

Recommended path:

```text
/etc/bind9-viewer/custom_protocols.json
```

```json
{
  "winbox": {
    "label": "Winbox",
    "scheme": "winbox",
    "port": 8291,
    "mode": "protocol"
  },
  "mgmt": {
    "label": "Mgmt",
    "scheme": "https",
    "port": 8443,
    "mode": "url"
  },
  "webssh": {
    "label": "WebSSH",
    "scheme": "web+ssh",
    "port": 22,
    "mode": "protocol"
  }
}
```

Configure it for PHP-FPM:

```ini
env[BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE] = /etc/bind9-viewer/custom_protocols.json
```

Or configure it for nginx FastCGI:

```nginx
fastcgi_param BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE /etc/bind9-viewer/custom_protocols.json;
```

### Raw JSON environment variable example

```bash
export BIND9_VIEWER_CUSTOM_PROTOCOLS_JSON='{
  "winbox": {
    "label": "Winbox",
    "scheme": "winbox",
    "port": 8291,
    "mode": "protocol"
  },
  "mgmt": {
    "label": "Mgmt",
    "scheme": "https",
    "port": 8443,
    "mode": "url"
  }
}'
```

### Use custom protocols in a BIND zone file

```zone
router01 IN A 192.168.1.1 ; proto=[winbox,mgmt,ssh:8022] Core router
```

Expected buttons:

```text
Winbox  Mgmt  SSH:8022
```

Generated links:

```text
winbox:router01.example.com:8291
https://router01.example.com:8443
ssh:router01.example.com:8022
```

### Use custom protocols in a hosts file

```text
192.168.1.1 router01 router01.local # proto=[winbox:8292,mgmt,ssh:8022] Core router
```

Expected buttons:

```text
Winbox:8292  Mgmt  SSH:8022
```

Generated links:

```text
winbox:router01.local:8292
https://router01.local:8443
ssh:router01.local:8022
```

---

## Custom Protocols for Aruba External Sources

Aruba Gateway sources can define `customProtocols` directly inside each source object. This is useful when different external sources should expose different protocol buttons.

Example:

```json
[
  {
    "enabled": true,
    "type": "aruba_gw_api",
    "title": "Home Wi-Fi",
    "baseUrl": "https://demo7005.selectium.local:4343",
    "username": "gorazd",
    "password": "xxx",
    "command": "show iap table",
    "verifySsl": false,
    "protocols": ["https:8443", "ssh:8022", "winbox", "ping"],
    "customProtocols": {
      "winbox": {
        "label": "Winbox",
        "scheme": "winbox",
        "port": 8291,
        "mode": "protocol"
      }
    }
  }
]
```

Expected buttons:

```text
HTTPS:8443  SSH:8022  Winbox  Ping
```

---

## Browser Requirements for Protocol Handlers

The viewer can generate custom protocol links, but browser and operating-system support determines what happens when a user clicks them.

Examples of generated links:

```text
ssh:host:22
vnc:host:5900
winbox:host:8291
web+ssh:host:22
```

For a custom protocol link to open an application:

1. The client operating system or browser must have a registered handler for the scheme.
2. The browser may prompt the user before opening an external application or web handler.
3. Some browser-registered web handlers must use `navigator.registerProtocolHandler()`.
4. Web-registered custom schemes usually need a `web+` prefix and an HTTPS handler URL.
5. Built-in schemes such as `mailto`, `tel`, `ssh`, and similar schemes depend on browser and OS support.

Important limitations:

- The PHP viewer only generates the link.
- The browser decides whether the link can be opened.
- Enterprise browser policies can block external protocol handlers.
- Some handlers work only after an application is installed locally.
- Some handlers require manual OS registry, desktop file, or browser configuration.

### Browser-registered handler example

A web application can register a handler for a custom `web+` protocol:

```javascript
navigator.registerProtocolHandler(
    "web+ssh",
    "https://example.com/handler?target=%s"
);
```

Then this viewer link can be handled by that web application:

```text
web+ssh:router01:22
```

### Native application handler example

A native application can register a local scheme such as:

```text
winbox:
vnc:
ssh:
```

Registration steps depend on the operating system and application. The viewer does not install or register native protocol handlers.

---

## RDP Behavior

The `rdp` protocol is handled specially. Instead of opening `rdp:host:port`, the browser dynamically creates and downloads a `.rdp` file.

Example:

```text
proto=[rdp:3390]
```

Generated `.rdp` content:

```text
full address:s:host.example.com:3390
username:s:Administrator
prompt for credentials:i:1
authentication level:i:2
redirectclipboard:i:1
redirectprinters:i:0
redirectdrives:i:0
redirectposdevices:i:0
```

Use `user=USERNAME` to set the username:

```text
proto=[rdp:3390] user=domain\admin
```

---

## Ping / Connection Test

The Ping button calls this endpoint:

```text
bind9_viewer.php?action=ping&target=HOST_OR_IP
```

The endpoint returns JSON:

```json
{
  "reachable": true,
  "target": "10.3.0.10",
  "exitCode": 0
}
```

The Ping button tests connectivity from the PHP server, not from the browser workstation:

```text
Browser -> bind9_viewer.php -> PHP server runs ping -> target
```

The PHP endpoint uses platform-specific ping syntax:

```text
Windows:    ping -n 1 -w 2000 TARGET
Linux/Unix: ping -c 1 -W 2 TARGET
```

---

## Aruba Gateway REST API Source

The Aruba Gateway source logs into the Aruba REST API, runs a show command, parses the response, renders the result, and attempts logout afterward.

Default command:

```text
show iap table
```

REST API flow:

```text
POST /v1/api/login
GET  /v1/configuration/showcommand?command=show%20iap%20table
GET  /v1/api/logout
```

Parsed Aruba fields:

```text
VC Name        -> hostname
Inner IP       -> IP address
Status         -> status badge
Assigned Vlan  -> VLAN information
VC MAC Address -> MAC address
_data          -> summary lines
```

Rows with `Inner IP` equal to `0.0.0.0` show `No IP` instead of action buttons.

---

## External Source Configuration

External sources can be configured in several ways:

1. Protected JSON config file
2. Raw JSON environment variable
3. nginx `fastcgi_param`
4. PHP-FPM pool `env[...]`
5. Backward-compatible single Aruba `ARUBA_GW_*` environment variables

### Recommended external source file

```text
/etc/bind9-viewer/external_sources.json
```

Example:

```json
[
  {
    "enabled": true,
    "type": "aruba_gw_api",
    "title": "Home Wi-Fi",
    "baseUrl": "https://demo7005.selectium.local:4343",
    "username": "gorazd",
    "password": "xxx",
    "command": "show iap table",
    "verifySsl": false,
    "protocols": ["https:8443", "ssh:8022", "ping"]
  },
  {
    "enabled": true,
    "type": "aruba_gw_api",
    "title": "Office Wi-Fi",
    "baseUrl": "https://office-gw.selectium.local:4343",
    "username": "gorazd",
    "password": "xxx",
    "command": "show iap table",
    "verifySsl": false,
    "protocols": ["http", "https", "ping"]
  }
]
```

Recommended permissions:

```bash
sudo mkdir -p /etc/bind9-viewer
sudo chown root:www-data /etc/bind9-viewer/external_sources.json
sudo chmod 640 /etc/bind9-viewer/external_sources.json
```

### PHP-FPM configuration

```ini
env[BIND9_VIEWER_EXTERNAL_SOURCES_FILE] = /etc/bind9-viewer/external_sources.json
env[BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE] = /etc/bind9-viewer/custom_protocols.json
```

Restart PHP-FPM:

```bash
sudo systemctl restart php8.3-fpm
```

### nginx FastCGI configuration

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;

    fastcgi_param BIND9_VIEWER_EXTERNAL_SOURCES_FILE /etc/bind9-viewer/external_sources.json;
    fastcgi_param BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE /etc/bind9-viewer/custom_protocols.json;
}
```

Reload nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### CLI configuration

```bash
export BIND9_VIEWER_EXTERNAL_SOURCES_FILE="/etc/bind9-viewer/external_sources.json"
export BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE="/etc/bind9-viewer/custom_protocols.json"
php bind9_viewer.php /etc/hosts --hosts
```

---

## Help Rendering and Parsedown

`bind9_help.php` renders `bind9_viewer.md` and `README.md` through Parsedown.

Required optional file:

```text
lib/Parsedown.php
```

Install example:

```bash
mkdir -p lib
curl -L -o lib/Parsedown.php https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php
```

If Parsedown is missing or invalid, `bind9_help.php` displays a styled error page with the Parsedown repository URL:

```text
https://github.com/erusev/parsedown
```

---

## Light and Dark Mode

The viewer supports automatic dark mode using `prefers-color-scheme` and manual theme switching.

Supported manual attributes/classes:

```html
<html data-theme="light">
<html data-theme="dark">
<body data-theme="dark">
<body class="dark-mode">
```

The generated HTML references CSS with cache busting, for example:

```html
<link rel="stylesheet" href="bind9_viewer.css?v=16">
```

If styling changes do not appear, hard-refresh the browser or increment the query string version.

---

## Notes and Limitations

- The script focuses on A, CNAME, and HOSTS records.
- Other DNS record types are ignored.
- Multi-line SOA and other parenthesized records are ignored during display parsing.
- BIND zone comments use `;`.
- Hosts comments use `#`.
- Clickable hostnames and alias buttons attempt HTTPS first and fall back to HTTP.
- Ping tests run from the PHP server, not from the browser workstation.
- RDP shortcuts are represented as browser-generated `.rdp` downloads.
- VNC and custom protocol shortcuts depend on browser and OS protocol-handler support.
- Aruba external data requires PHP cURL and valid Aruba REST API credentials.
- Aruba logout is attempted after fetching data. Logout failures are rendered as warnings and do not hide successfully fetched data.
- External source JSON files must be valid JSON and must not contain copied HTML anchor fragments.
- SMTP, POP3, and IMAP are not included in the built-in protocol shortcut map.
