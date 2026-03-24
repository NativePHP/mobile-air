<?php

use Endroid\QrCode\Builder\Builder;

/**
 * NativePHP Jump Server Router
 *
 * This router script is used with PHP's built-in development server (php -S)
 * to handle Jump server requests without requiring Workerman.
 *
 * Configuration is passed via environment variables:
 * - JUMP_ZIP_PATH: Path to the app.zip bundle
 * - JUMP_DISPLAY_HOST: The host IP to display in QR code
 * - JUMP_HTTP_PORT: The HTTP port the server is running on
 * - JUMP_LARAVEL_PORT: The Laravel dev server port to proxy to
 * - JUMP_BASE_PATH: The Laravel base path for autoloading
 */

// Suppress all error output to prevent corrupting binary responses
error_reporting(0);
ini_set('display_errors', '0');

// Get configuration from environment
$zipPath = getenv('JUMP_ZIP_PATH');
$displayHost = getenv('JUMP_DISPLAY_HOST');
$httpPort = getenv('JUMP_HTTP_PORT');
$laravelPort = getenv('JUMP_LARAVEL_PORT') ?: 8000;
$basePath = getenv('JUMP_BASE_PATH');
$offlineMode = getenv('JUMP_OFFLINE_MODE') === '1';

// Parse request FIRST - before loading any autoloaders
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$path = rtrim($path, '/');

// Helper function to format bytes
function formatBytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2).' '.$units[$pow];
}

// Helper function to parse device name from user agent
function parseDeviceName($userAgent)
{
    if (str_contains($userAgent, 'Android')) {
        if (preg_match('/;\s*([^;]+)\s+Build\//', $userAgent, $matches)) {
            return trim($matches[1]);
        }

        return 'Android device';
    } elseif (str_contains($userAgent, 'iPhone')) {
        return 'iPhone';
    } elseif (str_contains($userAgent, 'iPad')) {
        return 'iPad';
    }

    return 'Unknown device';
}

// Helper function to log to parent process
function jumpLog($message)
{
    // Write to stderr so parent process can capture it
    // Note: STDERR constant is not available in PHP's built-in server
    $stderr = @fopen('php://stderr', 'w');
    if ($stderr) {
        fwrite($stderr, "[Jump] {$message}\n");
        fclose($stderr);
    }
}

// Ignore favicon and sourcemap requests
if ($path === '/favicon.ico' || str_ends_with($path, '.map')) {
    http_response_code(204);
    exit;
}

