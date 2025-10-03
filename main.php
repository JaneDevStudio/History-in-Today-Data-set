#!/usr/bin/env php
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

const JSON_FILE   = __DIR__ . '/history_in_today.json';
const SQL_FILE    = __DIR__ . '/history_in_today.sql';
const SQLITE_FILE = __DIR__ . '/history_in_today.sqlite';
const CSV_FILE    = __DIR__ . '/history_in_today.csv';
const CACHE_DIR   = __DIR__ . '/cache';
const CONCURRENCY = 1;
const UA = 'JaneDevStudioBot/2.0 (+https://github.com/JaneDevStudio) master@zeapi.ink';

require __DIR__ . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Promise;

@mkdir(CACHE_DIR, 0755, true);

$tasks = [];
$period = new DatePeriod(
    new DateTime('2000-01-01'),
    new DateInterval('P1D'),
    new DateTime('2000-12-31 23:59:59')
);
foreach ($period as $d) {
    $m  = $d->format('n');
    $md = $d->format('n月j日');
    $key = sprintf('%02d-%02d', $m, $d->format('j'));
    $cache = CACHE_DIR . "/$m-{$d->format('j')}.html";

    if (file_exists($cache) && filemtime($cache) > time() - 86400 * 7) {
        continue;
    }
    $tasks[$key] = [
        'url'   => 'https://zh.wikipedia.org/wiki/' . $md,
        'cache' => $cache,
        'md'    => $md,
    ];
}

if (!$tasks) {
    echo "All pages are fresh. Nothing to do.\n";
    exit(0);
}

$total  = count($tasks);
$done   = 0;
$start  = microtime(true);
$barLen = 50;

function drawBar(int $done, int $total, float $start, int $barLen): void
{
    $percent = $done / $total;
    $filled  = (int)($percent * $barLen);
    $bar     = str_repeat('█', $filled) . str_repeat('░', $barLen - $filled);
    $elapsed = number_format(microtime(true) - $start, 1);
    $rate    = $done > 0 ? number_format($done / $elapsed, 1) : 0;
    echo "\r[{$bar}] {$done}/{$total}  {$elapsed}s  {$rate}req/s";
    if ($done === $total) echo PHP_EOL;
}

$loop    = Loop::get();
$browser = new Browser($loop);
$browser = $browser->withTimeout(30)->withHeader('User-Agent', UA);

$promises = [];
$queue    = new \SplQueue();
foreach ($tasks as $t) $queue->enqueue($t);

$concurrency = min(CONCURRENCY, $total);
for ($i = 0; $i < $concurrency; $i++) {
    $promises[] = work($browser, $queue);
}

function work(Browser $b, \SplQueue $q): Promise
{
    return new Promise(function (callable $resolve) use ($b, $q) {
        $next = function () use (&$next, $b, $q, $resolve): void {
            if ($q->isEmpty()) {
                $resolve(null);
                return;
            }
            $t = $q->dequeue();
            $b->get($t['url'])
              ->then(
                  function ($response) use ($t, &$next): void {
                      file_put_contents($t['cache'], (string)$response->getBody());
                      global $done;
                      $done++;
                      drawBar($done, $GLOBALS['total'], $GLOBALS['start'], $GLOBALS['barLen']);
                      $next();
                  },
                  function (\Exception $e) use ($t, &$next): void {
                      echo "\nError: {$t['md']} -> {$e->getMessage()}\n";
                      $next();
                  }
              );
        };
        $next();
    });
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) return;

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

\React\Promise\all($promises)->then(function (): void {
    echo "Download finished. Start parsing…\n";
    buildJson();
    echo "All done.\n";
    
    if (is_dir(CACHE_DIR)) {
        deleteDirectory(CACHE_DIR);
        echo "Cache directory deleted.\n";
    }
});

$loop->run();
exit(0);

function buildJson(): void
{
    $all = [];
    $period = new DatePeriod(
        new DateTime('2000-01-01'),
        new DateInterval('P1D'),
        new DateTime('2000-12-31 23:59:59')
    );
    foreach ($period as $d) {
        $key = sprintf('%02d-%02d', $d->format('n'), $d->format('j'));
        $cache = CACHE_DIR . '/' . $d->format('n') . '-' . $d->format('j') . '.html';
        $all[$key] = file_exists($cache) ? parse(file_get_contents($cache)) : ['events' => [], 'births' => [], 'deaths' => []];
    }

    $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!file_exists(JSON_FILE) || $json !== file_get_contents(JSON_FILE)) {
        file_put_contents(JSON_FILE, $json);
        echo "Written to " . JSON_FILE . PHP_EOL;
    } else {
        echo "No change, skip writing.\n";
    }

    exportSql($all);
    exportSqlite($all);
    exportCsv($all);
}

function parse(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $ret = ['events' => [], 'births' => [], 'deaths' => []];
    $map = [
        '大事记' => 'events',
        '出生'   => 'births',
        '逝世'   => 'deaths'
    ];

    foreach ($map as $cn => $key) {
        $h2 = $xpath->query("//h2[contains(., '$cn')]")->item(0);
        if (!$h2) continue;

        $node = $h2->parentNode;
        while ($node && $node->nodeName !== 'ul') {
            $node = $node->nextSibling;
        }
        if (!$node) continue;

        foreach ($node->getElementsByTagName('li') as $li) {
            $txt = trim($li->textContent);
            $txt = preg_replace('/\[.*?\]/', '', $txt);
            $txt = preg_replace('/\s+/', ' ', $txt);
            if ($txt !== '') $ret[$key][] = $txt;
        }
    }
    return $ret;
}

function exportSql(array $all): void
{
    $sql = "-- MySQL dump\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "DROP TABLE IF EXISTS history_in_today;\n";
    $sql .= "CREATE TABLE history_in_today (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        monthday  CHAR(5)     NOT NULL COMMENT 'mm-dd',
        type      ENUM('events','births','deaths') NOT NULL,
        content   TEXT        NOT NULL,
        KEY idx_md (monthday)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

    foreach ($all as $md => $groups) {
        foreach ($groups as $type => $items) {
            foreach ($items as $txt) {
                $txt = addslashes($txt);
                $sql .= "INSERT INTO history_in_today (monthday,type,content) VALUES ('$md','$type','$txt');\n";
            }
        }
    }
    file_put_contents(SQL_FILE, $sql);
    echo "Written to " . SQL_FILE . PHP_EOL;
}

function exportSqlite(array $all): void
{
    @unlink(SQLITE_FILE);
    $db = new PDO('sqlite:' . SQLITE_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE history_in_today (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        monthday TEXT,
        type     TEXT,
        content  TEXT
    )");

    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO history_in_today (monthday,type,content) VALUES (?,?,?)");

    foreach ($all as $md => $groups) {
        foreach ($groups as $type => $items) {
            foreach ($items as $txt) {
                $stmt->execute([$md, $type, $txt]);
            }
        }
    }
    $db->commit();          // 一次性刷盘

    echo "Written to " . SQLITE_FILE . "\n";
}

function exportCsv(array $all): void
{
    $fp = fopen(CSV_FILE, 'wb');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($fp, ['monthday', 'type', 'content'], '|');
    foreach ($all as $md => $groups) {
        foreach ($groups as $type => $items) {
            foreach ($items as $txt) {
                fputcsv($fp, [$md, $type, $txt], '|');
            }
        }
    }
    fclose($fp);
    echo "Written to " . CSV_FILE . PHP_EOL;
}