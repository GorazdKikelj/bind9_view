<?php
/**
 * BIND9 DNS Record Viewer
 * Parses BIND9 zone files and displays A and CNAME records with links
 */

class BIND9Viewer
{
    private $records = [
        'A' => [],
        'CNAME' => []
    ];
    private $items = [];
    private $origin = '';
    private $ttl = 3600;

    /**
     * Parse a BIND9 zone file
     * 
     * @param string $filePath Path to the BIND9 zone file
     * @param string $origin Optional origin/domain name
     * @return bool Success status
     */
    public function parseZoneFile($filePath, $origin = '')
    {
        if (!file_exists($filePath)) {
            throw new Exception("Zone file not found: $filePath");
        }

        $this->origin = $origin ?: basename($filePath);
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new Exception("Cannot read zone file: $filePath");
        }

        $currentName = '';
        $inParenthesis = false;
        $collectingArea = false;
        $areaLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($collectingArea) {
                if (preg_match('/^\s*;\s*---\s*END\s*$/i', $trimmedLine)) {
                    $areaText = trim(implode(" ", $areaLines));
                    $this->items[] = [
                        'type' => 'area',
                        'text' => $areaText
                    ];
                    $collectingArea = false;
                    $areaLines = [];
                } elseif (preg_match('/^\s*;(.+)$/', $trimmedLine, $commentMatch)) {
                    $areaLines[] = trim($commentMatch[1]);
                }
                continue;
            }

            if (preg_match('/^\s*;\s*---\s*START\s*$/i', $trimmedLine)) {
                $collectingArea = true;
                $areaLines = [];
                continue;
            }

            if (strpos($trimmedLine, ';') === 0) {
                continue;
            }

            // Keep inline comments for record parsing
            $originalLine = $line;
            $line = preg_replace('/;.*$/', '', $line);
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Handle parentheses for multi-line records
            if (strpos($line, '(') !== false) {
                $inParenthesis = true;
            }
            if (strpos($line, ')') !== false) {
                $inParenthesis = false;
            }

            // Skip lines inside SOA and other parenthetical records
            if ($inParenthesis && strpos($line, '(') === false) {
                continue;
            }

            // Parse directive lines
            if (preg_match('/^\$/', $line)) {
                if (preg_match('/^\$ORIGIN\s+(.+)/', $line, $matches)) {
                    $this->origin = trim($matches[1], '.');
                }
                if (preg_match('/^\$TTL\s+(\d+)/', $line, $matches)) {
                    $this->ttl = (int) $matches[1];
                }
                continue;
            }