// Handle ZIP download endpoint
if ($path === '/jump/download') {
    if (! $zipPath || ! file_exists($zipPath)) {
        http_response_code(500);
        echo 'App bundle not available. Please restart the server.';
        exit;
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $deviceName = parseDeviceName($userAgent);
    $fileSizeBytes = filesize($zipPath);
    $fileSize = formatBytes($fileSizeBytes);
    jumpLog("{$deviceName} downloading ({$fileSize})");

    // Clean ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Minimal headers - match what Workerman's withFile() sends
    header('Content-Type: application/zip');
    header('Content-Length: '.$fileSizeBytes);
    header('Content-Disposition: attachment; filename="app.zip"');

    // Use readfile - simplest approach
    readfile($zipPath);
    exit;
}

// Handle info endpoint
if ($path === '/jump/info') {
    header('Content-Type: application/json');
    echo json_encode([
        'name' => 'NativePHP Server',
        'app_name' => getenv('APP_NAME') ?: 'Laravel',
        'version' => '1.0.0',
        'type' => 'nativephp-server',
    ]);
    exit;
}

// Handle QR code page
if ($path === '/jump/qr' || $path === '/jump') {
    // Load Laravel's autoloader only for QR page (needs Endroid library)
    if ($basePath && file_exists($basePath.'/vendor/autoload.php')) {
        require_once $basePath.'/vendor/autoload.php';
    }

    try {
        // Check if Endroid QR Code is available
        if (! class_exists(Builder::class)) {
            throw new Exception('QR Code library not available. Make sure endroid/qr-code is installed.');
        }

        $appName = getenv('APP_NAME') ?: 'Laravel';

        // Create JSON data for the QR code
        $qrData = json_encode([
            'host' => $displayHost,
            'port' => (string) $httpPort,
        ]);

        // Generate QR code
        $result = Builder::create()
            ->data($qrData)
            ->size(300)
            ->margin(10)
            ->build();

        $qrCodeDataUri = $result->getDataUri();

        // Generate HTML - use offline version if flag is set
        global $offlineMode;
        $viewFile = $offlineMode ? 'qr-offline.blade.php' : 'qr.blade.php';
        $viewPath = __DIR__.'/views/'.$viewFile;

        if (file_exists($viewPath)) {
            // Read blade file and do simple variable substitution
            $html = file_get_contents($viewPath);
            $html = str_replace('{{ $qrCodeDataUri }}', $qrCodeDataUri, $html);
            $html = str_replace('{{ $displayHost }}', $displayHost, $html);
            $html = str_replace('{{ $port }}', $httpPort, $html);
        } else {
            // Fallback to inline generator
            $html = generateQrHtml($appName, $qrCodeDataUri, $displayHost, $httpPort);
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo 'Error generating QR code: '.$e->getMessage();
        exit;
    }
}

// Proxy all other requests to Laravel
proxyToLaravel($laravelPort);

/**
 * Generate QR code HTML page - matches the exact design from qr.blade.php
 */
function generateQrHtml($appName, $qrCodeDataUri, $displayHost, $port)
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jump | Bifrost Technology</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Space+Grotesk:wght@300;500;700&display=swap" rel="stylesheet">

    <!-- GSAP & Three.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

    <style>
        :root {
            --primary: #00f3ff;
            --secondary: #bc13fe;
            --bg: #050505;
            --glass: rgba(255, 255, 255, 0.03);
            --border: rgba(255, 255, 255, 0.1);
            --scan-line: rgba(0, 243, 255, 0.5);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Space Grotesk', sans-serif;
            background-color: var(--bg);
            color: #ffffff;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Three.js Canvas */
        #canvas-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: all;
        }

        /* Overlay Gradient/Vignette */
        .vignette {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, transparent 0%, #000000 120%);
            z-index: 1;
            pointer-events: none;
        }

        /* Main Card */
        .interface-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 420px;
            padding: 2px;
            background: linear-gradient(135deg, rgba(0, 243, 255, 0.3), rgba(188, 19, 254, 0.3));
            border-radius: 24px;
            box-shadow: 0 0 50px rgba(0, 243, 255, 0.15);
            opacity: 0;
            transform: translateY(20px);
        }

        .interface-inner {
            background: rgba(10, 10, 12, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 22px;
            padding: 20px 10px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        /* Decorative HUD lines */
        .hud-line {
            position: absolute;
            background: var(--primary);
            opacity: 0.5;
        }
        .hud-tl { top: 20px; left: 20px; width: 20px; height: 2px; }
        .hud-tr { top: 20px; right: 20px; width: 20px; height: 2px; }
        .hud-bl { bottom: 20px; left: 20px; width: 20px; height: 2px; }
        .hud-br { bottom: 20px; right: 20px; width: 20px; height: 2px; }

        .hud-corner {
            position: absolute;
            width: 8px;
            height: 8px;
            border: 2px solid var(--primary);
            opacity: 0.7;
        }
        .c-tl { top: 10px; left: 10px; border-right: none; border-bottom: none; }
        .c-tr { top: 10px; right: 10px; border-left: none; border-bottom: none; }
        .c-bl { bottom: 10px; left: 10px; border-right: none; border-top: none; }
        .c-br { bottom: 10px; right: 10px; border-left: none; border-top: none; }

        h1 {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            font-size: 3rem;
            letter-spacing: -2px;
            margin-bottom: 5px;
            background: linear-gradient(90deg, #fff, var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
        }

        .app-name {
            font-family: 'JetBrains Mono', monospace;
            color: #888;
            font-size: 0.9rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .app-name::before, .app-name::after {
            content: '';
            width: 20px;
            height: 1px;
            background: #333;
        }

        /* QR Section */
        .qr-frame {
            position: relative;
            width: 260px;
            height: 260px;
            margin: 0 auto 30px;
            padding: 15px;
            background: rgba(255,255,255,0.03);
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-code {
            width: 100%;
            height: 100%;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            background: white;
        }

        .qr-code img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        /* Scanning Laser Effect */
        .scan-line {
            position: absolute;
            top: 0;
            left: -10%;
            width: 120%;
            height: 4px;
            background: #ff3333;
            box-shadow: 0 0 15px #ff3333, 0 0 30px #ff3333;
            opacity: 0.6;
            animation: scan 2.5s ease-in-out infinite alternate;
            z-index: 5;
            pointer-events: none;
        }

        @keyframes scan {
            0% { top: 0%; opacity: 0.8; }
            100% { top: 100%; opacity: 0.8; }
        }

        .instructions {
            color: #ccc;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        /* Connection Badge */
        .connection-status {
            background: rgba(0, 243, 255, 0.05);
            border: 1px solid rgba(0, 243, 255, 0.2);
            padding: 15px;
            border-radius: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        /* Animated glow behind status */
        .connection-status::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(0, 243, 255, 0.1), transparent);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            50%, 100% { left: 200%; }
        }

        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .label { color: #666; }
        .value { color: var(--primary); font-weight: 700; text-shadow: 0 0 10px rgba(0, 243, 255, 0.5); }
        .value-alt { color: var(--secondary); font-weight: 700; }

        .pulse-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background-color: var(--primary);
            border-radius: 50%;
            margin-right: 8px;
            box-shadow: 0 0 8px var(--primary);
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.4); opacity: 0.5; }
            100% { transform: scale(1); opacity: 1; }
        }

        .hot-reload-text {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: var(--primary);
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Success Overlay */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            opacity: 0;
        }

        .success-overlay.active {
            opacity: 1;
            background-color: rgba(5, 5, 5, 0.95);
            pointer-events: all;
        }

        .success-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(0, 243, 255, 0.15) 0%, rgba(188, 19, 254, 0.1) 40%, rgba(0, 0, 0, 0.9) 70%);
        }

        .success-content {
            position: relative;
            text-align: center;
            z-index: 1;
        }

        .success-icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            position: relative;
        }

        .success-ring {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 3px solid var(--primary);
            border-radius: 50%;
            animation: ringPulse 1.5s ease-out infinite;
        }

        .success-ring:nth-child(2) {
            border-color: var(--secondary);
        }

        .success-ring:nth-child(2) { animation-delay: 0.3s; }
        .success-ring:nth-child(3) { animation-delay: 0.6s; }

        @keyframes ringPulse {
            0% { transform: scale(1); opacity: 1; }
            100% { transform: scale(2); opacity: 0; }
        }

        .success-check {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
        }

        .success-check svg {
            width: 100%;
            height: 100%;
            stroke: var(--primary);
            stroke-width: 3;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .success-check svg path {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: drawCheck 0.6s ease-out 0.3s forwards;
        }

        @keyframes drawCheck {
            to { stroke-dashoffset: 0; }
        }

        .success-title {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2.5rem;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 6px;
            margin-bottom: 15px;
            text-shadow:
                0 0 10px #00f3ff,
                0 0 20px #bc13fe,
                0 0 40px #00f3ff,
                0 0 80px #bc13fe;
        }

        .success-subtitle {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem;
            color: #ccc;
            margin-bottom: 20px;
        }

        .success-device {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: var(--primary);
            background: rgba(0, 243, 255, 0.1);
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid rgba(0, 243, 255, 0.3);
            display: inline-block;
        }

        /* Particle burst effect */
        .particle-burst {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 10px;
            height: 10px;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            width: 6px;
            height: 6px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0;
        }

        .particle:nth-child(even) {
            background: var(--secondary);
        }

    </style>
</head>
<body>

<!-- Three.js Background -->
<div id="canvas-container"></div>
<div class="vignette"></div>

<!-- Interface -->
<div class="interface-container">
    <div class="interface-inner">
        <!-- Decorative Corners -->
        <div class="hud-corner c-tl"></div>
        <div class="hud-corner c-tr"></div>
        <div class="hud-corner c-bl"></div>
        <div class="hud-corner c-br"></div>

        <!-- Decorative Lines -->
        <div class="hud-line hud-tl"></div>
        <div class="hud-line hud-tr"></div>

        <h1 class="split-text">JUMP</h1>
        <div class="app-name">
            BIFROST TECHNOLOGY
        </div>

        <div class="qr-frame">
            <div class="scan-line"></div>
            <div class="qr-code">
                <img src="{$qrCodeDataUri}" alt="Scan to Connect">
            </div>
        </div>

        <div class="instructions split-text">
            Initialize neural handshake.<br>Scan via mobile terminal.
        </div>

        <div class="connection-status">
            <div class="status-row">
                <span class="label">HOST</span>
                <span class="value">{$displayHost}</span>
            </div>
            <div class="status-row">
                <span class="label">PORT</span>
                <span class="value-alt">{$port}</span>
            </div>
            <div class="hot-reload-text">
                <span class="pulse-dot"></span> System Online
            </div>
        </div>
    </div>
</div>

<!-- Success Overlay -->
<div class="success-overlay" id="successOverlay">
    <div class="success-backdrop"></div>
    <div class="success-content">
        <div class="success-icon">
            <div class="success-ring"></div>
            <div class="success-ring"></div>
            <div class="success-ring"></div>
            <div class="success-check">
                <svg viewBox="0 0 50 50">
                    <path d="M14 27 L22 35 L37 16" />
                </svg>
            </div>
        </div>
        <div class="success-title">LINKED</div>
        <div class="success-subtitle">Neural handshake established</div>
        <div class="success-device" id="deviceInfo">Downloading bundle...</div>
        <div class="particle-burst" id="particleBurst"></div>
    </div>
</div>

<script>
    /**
     * Create particle burst effect
     */
    const createParticleBurst = (container) => {
        container.innerHTML = '';
        const particleCount = 20;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            container.appendChild(particle);

            const angle = (i / particleCount) * Math.PI * 2;
            const distance = 100 + Math.random() * 100;
            const x = Math.cos(angle) * distance;
            const y = Math.sin(angle) * distance;

            gsap.fromTo(particle,
                {
                    x: 0,
                    y: 0,
                    scale: 1,
                    opacity: 1
                },
                {
                    x: x,
                    y: y,
                    scale: 0,
                    opacity: 0,
                    duration: 1 + Math.random() * 0.5,
                    ease: 'power2.out',
                    delay: Math.random() * 0.2
                }
            );
        }
    };

    /**
     * 1. THREE.JS BACKGROUND ANIMATION
     */
    const initThree = () => {
        const container = document.getElementById('canvas-container');
        const scene = new THREE.Scene();

        const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        camera.position.z = 30;

        const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(window.devicePixelRatio);
        container.appendChild(renderer.domElement);

        const geometry = new THREE.BufferGeometry();
        const count = 400;
        const positions = new Float32Array(count * 3);
        const velocities = [];

        for(let i = 0; i < count * 3; i++) {
            positions[i] = (Math.random() - 0.5) * 100;
            if (i % 3 === 0) velocities.push((Math.random() - 0.5) * 0.05);
            if (i % 3 === 1) velocities.push((Math.random() - 0.5) * 0.05);
            if (i % 3 === 2) velocities.push((Math.random() - 0.5) * 0.05);
        }

        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));

        const material = new THREE.PointsMaterial({
            size: 0.4,
            color: 0x00f3ff,
            transparent: true,
            opacity: 0.8,
            blending: THREE.AdditiveBlending
        });

        const particlesMesh = new THREE.Points(geometry, material);
        scene.add(particlesMesh);

        const lineMaterial = new THREE.LineBasicMaterial({
            color: 0xbc13fe,
            transparent: true,
            opacity: 0.15
        });

        let mouseX = 0;
        let mouseY = 0;
        let targetX = 0;
        let targetY = 0;

        const windowHalfX = window.innerWidth / 2;
        const windowHalfY = window.innerHeight / 2;

        document.addEventListener('mousemove', (event) => {
            mouseX = (event.clientX - windowHalfX);
            mouseY = (event.clientY - windowHalfY);
        });

        const animate = () => {
            requestAnimationFrame(animate);

            targetX = mouseX * 0.001;
            targetY = mouseY * 0.001;

            particlesMesh.rotation.y += 0.001;
            particlesMesh.rotation.x += 0.05 * (targetY - particlesMesh.rotation.x);
            particlesMesh.rotation.y += 0.05 * (targetX - particlesMesh.rotation.y);

            const positions = particlesMesh.geometry.attributes.position.array;

            for(let i = 0; i < count; i++) {
                positions[i * 3] += velocities[i * 3];
                positions[i * 3 + 1] += velocities[i * 3 + 1];
                positions[i * 3 + 2] += velocities[i * 3 + 2];

                if(Math.abs(positions[i * 3]) > 50) positions[i * 3] *= -0.9;
                if(Math.abs(positions[i * 3 + 1]) > 50) positions[i * 3 + 1] *= -0.9;
                if(Math.abs(positions[i * 3 + 2]) > 50) positions[i * 3 + 2] *= -0.9;
            }

            particlesMesh.geometry.attributes.position.needsUpdate = true;

            renderer.render(scene, camera);
        };

        animate();

        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });
    };

    /**
     * 2. GSAP ANIMATIONS
     */
    const initAnimations = () => {
        const tl = gsap.timeline({ defaults: { ease: "power3.out" } });

        tl.to(".interface-container", {
            duration: 1.5,
            opacity: 1,
            y: 0,
            delay: 0.2
        });

        tl.from("h1", {
            y: 20,
            opacity: 0,
            duration: 0.8
        }, "-=1.0");

        tl.from(".app-name", {
            y: 10,
            opacity: 0,
            duration: 0.8
        }, "-=0.6");

        tl.from(".qr-frame", {
            scale: 0.8,
            opacity: 0,
            duration: 0.8,
            ease: "back.out(1.7)"
        }, "-=0.6");

        tl.from(".instructions", {
            opacity: 0,
            y: 10,
            duration: 0.6
        }, "-=0.4");

        tl.from(".connection-status", {
            opacity: 0,
            y: 20,
            duration: 0.8
        }, "-=0.4");

        tl.from(".hud-line", {
            width: 0,
            duration: 1,
            stagger: 0.1
        }, "-=1.2");
    };

    window.onload = () => {
        initThree();
        initAnimations();
    };

