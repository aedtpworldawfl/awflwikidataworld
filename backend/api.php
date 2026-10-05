<?php
// AWFLWIKIDATAWORLD writer API (runs on Render).
// Every change is committed to GitHub in ONE commit via the Git Data API,
// together with regenerated sitemap.xml, llms.txt, robots.txt, .well-known/* and tree.json.

require __DIR__ . '/config.php';

const ALLOWED_EXT     = ['html', 'json', 'txt'];
const IMG_EXT         = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const RESERVED        = ['images', 'backend', 'node_modules'];
const PROTECTED_NAMES = ['index.html', 'config.json'];

class UserError extends Exception {}

/* ---------- small helpers ---------- */

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ext($f) { return strtolower(pathinfo((string)$f, PATHINFO_EXTENSION)); }
function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Folder: lowercase, spaces -> underscore. */
function cleanFolder($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^a-z0-9_\-]/', '', $s);
    if ($s === '' || in_array($s, RESERVED, true)) throw new UserError('Invalid or reserved folder name');
    return $s;
}

/** File: keep case, spaces -> underscore, default .html, never index.html / config.json. */
function cleanFile($s) {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^A-Za-z0-9_.\-]/', '', $s);
    $s = ltrim($s, '.');
    $s = preg_replace('/\.{2,}/', '.', $s);
    if ($s === '') throw new UserError('File name is empty');
    $e = ext($s);
    if (in_array($e, ALLOWED_EXT, true)) {
        $s = substr($s, 0, strlen($s) - strlen($e)) . $e;   // lower-case the extension
    } else {
        $s .= '.html';                                       // default type
    }
    if (pathinfo($s, PATHINFO_FILENAME) === '') throw new UserError('File name is empty');
    if (in_array(strtolower($s), PROTECTED_NAMES, true)) throw new UserError('That file name is protected');
    return $s;
}

/** Validate an existing "folder/file" path coming from the client. */
function parsePath($p) {
    if (!preg_match('~^([a-z0-9_\-]+)/([A-Za-z0-9_][A-Za-z0-9_.\-]*)$~', (string)$p, $m)) throw new UserError('Bad path');
    if ($m[1] !== 'images' && in_array($m[1], RESERVED, true)) throw new UserError('Reserved folder');
    return [$m[1], $m[2]];
}
function parseFolder($f) {
    if (!preg_match('~^[a-z0-9_\-]+$~', (string)$f) || in_array($f, RESERVED, true)) throw new UserError('Bad folder');
    return $f;
}
function assertEditable($folder, $file) {
    if ($folder === 'images') throw new UserError('Images are not editable pages');
    if (!in_array(ext($file), ALLOWED_EXT, true)) throw new UserError('File type not allowed');
    if (in_array(strtolower($file), PROTECTED_NAMES, true)) throw new UserError('That file is protected');
}

/* ---------- GitHub API ---------- */

