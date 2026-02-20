<?php
/**
 * Simple script to create a favicon.ico from PNG
 * This creates a basic 16x16 ICO file
 */

// Read the source PNG file
$pngFile = __DIR__ . '/assets/logs.png';
$icoFile = __DIR__ . '/favicon.ico';

if (!file_exists($pngFile)) {
    die("Error: PNG file not found at $pngFile\n");
}

// Get PNG image info
$pngInfo = getimagesize($pngFile);
if ($pngInfo === false) {
    die("Error: Could not read PNG file\n");
}

echo "PNG file found: " . $pngFile . "\n";
echo "Image size: " . $pngInfo[0] . "x" . $pngInfo[1] . "\n";
echo "Image type: " . $pngInfo[2] . "\n";

// Since we don't have GD, we'll try to use ImageMagick via shell exec
// Or create a simple 16x16 ICO manually

// First, let's check if we can use any tool
$tools = [
    'magick' => 'magick convert',
    'convert' => 'convert',
    'gm' => 'gm convert'
];

$availableTool = null;
foreach ($tools as $name => $cmd) {
    $testCmd = strtok($cmd, ' ') . ' --version';
    exec($testCmd . ' 2>nul', $output, $return);
    if ($return === 0) {
        $availableTool = $cmd;
        echo "Found tool: $name\n";
        break;
    }
}

if ($availableTool) {
    // Try to convert using ImageMagick
    $cmd = $availableTool . " \"$pngFile\" -resize 16x16 \"$icoFile\"";
    echo "Trying command: $cmd\n";
    exec($cmd, $output, $return);
    
    if (file_exists($icoFile)) {
        echo "Success! Created favicon.ico\n";
    } else {
        echo "Failed to create favicon.ico\n";
    }
} else {
    echo "No image conversion tools available.\n";
    echo "Creating a minimal ICO file manually...\n";
    
    // Create a minimal ICO file manually
    // ICO format: ICONDIR header + ICONDIRENTRY + BMP data
    
    // Since we can't easily create a proper ICO without image libraries,
    // let's try copying the PNG and renaming (won't work in all browsers)
    // or create a minimal valid ICO with just a black square
    
    // For now, let's just copy the PNG as a workaround
    // This might not work in all browsers, but it's worth a try
    copy($pngFile, $icoFile);
    echo "Copied PNG to ICO (won't work in all browsers, but let's try)\n";
    
    // Alternative: Create a minimal 1x1 ICO
    $minimalIco = base64_decode(
        'AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAAAQAAMMOAADDDgAAAAAAAAAAAAD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A//8AAP//AADAAwAAwAMAAMADAADAAwAAwAMAAMADAADAAwAAwAMAAMADAADAAwAAwAMAAMADAAD//wAA//8AAA=='
    );
    
    file_put_contents($icoFile, $minimalIco);
    echo "Created minimal favicon.ico (1x1 pixel)\n";
}

echo "\nDone!\n";
