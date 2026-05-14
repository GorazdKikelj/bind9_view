<?php
{
include 'lib/Parsedown.php';

function displayHelp( $helpFile ) {
    $parser = new Parsedown();
    $file = file_get_contents($helpFile);
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="bind9_help.css">
    </head>
<body>
<div class="container">';

    $html .= $parser ->text($file) .'</div></body></html>';
    return $html;
}

    try {
        $helpFile = $_GET['file'];
    } catch (Exception $e) {
    
    }
        
    // Basic security check - ensure file exists and is readable
    if (!file_exists($helpFile) || !is_readable($helpFile)) {
        $helpFile = "bind9_viewer.md"; // Fallback to default help file
    }
                
    try {
        echo displayHelp($helpFile);
    } catch (Exception $e) {
        echo "<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error: " . htmlspecialchars($e->getMessage()) . "</h1></body></html>";
    }
}
?>