            // Parse DNS records
            $this->parseRecord($originalLine, $currentName);
        }

        return true;
    }

    /**
     * Parse a single DNS record line
     * 
     * @param string $line Record line (with comments)
     * @param string &$currentName Current DNS name for implicit names
     */
    private function parseRecord($line, &$currentName)
    {
        // Extract comment if present
        $comment = '';
        $port = null;
        $protocols = [];
        if (preg_match('/;\s*(.+)$/', $line, $commentMatch)) {
            $comment = trim($commentMatch[1]);
            // Check for port specification
            if (preg_match('/port:(\d+)/i', $comment, $portMatch)) {
                $port = (int) $portMatch[1];
            }
            // Check for protocol list
            if (preg_match('/proto=\[([^\]]+)\]/i', $comment, $protoMatch)) {
                $protoStr = $protoMatch[1];
                $protocols = array_map('trim', explode(',', $protoStr));
                // Remove proto specification from display comment
                $comment = preg_replace('/\s*proto=\[[^\]]*\]\s*/i', ' ', $comment);
                $comment = trim($comment);
            }
        }

        // Remove comment for parsing
        $parseLine = preg_replace('/;.*$/', '', $line);
        $parseLine = trim($parseLine);

        $parts = preg_split('/\s+/', $parseLine);

        if (count($parts) < 3) {
            return;
        }

        // Check if first part is numeric (TTL) - if so, name is implicit
        if (is_numeric($parts[0])) {
            $name = $currentName;
            $offset = 0;
        } else {
            $name = array_shift($parts);
            $currentName = $name;
            $offset = 0;
        }

        // Skip optional TTL
        if (count($parts) > 0 && is_numeric($parts[0])) {
            array_shift($parts);
        }

        // Skip IN/CH/etc
        if (count($parts) > 0 && preg_match('/^(IN|CH|HS|NONE|ANY)$/i', $parts[0])) {
            array_shift($parts);
        }

        if (count($parts) < 2) {
            return;
        }

        $type = strtoupper($parts[0]);
        $value = implode(' ', array_slice($parts, 1));
        $value = trim($value, '"');

        $fullName = $this->expandName($name);

        if ($type === 'A') {
            if ($this->isValidIP($value)) {
                $this->records['A'][$fullName] = $value;
                $this->items[] = [
                    'type' => 'record',
                    'recordType' => 'A',
                    'name' => $fullName,
                    'value' => $value,
                    'comment' => $comment,
                    'port' => $port,
                    'protocols' => $protocols
                ];
            }
        } elseif ($type === 'CNAME') {
            $target = $this->expandName($value);
            $this->records['CNAME'][$fullName] = $target;
            $this->items[] = [
                'type' => 'record',
                'recordType' => 'CNAME',
                'name' => $fullName,
                'value' => $target,
                'comment' => $comment,
                'port' => $port,
                'protocols' => $protocols
            ];
        }
    }

    /**
     * Expand relative DNS names to full names
     * 
     * @param string $name Relative or absolute name
     * @return string Full domain name
     */
    private function expandName($name)
    {
        $name = trim($name, '.');

        if ($name === '@' || $name === '') {
            return $this->origin;
        }

        if (substr($name, -1) === '.') {
            return $name;
        }

        // If name doesn't end with origin, append it
        if (strpos($name, '.') === false || !preg_match('/' . preg_quote($this->origin) . '$/', $name)) {
            return $name . '.' . $this->origin;
        }

        return $name;
    }

    /**
     * Validate IPv4 address
     * 
     * @param string $ip IP address
     * @return bool
     */
    private function isValidIP($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Get all A records
     * 
     * @return array
     */
    public function getARecords()
    {
        return $this->records['A'];
    }

    /**
     * Get all CNAME records
     * 
     * @return array
     */
    public function getCNAMERecords()
    {
        return $this->records['CNAME'];
    }

    /**
     * Get all records
     * 
     * @return array
     */
    public function getAllRecords()
    {
        return $this->records;
    }

    /**
     * Generate HTML output
     * 
     * @return string HTML content
     */
    public function generateHTML()
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIND9 DNS Records - ' . htmlspecialchars($this->origin) . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        .stat {
            background: #f5f5f5;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 14px;
        }
        .stat strong {
            color: #667eea;
        }
        .records-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .section-title {
            background: #f8f9fa;
            padding: 20px;
            border-left: 4px solid #667eea;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        .records-grid {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        .record-card {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            background: #fff;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .record-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }
        .record-hostname {
            font-weight: 600;
            color: #333;
            word-break: break-all;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .record-type {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
            width: fit-content;
        }
        .record-value {
            color: #666;
            font-family: "Courier New", monospace;
            font-size: 13px;
            margin-bottom: 10px;
            word-break: break-all;
            flex-grow: 1;
        }
        .record-comment {
            color: #888;
            font-style: italic;
            font-size: 12px;
            margin-bottom: 8px;
            padding: 4px 8px;
            background: #f8f9fa;
            border-radius: 3px;
            border-left: 3px solid #ddd;
        }
        .record-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .record-protocols {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .record-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #6f66ea;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 10px;
            background: #f5f7ff;
            border-radius: 4px;
            transition: all 0.2s ease;
            align-self: flex-start;
        }
        .area-separator {
            border-color: #ffca28;
            background: #fff8e1;
            grid-column: 1 / -1;
        }
        .area-separator .record-hostname {
            color: #b78103;
        }
        .record-link:hover {
            background: #667eea;
            color: white;
        }
        .record-link-button {
            border: none;
            cursor: pointer;
            background: #f5f7ff;
            color: #667eea;
        }
        .record-link-button:hover {
            background: #667eea;
            color: white;
        }
        .protocol-link {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            color: white;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            background: linear-gradient(135deg, #89e73cad 50%, #0bdecb5c 100%);
            border-radius: 3px;
            transition: all 0.2s ease;
        }
        .protocol-link:hover {
            background: linear-gradient(135deg, #89e73cad 0%, #0bdecb5c 100%);
            transform: scale(1.05);
        }
        .protocol-label {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            color: #666;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            background: #f0f0f0;
            border-radius: 3px;
            border: 1px dashed #ccc;
        }
        .a-record .record-type {
            background: #28a745;
        }
        .a-record .record-link {
            color: #28a745;
        }
        .a-record .record-link:hover {
            background: #28a745;
            color: white;
        }
        .cname-record .record-type {
            background: #007bff;
        }
        .cname-record .record-link {
            color: #007bff;
        }
        .cname-record .record-link:hover {
            background: #007bff;
            color: white;
        }
        .empty-message {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }
        .footer {
            text-align: center;
            color: white;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 BIND9 DNS Records</h1>
            <p><strong>Zone:</strong> ' . htmlspecialchars($this->origin) . '</p>
            <div class="stats">
                <div class="stat"><strong>' . count($this->records['A']) . '</strong> A Records</div>
                <div class="stat"><strong>' . count($this->records['CNAME']) . '</strong> CNAME Records</div>
                <div class="stat"><strong>' . (count($this->records['A']) + count($this->records['CNAME'])) . '</strong> Total Records</div>
            </div>
        </div>';

        $html .= '
        <div class="records-section">
            <div class="section-title">Parsed Records (' . (count($this->records['A']) + count($this->records['CNAME'])) . ')</div>
            <div class="records-grid">';

        if (!empty($this->items)) {
            foreach ($this->items as $item) {
                if ($item['type'] === 'area') {
                    $area_text = htmlspecialchars($item['text']);
                    $html .= '
                <div class="record-card area-separator">
                    <div class="record-hostname">' . $area_text . '</div>
                </div>';
                    continue;
                }

                $name_display = htmlspecialchars($item['name']);
                $value_display = htmlspecialchars($item['value']);
                $port = $item['port'] ?? null;
                $comment = $item['comment'] ?? '';
                $protocols = $item['protocols'] ?? [];

                // Build URLs with custom port if specified
                $base_url = htmlspecialchars($item['name']);
                if ($port) {
                    $http_link = 'http://' . $base_url . ':' . $port;
                    $https_link = 'https://' . $base_url . ':' . $port;
                } else {
                    $http_link = 'http://' . $base_url;
                    $https_link = 'https://' . $base_url;
                }

                $type = $item['recordType'];
                $label = $type === 'A' ? 'A' : 'CNAME';
                $value_text = $type === 'A' ? $value_display : '→ ' . $value_display;
                $cardClass = $type === 'A' ? 'a-record' : 'cname-record';

                $html .= '
                <div class="record-card ' . $cardClass . '">
                    <div class="record-hostname">' . $name_display . '</div>
                    <span class="record-type">' . $label . '</span>
                    <div class="record-value">' . $value_text . '</div>';

                if (!empty($comment)) {
                    $html .= '<div class="record-comment">' . htmlspecialchars($comment) . '</div>';
                }

                $html .= '
                    <div class="record-actions">
                        <a href="' . $http_link . '" target="_blank" class="record-link">HTTP</a>
                        <a href="' . $https_link . '" target="_blank" class="record-link">HTTPS</a>
                        <button type="button" onclick="openPreferred(\'' . $https_link . '\', \'' . $http_link . '\')" class="record-link record-link-button">Visit</button>
                    </div>';

                if (!empty($protocols)) {
                    $html .= '<div class="record-protocols">';
                    foreach ($protocols as $proto) {
                        $proto_lower = strtolower(trim($proto));
                        $proto_display = htmlspecialchars($proto);

                        $port_map = [
                            'ssh' => 22,
                            'telnet' => 23,
                            'http' => 80,
                            'https' => 443,
                            'ftp' => 21,
                            'sftp' => 22,
                            'smtp' => 25,
                            'pop3' => 110,
                            'imap' => 143,
                            'rdp' => 3389,
                            'vnc' => 5900,
                        ];

                        $proto_port = $port_map[$proto_lower] ?? $port ?? null;

                        if ($proto_port) {
                            if ($proto_lower === 'http' || $proto_lower === 'https') {
                                $proto_link = ($proto_lower === 'https' ? 'https' : 'http') . '://' . $base_url . ($port ? ':' . $port : '');
                            } else {
                                #    $proto_link = strtolower($proto_lower) . '://' . $base_url . ':' . $proto_port;
                                $proto_link = strtolower($proto_lower) . ':' . $base_url . ':' . $proto_port;
                            }
                            #                            $html .= '<a href="' . htmlspecialchars($proto_link) . '" target="_blank" class="record-link protocol-link">' . $proto_display . '</a>';
                            $html .= '<a href="' . $proto_link . '" target="_blank" class="record-link protocol-link">' . $proto_display . '</a>';
                        } else {
                            $html .= '<span class="record-link protocol-label">' . $proto_display . '</span>';
                        }
                    }
                    $html .= '</div>';
                }

                $html .= '
                </div>';
            }
        } else {
            $html .= '
                <div class="record-card area-separator">
                    <div class="record-hostname">No records found</div>
                </div>';
        }

        $html .= '
            </div>
        </div>
        <div class="footer">
            <p>Generated on ' . date('Y-m-d H:i:s') . ' | BIND9 DNS Record Viewer</p>
        </div>
    </div>
    <script>
        function openPreferred(urlHttps, urlHttp) {
            var win = window.open("", "_blank");
            if (!win) {
                return;
            }

            var opened = false;
            var img = new Image();

            function navigate(url) {
                if (opened || win.closed) {
                    return;
                }
                opened = true;
                win.location = url;
            }

            img.onload = function() {
                navigate(urlHttps);
            };
            img.onerror = function() {
                navigate(urlHttp);
            };
            img.src = urlHttps;

            setTimeout(function() {
                navigate(urlHttp);
            }, 3000);
        }
    </script>
</body>
</html>';

        return $html;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Command line usage
    if ($argc < 2) {
        echo "Usage: php " . basename(__FILE__) . " <zone-file> [origin]\n";
        echo "Example: php " . basename(__FILE__) . " /etc/bind/db.example.com example.com\n";
        exit(1);
    }

    $zoneFile = $argv[1];
    $origin = isset($argv[2]) ? $argv[2] : '';

    try {
        $viewer = new BIND9Viewer();
        $viewer->parseZoneFile($zoneFile, $origin);

        $outputFile = pathinfo($zoneFile, PATHINFO_FILENAME) . '.html';
        file_put_contents($outputFile, $viewer->generateHTML());

        echo "✓ Successfully generated: $outputFile\n";
        echo "  A Records: " . count($viewer->getARecords()) . "\n";
        echo "  CNAME Records: " . count($viewer->getCNAMERecords()) . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Web usage
    if (!isset($_GET['file'])) {
        ?>
        <!DOCTYPE html>
        <html>

            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>BIND9 DNS Viewer</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        min-height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 20px;
                    }

                    .form-container {
                        background: white;
                        padding: 40px;
                        border-radius: 8px;
                        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                        max-width: 500px;
                        width: 100%;
                    }

                    h1 {
                        color: #333;
                        margin-bottom: 10px;
                    }

                    p {
                        color: #666;
                        margin-bottom: 30px;
                        font-size: 14px;
                    }

                    .form-group {
                        margin-bottom: 20px;
                    }

                    label {
                        display: block;
                        margin-bottom: 8px;
                        color: #333;
                        font-weight: 600;
                        font-size: 14px;
                    }

                    input,
                    textarea {
                        width: 100%;
                        padding: 12px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        font-family: monospace;
                        font-size: 13px;
                        resize: vertical;
                    }

                    input:focus,
                    textarea:focus {
                        outline: none;
                        border-color: #667eea;
                        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
                    }

                    button {
                        width: 100%;
                        padding: 12px;
                        background: #667eea;
                        color: white;
                        border: none;
                        border-radius: 4px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: background 0.2s;
                    }

                    button:hover {
                        background: #764ba2;
                    }
                </style>
            </head>

            <body>
                <div class="form-container">
                    <h1>🔗 BIND9 DNS Viewer</h1>
                    <p>Paste your BIND9 zone file content below to view A and CNAME records</p>
                    <form method="post">
                        <div class="form-group">
                            <label for="origin">Zone Origin (e.g., example.com):</label>
                            <input type="text" id="origin" name="origin" placeholder="example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="content">Zone File Content:</label>
                            <textarea id="content" name="content" rows="15" placeholder="Paste BIND9 zone file content here..."
                                required></textarea>
                        </div>
                        <button type="submit">Parse Zone File</button>
                    </form>
                </div>
            </body>

        </html>
        <?php
    } else {
        // Parse posted content
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content']) && isset($_POST['origin'])) {
            $content = $_POST['content'];
            $origin = $_POST['origin'];

            // Write temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'bind9_');
            file_put_contents($tempFile, $content);

            try {
                $viewer = new BIND9Viewer();
                $viewer->parseZoneFile($tempFile, $origin);
                echo $viewer->generateHTML();
            } catch (Exception $e) {
                echo "Error: " . htmlspecialchars($e->getMessage());
            } finally {
                unlink($tempFile);
            }
        }
    }
}
/*
I'll create a PHP script that parses BIND9 DNS zone files and generates HTML output with clickable links for A and CNAME records. 

Created [](file:///home/gorazd/bind9_viewer.php)

I've created a comprehensive PHP script that parses BIND9 zone files and generates interactive HTML output. Here's what it does:

**Features:**
- ✓ Parses A records (hostname → IPv4)
- ✓ Parses CNAME records (alias → target)
- ✓ Generates responsive HTML with styled cards
- ✓ Clickable links to visit hosts
- ✓ Statistics dashboard
- ✓ Works in both CLI and web modes

**Usage:**

**CLI mode** (generates HTML file):
```bash
php /home/gorazd/bind9_viewer.php /path/to/zone-file [zone-origin]
```
Example:
```bash
php /home/gorazd/bind9_viewer.php /etc/bind/db.example.com example.com
```

**Web mode** (interactive form):
1. Place the script in your web root
2. Access via browser: `http://your-server/bind9_viewer.php`
3. Paste zone file content and submit
4. Or use: `http://your-server/bind9_viewer.php?file=1`

**Script Capabilities:**
- Handles BIND9 zone file syntax (@ symbol, implicit names, $ORIGIN, $TTL)
- Removes comments and handles multi-line SOA records
- Expands relative domain names
- IP validation for A records
- Beautiful responsive UI with gradient background
- Hover effects on record cards
- Mobile-friendly design

The generated HTML includes record type badges, clickable links, and a summary dashboard showing total counts of each record type.

*/