<?php declare(strict_types=1);

use GuzzleHttp\Client;
use League\Csv\Writer;
use Nette\Utils\DateTime;

define('URL', 'http://www.supcourt.gov.sk/rozhodnutia/?&art_datrozh_od=__DATE_FROM__&art_datrozh_do=__DATE_TO__&page=__PAGE__');

function prepareUrl(DateTime $dateFrom, DateTime $dateTo, int $page): string
{
    return strtr(
        URL,
        [
            '__DATE_FROM__' => $dateFrom->format('j.n.Y'),
            '__DATE_TO__' => $dateTo->format('j.n.Y'),
            '__PAGE__' => $page
        ]
    );
}

function fetchUrl(string $url, bool $wait = false): string
{
    $client = new Client();

    $attempts = 5;
    while ($attempts > 0 || $wait) {
        try {
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() !== 200) {
                throw new UnexpectedHTTPStatusCode();
            }
            return $response->getBody()->__toString();
        } catch (Exception|UnexpectedHTTPStatusCode $exception) {
            if ($exception instanceof UnexpectedHTTPStatusCode && isset($response)) {
                cli\err("Unexpected HTTP status code: {$response->getStatusCode()}.\n");
            }
            if ($wait) {
                cli\err("Failed to fetch [$url], waiting on user input.\n");
                \cli\input();
            } else {
                cli\err("Failed to fetch [$url], waiting [10] seconds, [$attempts] left.\n");
                sleep(10);
                $attempts--;
            }
        }
    }
    throw new AllDownloadAttemptsFailed();
}

function storeUrl(string $outputDirectory, ?string $url): ?string
{
    if (!$url) {
        \cli\out("Empty file URL.\n");
        return null;
    }
    $baseName = basename($url);
    if (!$baseName) {
        \cli\out("Could not determine basename from [$url].\n");
        return null;
    }

    $cli = new Client();
    \cli\out("Downloading file [$url]...\n");
    $response = $cli->get($url, [GuzzleHttp\RequestOptions::SINK => $outputDirectory . '/' . $baseName]);
    if ($response->getStatusCode() !== 200) {
        unlink($outputDirectory . '/' . $baseName);
        \cli\err("File [$url] was not downloaded properly and was removed (HTTP status code [{$response->getStatusCode()}]).");
    }
    return $outputDirectory . $baseName;
}

function parseLinkFromCell(DOMElement $column): ?string
{
    $links = $column->getElementsByTagName('a');
    if ($links->length < 1) {
        return null;
    }
    $link = $links->item(0);
    if ($link->hasAttribute('href')) {
        return 'http://www.supcourt.gov.sk' . $link->getAttribute('href');
    }
    return null;
}

function processResponse(string $content): ?array
{
    $dom = new DOMDocument();
    $dom->loadHTML($content);
    $tables = $dom->getElementsByTagName('table');

    $records = [];

    /** @var DOMElement $table */
    foreach ($tables as $table) {
        if ($table->hasAttribute('class') && $table->getAttribute('class') === 'rozlist') {
            $rows = $table->getElementsByTagName('tr');

            /** @var DOMElement $row */
            foreach ($rows as $row) {
                $columns = $row->getElementsByTagName('td');

                // Skip header
                if ($columns->length !== 5) {
                    continue;
                }

                /** @var DOMElement $column */
                $records[] = [
                    $columns->item(0)->textContent,
                    $columns->item(1)->textContent,
                    $columns->item(2)->textContent,
                    $columns->item(3)->textContent,
                    parseLinkFromCell($columns->item(4)),
                ];
            }
        }
    }
    if (count($records) === 0) {
        return null;
    }
    return $records;

}

function downloadPeriod(DateTime $from, DateTime $to, bool $wait = false): array
{
    $records = [];
    $page = 0; // starts from 0.
    $nextPage = true;
    while ($nextPage) {
        $url = prepareUrl($from, $to, $page);
        \cli\out("Downloading page [$url]\n");
        try {
            $content = fetchUrl($url, $wait);
            $temp = processResponse($content);
            if (!$temp) {
                $nextPage = false;
                continue;
            }
            $records = array_merge($records, $temp);
            $page++;
        } catch (AllDownloadAttemptsFailed $exception) {
            \cli\err("All download attempts of [$url] has failed, quitting now.\n");
            exit(1);
        }
    }
    return $records;
}

function downloadReferencedFiles(string $outputDirectory, array &$records): void
{
    if (!@mkdir($outputDirectory . '/files/') && !is_dir($outputDirectory . '/files/')) {
        cli\err("Vytvoření adresáře pro stažené soubory rozhodnutí se nezdařilo. Nelze pokračovat.\n");
        return;
    }
    foreach ($records as &$record) {
        $url = $record[4];
        $localPath = storeUrl($outputDirectory . '/files/', $url);
        $record[5] = substr($localPath, strlen($outputDirectory) + 1, strlen($localPath) - strlen($outputDirectory) + 1);
    }
}

function saveOutputToCsv(string $path, array $records): void
{
    // Write the files
    $header = ['Datum', 'Kolégium', 'Spisová značka', 'Merito věci', 'URL', 'Soubor'];

    $csv = Writer::createFromString('');
    $csv->insertOne($header);
    $csv->insertAll($records);
    file_put_contents($path . '/metadata.csv', $csv);

}