function gh($method, $path, $body = null) {
    global $github_token, $repo;
    $ch = curl_init((getenv('GITHUB_API') ?: 'https://api.github.com') . '/repos/' . $repo . $path);
    $headers = [
        'Authorization: Bearer ' . $github_token,
        'Accept: application/vnd.github+json',
        'User-Agent: awfl-wiki-writer',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new Exception('GitHub unreachable: ' . $err);
    return [$code, json_decode($res, true)];
}
function ghok($method, $path, $body = null) {
    [$c, $j] = gh($method, $path, $body);
    if ($c >= 300) throw new Exception('GitHub ' . $c . ': ' . (is_array($j) ? ($j['message'] ?? 'error') : 'error'));
    return $j;
}
function getBlob($sha) {
    $j = ghok('GET', '/git/blobs/' . $sha);
    return base64_decode(str_replace(["\n", "\r"], '', $j['content']));
}
function repoConfig() {
    global $branch;
    try {
        $j = ghok('GET', '/contents/config.json?ref=' . $branch);
        $c = json_decode(base64_decode(str_replace(["\n", "\r"], '', $j['content'])), true);
        return is_array($c) ? $c : [];
    } catch (Exception $e) { return []; }
}
function currentState() {
    global $branch;
    $ref   = ghok('GET', '/git/ref/heads/' . $branch);
    $commit = $ref['object']['sha'];
    $c     = ghok('GET', '/git/commits/' . $commit);
    $tree  = ghok('GET', '/git/trees/' . $c['tree']['sha'] . '?recursive=1');
    $files = [];
    foreach ($tree['tree'] as $e) if ($e['type'] === 'blob') $files[$e['path']] = ['sha' => $e['sha'], 'mode' => $e['mode']];
    return [$commit, $c['tree']['sha'], $files];
}

/* ---------- page template (single source of truth) ---------- */

function extractBody($html) {
    if (preg_match('~<main id="content"[^>]*>(.*)</main>~is', $html, $m)) return trim($m[1]);
    if (preg_match('~<body[^>]*>(.*)</body>~is', $html, $m)) $html = $m[1];
    $html = preg_replace('~<footer\b.*?</footer>~is', '', $html);
    $html = preg_replace('~<nav id="crumb".*?</nav>~is', '', $html);
    return trim($html);
}

function buildPage($folder, $name, $body, $cfg) {
    global $site_url;
    $sitename  = $cfg['sitename'] ?? 'AWFLWIKIDATAWORLD';
    $metatitle = $cfg['metatitle'] ?? 'AWFLWIKIDATAWORLD PROJECT DOCUMENTATION';
    $metadesc  = $cfg['metadescription'] ?? $metatitle;
    $icon      = $cfg['icon'] ?? 'https://aedtpworld.com/icons/aedtpworld.png';

    $n = h($name); $f = h($folder); $st = h($sitename); $mt = h($metatitle); $ic = h($icon);
    $title = "$n - $f | $mt";
    $desc  = "Learn $n from $f - official guide, setup, usage and examples inside $mt.";
    $url   = h($site_url . '/' . rawurlencode($folder) . '/' . rawurlencode($name) . '.html');
    $nav   = '<a href="../">Home</a> &gt; <a href="../#/' . rawurlencode($folder) . '">' . $f . '</a> &gt; ' . $n;

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    $org = json_encode([
        '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $sitename, 'logo' => $icon,
        'alternateName' => 'AEDTP WORLD FREE LICENSE (AWFL)', 'url' => $site_url, 'description' => $metadesc,
        'publisher' => ['@type' => 'Organization', 'name' => $sitename],
        'sameAs' => [
            'https://aedtpworld.com', 'https://youtube.com/@aedtpworld',
            'https://www.crunchbase.com/organization/aedtpworld',
            'https://soundbetter.com/profiles/636065-aedtp-world', 'https://www.f6s.com/aedtpworld',
        ],
    ], $flags);
    $art = json_encode([
        '@context' => 'https://schema.org', '@type' => 'TechArticle', 'headline' => $name, 'about' => $folder,
        'author' => ['@type' => 'Organization', 'name' => 'AEDTP WORLD'],
    ], $flags);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="author" content="AEDTP WORLD">
<meta name="copyright" content="© AEDTP WORLD">
<meta name="license" content="AEDTP WORLD FREE LICENSE (AWFL)">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<title>$title</title>
<meta name="description" content="$desc">
<meta name="keywords" content="$n, $f, $st, AWFL, documentation">
<link rel="canonical" href="$url">
<link rel="icon" href="$ic">
<meta property="og:type" content="article">
<meta property="og:site_name" content="$st">
<meta property="og:title" content="$title">
<meta property="og:description" content="$desc">
<meta property="og:image" content="$ic">
<meta property="og:url" content="$url">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="$title">
<meta name="twitter:description" content="$desc">
<meta name="twitter:image" content="$ic">
<script type="application/ld+json">$org</script>
<script type="application/ld+json">$art</script>
<style>body{margin:0;background:#fff7fb;color:#000;font-family:"Segoe UI",system-ui,sans-serif}a{color:#d6336c}img{max-width:100%}pre{background:#f6f2f5;padding:12px;border-radius:12px;overflow:auto}</style>
</head>
<body>
<nav id="crumb" style="max-width:880px;margin:0 auto;padding:16px 20px 0;font-size:.9rem">$nav</nav>
<main id="content" style="max-width:880px;margin:16px auto;padding:20px;line-height:1.6;background:#fff;color:#000;border-radius:16px">
$body
</main>
<footer style="border-radius:16px; text-align:center; padding:20px;">$st © AEDTPWORLD | AEDTP WORLD FREE LICENSE (AWFL)</footer>
</body>
</html>
HTML;
}

/* ---------- generated files ---------- */

function listing($files) {
    $folders = []; $images = [];
    foreach (array_keys($files) as $p) {
        $parts = explode('/', $p);
        if (count($parts) !== 2) continue;
        [$d, $f] = $parts;
        if ($d === 'images') {
            if (in_array(ext($f), IMG_EXT, true)) $images[] = $f;
            continue;
        }
        if ($d === '' || $d[0] === '.' || in_array($d, RESERVED, true)) continue;
        if (!isset($folders[$d])) $folders[$d] = [];
        if ($f === '.gitkeep') continue;
        if (in_array(ext($f), ALLOWED_EXT, true)) $folders[$d][] = $f;
    }
    uksort($folders, 'strnatcasecmp');
    foreach ($folders as $k => $l) { usort($l, 'strnatcasecmp'); $folders[$k] = $l; }
    usort($images, 'strnatcasecmp');
    return ['updated' => gmdate('c'), 'folders' => (object)$folders, 'images' => $images];
}

function robotsText() {
    global $site_url;
    $bots = ['GPTBot', 'ChatGPT-User', 'CCBot', 'Google-Extended', 'PerplexityBot'];
    $t = "User-agent: *\nAllow: /\nSitemap: $site_url/sitemap.xml\n";
    foreach ($bots as $b) $t .= "\nUser-agent: $b\nAllow: /\n";
    return $t;
}

function generated($files, $cfg) {
    global $site_url;
    $tree = listing($files);
    $now  = gmdate('c');
    $name = $cfg['sitename'] ?? 'AWFLWIKIDATAWORLD';

    $sm  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sm .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $sm .= "  <url><loc>$site_url/</loc><lastmod>$now</lastmod><priority>1.0</priority></url>\n";

    $llms = "# $name\n\n> Structured documentation for AWFL ecosystem\n\n- [Home]($site_url/)\n";

    foreach ((array)$tree['folders'] as $d => $list) {
        $pages = array_values(array_filter($list, function ($f) { return ext($f) === 'html'; }));
        if ($pages) $llms .= "\n## $d\n";
        foreach ($pages as $f) {
            $u = $site_url . '/' . rawurlencode($d) . '/' . rawurlencode($f);
            $sm   .= '  <url><loc>' . h($u) . "</loc><lastmod>$now</lastmod><priority>0.8</priority></url>\n";
            $llms .= '- [' . pathinfo($f, PATHINFO_FILENAME) . "]($u)\n";
        }
    }
    $sm .= "</urlset>\n";

    $robots = robotsText();
    return [
        'tree'  => $tree,
        'files' => [
            'sitemap.xml'           => $sm,
            'llms.txt'              => $llms,
            'robots.txt'            => $robots,
            '.well-known/robots.txt' => $robots,
            '.well-known/ai.txt'    => $robots,
            'tree.json'             => json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
        ],
    ];
}

/* ---------- one atomic commit per action ---------- */

/**
 * $fn($files) returns [$ops, $extra]. $ops: path => ['text'=>..] | ['b64'=>..] | ['sha'=>..] | null (delete).
 * Generated SEO files + tree.json are added to the same commit.
 */
function mutate($message, $fn, $cfg) {
    global $branch;
    $last = null;
    for ($i = 0; $i < 3; $i++) {
        try {
            [$commit, $baseTree, $files] = currentState();
            [$ops, $extra] = $fn($files);

            $final = $files;
            foreach ($ops as $p => $o) {
                if ($o === null) unset($final[$p]); else $final[$p] = ['sha' => 'x', 'mode' => '100644'];
            }
            $gen = generated($final, $cfg);
            foreach ($gen['files'] as $p => $t) $ops[$p] = ['text' => $t];

            $entries = [];
            foreach ($ops as $p => $o) {
                if ($o === null) {
                    if (isset($files[$p])) $entries[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                } elseif (isset($o['text'])) {
                    $entries[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'content' => $o['text']];
                } elseif (isset($o['b64'])) {
                    $b = ghok('POST', '/git/blobs', ['content' => $o['b64'], 'encoding' => 'base64']);
                    $entries[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => $b['sha']];
                } else {
                    $entries[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => $o['sha']];
                }
            }
            $t = ghok('POST', '/git/trees', ['base_tree' => $baseTree, 'tree' => $entries]);
            $c = ghok('POST', '/git/commits', ['message' => $message, 'tree' => $t['sha'], 'parents' => [$commit]]);
            ghok('PATCH', '/git/refs/heads/' . $branch, ['sha' => $c['sha']]);
            return ['tree' => $gen['tree'], 'commit' => $c['sha'], 'extra' => $extra];
        } catch (UserError $e) {
            throw $e;
        } catch (Exception $e) {
            $last = $e;
            if (!preg_match('/GitHub (409|422)/', $e->getMessage())) break;
            usleep(400000);
        }
    }
    throw $last;
}

/** Move/rename one file, rebuilding the page template for .html so canonical URLs stay right. */
function movedEntry($files, $old, $newFolder, $newFile, $cfg) {
    if (ext($newFile) === 'html') {
        $body = extractBody(getBlob($files[$old]['sha']));
        return ['text' => buildPage($newFolder, substr($newFile, 0, -5), $body, $cfg)];
    }
    return ['sha' => $files[$old]['sha']];
}
function keepIfEmpty($files, &$ops, $folder) {
    if ($folder === 'images') return;
    foreach ($files as $p => $_) {
        if (strpos($p, $folder . '/') === 0 && !array_key_exists($p, $ops)) return;   // still has something
    }
    foreach ($ops as $p => $o) if ($o !== null && strpos($p, $folder . '/') === 0) return;
    $ops[$folder . '/.gitkeep'] = ['text' => "\n"];
}

/* ---------- request handling ---------- */

function handle() {
    global $github_token, $allowed_origin, $branch, $site_url;

    header('Access-Control-Allow-Origin: ' . $allowed_origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    if (isset($_GET['ping'])) out(['ok' => true]);
    if (!$github_token) out(['ok' => false, 'error' => 'Server is missing GITHUB_TOKEN'], 500);

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) out(['ok' => false, 'error' => 'Send a JSON body'], 400);
    $action = (string)($in['action'] ?? '');

    $cfg  = repoConfig();
    $user = getenv('ADMIN_USERNAME') ?: (string)($cfg['username'] ?? '');
    $pass = getenv('ADMIN_PASSWORD') ?: (string)($cfg['password'] ?? '');
    if ($pass === '' || !hash_equals($user, (string)($in['username'] ?? '')) || !hash_equals($pass, (string)($in['password'] ?? ''))) {
        usleep(500000);
        out(['ok' => false, 'error' => 'Unauthorized'], 401);
    }

    try {
        switch ($action) {

            case 'login':
                out(['ok' => true]);

            case 'read': {
                [$folder, $file] = parsePath($in['path'] ?? '');
                assertEditable($folder, $file);
                [$c, $j] = gh('GET', '/contents/' . $folder . '/' . $file . '?ref=' . $branch);
                if ($c === 404) throw new UserError('File not found');
                if ($c >= 300) throw new Exception('GitHub ' . $c);
                $text = base64_decode(str_replace(["\n", "\r"], '', $j['content'] ?? ''));
                if ($text === '' && !empty($j['size'])) $text = getBlob($j['sha']);
                $isHtml = ext($file) === 'html';
                out(['ok' => true, 'kind' => $isHtml ? 'html' : 'text', 'content' => $isHtml ? extractBody($text) : $text]);
            }

            case 'save': {
                $folder  = cleanFolder($in['folder'] ?? '');
                $file    = cleanFile($in['name'] ?? '');
                $content = (string)($in['content'] ?? '');
                if (strlen($content) > 1000000) throw new UserError('Content too large (max 1MB)');
                if (ext($file) === 'html') {
                    if (preg_match('/<!doctype|<html/i', $content)) $content = extractBody($content);
                    $text = buildPage($folder, substr($file, 0, -5), $content, $cfg);
                } else {
                    $text = $content;
                }
                $r = mutate("wiki: save $folder/$file", function ($files) use ($folder, $file, $text) {
                    return [["$folder/$file" => ['text' => $text]], null];
                }, $cfg);
                out(['ok' => true, 'folder' => $folder, 'name' => $file, 'path' => "$folder/$file", 'tree' => $r['tree']]);
            }

            case 'mkdir': {
                $folder = cleanFolder($in['folder'] ?? '');
                $r = mutate("wiki: create folder $folder", function ($files) use ($folder) {
                    foreach ($files as $p => $_) if (strpos($p, $folder . '/') === 0) throw new UserError('Folder already exists');
                    return [["$folder/.gitkeep" => ['text' => "\n"]], null];
                }, $cfg);
                out(['ok' => true, 'folder' => $folder, 'tree' => $r['tree']]);
            }

            case 'delete': {
                if (!empty($in['folder'])) {
                    $folder = parseFolder($in['folder']);
                    $r = mutate("wiki: delete folder $folder", function ($files) use ($folder) {
                        $ops = [];
                        foreach ($files as $p => $_) if (strpos($p, $folder . '/') === 0) $ops[$p] = null;
                        if (!$ops) throw new UserError('Folder not found');
                        return [$ops, null];
                    }, $cfg);
                } else {
                    [$folder, $file] = parsePath($in['path'] ?? '');
                    if ($folder === 'images') {
                        if (!in_array(ext($file), IMG_EXT, true)) throw new UserError('Not an image');
                    } else {
                        assertEditable($folder, $file);
                    }
                    $r = mutate("wiki: delete $folder/$file", function ($files) use ($folder, $file) {
                        if (!isset($files["$folder/$file"])) throw new UserError('File not found');
                        $ops = ["$folder/$file" => null];
                        keepIfEmpty($files, $ops, $folder);
                        return [$ops, null];
                    }, $cfg);
                }
                out(['ok' => true, 'tree' => $r['tree']]);
            }

            case 'rename': {
                $type = (string)($in['type'] ?? 'file');
                if ($type === 'folder') {
                    $from = parseFolder($in['from'] ?? '');
                    $to   = cleanFolder($in['to'] ?? '');
                    if ($from === $to) throw new UserError('Same name');
                    $r = mutate("wiki: rename folder $from -> $to", function ($files) use ($from, $to, $cfg) {
                        $ops = [];
                        foreach ($files as $p => $_) if (strpos($p, $to . '/') === 0) throw new UserError('Target folder already exists');
                        foreach ($files as $p => $_) {
                            if (strpos($p, $from . '/') !== 0) continue;
                            $f = substr($p, strlen($from) + 1);
                            if (strpos($f, '/') !== false) { $ops[$to . '/' . $f] = ['sha' => $files[$p]['sha']]; }
                            else { $ops[$to . '/' . $f] = movedEntry($files, $p, $to, $f, $cfg); }
                            $ops[$p] = null;
                        }
                        if (!$ops) throw new UserError('Folder not found');
                        return [$ops, null];
                    }, $cfg);
                    out(['ok' => true, 'folder' => $to, 'tree' => $r['tree']]);
                }
                [$folder, $file] = parsePath($in['path'] ?? '');
                assertEditable($folder, $file);
                $new = cleanFile($in['newname'] ?? '');
                if ($new === $file) throw new UserError('Same name');
                $r = mutate("wiki: rename $folder/$file -> $new", function ($files) use ($folder, $file, $new, $cfg) {
                    if (!isset($files["$folder/$file"])) throw new UserError('File not found');
                    if (isset($files["$folder/$new"])) throw new UserError('A file with that name already exists');
                    return [["$folder/$new" => movedEntry($files, "$folder/$file", $folder, $new, $cfg), "$folder/$file" => null], null];
                }, $cfg);
                out(['ok' => true, 'folder' => $folder, 'name' => $new, 'tree' => $r['tree']]);
            }

            case 'move': {
                [$folder, $file] = parsePath($in['path'] ?? '');
                assertEditable($folder, $file);
                $to = cleanFolder($in['to'] ?? '');
                if ($to === $folder) throw new UserError('Already in that folder');
                $r = mutate("wiki: move $folder/$file -> $to/", function ($files) use ($folder, $file, $to, $cfg) {
                    if (!isset($files["$folder/$file"])) throw new UserError('File not found');
                    if (isset($files["$to/$file"])) throw new UserError('That folder already has a file with this name');
                    $ops = ["$to/$file" => movedEntry($files, "$folder/$file", $to, $file, $cfg), "$folder/$file" => null];
                    keepIfEmpty($files, $ops, $folder);
                    return [$ops, null];
                }, $cfg);
                out(['ok' => true, 'folder' => $to, 'name' => $file, 'tree' => $r['tree']]);
            }

            case 'upload': {
                $data = preg_replace('~^data:[^,]*,~', '', (string)($in['data'] ?? ''));
                $bin  = base64_decode($data, true);
                if ($bin === false || $bin === '') throw new UserError('Bad image data');
                if (strlen($bin) > 2 * 1024 * 1024) throw new UserError('Image too large (max 2MB)');
                $info = @getimagesizefromstring($bin);
                $okTypes = [IMAGETYPE_GIF => 'gif', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                if (!$info || !isset($okTypes[$info[2]])) throw new UserError('Only jpg, jpeg, png, webp, gif images are allowed');

                $name = preg_replace('/[^A-Za-z0-9_.\-]/', '', preg_replace('/\s+/', '_', trim((string)($in['name'] ?? 'image'))));
                $name = ltrim($name, '.');
                $e = ext($name);
                if (!in_array($e, IMG_EXT, true)) $name = ($name === '' ? 'image' : $name) . '.' . $okTypes[$info[2]];
                else $name = substr($name, 0, strlen($name) - strlen($e)) . $e;
                $b64 = base64_encode($bin);

                $r = mutate("wiki: upload image $name", function ($files) use (&$name, $b64) {
                    if (isset($files["images/$name"])) $name = time() . '_' . $name;
                    return [["images/$name" => ['b64' => $b64]], null];
                }, $cfg);
                out(['ok' => true, 'name' => $name, 'url' => $site_url . '/images/' . rawurlencode($name), 'tree' => $r['tree']]);
            }

            case 'rebuild': {
                $r = mutate('wiki: rebuild sitemap, llms.txt, robots.txt', function ($files) { return [[], null]; }, $cfg);
                out(['ok' => true, 'tree' => $r['tree']]);
            }

            default:
                throw new UserError('Unknown action');
        }
    } catch (UserError $e) {
        out(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Exception $e) {
        out(['ok' => false, 'error' => $e->getMessage()], 502);
    }
}

// php -S reports 'cli-server'; plain CLI (used for tests) skips the request handler.
if (PHP_SAPI !== 'cli') handle();
