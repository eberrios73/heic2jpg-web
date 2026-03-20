<?php
// heic2jpg-web — HEIC to JPG converter backend
// Streams progress lines, then provides a download link

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['heicFiles']) || empty($_FILES['heicFiles']['name'][0])) {
    http_response_code(400);
    echo json_encode(['error' => 'No files uploaded']);
    exit;
}

// Stream output
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

function logLine($msg) {
    echo $msg . "\n";
    flush();
}

/** Resolve a CLI tool on Linux (e.g. /usr/bin) or macOS Homebrew (/opt/homebrew/bin). */
function resolveTool(array $names) {
    $dirs = ['/usr/bin', '/opt/homebrew/bin', '/usr/local/bin'];
    foreach ($names as $name) {
        if ($name !== '' && $name[0] === '/' && is_executable($name)) {
            return $name;
        }
    }
    foreach ($names as $name) {
        foreach ($dirs as $dir) {
            $p = $dir . '/' . $name;
            if (is_executable($p)) {
                return $p;
            }
        }
    }
    foreach ($names as $name) {
        $out = [];
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
        if (!empty($out[0]) && is_executable($out[0])) {
            return $out[0];
        }
    }
    return null;
}

// Create temp directories
$tempId = uniqid('heic_', true);
$tempDir = sys_get_temp_dir() . '/heic_convert_' . $tempId;
$inputDir = $tempDir . '/input';
$outputDir = $tempDir . '/output';
mkdir($inputDir, 0777, true);
mkdir($outputDir, 0777, true);

$uploadedFiles = $_FILES['heicFiles'];
$heicFiles = [];

