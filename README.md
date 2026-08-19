# BIND9 DNS Record Viewer

A lightweight PHP-based dashboard for viewing BIND9 zone files and `/etc/hosts`-style files. `bind9_viewer.php` parses DNS and hosts records, merges aliases where possible, and renders responsive card and list views with clickable hostnames, protocol/action buttons, optional server-side ping tests, browser-generated RDP files, light/dark theme support, and optional Aruba Gateway REST API external sections.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Project Files](#project-files)
- [Installation](#installation)
- [Web Server Configuration](#web-server-configuration)
- [Quick Start](#quick-start)
- [CLI Usage](#cli-usage)
- [Web Usage and Online Calls](#web-usage-and-online-calls)
- [BIND Zone File Examples](#bind-zone-file-examples)
- [Hosts File Examples](#hosts-file-examples)
- [Protocol Buttons](#protocol-buttons)
- [Custom Protocols](#custom-protocols)
- [External Aruba Gateway Sources](#external-aruba-gateway-sources)
- [JSON Configuration Examples](#json-configuration-examples)
- [Help Rendering with Parsedown](#help-rendering-with-parsedown)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Features

- Parse BIND9 zone files.
- Parse `/etc/hosts`-style files.
- Display BIND `A` records.
- Display BIND `CNAME` records.
- Display hosts-file entries as `HOSTS` records.
- Merge CNAME records into matching target `A` records as aliases when possible.
- Resolve chained CNAME records before merging aliases.
- Consolidate all hostnames from one hosts-file line into a single displayed item.
- Skip common localhost and IPv6 helper names from hosts files.
- Render aliases in collapsible sections.
- Make aliases clickable with HTTPS-first and HTTP-fallback behavior.
- Make card-view hostnames clickable instead of using a separate Visit button.
- Support inline protocol metadata with per-protocol ports, for example `proto=[ssh:8022,https:8443]`.
- Support custom protocol definitions from JSON.
- Generate `.rdp` files in the browser for RDP connections.
- Provide optional server-side Ping connection tests.
- Support card view and list view.
- Support CLI static HTML generation.
- Support browser-based paste form mode.
- Support area separators in both BIND zone files and hosts files.
- Support optional Aruba Gateway REST API external sections.
- Support multiple Aruba Gateway sources from one JSON config file.
- Support per-Aruba-source protocol buttons and custom protocols.
- Support automatic dark mode and manual light/dark theme toggle.
- Provide optional Markdown help pages through `bind9_help.php` and Parsedown.

## Requirements

- PHP 7.4 or newer recommended.
- PHP CLI for command-line generation.
- PHP-FPM or another PHP-enabled web server for browser usage.
- PHP `filter` extension, normally enabled by default.
- PHP `curl` extension if Aruba Gateway API external sources are used.
- OS-level `ping` command if the Ping button is used.
- Read access to the BIND zone file or hosts file when using file-path mode.
- `bind9_viewer.css` in the same directory as `bind9_viewer.php`.
- Optional `lib/Parsedown.php` if `bind9_help.php` is used.

No database, Composer dependency, BIND library, JavaScript package, or external CSS framework is required.

## Project Files

```text
bind9_viewer.php   Main parser, dashboard renderer, ping endpoint, external-source loader, and Aruba API integration
bind9_viewer.css   Main dashboard styling for card view, list view, forms, aliases, buttons, Aruba sections, and themes
bind9_viewer.md    Detailed user documentation rendered by bind9_help.php
bind9_help.php     Optional Markdown help renderer using Parsedown
bind9_help.css     Optional help-page styling with light/dark mode support
README.md          GitHub project overview and quick-start documentation
lib/Parsedown.php  Optional Parsedown dependency for help rendering
```

## Installation

### 1. Install PHP

Debian or Ubuntu example:

```bash
sudo apt update
sudo apt install php php-cli php-fpm php-curl
```

RHEL, Rocky Linux, AlmaLinux, or Fedora example:

```bash
sudo dnf install php php-cli php-fpm php-curl
```

### 2. Copy the project files

Example installation path:

```bash
sudo mkdir -p /var/www/bind9-viewer
sudo cp bind9_viewer.php bind9_viewer.css bind9_viewer.md README.md /var/www/bind9-viewer/
sudo cp bind9_help.php bind9_help.css /var/www/bind9-viewer/
```

### 3. Optional: install Parsedown for help rendering

```bash
cd /var/www/bind9-viewer
sudo mkdir -p lib
sudo curl -L -o lib/Parsedown.php https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php
```

If `lib/Parsedown.php` is missing, `bind9_help.php` displays a clear error page with the Parsedown repository URL:

```text
https://github.com/erusev/parsedown
```

### 4. Set ownership and permissions

Example for nginx and PHP-FPM running as `www-data`:

```bash
sudo chown -R root:www-data /var/www/bind9-viewer
sudo find /var/www/bind9-viewer -type d -exec chmod 750 {} \;
sudo find /var/www/bind9-viewer -type f -exec chmod 640 {} \;
```

If `bind9_viewer.php` must read files outside the web root, ensure the PHP-FPM user or group has read permissions for those files.

### 5. Validate PHP syntax

Run this on the server:

```bash
php -l /var/www/bind9-viewer/bind9_viewer.php
php -l /var/www/bind9-viewer/bind9_help.php
```

## Web Server Configuration

### nginx with PHP-FPM

Example server block:

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

### nginx with external JSON configuration

Use `fastcgi_param` to pass JSON file paths to PHP:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;

    fastcgi_param BIND9_VIEWER_EXTERNAL_SOURCES_FILE /etc/bind9-viewer/external_sources.json;
    fastcgi_param BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE /etc/bind9-viewer/custom_protocols.json;
}
```

### PHP-FPM pool configuration

You can also set variables in the PHP-FPM pool config, for example `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
env[BIND9_VIEWER_EXTERNAL_SOURCES_FILE] = /etc/bind9-viewer/external_sources.json
env[BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE] = /etc/bind9-viewer/custom_protocols.json
```

Restart PHP-FPM after editing pool configuration:

```bash
sudo systemctl restart php8.3-fpm
```

## Quick Start

### Generate dashboard from a BIND zone file

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com
```

Expected output:

```text
db.example.html
```

### Generate dashboard from a hosts file

```bash
php bind9_viewer.php /etc/hosts
```

Expected output:

```text
hosts.html
```

### Generate list view

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com list=true
php bind9_viewer.php /etc/hosts list=true --hosts
```

## CLI Usage

Show usage help:

```bash
php bind9_viewer.php
```

Generate card view from a BIND zone file:

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com
```

Generate list view from a BIND zone file:

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com list=true
```

Generate card view from `/etc/hosts`:

```bash
php bind9_viewer.php /etc/hosts
```

Force hosts-file parsing when the file is not named `hosts`:

```bash
php bind9_viewer.php ./hosts.backup --hosts
php bind9_viewer.php ./hosts.backup list=true --hosts
```

Open the generated HTML locally:

```bash
php bind9_viewer.php ./db.example.com example.com
xdg-open db.example.html
```

CLI summary output looks similar to:

```text
✓ Successfully generated: db.example.html
  A Records: 12
  CNAME Records: 4
  HOSTS Names: 0
```

### CLI notes

- CLI mode generates static HTML files.
- The Ping button requires the PHP endpoint, so Ping works when the generated file is served through PHP, not when opened only as `file://`.
- RDP downloads and normal links work from static HTML if browser JavaScript is allowed.

## Web Usage and Online Calls

### File path mode

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

Open the viewer without parameters:

```text
bind9_viewer.php
```

The form allows the user to:

- select BIND zone mode or hosts-file mode
- paste file content
- optionally provide a zone origin
- render the dashboard directly in the browser

### Ping endpoint

```text
bind9_viewer.php?action=ping&target=10.3.0.10
```

Example JSON response:

```json
{
  "reachable": true,
  "target": "10.3.0.10",
  "exitCode": 0
}
```

The Ping endpoint tests connectivity from the PHP server, not from the browser workstation.

### Help pages

Default help page:

```text
bind9_help.php
```

README rendered as help page:

```text
bind9_help.php?file=README.md
```

Return to the original viewer page from help:

```text
bind9_help.php?file=README.md&return=bind9_viewer.php%3Fzone%3D%2Fetc%2Fhosts%26type%3Dhosts%26list%3Dtrue
```

## BIND Zone File Examples

### Basic BIND zone

```zone
$ORIGIN example.com.
$TTL 3600

web01  IN A      192.168.0.10 ; Web server
www    IN CNAME  web01
app    IN CNAME  www
```

### BIND zone with protocol buttons

```zone
router01 IN A 192.168.1.1 ; proto=[ssh:8022,https:8443] Router management
switch01 IN A 192.168.1.2 ; proto=[ssh,telnet:2323,http:8080,https:8443] Switch management
jump01   IN A 192.168.1.10 ; proto=[rdp:3390,ssh:8022] Jump server
nas01    IN A 192.168.1.20 ; proto=[http:8080,https:8443,ftp:2121,sftp:2222] NAS services
kvm01    IN A 192.168.1.30 ; proto=[vnc:5901,ssh] KVM console
```

### BIND zone area separator

```zone
; --- START
; Production servers
; Web tier
; --- END

web01 IN A 192.168.0.10 ; proto=[http,https]
```

## Hosts File Examples

### Basic hosts file

```text
192.168.0.10 web01 web01.example.com web01.local # Web server
192.168.0.20 db01 db01.example.com # Database server
```

The first non-ignored hostname is displayed as the primary name. Additional non-ignored names on the same line are displayed as aliases.

### Hosts file with protocol buttons

```text
192.168.1.1 router01 router01.local # proto=[ssh:8022,https:8443] Router management
192.168.1.2 switch01 switch01.local # proto=[ssh,telnet:2323,http:8080,https:8443] Switch management
192.168.1.10 jump01 jump01.local # proto=[rdp:3390,ssh:8022] Jump server
192.168.1.20 nas01 nas01.local # proto=[http:8080,https:8443,ftp:2121,sftp:2222] NAS services
192.168.1.30 kvm01 kvm01.local # proto=[vnc:5901,ssh] KVM console
```

### Hosts file area separator

```text
# --- START
# Production hosts
# Web tier
# --- END

192.168.0.10 web01 web01.example.com # proto=[http,https]
```

## Protocol Buttons

Protocol metadata is added inside comments:

```text
proto=[ssh,http,https,rdp]
```

Per-protocol ports are supported:

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

Examples:

```text
proto=[ssh]
proto=[ssh:8022]
proto=[http:8080]
proto=[https:8443]
proto=[rdp:3390]
proto=[vnc:5901]
proto=[ssh:8022,http:8080,https:8443,rdp:3390]
```

Expected behavior:

```text
ssh          -> ssh:host:22
ssh:8022     -> ssh:host:8022
http         -> http://host
http:8080    -> http://host:8080
https        -> https://host
https:8443   -> https://host:8443
rdp          -> downloadable RDP file using port 3389
rdp:3390     -> downloadable RDP file using port 3390
vnc          -> vnc:host:5900
vnc:5901     -> vnc:host:5901
```

### Legacy port directive

The old directive is no longer used for active link generation:

```text
port:XXXX
```

Use per-protocol ports instead:

```text
proto=[http:8080,https:8443,ssh:8022]
```

If an old file still contains `port:XXXX`, the viewer removes it from the visible comment but does not use it to build links.

## Custom Protocols

Custom protocols can be configured globally and then used in BIND zone comments or hosts-file comments.

Supported configuration variables:

```text
BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE
BIND9_VIEWER_CUSTOM_PROTOCOLS_JSON
```

### Custom protocol JSON file

Recommended path:

```text
/etc/bind9-viewer/custom_protocols.json
```

Example:

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
  },
  "console": {
    "label": "Console",
    "scheme": "console",
    "port": 9000,
    "mode": "protocol"
  }
}
```

Configure through PHP-FPM:

```ini
env[BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE] = /etc/bind9-viewer/custom_protocols.json
```

Configure through nginx FastCGI:

```nginx
fastcgi_param BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE /etc/bind9-viewer/custom_protocols.json;
```

Use custom protocols in BIND zone comments:

```zone
router01 IN A 192.168.1.1 ; proto=[winbox,mgmt,ssh:8022] Core router
server01 IN A 192.168.1.10 ; proto=[webssh,console:9001,https:8443] Server
```

Use custom protocols in hosts-file comments:

```text
192.168.1.1 router01 router01.local # proto=[winbox,mgmt,ssh:8022] Core router
192.168.1.10 server01 server01.local # proto=[webssh,console:9001,https:8443] Server
```

Expected links:

```text
winbox         -> winbox:host:8291
winbox:8292    -> winbox:host:8292
mgmt           -> https://host:8443
webssh         -> web+ssh:host:22
console        -> console:host:9000
console:9001   -> console:host:9001
```

## External Aruba Gateway Sources

`bind9_viewer.php` can render Aruba Gateway REST API `showcommand` output above the parsed DNS or hosts records.

The Aruba integration performs this sequence:

```text
login -> show command -> logout
```

Default command:

```text
show iap table
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

If one Aruba source fails, that source is displayed as an error while the rest of the dashboard continues to render.

## JSON Configuration Examples

### External sources JSON file

Recommended path:

```text
/etc/bind9-viewer/external_sources.json
```

Example with two Aruba Gateways:

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

### External sources raw JSON string

```bash
export ARUBA_GW_SOURCES_JSON='[
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
  }
]'
```

### Custom protocols raw JSON string

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

### Aruba source-specific custom protocols

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
    "protocols": ["https:8443", "ssh:8022", "winbox", "mgmt", "ping"],
    "customProtocols": {
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
    }
  }
]
```

Expected buttons:

```text
HTTPS:8443 SSH:8022 Winbox Mgmt Ping
```

## Browser and Protocol Handler Requirements

The viewer can generate custom protocol links, but browser and operating-system support determines what happens when a user clicks a protocol button.

Examples of generated links:

```text
ssh:host:22
vnc:host:5900
winbox:host:8291
web+ssh:host:22
```

For these links to work:

- The client computer must have a registered handler for the scheme.
- The browser may ask the user for permission before opening the handler.
- Enterprise browser policies may block external protocol handlers.
- Web-based handlers usually need a `web+` scheme and an HTTPS handler page.
- Native handlers usually require an installed application or OS-level registration.

If no handler is registered, clicking the button may do nothing or show a browser error.

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

## Security Notes

- Do not store Aruba credentials inside the web root.
- Prefer protected JSON files under `/etc/bind9-viewer/`.
- Restrict JSON file permissions, for example `640` with group ownership for the PHP-FPM group.
- The Ping endpoint validates targets and escapes shell arguments, but Ping still executes on the PHP server.
- Browser protocol handlers may be blocked or controlled by enterprise security policy.
- Custom protocol links can open local applications, so configure only trusted protocol definitions.

## Troubleshooting

### JSON is not loading

Check whether the PHP process can read the file:

```bash
sudo -u www-data test -r /etc/bind9-viewer/external_sources.json && echo readable
```

### nginx `fastcgi_param` is not visible in PHP

Use a debug PHP script to compare `getenv()` and `$_SERVER[...]` values. The loader checks both, but PHP-FPM pool settings and nginx config order can still affect visibility.

### Custom protocol button appears but does not open an app

The client workstation probably does not have a registered protocol handler for that scheme, or the browser/enterprise policy blocked it.

### CSS changes do not appear

Hard-refresh the browser or increment the cache-busting version:

```html
<link rel="stylesheet" href="bind9_viewer.css?v=16">
```

## Contributing

Contributions are welcome. Suggested improvements include:

- Additional DNS record type support.
- Search and filtering in the dashboard.
- Export options for parsed records.
- Additional external data source providers.
- Optional client-side HTTP/HTTPS reachability tests.
- Improved examples for browser and OS protocol-handler registration.

## License

The MIT License (MIT)

Copyright (c) 2026 Gorazd Kikelj

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
