<?php
/**
 * BIND9 DNS Record Viewer
 * Parses BIND9 zone files and /etc/hosts-style files and displays records with links.
 * Supports external data source sections, including Aruba Gateway REST API showcommand.
 */
class BIND9Viewer
{
    private $records = [
        'A' => [],
        'CNAME' => [],
        'HOSTS' => []
    ];

    private $items = [];
    private $origin = '';
    private $ttl = 3600;
    private $externalSources = [];
    private $externalSections = [];
    private $externalSectionsLoaded = false;
    private $customProtocols = [];

    private $ignoredHosts = [
        'localhost',
        'localhost.localdomain',
        'localhost6',
        'localhost6.localdomain',
        'localhost6.localdomain6',
        'ip6-localhost',
        'ip6-loopback',
        'ip6-localnet',
        'ip6-allnodes',
        'ip6-allrouters',
        'ip6-allhosts'
    ];

    private $cnameAliases = [];

    public function setExternalSources($sources)
    {
        $this->externalSources = is_array($sources) ? $sources : [];
        $this->externalSections = [];
        $this->externalSectionsLoaded = false;
    }

    public function setCustomProtocols($customProtocols)
    {
        $this->customProtocols = is_array($customProtocols) ? $customProtocols : [];
    }

    public function parseZoneFile($filePath, $origin = '')
    {
        if (!file_exists($filePath)) {
            throw new Exception("Zone file not found: $filePath");
        }

        $this->origin = trim($origin ?: basename($filePath), '.');
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new Exception("Cannot read zone file: $filePath");
        }

        $currentName = '';
        $inParenthesis = false;
        $collectingArea = false;
        $areaLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            if ($this->handleAreaComment($trimmedLine, $collectingArea, $areaLines, ';')) {
                continue;
            }

            if (strpos($trimmedLine, ';') === 0) {
                continue;
            }

            $originalLine = $line;
            $lineWithoutComment = trim(preg_replace('/;.*$/', '', $line));
            if ($lineWithoutComment === '') {
                continue;
            }

            if (preg_match('/^\$/', $lineWithoutComment)) {
                if (preg_match('/^\$ORIGIN\s+(.+)/i', $lineWithoutComment, $matches)) {
                    $this->origin = trim($matches[1], '.');
                } elseif (preg_match('/^\$TTL\s+(\d+)/i', $lineWithoutComment, $matches)) {
                    $this->ttl = (int)$matches[1];
                }
                continue;
            }

            $hasOpenParen = strpos($lineWithoutComment, '(') !== false;
            $hasCloseParen = strpos($lineWithoutComment, ')') !== false;
            if ($inParenthesis && !$hasCloseParen) {
                continue;
            }
            if ($hasOpenParen && !$hasCloseParen) {
                $inParenthesis = true;
            }
            if ($hasCloseParen) {
                $inParenthesis = false;
            }
            if ($hasOpenParen || $hasCloseParen) {
                continue;
            }

