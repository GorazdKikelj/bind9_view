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
     * Get the common protocol port map.
     *
     * @return array<string,int>
     */
    private function getPortMap()
    {
        return [
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
    }

    /**
     * Get shared JavaScript for opening preferred links.
     *
     * @return string JavaScript block
     */
    private function getOpenPreferredScript()
    {
        return '<script>
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
    </script>';
    }

    /**
     * Generate simple list HTML output
     * 
     * @return string HTML content
     */
    public function generateListHTML()
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIND9 DNS Records List - ' . htmlspecialchars($this->origin) . '</title>
    <link rel="stylesheet" href="bind9_viewer.css">
</head>
<body>
    <div class="container">
        <h1>BIND9 DNS Records - ' . htmlspecialchars($this->origin) . '</h1>
        <div class="view-toggle"><a href="' . (isset($_GET['zone']) ? 'bind9_viewer.php?zone=' . urlencode($_GET['zone']) . '&origin=' . urlencode(isset($_GET['origin']) ? $_GET['origin'] : '') : '#') . '" class="view-link">Card View</a> | <a href="bind9_help.php" class="view-link">Help</a></div>
         <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>IP Address</th>
                    <th>Links</th>
                    <th>Comment</th>
                </tr>
            </thead>
            <tbody>';

        $port_map = $this->getPortMap();

        foreach ($this->items as $item) {
            if ($item['type'] === 'record' && in_array($item['recordType'], ['A', 'CNAME'])) {
                $name = htmlspecialchars($item['name']);
                $value = htmlspecialchars($item['value']);
                $type = $item['recordType'];
                $class = strtolower($type) . '-record';
                $port = $item['port'] ?? null;
                $protocols = $item['protocols'] ?? [];
                $comment = $item['comment'] ?? '';

                // Build base URL for hostname
                $base_url = $name;
                $http_port = $port ?: 80;
                $https_port = $port ?: 443;

                $http_link = 'http://' . $base_url . ($http_port != 80 ? ':' . $http_port : '');
                $https_link = 'https://' . $base_url . ($https_port != 443 ? ':' . $https_port : '');

                $js_https = str_replace("'", "\\'", $https_link);
                $js_http = str_replace("'", "\\'", $http_link);

                $html .= '<tr class="' . $class . '">
                    <td><span class="record-type">' . $type . '</span></td>
                    <td class="hostname"><a href="#" class="hostname" onclick="openPreferred(\'' . $js_https . '\', \'' . $js_http . '\'); return false;">' . $name . '</a></td>
                    <td class="value"><a href="#" class="value" onclick="openPreferred(\'' . $js_https . '\', \'' . $js_http . '\'); return false;">' . $value . '</a></td>
                    <td class="links">
                        <a href="' . $http_link . '" target="_blank" class="link">HTTP</a>
                        <a href="' . $https_link . '" target="_blank" class="link">HTTPS</a>';

                // Add protocol links
                foreach ($protocols as $proto) {
                    $proto_lower = strtolower(trim($proto));
                    $proto_port = $port_map[$proto_lower] ?? $port ?? null;

                    if ($proto_port) {
                        if ($proto_lower === 'http' || $proto_lower === 'https') {
                            $proto_link = ($proto_lower === 'https' ? 'https' : 'http') . '://' . $base_url . ($port ? ':' . $port : '');
                        } else {
                            $proto_link = $proto_lower . '://' . $base_url . ':' . $proto_port;
                        }
                        $html .= '<a href="' . $proto_link . '" target="_blank" class="link protocol-link">' . htmlspecialchars($proto) . '</a>';
                    } else {
                        $html .= '<span class="link protocol-link" style="cursor: default;">' . htmlspecialchars($proto) . '</span>';
                    }
                }

                $html .= '</td>
                    <td class="comment">' . htmlspecialchars($comment) . '</td>
                </tr>';
            } elseif ($item['type'] === 'area') {
                $area_text = htmlspecialchars($item['text']);
                $html .= '<tr class="area-section">
                    <td colspan="5">' . $area_text . '</td>
                </tr>';
            }
        }

        $html .= '
            </tbody>
        </table>
    </div>';
        $html .= $this->getOpenPreferredScript();
        $html .= '
</body>
</html>';

        return $html;
    }

    /**
     * Generate HTML output
     * 
     * @return string HTML content
     */
    public function generateHTML()
    {
        // Check for list mode
        $isListMode = isset($_GET['list']) && $_GET['list'] === 'true';

        if ($isListMode) {
            return $this->generateListHTML();
        }
       
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIND9 DNS Records - ' . htmlspecialchars($this->origin) . '</title>
    <link rel="stylesheet" href="bind9_viewer.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 BIND9 DNS Records</h1>
            <h2><strong>Zone:</strong> ' . htmlspecialchars($this->origin) . '</h2>
            <div class="stats">
                <div class="stat"><strong>' . count($this->records['A']) . '</strong> A Records</div>
                <div class="stat"><strong>' . count($this->records['CNAME']) . '</strong> CNAME Records</div>
                <div class="stat"><strong>' . (count($this->records['A']) + count($this->records['CNAME'])) . '</strong> Total Records</div>
                ' . (isset($_GET['zone']) ? '<div class="stat"><strong><a href="bind9_viewer.php?zone=' . urlencode($_GET['zone']) . '&origin=' . urlencode(isset($_GET['origin']) ? $_GET['origin'] : '') . '&list=true" class="view-link">List View</a></strong></div>' : '') . '
                <div class="stat"><strong><a href="bind9_help.php" class="view-link">Help</a></strong></div>
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
                    <div class="record-hostname">
                    <button type="button" onclick="openPreferred(\'' . $https_link . '\', \'' . $http_link . '\')" class="record-link record-link-button">' . $value_text . '</button>
                    <button type="button" onclick="openPreferred(\'' . $https_link . '\', \'' . $http_link . '\')" class="record-link record-link-button">' . $name_display . '</button>
                    
                    </div>';

                if (!empty($comment)) {
                    $html .= '<div class="record-comment">' . htmlspecialchars($comment) . '</div>';
                }

                $html .= '
                    <div class="record-actions">
                        <a href="' . $http_link . '" target="_blank" class="record-link">HTTP</a>
                        <a href="' . $https_link . '" target="_blank" class="record-link">HTTPS</a>
                    ';

                if (!empty($protocols)) {
                    $port_map = $this->getPortMap();
                    $html .= ' ';
                    foreach ($protocols as $proto) {
                        $proto_lower = strtolower(trim($proto));
                        $proto_display = htmlspecialchars($proto);

                        $proto_port = $port_map[$proto_lower] ?? $port ?? null;

                        if ($proto_port) {
                            if ($proto_lower === 'http' || $proto_lower === 'https') {
                                $proto_link = ($proto_lower === 'https' ? 'https' : 'http') . '://' . $base_url . ($port ? ':' . $port : '');
                            } else {
                                #    $proto_link = strtolower($proto_lower) . '://' . $base_url . ':' . $proto_port;
                                $proto_link = strtolower($proto_lower) . ':' . $base_url . ':' . $proto_port;
                            }
                            #                            $html .= '<a href="' . htmlspecialchars($proto_link) . '" target="_blank" class="link protocol-link">' . $proto_display . '</a>';
                            $html .= '<a href="' . $proto_link . '" target="_blank" class="link protocol-link">' . $proto_display . '</a>';
                        } else {
                            $html .= '<span class="link protocol-label">' . $proto_display . '</span>';
                        }
                    }
                }

                $html .= '</div>
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
    </div>';
        $html .= $this->getOpenPreferredScript();
        $html .= '
</body>
</html>';

        return $html;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Command line usage
    if ($argc < 2) {
        echo "Usage: php " . basename(__FILE__) . " <zone-file> [origin] [list=true]\n";
        echo "Example: php " . basename(__FILE__) . " /etc/bind/db.example.com example.com list=true\n";
        exit(1);
    }

    $zoneFile = $argv[1];
    $origin = isset($argv[2]) ? $argv[2] : '';
    $isListMode = isset($argv[3]) && $argv[3] === 'list=true';

    try {
        $viewer = new BIND9Viewer();
        $viewer->parseZoneFile($zoneFile, $origin);

        $outputFile = pathinfo($zoneFile, PATHINFO_FILENAME) . '.html';
        if ($isListMode) {
            file_put_contents($outputFile, $viewer->generateListHTML());
        } else {
            file_put_contents($outputFile, $viewer->generateHTML());
        }

        echo "✓ Successfully generated: $outputFile\n";
        echo "  A Records: " . count($viewer->getARecords()) . "\n";
        echo "  CNAME Records: " . count($viewer->getCNAMERecords()) . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Web usage
    if (isset($_GET['zone'])) {
        // Zone file provided as URL parameter
        $zoneFile = $_GET['zone'];
        
        // Basic security check - ensure file exists and is readable
        if (!file_exists($zoneFile) || !is_readable($zoneFile)) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error: Zone file not found or not readable: " . htmlspecialchars($zoneFile) . "</h1></body></html>";
            exit;
        }
        
        $origin = isset($_GET['origin']) ? $_GET['origin'] : '';
        
        try {
            $viewer = new BIND9Viewer();
            $viewer->parseZoneFile($zoneFile, $origin);
            echo $viewer->generateHTML();
        } catch (Exception $e) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error: " . htmlspecialchars($e->getMessage()) . "</h1></body></html>";
        }
    }
}
