<?php
include('narrative.php');
include('fetch-metrics-data.php');

$nar = new NarrativeProcessor((object)[
   'sources' => './var/data'
]);

$fetcher = new MetricsFetcher();
$fetcher->fetchData();


$hist = $nar->histogram((object)[
   'bins'=>10
]);

file_put_contents('var/export/narrative_histogram.json', json_encode($hist, JSON_PRETTY_PRINT));


$hist = $nar->histogram_shared((object)[
   'bins'=>10
]);

file_put_contents('var/export/narrative_shared_histogram.json', json_encode($hist, JSON_PRETTY_PRINT));

$hist = $nar->histogram_sharing((object)[
   'bins'=>10
]);
file_put_contents('var/export/narrative_sharing_histogram.json', json_encode($hist, JSON_PRETTY_PRINT));