            $this->parseRecord($originalLine, $currentName);
        }

        $this->flushOpenArea($collectingArea, $areaLines);
        $this->mergeCNAMEAliases();
        return true;
    }

    public function parseHostsFile($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("Hosts file not found: $filePath");
        }

        $this->origin = 'hosts';
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new Exception("Cannot read hosts file: $filePath");
        }

        $collectingArea = false;
        $areaLines = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            if ($this->handleAreaComment($trimmedLine, $collectingArea, $areaLines, '#')) {
                continue;
            }

            if (strpos($trimmedLine, '#') === 0) {
                continue;
            }

            $comment = '';
            $recordPart = $line;
            if (strpos($line, '#') !== false) {
                [$recordPart, $commentPart] = explode('#', $line, 2);
                $comment = trim($commentPart);
            }

            $recordPart = trim($recordPart);
            if ($recordPart === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $recordPart);
            if (count($parts) < 2) {
                continue;
            }

            $ip = array_shift($parts);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $hostnames = array_values(array_filter(array_map('trim', $parts), 'strlen'));
            $hostnames = array_values(array_filter($hostnames, function ($hostname) {
                return !$this->isIgnoredHost($hostname);
            }));

            if (empty($hostnames)) {
                continue;
            }

            [$displayComment, $port, $protocols, $username] = $this->parseCommentMeta($comment);
            $primaryHostname = array_shift($hostnames);
            $aliases = $hostnames;

            $this->records['HOSTS'][$primaryHostname] = $ip;
            foreach ($aliases as $alias) {
                $this->records['HOSTS'][$alias] = $ip;
            }

            $this->items[] = [
                'type' => 'record',
                'recordType' => 'HOSTS',
                'name' => $primaryHostname,
                'aliases' => $aliases,
                'value' => $ip,
                'comment' => $displayComment,
                'port' => $port,
                'protocols' => $protocols,
                'username' => $username
            ];
        }

        $this->flushOpenArea($collectingArea, $areaLines);
        return true;
    }

    private function isIgnoredHost($hostname)
    {
        return in_array(strtolower(trim($hostname)), $this->ignoredHosts, true);
    }

    private function handleAreaComment($trimmedLine, &$collectingArea, &$areaLines, $commentChar)
    {
        $commentCharRegex = preg_quote($commentChar, '/');

        if ($collectingArea) {
            if (preg_match('/^\s*' . $commentCharRegex . '\s*---\s*END\s*$/i', $trimmedLine)) {
                $areaText = trim(implode(' ', array_filter($areaLines, 'strlen')));
                if ($areaText !== '') {
                    $this->items[] = ['type' => 'area', 'text' => $areaText];
                }
                $collectingArea = false;
                $areaLines = [];
                return true;
            }

            if (preg_match('/^\s*' . $commentCharRegex . '(.*)$/', $trimmedLine, $commentMatch)) {
                $text = trim($commentMatch[1]);
                if ($text !== '') {
                    $areaLines[] = $text;
                }
            }

            return true;
        }

        if (preg_match('/^\s*' . $commentCharRegex . '\s*---\s*START\s*$/i', $trimmedLine)) {
            $collectingArea = true;
            $areaLines = [];
            return true;
        }

        return false;
    }

    private function flushOpenArea($collectingArea, $areaLines)
    {
        if ($collectingArea && !empty($areaLines)) {
            $areaText = trim(implode(' ', array_filter($areaLines, 'strlen')));
            if ($areaText !== '') {
                $this->items[] = ['type' => 'area', 'text' => $areaText];
            }
        }
    }

    private function parseCommentMeta($comment)
    {
        $displayComment = trim($comment);
        $protocols = [];
        $username = null;

        if ($displayComment !== '') {
            if (preg_match('/proto=\[([^\]]+)\]/i', $displayComment, $protoMatch)) {
                $protocols = array_values(array_filter(array_map('trim', explode(',', $protoMatch[1])), 'strlen'));
                $displayComment = preg_replace('/\s*proto=\[[^\]]*\]\s*/i', ' ', $displayComment);
                $displayComment = trim(preg_replace('/\s+/', ' ', $displayComment));
            }

            if (preg_match('/user=([^\s]+)/i', $displayComment, $userMatch)) {
                $username = trim($userMatch[1]);
                $displayComment = preg_replace('/\s*user=[^\s]+\s*/i', ' ', $displayComment);
                $displayComment = trim(preg_replace('/\s+/', ' ', $displayComment));
            }

            /* Backward-compatible cleanup only. port:XXXX no longer affects generated buttons. */
            if (preg_match('/port:(\d+)/i', $displayComment)) {
                $displayComment = preg_replace('/\s*port:\d+\s*/i', ' ', $displayComment);
                $displayComment = trim(preg_replace('/\s+/', ' ', $displayComment));
            }
        }

        return [$displayComment, null, $protocols, $username];
    }

    private function parseProtocolSpec($spec)
    {
        $spec = strtolower(trim((string)$spec));

        if ($spec === '') {
            return [null, null];
        }

        if (strpos($spec, ':') !== false) {
            [$protocol, $port] = explode(':', $spec, 2);
            $protocol = strtolower(trim($protocol));
            $port = trim($port);

            if ($protocol === '') {
                return [null, null];
            }

            if ($port !== '' && ctype_digit($port)) {
                return [$protocol, (int)$port];
            }

            return [$protocol, null];
        }

        return [$spec, null];
    }

    private function parseRecord($line, &$currentName)
    {
        $comment = '';
        if (preg_match('/;\s*(.+)$/', $line, $commentMatch)) {
            $comment = trim($commentMatch[1]);
        }

        [$displayComment, $port, $protocols, $username] = $this->parseCommentMeta($comment);
        $parseLine = trim(preg_replace('/;.*$/', '', $line));
        if ($parseLine === '') {
            return;
        }

        $parts = preg_split('/\s+/', $parseLine);
        if (count($parts) < 2) {
            return;
        }

        $firstToken = $parts[0] ?? '';
        $lineStartsWithWhitespace = preg_match('/^\s+/', $line) === 1;
        $firstIsTTL = is_numeric($firstToken);
        $firstIsClass = preg_match('/^(IN|CH|HS|NONE|ANY)$/i', $firstToken) === 1;

        if ($lineStartsWithWhitespace || $firstIsTTL || $firstIsClass) {
            $name = $currentName;
        } else {
            $name = array_shift($parts);
            $currentName = $name;
        }

        if ($name === '') {
            return;
        }

        $changed = true;
        while ($changed && count($parts) > 0) {
            $changed = false;
            if (count($parts) > 0 && is_numeric($parts[0])) {
                array_shift($parts);
                $changed = true;
            }
            if (count($parts) > 0 && preg_match('/^(IN|CH|HS|NONE|ANY)$/i', $parts[0])) {
                array_shift($parts);
                $changed = true;
            }
        }

        if (count($parts) < 2) {
            return;
        }

        $type = strtoupper(array_shift($parts));
        $value = trim(implode(' ', $parts), '"');
        $fullName = $this->expandName($name);

        if ($type === 'A' && $this->isValidIP($value)) {
            $this->records['A'][$fullName] = $value;
            $this->items[] = [
                'type' => 'record',
                'recordType' => 'A',
                'name' => $fullName,
                'aliases' => [],
                'value' => $value,
                'comment' => $displayComment,
                'port' => $port,
                'protocols' => $protocols,
                'username' => $username
            ];
        } elseif ($type === 'CNAME') {
            $target = $this->expandName($value);
            $this->records['CNAME'][$fullName] = $target;
            $this->cnameAliases[$fullName] = [
                'target' => $target,
                'comment' => $displayComment,
                'port' => $port,
                'protocols' => $protocols,
                'username' => $username
            ];
        }
    }

    private function expandName($name)
    {
        $name = trim($name);

        if ($name === '@' || $name === '') {
            return $this->origin;
        }

        if (substr($name, -1) === '.') {
            return rtrim($name, '.');
        }

        if ($this->origin === '') {
            return $name;
        }

        $lowerName = strtolower($name);
        $lowerOrigin = strtolower($this->origin);
        if ($lowerName === $lowerOrigin || substr($lowerName, -strlen('.' . $lowerOrigin)) === '.' . $lowerOrigin) {
            return $name;
        }

        return $name . '.' . $this->origin;
    }

    private function isValidIP($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function resolveFinalTarget($host)
    {
        $seen = [];
        while (isset($this->records['CNAME'][$host])) {
            if (isset($seen[$host])) {
                break;
            }
            $seen[$host] = true;
            $host = $this->records['CNAME'][$host];
        }
        return $host;
    }

    private function mergeCNAMEAliases()
    {
        $aRecords = [];
        foreach ($this->items as $idx => $item) {
            if (($item['type'] ?? '') === 'record' && ($item['recordType'] ?? '') === 'A') {
                $aRecords[$item['name']] = $idx;
            }
        }

        foreach ($this->records['CNAME'] as $cname => $target) {
            $finalTarget = $this->resolveFinalTarget($target);
            if (isset($aRecords[$finalTarget])) {
                $idx = $aRecords[$finalTarget];
                if (!isset($this->items[$idx]['aliases'])) {
                    $this->items[$idx]['aliases'] = [];
                }
                $this->items[$idx]['aliases'][] = $cname;
                continue;
            }

            $this->items[] = [
                'type' => 'record',
                'recordType' => 'CNAME',
                'name' => $cname,
                'aliases' => [],
                'value' => $target,
                'comment' => '',
                'port' => null,
                'protocols' => [],
                'username' => null
            ];
        }
    }

    public function getARecords()
    {
        return $this->records['A'];
    }

    public function getCNAMERecords()
    {
        return $this->records['CNAME'];
    }

    public function getHostsRecords()
    {
        return $this->records['HOSTS'];
    }

    public function getAllRecords()
    {
        return $this->records;
    }

    private function getTotalCount()
    {
        return count($this->records['A']) + count($this->records['CNAME']) + count($this->records['HOSTS']);
    }

    private function getDisplayItemCount()
    {
        $count = 0;
        foreach ($this->items as $item) {
            if (($item['type'] ?? '') === 'record') {
                $count++;
            }
        }
        return $count;
    }

    private function getExternalSupportedProtocols()
    {
        return ['http', 'https', 'ping', 'ssh', 'telnet', 'ftp', 'sftp', 'rdp', 'vnc'];
    }

    private function getPortMap()
    {
        return [
            'ssh' => 22,
            'telnet' => 23,
            'http' => 80,
            'https' => 443,
            'ftp' => 21,
            'sftp' => 22,
            'rdp' => 3389,
            'vnc' => 5900,
        ];
    }

    private function normalizeExternalProtocols($source)
    {
        $defaultProtocols = ['http', 'https', 'ping'];
        $protocols = $source['protocols'] ?? ($source['protocol'] ?? $defaultProtocols);

        if (is_string($protocols)) {
            $protocols = array_map('trim', explode(',', $protocols));
        }

        if (!is_array($protocols)) {
            $protocols = $defaultProtocols;
        }

        $supported = array_flip($this->getExternalSupportedProtocols());
        $customProtocols = $source['customProtocols'] ?? [];

        if (is_array($customProtocols)) {
            foreach ($customProtocols as $customName => $_definition) {
                $customName = strtolower(trim((string)$customName));
                if ($customName !== '') {
                    $supported[$customName] = true;
                }
            }
        }

        $normalized = [];
        foreach ($protocols as $protocolSpec) {
            [$protocol, $_protocolPort] = $this->parseProtocolSpec($protocolSpec);

            if ($protocol === null) {
                continue;
            }

            if (!isset($supported[$protocol])) {
                continue;
            }

            $protocolSpec = strtolower(trim((string)$protocolSpec));
            if (!in_array($protocolSpec, $normalized, true)) {
                $normalized[] = $protocolSpec;
            }
        }

        return !empty($normalized) ? $normalized : $defaultProtocols;
    }

    private function getExternalCustomProtocols($section)
    {
        $customProtocols = $section['customProtocols'] ?? [];

        if (!is_array($customProtocols)) {
            return [];
        }

        $normalized = [];
        foreach ($customProtocols as $name => $definition) {
            $name = strtolower(trim((string)$name));
            if ($name === '' || !is_array($definition)) {
                continue;
            }

            $scheme = strtolower(trim((string)($definition['scheme'] ?? $name)));
            $label = trim((string)($definition['label'] ?? strtoupper($name)));
            $port = isset($definition['port']) && $definition['port'] !== '' ? (int)$definition['port'] : null;
            $mode = strtolower(trim((string)($definition['mode'] ?? 'protocol')));

            if ($scheme === '') {
                continue;
            }

            if (!in_array($mode, ['protocol', 'url'], true)) {
                $mode = 'protocol';
            }

            $normalized[$name] = [
                'scheme' => $scheme,
                'label' => $label !== '' ? $label : strtoupper($name),
                'port' => $port,
                'mode' => $mode
            ];
        }

        return $normalized;
    }

    private function getGlobalCustomProtocols()
    {
        return $this->getExternalCustomProtocols(['customProtocols' => $this->customProtocols]);
    }

    private function buildCustomProtocolUrl($definition, $target, $protocolPort = null)
    {
        $scheme = $definition['scheme'];
        $port = $protocolPort ?: ($definition['port'] ?? null);
        $mode = $definition['mode'] ?? 'protocol';

        if ($mode === 'url') {
            $url = $scheme . ':' . '//' . $target;
            if ($port) {
                $url .= ':' . (int)$port;
            }
            return $url;
        }

        $url = $scheme . ':' . $target;
        if ($port) {
            $url .= ':' . (int)$port;
        }
        return $url;
    }

    private function buildWebUrls($host, $port)
    {
        $host = trim((string)$host);

        if ($port) {
            return [
                'http' . '://' . $host . ':' . (int)$port,
                'https' . '://' . $host . ':' . (int)$port
            ];
        }

        return [
            'http' . '://' . $host,
            'https' . '://' . $host
        ];
    }

    private function buildProtocolUrl($protocol, $host, $defaultPort, $protocolPort = null)
    {
        $protocol = strtolower(trim((string)$protocol));
        $host = trim((string)$host);

        if ($protocol === '' || $host === '') {
            return null;
        }

        $port = $protocolPort ?: $defaultPort;

        if (in_array($protocol, ['http', 'https'], true)) {
            return $protocol . ':' . '//' . $host . ($port ? ':' . (int)$port : '');
        }

        if ($port) {
            return $protocol . ':' . $host . ':' . (int)$port;
        }

        return $protocol . ':' . $host;
    }

    private function currentViewUrl($listMode)
    {
        if (php_sapi_name() === 'cli' || !isset($_GET['zone'])) {
            return '#';
        }

        $params = ['zone' => $_GET['zone'], 'origin' => $_GET['origin'] ?? ''];
        if (isset($_GET['type'])) {
            $params['type'] = $_GET['type'];
        }
        if ($listMode) {
            $params['list'] = 'true';
        }

        return 'bind9_viewer.php?' . http_build_query($params);
    }

    private function getOpenPreferredScript()
    {
        return '<script>
            function openPreferred(urlHttps, urlHttp) {
                var win = window.open("", "_blank");
                if (!win) return;
                var opened = false;
                var img = new Image();
                function navigate(url) {
                    if (opened || win.closed) return;
                    opened = true;
                    win.location = url;
                }
                img.onload = function() { navigate(urlHttps); };
                img.onerror = function() { navigate(urlHttp); };
                img.src = urlHttps;
                setTimeout(function() { navigate(urlHttp); }, 3000);
            }

            function pingTarget(target, button) {
                if (!target || !button) return;
                const originalText = button.textContent;
                button.disabled = true;
                button.classList.remove("ping-ok", "ping-fail");
                button.classList.add("ping-pending");
                button.textContent = "Testing...";
                fetch("bind9_viewer.php?action=ping&target=" + encodeURIComponent(target), { method: "GET", credentials: "same-origin" })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        button.classList.remove("ping-pending");
                        if (data && data.reachable) {
                            button.classList.add("ping-ok");
                            button.textContent = "Reachable";
                        } else {
                            button.classList.add("ping-fail");
                            button.textContent = "No response";
                        }
                    })
                    .catch(function() {
                        button.classList.remove("ping-pending");
                        button.classList.add("ping-fail");
                        button.textContent = "Error";
                    })
                    .finally(function() {
                        setTimeout(function() {
                            button.disabled = false;
                            button.classList.remove("ping-ok", "ping-fail", "ping-pending");
                            button.textContent = originalText;
                        }, 3000);
                    });
            }

            function downloadRDP(host, port, username) {
                port = port || 3389;
                username = username || "";
                const rdpContent =
                    "full address:s:" + host + ":" + port + "\n" +
                    "username:s:" + username + "\n" +
                    "prompt for credentials:i:1\n" +
                    "authentication level:i:2\n" +
                    "redirectclipboard:i:1\n" +
                    "redirectprinters:i:0\n" +
                    "redirectdrives:i:0\n" +
                    "redirectposdevices:i:0\n";
                const blob = new Blob([rdpContent], { type: "application/x-rdp" });
                const link = document.createElement("a");
                link.href = URL.createObjectURL(blob);
                link.download = host + ".rdp";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(function() { URL.revokeObjectURL(link.href); }, 1000);
            }

            (function initializeTheme() {
                var savedTheme = localStorage.getItem("bind9_viewer_theme");
                if (savedTheme === "light" || savedTheme === "dark") {
                    document.documentElement.setAttribute("data-theme", savedTheme);
                }
            })();

            function toggleTheme() {
                var currentTheme = document.documentElement.getAttribute("data-theme");
                if (!currentTheme) {
                    currentTheme = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
                }
                var nextTheme = currentTheme === "dark" ? "light" : "dark";
                document.documentElement.setAttribute("data-theme", nextTheme);
                localStorage.setItem("bind9_viewer_theme", nextTheme);
                updateThemeToggleText();
            }

            function updateThemeToggleText() {
                var button = document.getElementById("theme-toggle");
                if (!button) return;
                var currentTheme = document.documentElement.getAttribute("data-theme");
                if (!currentTheme) {
                    currentTheme = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
                }
                button.textContent = currentTheme === "dark" ? "☀ Light" : "🌙 Dark";
            }

            document.addEventListener("DOMContentLoaded", updateThemeToggleText);
        </script>';
    }

    private function recordClass($type)
    {
        if ($type === 'A') {
            return 'a-record';
        }
        if ($type === 'CNAME') {
            return 'cname-record';
        }
        if ($type === 'HOSTS') {
            return 'hosts-record';
        }
        return '';
    }

    private function displayValue($type, $value)
    {
        $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return $type === 'CNAME' ? '-> ' . $safeValue : $safeValue;
    }

    private function aliasButton($alias, $port)
    {
        [$http, $https] = $this->buildWebUrls($alias, $port);
        $safeAlias = htmlspecialchars($alias, ENT_QUOTES, 'UTF-8');
        $jsHttps = htmlspecialchars(json_encode($https), ENT_QUOTES, 'UTF-8');
        $jsHttp = htmlspecialchars(json_encode($http), ENT_QUOTES, 'UTF-8');
        return '<div class="alias-line"><button type="button" class="alias-link" onclick="openPreferred(' . $jsHttps . ', ' . $jsHttp . ');"><span class="alias-icon">🖥️</span><span class="alias-text">' . $safeAlias . '</span></button></div>';
    }

    private function formatNameWithAliases($name, $aliases = [], $port = null, $httpsLink = null, $httpLink = null)
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        if ($httpsLink !== null && $httpLink !== null) {
            $jsHttps = htmlspecialchars(json_encode($httpsLink), ENT_QUOTES, 'UTF-8');
            $jsHttp = htmlspecialchars(json_encode($httpLink), ENT_QUOTES, 'UTF-8');
            $tooltip = 'Open HTTPS: ' . $httpsLink . ' | fallback HTTP: ' . $httpLink;
            $safeTooltip = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');
            $html = '<a href="#" class="record-hostname-link" onclick="openPreferred(' . $jsHttps . ', ' . $jsHttp . '); return false;" title="' . $safeTooltip . '">' . $safeName . '</a>';
        } else {
            $html = $safeName;
        }

        if (empty($aliases)) {
            return $html;
        }

        $html .= '<details class="record-aliases">';
        $html .= '<summary class="record-aliases-summary">Aliases (' . count($aliases) . ')</summary>';
        $html .= '<div class="record-aliases-list">';

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $html .= $this->aliasButton($alias, $port);
            }
        }

        $html .= '</div></details>';
        return $html;
    }

    private function preferredLinkHost($name, $aliases = [])
    {
        foreach ($aliases as $alias) {
            if (strpos($alias, '.') !== false) {
                return $alias;
            }
        }
        return $name;
    }

    private function pingTargetForItem($type, $value, $linkHost)
    {
        return ($type === 'A' || $type === 'HOSTS') ? $value : $linkHost;
    }

    private function pingButtonHtml($target, $listMode)
    {
        $class = $listMode ? 'link ping-button' : 'record-link record-link-button ping-button';
        $safeTarget = htmlspecialchars(json_encode($target), ENT_QUOTES, 'UTF-8');
        return '<button type="button" class="' . $class . '" onclick="pingTarget(' . $safeTarget . ', this)">Ping</button>';
    }

    private function protocolButtonHtml($protoSpec, $protoDisplay, $linkHost, $port, $item, $listMode)
    {
        [$protoLower, $protocolPort] = $this->parseProtocolSpec($protoSpec);
        if ($protoLower === null) {
            return '';
        }

        $portMap = $this->getPortMap();
        $customProtocols = $this->getGlobalCustomProtocols();
        $buttonClass = $listMode ? 'link protocol-link protocol-button' : 'record-link protocol-link protocol-button';
        $linkClass = $listMode ? 'link protocol-link' : 'record-link protocol-link';
        $labelClass = $listMode ? 'link protocol-label' : 'record-link protocol-label';

        if ($protoLower === 'rdp') {
            $label = 'RDP' . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $rdpPort = $protocolPort ?: ($portMap['rdp'] ?? 3389);
            $rdpUser = $item['username'] ?? 'Administrator';
            return '<button type="button" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '" onclick="downloadRDP('
                . htmlspecialchars(json_encode($linkHost), ENT_QUOTES, 'UTF-8') . ','
                . (int)$rdpPort . ','
                . htmlspecialchars(json_encode($rdpUser), ENT_QUOTES, 'UTF-8') . ')">' . $safeLabel . '</button>';
        }

        if (isset($customProtocols[$protoLower])) {
            $definition = $customProtocols[$protoLower];
            $link = $this->buildCustomProtocolUrl($definition, $linkHost, $protocolPort);
            $label = $definition['label'] . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            return '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') . '">' . $safeLabel . '</a>';
        }

        $defaultPort = $portMap[$protoLower] ?? null;
        $protoLink = $this->buildProtocolUrl($protoLower, $linkHost, $defaultPort, $protocolPort);
        if ($protoLink !== null) {
            $label = strtoupper($protoLower) . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            return '<a href="' . htmlspecialchars($protoLink, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') . '">' . $safeLabel . '</a>';
        }

        $fallbackLabel = strtoupper($protoLower) . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
        return '<span class="' . htmlspecialchars($labelClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($fallbackLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    private function logoutArubaGateway($baseUrl, $cookieFile, $verifySsl, $csrfToken = '')
    {
        $headers = [];

        if ($csrfToken !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }

        $this->curlRequest([
            'url' => rtrim($baseUrl, '/') . '/v1/api/logout',
            'method' => 'GET',
            'cookieFile' => $cookieFile,
            'verifySsl' => $verifySsl,
            'headers' => $headers,
            'description' => 'Aruba logout'
        ]);
    }

    private function loadExternalSections()
    {
        if ($this->externalSectionsLoaded) {
            return;
        }

        $this->externalSectionsLoaded = true;
        $this->externalSections = [];

        foreach ($this->externalSources as $source) {
            $enabled = $source['enabled'] ?? false;

            if ($enabled !== true) {
                continue;
            }

            try {
                if (($source['type'] ?? '') === 'aruba_gw_api') {
                    $this->externalSections[] = $this->fetchArubaGatewaySection($source);
                    continue;
                }

                $this->externalSections[] = [
                    'title' => $source['title'] ?? 'Unknown external source',
                    'type' => 'error',
                    'content' => 'Unsupported external source type: ' . ($source['type'] ?? 'missing')
                ];
            } catch (Exception $e) {
                $this->externalSections[] = [
                    'title' => $source['title'] ?? 'External source error',
                    'type' => 'error',
                    'content' => $e->getMessage()
                ];
            }
        }
    }

    private function fetchArubaGatewaySection($source)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('PHP cURL extension is required for Aruba Gateway API source.');
        }

        $baseUrl = rtrim((string)($source['baseUrl'] ?? ''), '/');
        $username = (string)($source['username'] ?? '');
        $password = (string)($source['password'] ?? '');
        $command = (string)($source['command'] ?? 'show iap table');
        $verifySsl = (bool)($source['verifySsl'] ?? false);
        $title = (string)($source['title'] ?? 'Aruba Gateway API');
        $protocols = $this->normalizeExternalProtocols($source);
        $customProtocols = isset($source['customProtocols']) && is_array($source['customProtocols']) ? $source['customProtocols'] : [];
        $rdpUser = (string)($source['rdpUser'] ?? 'Administrator');
        $rdpPort = (int)($source['rdpPort'] ?? 3389);

        if ($baseUrl === '') {
            throw new Exception('Missing Aruba Gateway baseUrl.');
        }

        if ($username === '') {
            throw new Exception('Missing Aruba Gateway username.');
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'aruba_cookie_');
        if ($cookieFile === false) {
            throw new Exception('Could not create temporary Aruba cookie file.');
        }

        $loggedIn = false;
        $csrfToken = '';
        $logoutWarning = null;
        $section = null;

        try {
            $loginResponse = $this->curlRequest([
                'url' => $baseUrl . '/v1/api/login',
                'method' => 'POST',
                'postFields' => http_build_query(['username' => $username, 'password' => $password]),
                'cookieFile' => $cookieFile,
                'verifySsl' => $verifySsl,
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'description' => 'Aruba login'
            ]);

            $loginJson = json_decode($loginResponse['body'], true);
            if (!is_array($loginJson)) {
                throw new Exception('Aruba login returned invalid JSON.');
            }

            $globalResult = $loginJson['_global_result'] ?? [];
            $status = (string)($globalResult['status'] ?? '');
            $statusText = (string)($globalResult['status_str'] ?? '');
            $csrfToken = (string)($globalResult['X-CSRF-Token'] ?? '');

            if ($status !== '0') {
                throw new Exception('Aruba login failed: ' . ($statusText !== '' ? $statusText : 'Unknown login error'));
            }

            if ($csrfToken === '') {
                throw new Exception('Aruba login succeeded, but X-CSRF-Token was not returned.');
            }

            $loggedIn = true;
            $showUrl = $baseUrl . '/v1/configuration/showcommand?command=' . rawurlencode($command);

            $showResponse = $this->curlRequest([
                'url' => $showUrl,
                'method' => 'GET',
                'cookieFile' => $cookieFile,
                'verifySsl' => $verifySsl,
                'headers' => ['X-CSRF-Token: ' . $csrfToken],
                'description' => 'Aruba showcommand: ' . $command
            ]);

            $parsed = $this->parseArubaIapTable($showResponse['body']);

            $section = [
                'title' => $title . ' - ' . $command,
                'type' => 'aruba_iap_table',
                'rows' => $parsed['rows'],
                'summary' => $parsed['summary'],
                'raw' => $parsed['raw'],
                'warnings' => [],
                'protocols' => $protocols,
                'customProtocols' => $customProtocols,
                'rdpUser' => $rdpUser,
                'rdpPort' => $rdpPort
            ];
        } finally {
            if ($loggedIn) {
                try {
                    $this->logoutArubaGateway($baseUrl, $cookieFile, $verifySsl, $csrfToken);
                } catch (Exception $logoutException) {
                    $logoutWarning = 'Aruba logout warning: ' . $logoutException->getMessage();
                }
            }

            if (file_exists($cookieFile)) {
                unlink($cookieFile);
            }
        }

        if ($section === null) {
            throw new Exception('Aruba Gateway source did not return a usable section.');
        }

        if ($logoutWarning !== null) {
            $section['warnings'][] = $logoutWarning;
        }

        return $section;
    }

    private function curlRequest($options)
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new Exception('Could not initialize cURL.');
        }

        $url = (string)($options['url'] ?? '');
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $headers = $options['headers'] ?? [];
        $cookieFile = $options['cookieFile'] ?? null;
        $verifySsl = (bool)($options['verifySsl'] ?? true);
        $description = (string)($options['description'] ?? $url);

        if ($url === '') {
            unset($ch);
            throw new Exception('Missing cURL URL for ' . $description . '.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($cookieFile !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)($options['postFields'] ?? ''));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            unset($ch);
            throw new Exception($description . ' failed with cURL error ' . $errno . ': ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            $preview = trim(substr((string)$body, 0, 500));
            if ($preview !== '') {
                throw new Exception($description . ' failed with HTTP ' . $httpCode . '. Response preview: ' . $preview);
            }
            throw new Exception($description . ' failed with HTTP ' . $httpCode . '.');
        }

        return ['httpCode' => $httpCode, 'body' => $body];
    }

    private function parseArubaIapTable($body)
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['rows' => [], 'summary' => [], 'raw' => trim($body)];
        }

        $table = $json['IAP Branch Table'] ?? [];
        $summary = $json['_data'] ?? [];
        if (!is_array($table)) {
            $table = [];
        }
        if (!is_array($summary)) {
            $summary = [];
        }

        $rows = [];
        foreach ($table as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hostname = trim((string)($row['VC Name'] ?? ''));
            $ip = trim((string)($row['Inner IP'] ?? ''));
            if ($hostname === '' && $ip === '') {
                continue;
            }
            $rows[] = [
                'hostname' => $hostname,
                'ip' => $ip,
                'status' => trim((string)($row['Status'] ?? '')),
                'mac' => trim((string)($row['VC MAC Address'] ?? '')),
                'vlans' => trim((string)($row['Assigned Vlan'] ?? '')),
                'subnet' => trim((string)($row['Assigned Subnet'] ?? ''))
            ];
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'raw' => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
    }

    private function renderExternalSections($listMode = false)
    {
        $this->loadExternalSections();
        if (empty($this->externalSections)) {
            return '';
        }

        $html = '<div class="external-section"><div class="section-title">External Data Sources</div>';

        foreach ($this->externalSections as $section) {
            $type = $section['type'] ?? 'pre';
            if ($type === 'aruba_iap_table') {
                $html .= $this->renderArubaIapTable($section, $listMode);
                continue;
            }

            $title = htmlspecialchars($section['title'] ?? 'External Source', ENT_QUOTES, 'UTF-8');
            $content = (string)($section['content'] ?? '');
            $html .= '<div class="external-card"><div class="external-title">' . $title . '</div>';

            if ($type === 'error') {
                $html .= '<div class="external-error">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</div>';
            } else {
                $html .= '<pre class="external-output">' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderExternalProtocolActions($target, $section, $listMode)
    {
        $target = trim((string)$target);
        if ($target === '' || $target === '0.0.0.0') {
            return '<span class="protocol-label">No IP</span>';
        }

        $protocols = $section['protocols'] ?? ['http', 'https', 'ping'];
        if (is_string($protocols)) {
            $protocols = array_map('trim', explode(',', $protocols));
        }
        if (!is_array($protocols)) {
            $protocols = ['http', 'https', 'ping'];
        }

        $rdpUser = (string)($section['rdpUser'] ?? 'Administrator');
        $rdpPort = (int)($section['rdpPort'] ?? 3389);
        $portMap = $this->getPortMap();
        $customProtocols = $this->getExternalCustomProtocols($section);
        $html = '';

        foreach ($protocols as $protocolSpec) {
            [$protocol, $protocolPort] = $this->parseProtocolSpec($protocolSpec);
            if ($protocol === null) {
                continue;
            }

            if ($protocol === 'ping') {
                $html .= $this->pingButtonHtml($target, $listMode);
                continue;
            }

            $label = strtoupper($protocol) . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

            if (in_array($protocol, ['http', 'https'], true)) {
                $url = $this->buildProtocolUrl($protocol, $target, $portMap[$protocol] ?? null, $protocolPort);
                $class = $listMode ? 'link' : 'record-link';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . $safeLabel . '</a>';
                continue;
            }

            if ($protocol === 'rdp') {
                $buttonClass = $listMode ? 'link protocol-link protocol-button' : 'record-link protocol-link protocol-button';
                $effectiveRdpPort = $protocolPort ?: $rdpPort;
                $rdpLabel = 'RDP' . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
                $html .= '<button type="button" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '" onclick="downloadRDP('
                    . htmlspecialchars(json_encode($target), ENT_QUOTES, 'UTF-8') . ','
                    . (int)$effectiveRdpPort . ','
                    . htmlspecialchars(json_encode($rdpUser), ENT_QUOTES, 'UTF-8')
                    . ')">' . htmlspecialchars($rdpLabel, ENT_QUOTES, 'UTF-8') . '</button>';
                continue;
            }

            if (isset($customProtocols[$protocol])) {
                $definition = $customProtocols[$protocol];
                $url = $this->buildCustomProtocolUrl($definition, $target, $protocolPort);
                $class = $listMode ? 'link protocol-link' : 'record-link protocol-link';
                $customLabel = $definition['label'] . ($protocolPort !== null ? ':' . (int)$protocolPort : '');
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($customLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                continue;
            }

            $defaultPort = $portMap[$protocol] ?? null;
            $url = $this->buildProtocolUrl($protocol, $target, $defaultPort, $protocolPort);
            if ($url !== null) {
                $class = $listMode ? 'link protocol-link' : 'record-link protocol-link';
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . $safeLabel . '</a>';
            }
        }

        if ($html === '') {
            return '<span class="protocol-label">No actions</span>';
        }

        return $html;
    }

    private function renderArubaIapCardView($section)
    {
        $title = htmlspecialchars($section['title'] ?? 'Aruba Gateway IAP Table', ENT_QUOTES, 'UTF-8');
        $rows = $section['rows'] ?? [];
        $summary = $section['summary'] ?? [];
        $warnings = $section['warnings'] ?? [];

        $html = '<div class="external-card aruba-card"><div class="external-title">' . $title . '</div>';

        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                $html .= '<div class="external-warning">' . htmlspecialchars((string)$warning, ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }

        if (!empty($summary)) {
            $html .= '<div class="external-summary">';
            foreach ($summary as $line) {
                $html .= '<div>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $html .= '</div>';
        }

        if (empty($rows)) {
            $html .= '<div class="external-error">No Aruba IAP rows found.</div></div>';
            return $html;
        }

        $html .= '<div class="external-records-grid">';
        foreach ($rows as $row) {
            $hostname = (string)($row['hostname'] ?? '');
            $ip = (string)($row['ip'] ?? '');
            $status = strtoupper((string)($row['status'] ?? ''));
            $vlans = (string)($row['vlans'] ?? '');
            $mac = (string)($row['mac'] ?? '');
            $statusClass = strtolower($status) === 'up' ? 'status-up' : 'status-down';
            $cardStatusClass = strtolower($status) === 'up' ? 'aruba-up' : 'aruba-down';

            $html .= '<div class="record-card external-record-card aruba-record-card ' . $cardStatusClass . '">';
            $html .= '<div class="record-hostname">' . htmlspecialchars($hostname, ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '<span class="record-type">ARUBA</span>';
            $html .= '<div class="record-value">' . htmlspecialchars($ip !== '' ? $ip : '-', ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '<div class="external-detail-row"><strong>Status:</strong> <span class="external-status ' . $statusClass . '">' . htmlspecialchars($status !== '' ? $status : 'UNKNOWN', ENT_QUOTES, 'UTF-8') . '</span></div>';
            $html .= '<div class="external-detail-row"><strong>VLAN:</strong> ' . htmlspecialchars($vlans !== '' ? $vlans : '-', ENT_QUOTES, 'UTF-8') . '</div>';
            $html .= '<div class="external-detail-row"><strong>MAC:</strong> <span class="external-mac">' . htmlspecialchars($mac !== '' ? $mac : '-', ENT_QUOTES, 'UTF-8') . '</span></div>';
            $html .= '<div class="record-actions">' . $this->renderExternalProtocolActions($ip, $section, false) . '</div>';
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function renderArubaIapListView($section)
    {
        $title = htmlspecialchars($section['title'] ?? 'Aruba Gateway IAP Table', ENT_QUOTES, 'UTF-8');
        $rows = $section['rows'] ?? [];
        $summary = $section['summary'] ?? [];
        $warnings = $section['warnings'] ?? [];

        $html = '<div class="external-card aruba-card"><div class="external-title">' . $title . '</div>';

        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                $html .= '<div class="external-warning">' . htmlspecialchars((string)$warning, ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }

        if (!empty($summary)) {
            $html .= '<div class="external-summary">';
            foreach ($summary as $line) {
                $html .= '<div>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            $html .= '</div>';
        }

        if (empty($rows)) {
            $html .= '<div class="external-error">No Aruba IAP rows found.</div></div>';
            return $html;
        }

        $html .= '<div class="external-table-wrap"><table class="external-table aruba-iap-table"><thead><tr><th>VC Name</th><th>Inner IP</th><th>Status</th><th>Assigned VLAN</th><th>VC MAC Address</th><th>Actions</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $hostname = (string)($row['hostname'] ?? '');
            $ip = (string)($row['ip'] ?? '');
            $status = strtoupper((string)($row['status'] ?? ''));
            $vlans = (string)($row['vlans'] ?? '');
            $mac = (string)($row['mac'] ?? '');
            $statusClass = strtolower($status) === 'up' ? 'status-up' : 'status-down';

            $html .= '<tr>';
            $html .= '<td class="external-hostname">' . htmlspecialchars($hostname, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="external-ip">' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="external-status ' . $statusClass . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . htmlspecialchars($vlans !== '' ? $vlans : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="external-mac">' . htmlspecialchars($mac !== '' ? $mac : '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="external-actions">' . $this->renderExternalProtocolActions($ip, $section, true) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';
        return $html;
    }

    private function renderArubaIapTable($section, $listMode = false)
    {
        return $listMode ? $this->renderArubaIapListView($section) : $this->renderArubaIapCardView($section);
    }

    public function generateListHTML()
    {
        $safeOrigin = htmlspecialchars($this->origin, ENT_QUOTES, 'UTF-8');
        $cardUrl = htmlspecialchars($this->currentViewUrl(false), ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BIND9 DNS Records List - ' . $safeOrigin . '</title><link rel="stylesheet" href="bind9_viewer.css?v=16"></head><body><div class="container"><h1>BIND9 DNS Records - ' . $safeOrigin . '</h1><div class="view-toggle"><a href="' . $cardUrl . '" class="view-link">Card View</a> | <a href="bind9_help.php" class="view-link">Help</a> | <a href="bind9_help.php?file=README.md" class="view-link">ReadMe</a></div>';
        $html .= '<div class="stat"><strong><button type="button" id="theme-toggle" class="theme-toggle" onclick="toggleTheme()">🌙 Dark</button></strong></div>';
        $html .= $this->renderExternalSections(true);
        $html .= '<table><thead><tr><th>Type</th><th>Name</th><th>Value</th><th>Links</th><th>Comment</th></tr></thead><tbody>';

        foreach ($this->items as $item) {
            if (($item['type'] ?? '') === 'area') {
                $html .= '<tr class="area-section"><td colspan="5">' . htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
                continue;
            }
            if (($item['type'] ?? '') !== 'record') {
                continue;
            }
            $type = $item['recordType'];
            if (!in_array($type, ['A', 'CNAME', 'HOSTS'], true)) {
                continue;
            }

            $name = $item['name'];
            $aliases = $item['aliases'] ?? [];
            $value = $item['value'];
            $port = $item['port'] ?? null;
            $protocols = $item['protocols'] ?? [];
            $comment = $item['comment'] ?? '';
            $linkHost = $this->preferredLinkHost($name, $aliases);
            [$httpLink, $httpsLink] = $this->buildWebUrls($linkHost, $port);
            $jsHttps = htmlspecialchars(json_encode($httpsLink), ENT_QUOTES, 'UTF-8');
            $jsHttp = htmlspecialchars(json_encode($httpLink), ENT_QUOTES, 'UTF-8');
            $httpHref = htmlspecialchars($httpLink, ENT_QUOTES, 'UTF-8');
            $httpsHref = htmlspecialchars($httpsLink, ENT_QUOTES, 'UTF-8');
            $class = htmlspecialchars($this->recordClass($type), ENT_QUOTES, 'UTF-8');
            $pingTarget = $this->pingTargetForItem($type, $value, $linkHost);

            $nameCell = '<a href="#" class="hostname" onclick="openPreferred(' . $jsHttps . ', ' . $jsHttp . '); return false;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
            if (!empty($aliases)) {
                $nameCell .= '<details class="record-aliases"><summary class="record-aliases-summary">Aliases (' . count($aliases) . ')</summary><div class="record-aliases-list">';
                foreach ($aliases as $alias) {
                    if (trim($alias) !== '') {
                        $nameCell .= $this->aliasButton($alias, $port);
                    }
                }
                $nameCell .= '</div></details>';
            }

            $html .= '<tr class="' . $class . '"><td><span class="record-type">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span></td><td>' . $nameCell . '</td><td><a href="#" class="value" onclick="openPreferred(' . $jsHttps . ', ' . $jsHttp . '); return false;">' . $this->displayValue($type, $value) . '</a></td><td class="links"><a href="' . $httpHref . '" target="_blank" rel="noopener noreferrer" class="link">HTTP</a><a href="' . $httpsHref . '" target="_blank" rel="noopener noreferrer" class="link">HTTPS</a>' . $this->pingButtonHtml($pingTarget, true);
            foreach ($protocols as $proto) {
                $protoDisplay = htmlspecialchars($proto, ENT_QUOTES, 'UTF-8');
                $html .= $this->protocolButtonHtml($proto, $protoDisplay, $linkHost, $port, $item, true);
            }
            $html .= '</td><td class="comment">' . htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $html .= '</tbody></table></div>' . $this->getOpenPreferredScript() . '</body></html>';
        return $html;
    }

    public function generateHTML()
    {
        if (isset($_GET['list']) && $_GET['list'] === 'true') {
            return $this->generateListHTML();
        }

        $safeOrigin = htmlspecialchars($this->origin, ENT_QUOTES, 'UTF-8');
        $total = $this->getTotalCount();
        $displayTotal = $this->getDisplayItemCount();
        $listUrl = htmlspecialchars($this->currentViewUrl(true), ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BIND9 DNS Records - ' . $safeOrigin . '</title><link rel="stylesheet" href="bind9_viewer.css?v=16"></head><body><div class="container"><div class="header"><h1>🔗 BIND9 Zone/source: ' . $safeOrigin . '</h1><div class="stats"><div class="stat"><strong>' . count($this->records['A']) . '</strong> A Records</div><div class="stat"><strong>' . count($this->records['CNAME']) . '</strong> CNAME Records</div><div class="stat"><strong>' . count($this->records['HOSTS']) . '</strong> HOSTS Names</div><div class="stat"><strong>' . $displayTotal . '</strong> Displayed Items</div><div class="stat"><strong>' . $total . '</strong> Total Names</div><div class="stat"><strong><a href="' . $listUrl . '" class="view-link">List View</a></strong></div><div class="stat"><strong><a href="bind9_help.php" class="view-link">Help</a></strong></div><div class="stat"><strong><a href="bind9_help.php?file=README.md" class="view-link">ReadMe</a></strong></div><div class="stat"><strong><button type="button" id="theme-toggle" class="theme-toggle" onclick="toggleTheme()">🌙 Dark</button></strong></div></div></div>';
        $html .= $this->renderExternalSections(false);
        $html .= '<div class="records-section"><div class="section-title">Parsed Records (' . $displayTotal . ' displayed items, ' . $total . ' total names)</div><div class="records-grid">';

        if (!empty($this->items)) {
            foreach ($this->items as $item) {
                if (($item['type'] ?? '') === 'area') {
                    $html .= '<div class="record-card area-separator"><div class="record-hostname">' . htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8') . '</div></div>';
                    continue;
                }
                if (($item['type'] ?? '') !== 'record') {
                    continue;
                }

                $type = $item['recordType'];
                $name = $item['name'];
                $aliases = $item['aliases'] ?? [];
                $value = $item['value'];
                $port = $item['port'] ?? null;
                $comment = $item['comment'] ?? '';
                $protocols = $item['protocols'] ?? [];
                $linkHost = $this->preferredLinkHost($name, $aliases);
                [$httpLink, $httpsLink] = $this->buildWebUrls($linkHost, $port);
                $httpHref = htmlspecialchars($httpLink, ENT_QUOTES, 'UTF-8');
                $httpsHref = htmlspecialchars($httpsLink, ENT_QUOTES, 'UTF-8');
                $cardClass = htmlspecialchars($this->recordClass($type), ENT_QUOTES, 'UTF-8');
                $pingTarget = $this->pingTargetForItem($type, $value, $linkHost);

                $html .= '<div class="record-card ' . $cardClass . '"><div class="record-hostname">' . $this->formatNameWithAliases($name, $aliases, $port, $httpsLink, $httpLink) . '</div><span class="record-type">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span><div class="record-value">' . $this->displayValue($type, $value) . '</div>';
                if ($comment !== '') {
                    $html .= '<div class="record-comment">' . htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') . '</div>';
                }
                $html .= '<div class="record-actions"><a href="' . $httpHref . '" target="_blank" rel="noopener noreferrer" class="record-link">HTTP</a><a href="' . $httpsHref . '" target="_blank" rel="noopener noreferrer" class="record-link">HTTPS</a>' . $this->pingButtonHtml($pingTarget, false) . '</div>';
                if (!empty($protocols)) {
                    $html .= '<div class="record-protocols">';
                    foreach ($protocols as $proto) {
                        $protoDisplay = htmlspecialchars($proto, ENT_QUOTES, 'UTF-8');
                        $html .= $this->protocolButtonHtml($proto, $protoDisplay, $linkHost, $port, $item, false);
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        } else {
            $html .= '<div class="record-card area-separator"><div class="record-hostname">No records found</div></div>';
        }

        $html .= '</div></div><div class="footer"><p>Generated on ' . date('Y-m-d H:i:s') . ' | BIND9 DNS Record Viewer</p></div></div>' . $this->getOpenPreferredScript() . '</body></html>';
        return $html;
    }
}

function bind9_viewer_validate_ping_target($target)
{
    $target = trim((string)$target);
    if ($target === '' || strlen($target) > 253) {
        return null;
    }
    if (filter_var($target, FILTER_VALIDATE_IP)) {
        return $target;
    }
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $target)) {
        return null;
    }
    return $target;
}

function bind9_viewer_handle_ping_request()
{
    header('Content-Type: application/json; charset=UTF-8');
    $target = bind9_viewer_validate_ping_target($_GET['target'] ?? '');
    if ($target === null) {
        echo json_encode(['reachable' => false, 'error' => 'Invalid target']);
        return;
    }

    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $escapedTarget = escapeshellarg($target);
    $command = $isWindows ? 'ping -n 1 -w 2000 ' . $escapedTarget : 'ping -c 1 -W 2 ' . $escapedTarget;
    $output = [];
    $exitCode = 1;
    @exec($command . ' 2>&1', $output, $exitCode);
    echo json_encode(['reachable' => $exitCode === 0, 'target' => $target, 'exitCode' => $exitCode]);
}

function bind9_viewer_is_hosts_mode($filePath, $argv = [])
{
    foreach ($argv as $arg) {
        if ($arg === '--hosts') {
            return true;
        }
    }
    if (php_sapi_name() !== 'cli' && isset($_GET['type']) && $_GET['type'] === 'hosts') {
        return true;
    }
    return strtolower(basename($filePath)) === 'hosts';
}

function bind9_viewer_bool_env($value)
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function bind9_viewer_config_value($name, $default = '')
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return $_SERVER[$name];
    }
    return $default;
}

function bind9_viewer_source_enabled($source)
{
    if (!array_key_exists('enabled', $source)) {
        return true;
    }
    if (is_bool($source['enabled'])) {
        return $source['enabled'];
    }
    return bind9_viewer_bool_env($source['enabled']);
}

function bind9_viewer_load_json_config($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (is_file($value) && is_readable($value)) {
        $json = file_get_contents($value);
        if ($json === false) {
            throw new Exception('Could not read JSON config file: ' . $value);
        }
    } else {
        $json = $value;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON config. Source: ' . $value . '. JSON error: ' . json_last_error_msg());
    }

    return $decoded;
}

function bind9_viewer_normalize_aruba_source($source, $index = 0)
{
    return [
        'enabled' => bind9_viewer_source_enabled($source),
        'type' => 'aruba_gw_api',
        'title' => (string)($source['title'] ?? ('Aruba Gateway ' . ($index + 1))),
        'baseUrl' => rtrim((string)($source['baseUrl'] ?? ''), '/'),
        'username' => (string)($source['username'] ?? ''),
        'password' => (string)($source['password'] ?? ''),
        'command' => (string)($source['command'] ?? 'show iap table'),
        'verifySsl' => array_key_exists('verifySsl', $source) ? bind9_viewer_bool_env($source['verifySsl']) : false,
        'protocols' => $source['protocols'] ?? ($source['protocol'] ?? ['http', 'https', 'ping']),
        'customProtocols' => isset($source['customProtocols']) && is_array($source['customProtocols']) ? $source['customProtocols'] : [],
        'rdpUser' => (string)($source['rdpUser'] ?? 'Administrator'),
        'rdpPort' => (int)($source['rdpPort'] ?? 3389)
    ];
}

function bind9_viewer_load_custom_protocols_config()
{
    $customProtocols = [];

    $customFile = bind9_viewer_config_value('BIND9_VIEWER_CUSTOM_PROTOCOLS_FILE', '');
    if ($customFile !== '') {
        $decoded = bind9_viewer_load_json_config($customFile);
        if (isset($decoded['customProtocols']) && is_array($decoded['customProtocols'])) {
            $customProtocols = array_merge($customProtocols, $decoded['customProtocols']);
        } elseif (is_array($decoded)) {
            $customProtocols = array_merge($customProtocols, $decoded);
        }
    }

    $customJson = bind9_viewer_config_value('BIND9_VIEWER_CUSTOM_PROTOCOLS_JSON', '');
    if ($customJson !== '') {
        $decoded = bind9_viewer_load_json_config($customJson);
        if (isset($decoded['customProtocols']) && is_array($decoded['customProtocols'])) {
            $customProtocols = array_merge($customProtocols, $decoded['customProtocols']);
        } elseif (is_array($decoded)) {
            $customProtocols = array_merge($customProtocols, $decoded);
        }
    }

    return $customProtocols;
}

function bind9_viewer_configure_external_sources($viewer)
{
    $sources = [];

    $configFile = bind9_viewer_config_value('BIND9_VIEWER_EXTERNAL_SOURCES_FILE', '');
    if ($configFile !== '') {
        $decoded = bind9_viewer_load_json_config($configFile);
        foreach ($decoded as $index => $source) {
            if (is_array($source)) {
                $sources[] = (($source['type'] ?? '') === 'aruba_gw_api') ? bind9_viewer_normalize_aruba_source($source, $index) : array_merge($source, ['enabled' => bind9_viewer_source_enabled($source)]);
            }
        }
    }

    $genericValue = bind9_viewer_config_value('BIND9_VIEWER_EXTERNAL_SOURCES_JSON', '');
    if ($genericValue !== '') {
        $decoded = bind9_viewer_load_json_config($genericValue);
        foreach ($decoded as $index => $source) {
            if (is_array($source)) {
                $sources[] = (($source['type'] ?? '') === 'aruba_gw_api') ? bind9_viewer_normalize_aruba_source($source, $index) : array_merge($source, ['enabled' => bind9_viewer_source_enabled($source)]);
            }
        }
    }

    $arubaValue = bind9_viewer_config_value('ARUBA_GW_SOURCES_JSON', '');
    if ($arubaValue !== '') {
        $decoded = bind9_viewer_load_json_config($arubaValue);
        foreach ($decoded as $index => $source) {
            if (is_array($source)) {
                $sources[] = bind9_viewer_normalize_aruba_source($source, $index);
            }
        }
    }

    if (bind9_viewer_bool_env(bind9_viewer_config_value('ARUBA_GW_ENABLED', '0'))) {
        $protocolsValue = bind9_viewer_config_value('ARUBA_GW_PROTOCOLS', '');
        $sources[] = [
            'enabled' => true,
            'type' => 'aruba_gw_api',
            'title' => bind9_viewer_config_value('ARUBA_GW_TITLE', 'Aruba Gateway AP Table'),
            'baseUrl' => rtrim(bind9_viewer_config_value('ARUBA_GW_BASE_URL', ''), '/'),
            'username' => bind9_viewer_config_value('ARUBA_GW_USERNAME', ''),
            'password' => bind9_viewer_config_value('ARUBA_GW_PASSWORD', ''),
            'command' => bind9_viewer_config_value('ARUBA_GW_COMMAND', 'show iap table'),
            'verifySsl' => bind9_viewer_bool_env(bind9_viewer_config_value('ARUBA_GW_VERIFY_SSL', '0')),
            'protocols' => $protocolsValue !== '' ? array_map('trim', explode(',', $protocolsValue)) : ['http', 'https', 'ping'],
            'customProtocols' => [],
            'rdpUser' => bind9_viewer_config_value('ARUBA_GW_RDP_USER', 'Administrator'),
            'rdpPort' => (int)bind9_viewer_config_value('ARUBA_GW_RDP_PORT', '3389')
        ];
    }

    $viewer->setExternalSources($sources);
    $viewer->setCustomProtocols(bind9_viewer_load_custom_protocols_config());
}

function bind9_viewer_show_form()
{
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>BIND9 DNS Viewer</title><link rel="stylesheet" href="bind9_viewer.css?v=16"></head><body><div class="container form-container"><h1>🔗 BIND9 DNS Viewer</h1><p>Paste BIND zone content or /etc/hosts-style content. /etc/hosts comments use <strong>#</strong> as the delimiter. BIND zone files use <strong>;</strong> as the delimiter.</p><form method="post"><div class="form-group"><label>File type:</label><label class="radio-label"><input type="radio" name="filetype" value="zone" checked> BIND Zone File</label><label class="radio-label"><input type="radio" name="filetype" value="hosts"> /etc/hosts File</label></div><div class="form-group"><label for="origin">Zone Origin, for BIND zone files:</label><input type="text" id="origin" name="origin" placeholder="example.com"><div class="hint">Optional for hosts files.</div></div><div class="form-group"><label for="content">File Content:</label><textarea id="content" name="content" rows="16" placeholder="Paste BIND zone or hosts file content here..." required></textarea></div><button type="submit" class="primary-button">Parse File</button></form></div></body></html>';
}

if (php_sapi_name() !== 'cli' && ($_GET['action'] ?? '') === 'ping') {
    bind9_viewer_handle_ping_request();
    exit;
}

if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php " . basename(__FILE__) . " <zone-file|hosts-file> [origin] [list=true] [--hosts]\n";
        echo "Examples:\n";
        echo "  php " . basename(__FILE__) . " /etc/bind/db.example.com example.com\n";
        echo "  php " . basename(__FILE__) . " /etc/hosts --hosts\n";
        echo "  php " . basename(__FILE__) . " /etc/hosts list=true --hosts\n";
        exit(1);
    }

    $inputFile = $argv[1];
    $origin = '';
    $isListMode = in_array('list=true', $argv, true);
    for ($i = 2; $i < $argc; $i++) {
        if ($argv[$i] !== 'list=true' && substr($argv[$i], 0, 2) !== '--') {
            $origin = $argv[$i];
            break;
        }
    }

    try {
        $viewer = new BIND9Viewer();
        bind9_viewer_configure_external_sources($viewer);
        if (bind9_viewer_is_hosts_mode($inputFile, $argv)) {
            $viewer->parseHostsFile($inputFile);
        } else {
            $viewer->parseZoneFile($inputFile, $origin);
        }
        $outputFile = pathinfo($inputFile, PATHINFO_FILENAME) . '.html';
        file_put_contents($outputFile, $isListMode ? $viewer->generateListHTML() : $viewer->generateHTML());
        echo "✓ Successfully generated: $outputFile\n";
        echo "  A Records: " . count($viewer->getARecords()) . "\n";
        echo "  CNAME Records: " . count($viewer->getCNAMERecords()) . "\n";
        echo "  HOSTS Names: " . count($viewer->getHostsRecords()) . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $content = (string)($_POST['content'] ?? '');
        $origin = trim((string)($_POST['origin'] ?? ''));
        $fileType = $_POST['filetype'] ?? 'zone';
        if ($content === '') {
            echo 'Error: missing content.';
            exit;
        }
        $tempFile = tempnam(sys_get_temp_dir(), 'bind9_');
        if ($tempFile === false) {
            echo 'Error: could not create temporary file.';
            exit;
        }
        file_put_contents($tempFile, $content);
        try {
            $viewer = new BIND9Viewer();
            bind9_viewer_configure_external_sources($viewer);
            if ($fileType === 'hosts') {
                $viewer->parseHostsFile($tempFile);
            } else {
                $viewer->parseZoneFile($tempFile, $origin !== '' ? $origin : 'posted-zone');
            }
            echo $viewer->generateHTML();
        } catch (Exception $e) {
            echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        exit;
    }

    if (isset($_GET['zone'])) {
        $zoneFile = $_GET['zone'];
        if (!file_exists($zoneFile) || !is_readable($zoneFile)) {
            echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error: file not found or not readable: ' . htmlspecialchars($zoneFile, ENT_QUOTES, 'UTF-8') . '</h1></body></html>';
            exit;
        }
        $origin = $_GET['origin'] ?? '';
        try {
            $viewer = new BIND9Viewer();
            bind9_viewer_configure_external_sources($viewer);
            if (bind9_viewer_is_hosts_mode($zoneFile)) {
                $viewer->parseHostsFile($zoneFile);
            } else {
                $viewer->parseZoneFile($zoneFile, $origin);
            }
            echo $viewer->generateHTML();
        } catch (Exception $e) {
            echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</h1></body></html>';
        }
        exit;
    }

    bind9_viewer_show_form();
}
