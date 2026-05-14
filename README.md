# bind9_viewer.php

## Overview

`bind9_viewer.php` is a PHP script that parses BIND9 DNS zone files and generates styled HTML output for A and CNAME records. It supports both CLI usage and web usage via a `zone` URL parameter that points to a readable zone file on the server.

## Features

- Parses A and CNAME records from BIND9 zone files
- Preserves `$ORIGIN` and `$TTL` directives
- Supports implicit hostnames and `@` origin references
- Parses inline comments and displays them in the output
- Detects `port:XXXX` values in comments for custom HTTP/HTTPS links
- Recognizes protocol lists in comments using `proto=[protocol1,protocol2]`
- Adds clickable `HTTP`, `HTTPS`, and `Visit` buttons for each record
- Adds protocol-specific links for supported protocols
- Recognizes area separator blocks using `; --- START` / `; --- END`
- Renders a responsive, styled HTML dashboard
- **NEW:** List mode with table layout (`list=true` parameter)
- **NEW:** Section delimiters displayed as table headers in list mode

## Requirements

- PHP 7.4+ (or compatible PHP build)
- Read access to the target BIND9 zone file
- Web server for web usage or CLI access for static HTML generation

## Supported Zone File Syntax

The script handles common BIND9 zone syntax, including:

- `$ORIGIN` and `$TTL` directives
- A records: `host IN A 192.168.0.1`
- CNAME records: `alias IN CNAME target`
- Inline comments after records using `;`
- Area separators using comment blocks:
  - `; --- START`
  - `; Comment for Area`
  - `; --- END`
- Implicit record names when the name is omitted
- `@` origin substitution
- Multi-line parenthetical sections are ignored during record parsing

## Comment and Port Support

Inline record comments are preserved and shown in the output. If a record comment includes a `port:XXXX` directive, that port is used for HTTP/HTTPS access links.

Example:

```zone
webserver IN A 192.168.0.10 ; port:8080 Web server
```

This creates:

- `http://webserver.example.com:8080`
- `https://webserver.example.com:8080`

## Protocol Support

Comments can include protocol hints using `proto=[protocol1,protocol2]`. The script adds clickable links for supported protocols using standard default ports.

Supported protocols:
- `ssh`: 22
- `telnet`: 23
- `http`: 80
- `https`: 443
- `ftp`: 21
- `sftp`: 22
- `smtp`: 25
- `pop3`: 110
- `imap`: 143
- `rdp`: 3389
- `vnc`: 5900

Example:

```zone
server IN A 192.168.0.10 ; proto=[ssh,telnet,rdp] Remote access server
```

This creates links for:

- `ssh:server.example.com:22`
- `telnet:server.example.com:23`
- `rdp:server.example.com:3389`

The `proto=[...]` directive is removed from the displayed comment text.

If a custom `port:XXXX` value is present, it is used for HTTP/HTTPS links. Other protocols use their standard default ports.

## Usage

### CLI Mode

Generate a static HTML file from a zone file:

```bash
php bind9_viewer.php /path/to/zone-file [origin]
```

Example:

```bash
php bind9_viewer.php /etc/bind/db.example.com example.com
```

The script writes output to `PATH_FILENAME.html`, for example `db.example.html`.

### Web Mode

Use the `zone` URL parameter to point to a readable zone file on the server. Optionally provide `origin` if the file does not define `$ORIGIN`.

Example:

```text
http://your-server/bind9_viewer.php?zone=/etc/bind/db.example.com&origin=example.com
```

### List Mode

For a compact table view instead of the card layout, add the `list=true` parameter:

```text
http://your-server/bind9_viewer.php?zone=/etc/bind/db.example.com&origin=example.com&list=true
```

The list mode displays records in a table format with columns for Type, Name, Value, Links, and Comment. Section delimiters from the zone file are shown as highlighted table rows spanning all columns.

## Output

### Card Mode (Default)

The generated HTML includes:

- A header showing the zone origin
- Statistics for A and CNAME records
- Record cards with hostname, type, value, comments, and links
- Protocol-specific links for supported protocols
- `HTTP`, `HTTPS`, and `Visit` buttons on each record card
- Area separator cards for named comment blocks
- Responsive styling for desktop and mobile

### List Mode (`list=true`)

The list mode provides a compact table view:

- Table with columns: Type, Name, Value, Links, Comment
- Section delimiters displayed as highlighted full-width rows
- All protocol and port links preserved
- Comments shown in dedicated column
- Clean, minimal styling optimized for quick scanning

## Notes

- The script focuses on A and CNAME records only.
- Multi-line SOA and other parenthetical records are ignored during parsing.
- Custom ports are parsed only from inline comments in the form `port:9999`.
- Protocol hints use `proto=[protocol1,protocol2]` and are stripped from displayed comments.
- The `Visit` button prefers HTTPS first and falls back to HTTP after a timeout.
- Web mode requires the PHP process to have read access to the provided `zone` file path.