</script>
</body>
</html>
HTML;
}

/**
 * Proxy request to Laravel development server
 */
function proxyToLaravel($laravelPort)
{
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];
    $laravelUrl = "http://127.0.0.1:{$laravelPort}{$uri}";

    // Build headers to forward
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = str_replace('_', '-', substr($key, 5));
            // Skip hop-by-hop headers
            if (in_array(strtolower($headerName), ['connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'host'])) {
                continue;
            }
            $headers[] = "{$headerName}: {$value}";
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: '.$_SERVER['CONTENT_TYPE'];
    }

    // Get request body for POST/PUT/PATCH
    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $body = file_get_contents('php://input');
    }

    // Make the request using cURL
    $ch = curl_init($laravelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        http_response_code(502);
        echo "Bad Gateway: Could not connect to Laravel on port {$laravelPort}. Error: {$error}";

        return;
    }

    // Split response into headers and body
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // Set response code
    http_response_code($httpCode);

    // Forward response headers (skip some)
    $headerLines = explode("\r\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        if (empty($headerLine) || strpos($headerLine, 'HTTP/') === 0) {
            continue;
        }
        // Skip transfer-encoding as we're not doing chunked
        if (stripos($headerLine, 'transfer-encoding:') === 0) {
            continue;
        }
        header($headerLine);
    }

    // Output response body
    echo $responseBody;
}
