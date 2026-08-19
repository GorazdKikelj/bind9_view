<?php
/**
 * BIND9 Viewer Help
 * Renders Markdown help files using Parsedown.
 */

function bind9_help_theme_script()
{
    return '<script>
(function initializeTheme() {
    var savedTheme = localStorage.getItem("bind9_help_theme") || localStorage.getItem("bind9_viewer_theme");

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
    localStorage.setItem("bind9_help_theme", nextTheme);
    localStorage.setItem("bind9_viewer_theme", nextTheme);
    updateThemeToggleText();
}

function updateThemeToggleText() {
    var button = document.getElementById("theme-toggle");

    if (!button) {
        return;
    }

    var currentTheme = document.documentElement.getAttribute("data-theme");

    if (!currentTheme) {
        currentTheme = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    }

    button.textContent = currentTheme === "dark" ? "☀ Light" : "🌙 Dark";
}

document.addEventListener("DOMContentLoaded", updateThemeToggleText);
</script>';
}

function bind9_help_is_safe_viewer_url($url)
{
    $url = trim((string)$url);

    if ($url === '') {
        return false;
    }

    if (preg_match('/[\r\n]/', $url)) {
        return false;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return false;
    }

    $path = $parts['path'] ?? '';

    if ($path === '') {
        return false;
    }

    if (basename($path) !== 'bind9_viewer.php') {
        return false;
    }

    if (isset($parts['host'])) {
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';

        if ($requestHost === '' || strcasecmp($parts['host'], preg_replace('/:\d+$/', '', $requestHost)) !== 0) {
            return false;
        }
    }

    return true;
}

function bind9_help_normalize_viewer_url($url)
{
    $parts = parse_url($url);

    if ($parts === false) {
        return 'bind9_viewer.php';
    }

    $path = $parts['path'] ?? 'bind9_viewer.php';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return basename($path) . $query;
}

function bind9_help_viewer_return_url()
{
    $returnUrl = $_GET['return'] ?? '';

    if (bind9_help_is_safe_viewer_url($returnUrl)) {
        return bind9_help_normalize_viewer_url($returnUrl);
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if (bind9_help_is_safe_viewer_url($referer)) {
        return bind9_help_normalize_viewer_url($referer);
    }

    return 'bind9_viewer.php';
}

function bind9_help_query_for_file($file, $returnUrl)
{
    return 'bind9_help.php?' . http_build_query([
        'file' => $file,
        'return' => $returnUrl
    ]);
}

function bind9_help_render_top_actions($returnUrl)
{
    $safeReturnUrl = htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8');
    $viewerHelpUrl = htmlspecialchars(bind9_help_query_for_file('bind9_viewer.md', $returnUrl), ENT_QUOTES, 'UTF-8');
    $readmeUrl = htmlspecialchars(bind9_help_query_for_file('README.md', $returnUrl), ENT_QUOTES, 'UTF-8');

    return '<div class="top-actions">
        <a class="nav-link" href="' . $safeReturnUrl . '">Back to viewer</a>
        <a class="nav-link" href="' . $viewerHelpUrl . '">Viewer help</a>
        <a class="nav-link" href="' . $readmeUrl . '">README</a>
        <button type="button" id="theme-toggle" class="theme-toggle" onclick="toggleTheme()">🌙 Dark</button>
    </div>';
}

function bind9_help_render_error($message, $details = '', $returnUrl = 'bind9_viewer.php')
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeDetails = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="bind9_help.css?v=3">
    <title>BIND9 Viewer Help - Error</title>
</head>
<body>
<div class="container error-container">';

    $html .= bind9_help_render_top_actions($returnUrl);

    $html .= '    <div class="error-card">
        <h1>Error</h1>
        <p>' . $safeMessage . '</p>';

    if ($safeDetails !== '') {
        $html .= '<pre><code>' . $safeDetails . '</code></pre>';
    }

    $html .= '    </div>
</div>
' . bind9_help_theme_script() . '
</body>
</html>';

    return $html;
}

function bind9_help_parsedown_path()
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Parsedown.php';
}

function bind9_help_require_parsedown()
{
    $parsedownPath = bind9_help_parsedown_path();

    if (!file_exists($parsedownPath) || !is_readable($parsedownPath)) {
        throw new Exception(
            'Parsedown module is missing. Install Parsedown.php into lib/Parsedown.php. Download it from https://github.com/erusev/parsedown'
        );
    }

    require_once $parsedownPath;

    if (!class_exists('Parsedown')) {
        throw new Exception(
            'Parsedown module was found, but the Parsedown class is not available. Verify lib/Parsedown.php. Repository: https://github.com/erusev/parsedown'
        );
    }
}

function bind9_help_allowed_file($helpFile)
{
    $allowedFiles = [
        'bind9_viewer.md',
        'README.md'
    ];

    $requestedBaseName = basename((string)$helpFile);

    if (!in_array($requestedBaseName, $allowedFiles, true)) {
        return 'bind9_viewer.md';
    }

    if (!file_exists($requestedBaseName) || !is_readable($requestedBaseName)) {
        return 'bind9_viewer.md';
    }

    return $requestedBaseName;
}

function displayHelp($helpFile, $returnUrl)
{
    bind9_help_require_parsedown();

    $file = file_get_contents($helpFile);

    if ($file === false) {
        throw new Exception('Cannot read help file: ' . $helpFile);
    }

    $parser = new Parsedown();

    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="bind9_help.css?v=3">
    <title>BIND9 Viewer Help</title>
</head>
<body>
<div class="container">';

    $html .= bind9_help_render_top_actions($returnUrl);
    $html .= '    <div class="markdown-body">';
    $html .= $parser->text($file);
    $html .= '</div>
</div>
' . bind9_help_theme_script() . '
</body>
</html>';

    return $html;
}

$helpFile = $_GET['file'] ?? 'bind9_viewer.md';
$helpFile = bind9_help_allowed_file($helpFile);
$returnUrl = bind9_help_viewer_return_url();

try {
    echo displayHelp($helpFile, $returnUrl);
} catch (Exception $e) {
    $message = $e->getMessage();

    if (strpos($message, 'Parsedown') !== false) {
        echo bind9_help_render_error(
            'Parsedown module is missing or invalid.',
            "Required file: lib/Parsedown.php\nRepository: https://github.com/erusev/parsedown\nInstall example:\nmkdir -p lib\ncurl -L -o lib/Parsedown.php https://raw.githubusercontent.com/erusev/parsedown/master/Parsedown.php",
            $returnUrl
        );
    } else {
        echo bind9_help_render_error('Cannot render help file.', $message, $returnUrl);
    }
}
