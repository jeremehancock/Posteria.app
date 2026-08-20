<?php
// Request router for the built-in PHP server.
//
// nixpacks starts this deployment with `php -S 0.0.0.0:$PORT -t .` from the repo
// root, which means every tracked file is a URL. That is fine for the site itself
// and for the API trees, but it also published the OpenSpec planning documents and
// the API test scripts at posteria.app/<path> — internal working material that was
// never meant to be part of the site.
//
// A .gitignore cannot fix that: the files are already tracked, and ignoring them
// would only stop new ones. The built-in server has no configuration file either.
// A router script is the one mechanism it does offer.
//
// This file is deliberately small and total. It denies a fixed set of paths and
// hands everything else back to the server unchanged by returning false, which
// preserves the normal static-file and directory-index behaviour the endpoints rely
// on. Nothing here rewrites, redirects, or interprets a request — a bug in this file
// would take the whole site down with it, so it does as little as possible.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Collapse repeated separators so /openspec//spec.md cannot slip past a prefix test.
$path = preg_replace('#/+#', '/', $path);

// Paths that are part of the repository but not part of the site.
$internal = [
    // OpenSpec proposals, designs, specs and archived changes.
    '#^/openspec(/|$)#i',

    // Any dot-prefixed segment: .git, .gitignore, .claude, .env, .DS_Store.
    // Matches the segment, never a dot inside a filename, so posteria.html is safe.
    '#/\.#',

    // Test suites living beside the code they exercise.
    '#/tests?(/|$)#i',

    // Build and deployment descriptors.
    '#^/(nixpacks\.toml|composer\.(json|lock)|package(-lock)?\.json)$#i',

    // This file.
    '#^/router\.php$#i',
];

foreach ($internal as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not Found\n";
        return true;
    }
}

// Everything else is served exactly as it was before this file existed.
return false;