try {
    logLine("[INFO] Processing uploaded files...");

    for ($i = 0; $i < count($uploadedFiles['name']); $i++) {
        $tmpName = $uploadedFiles['tmp_name'][$i];
        $originalName = basename($uploadedFiles['name'][$i]);

        if (!is_uploaded_file($tmpName)) continue;

        $targetPath = $inputDir . '/' . $originalName;
        move_uploaded_file($tmpName, $targetPath);

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'zip') {
            logLine("[INFO] Extracting ZIP: $originalName");
            $zip = new ZipArchive();
            if ($zip->open($targetPath) === true) {
                $extracted = 0;
                $skipped = 0;
                for ($j = 0; $j < $zip->numFiles; $j++) {
                    $entryName = $zip->getNameIndex($j);
                    $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));

                    // Skip macOS resource forks
                    if (strpos($entryName, '__MACOSX') !== false || strpos(basename($entryName), '._') === 0) {
                        $skipped++;
                        continue;
                    }

                    if (in_array($entryExt, ['heic', 'heif'])) {
                        $zip->extractTo($inputDir, $entryName);
                        $extractedPath = $inputDir . '/' . $entryName;
                        if (file_exists($extractedPath)) {
                            $flatName = basename($entryName);
                            if ($flatName !== $entryName) {
                                $flatPath = $inputDir . '/' . $flatName;
                                $counter = 1;
                                while (file_exists($flatPath)) {
                                    $info = pathinfo($flatName);
                                    $flatPath = $inputDir . '/' . $info['filename'] . '-' . $counter . '.' . $info['extension'];
                                    $counter++;
                                }
                                rename($extractedPath, $flatPath);
                                $heicFiles[] = $flatPath;
                            } else {
                                $heicFiles[] = $extractedPath;
                            }
                            $extracted++;
                        }
                    }
                }
                $zip->close();
                logLine("[INFO] Extracted $extracted HEIC files" . ($skipped ? " (skipped $skipped macOS metadata files)" : ""));
            }
            unlink($targetPath);
        } elseif (in_array($ext, ['heic', 'heif']) && strpos(basename($originalName), '._') !== 0) {
            $heicFiles[] = $targetPath;
            logLine("[INFO] Queued: $originalName");
        }
    }

    $total = count($heicFiles);
    if ($total === 0) {
        logLine("[ERROR] No HEIC/HEIF files found in upload");
        exit;
    }

    logLine("[INFO] Starting conversion of $total file(s)...");
    logLine("---");

    $heifBin = resolveTool(['heif-convert']);
    $ffmpegBin = resolveTool(['ffmpeg']);
    $magickBin = resolveTool(['magick', 'convert']);

    $convertedFiles = [];
    $failedFiles = [];
    $current = 0;

    foreach ($heicFiles as $heicFile) {
        $current++;
        $baseName = pathinfo(basename($heicFile), PATHINFO_FILENAME);
        $jpgPath = $outputDir . '/' . $baseName . '.jpg';

        $counter = 1;
        while (file_exists($jpgPath)) {
            $jpgPath = $outputDir . '/' . $baseName . '-' . $counter . '.jpg';
            $counter++;
        }

        $heicEscaped = escapeshellarg($heicFile);
        $jpgEscaped = escapeshellarg($jpgPath);
        $converted = false;

        $heicExt = strtolower(pathinfo($heicFile, PATHINFO_EXTENSION));
        logLine("[$current/$total] Converting $baseName.$heicExt ...");

        // Try heif-convert first
        $output = [];
        if ($heifBin) {
            exec(escapeshellarg($heifBin) . " -q 90 $heicEscaped $jpgEscaped 2>&1", $output, $rc);
            if ($rc === 0 && file_exists($jpgPath)) {
                $converted = true;
                $size = round(filesize($jpgPath) / 1024);
                logLine("[$current/$total] OK — heif-convert → {$baseName}.jpg ({$size} KB)");
            } else {
                logLine("[$current/$total] heif-convert failed: " . implode(' ', $output));
            }
        } else {
            logLine("[$current/$total] heif-convert not found (install libheif / brew install libheif)");
        }

        // Fall back to ffmpeg
        if (!$converted && $ffmpegBin) {
            $output = [];
            exec(escapeshellarg($ffmpegBin) . " -y -i $heicEscaped -q:v 2 $jpgEscaped 2>&1", $output, $rc);
            if ($rc === 0 && file_exists($jpgPath)) {
                $converted = true;
                $size = round(filesize($jpgPath) / 1024);
                logLine("[$current/$total] OK — ffmpeg → {$baseName}.jpg ({$size} KB)");
            } else {
                logLine("[$current/$total] ffmpeg failed: " . implode(' ', array_slice($output, -2)));
            }
        } elseif (!$converted && !$ffmpegBin) {
            logLine("[$current/$total] ffmpeg not found — skipping (optional fallback)");
        }

        // Fall back to ImageMagick (magick or convert)
        if (!$converted && $magickBin) {
            $output = [];
            exec(escapeshellarg($magickBin) . " $heicEscaped $jpgEscaped 2>&1", $output, $rc);
            if ($rc === 0 && file_exists($jpgPath)) {
                $converted = true;
                $size = round(filesize($jpgPath) / 1024);
                $tool = basename($magickBin);
                logLine("[$current/$total] OK — $tool → {$baseName}.jpg ({$size} KB)");
            } else {
                logLine("[$current/$total] ImageMagick failed: " . implode(' ', $output));
            }
        } elseif (!$converted && !$magickBin) {
            logLine("[$current/$total] ImageMagick (magick/convert) not found — skipping");
        }

        if ($converted) {
            $convertedFiles[] = $jpgPath;
        } else {
            $failedFiles[] = $baseName;
            logLine("[$current/$total] FAILED — all converters failed for $baseName.$heicExt");
        }
    }

    logLine("---");

    if (empty($convertedFiles)) {
        logLine("[ERROR] All conversions failed. No output files.");
        exit;
    }

    $successCount = count($convertedFiles);
    $failCount = count($failedFiles);
    logLine("[INFO] Converted $successCount/$total files" . ($failCount ? " ($failCount failed)" : ""));

    // Create ZIP
    logLine("[INFO] Creating ZIP archive...");
    $zipPath = $tempDir . '/converted_images.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        logLine("[ERROR] Failed to create ZIP archive");
        exit;
    }

    foreach ($convertedFiles as $file) {
        $zip->addFile($file, basename($file));
    }
    $zip->close();

    $zipSize = round(filesize($zipPath) / 1024 / 1024, 1);
    logLine("[INFO] ZIP ready: converted_images.zip ({$zipSize} MB)");

    // Store for download
    $downloadId = bin2hex(random_bytes(8));
    $downloadPath = sys_get_temp_dir() . '/heic_download_' . $downloadId . '.zip';
    copy($zipPath, $downloadPath);

    logLine("[DONE]");
    logLine("[DOWNLOAD] heic_download.php?id=$downloadId");

} catch (Exception $e) {
    logLine("[ERROR] " . $e->getMessage());
} finally {
    $cleanup = function ($dir) use (&$cleanup) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };
    $cleanup($tempDir);
}
