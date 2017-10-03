<?php

use cli\Arguments;
use Nette\Utils\DateTime;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/exceptions.php';

$arguments = new Arguments(compact('strict'));

$arguments->addFlag(array('help', 'h'), 'Show this help screen');
$arguments->addOption(
    ['date-from'],
    ['description' => 'Date from which the documents should be obtained.']
);
$arguments->addOption(
    ['date-to'],
    ['description' => 'Date to which the documents should be obtained.']
);
$arguments->addOption(
    ['output', 'o'],
    ['description' => 'Output directory to which the documents and metadata should be stored.']
);

$arguments->addFlag('w', ['description' => 'Instead of limited number of retries after each failure user input is requested.']);

$arguments->parse();
if ($arguments['help']) {
    echo $arguments->getHelpScreen();
    echo "\n\n";
    exit();
}
if (!isset($arguments['date-from']) || !DateTime::createFromFormat('Y-m-d', $arguments['date-from'])) {
    echo 'Date from is either missing or invalid. Proper format is YYYY-MM-DD.';
    echo "\n";
    exit();
}

if (!isset($arguments['date-to']) || !DateTime::createFromFormat('Y-m-d', $arguments['date-to'])) {
    echo 'Date to is either missing or invalid. Proper format is YYYY-MM-DD.';
    echo "\n";
    exit();
}

if (DateTime::createFromFormat('Y-m-d', $arguments['date-to']) < DateTime::createFromFormat('Y-m-d', $arguments['date-from'])) {
    echo 'Date from is higher than date to.';
    echo "\n";
    exit();
}

if (!isset($arguments['output']) || !file_exists(getcwd() . '/' . $arguments['output']) || !is_dir(getcwd() . '/' .$arguments['output']) || !is_writable(getcwd() . '/' .$arguments['output'])) {
    echo 'Output directory is missing, not exists, or is not writable.';
    echo "\n";
    exit();
}

$dateFrom = DateTime::createFromFormat('Y-m-d', $arguments['date-from']);
$dateTo = DateTime::createFromFormat('Y-m-d', $arguments['date-to']);
$outputDirectory = getcwd() . '/' . $arguments['output'];
$wait = $arguments['w'];


$results = downloadPeriod($dateFrom, $dateTo, $wait);
downloadReferencedFiles($outputDirectory, $results);
saveOutputToCsv($outputDirectory, $results);
