<?php

declare(strict_types=1);

// Minimal WebDAV server for the tests (PHP built-in web server): MKCOL, PUT, COPY, PROPFIND, DELETE below /archiv,
// basic authentication with user "cam" and password "secret". The files lie in the directory WEBDAV_ROOT.

$root = rtrim((string) getenv('WEBDAV_ROOT'), '/\\');
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$password = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($user !== 'cam' || $password !== 'secret') {
    header('WWW-Authenticate: Basic realm="test"');
    http_response_code(401);
    return true;
}

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path !== '/archiv' && !str_starts_with($path, '/archiv/')) {
    http_response_code(404);
    return true;
}
$relative = rtrim(substr($path, strlen('/archiv')), '/');
$file = $root . $relative;

function deleteTree(string $Path): void
{
    if (is_dir($Path)) {
        foreach (scandir($Path) as $name) {
            if ($name !== '.' && $name !== '..') {
                deleteTree($Path . '/' . $name);
            }
        }
        rmdir($Path);
    } elseif (is_file($Path)) {
        unlink($Path);
    }
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'MKCOL':
        if (file_exists($file)) {
            http_response_code(405);
        } elseif (!is_dir(dirname($file))) {
            http_response_code(409);
        } else {
            mkdir($file);
            http_response_code(201);
        }
        break;
    case 'PUT':
        if (!is_dir(dirname($file))) {
            http_response_code(409);
            break;
        }
        $existed = is_file($file);
        $data = file_get_contents('php://input');
        // Simulates a NAS that loses the beginning of the upload but still confirms it
        if (str_contains($file, 'Truncate')) {
            $data = substr($data, 10);
        }
        file_put_contents($file, $data);
        http_response_code($existed ? 204 : 201);
        break;
    case 'HEAD':
        if (!is_file($file)) {
            http_response_code(404);
            break;
        }
        header('Content-Length: ' . filesize($file));
        http_response_code(200);
        break;
    case 'COPY':
        $destination = rawurldecode((string) parse_url($_SERVER['HTTP_DESTINATION'] ?? '', PHP_URL_PATH));
        $target = $root . rtrim(substr($destination, strlen('/archiv')), '/');
        if (!is_file($file)) {
            http_response_code(404);
        } elseif (!is_dir(dirname($target))) {
            http_response_code(409);
        } else {
            copy($file, $target);
            http_response_code(201);
        }
        break;
    case 'PROPFIND':
        if (!file_exists($file)) {
            http_response_code(404);
            break;
        }
        $hrefs = ['/archiv' . $relative . (is_dir($file) ? '/' : '')];
        if (is_dir($file) && ($_SERVER['HTTP_DEPTH'] ?? '1') !== '0') {
            foreach (scandir($file) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $hrefs[] = '/archiv' . $relative . '/' . $name . (is_dir($file . '/' . $name) ? '/' : '');
                }
            }
        }
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">';
        foreach ($hrefs as $href) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $href)));
            echo '<D:response><D:href>' . htmlspecialchars($encoded, ENT_XML1) . '</D:href><D:propstat><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
        }
        echo '</D:multistatus>';
        break;
    case 'DELETE':
        if (!file_exists($file)) {
            http_response_code(404);
        } else {
            deleteTree($file);
            http_response_code(204);
        }
        break;
    default:
        http_response_code(405);
}
return true;
