<?php

#
# Fetch Metrics Data
# Fetches data from the metrics server. The metrics server hosts json files of summary
# stats pulled from various systems. The files are generated at most once per day.
#
include_once('common.php');

class MetricsFetcher {
    public function fetchData() {
        set_note_app_name('Metrics Data Fetch');
        note('Starting Metrics Data Fetch');

        # CONFIG
        $url = 'http://metrics.kbase.us';
        $filesToFetch = [
            'awe_user_data.json',
            'histogram.json',
            'methods.json',
            'narrative_access.json',
            'narratives2.json',
            'recent.json',
            'shock_data.json',
            'users.json',
            'user_data.json',
            'ws_data.json',
            'ws_bymonth.json',
            'ws_object_list.json'
        ];

        note("Ready to fetch data");

        $destDir = 'var/data';

        foreach ($filesToFetch as $file) {
            note('Fetching ' . $url . '/' . $file);
            $header = (object) [];
            $ch = curl_init($url . '/' . $file);
            $fp = fopen($destDir . '/' . $file, 'w+');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);

            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$header) {
                if (preg_match('/^HTTP/', $h)) {
                    // skip the response line...
                } elseif (preg_match('/^\r\n$/', $h)) {
                    // and skip the last line...
                } else {
                    preg_match('/^(.+?):[ ]*(.*?)\r\n$/', $h, $matches);
                    if (count($matches) == 3) {
                        $key = preg_replace('/-/', '_', strtolower($matches[1]));
                        $header->{$key} = $matches[2];
                    }
                }
                return strlen($h);
            });
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $contentBody = curl_exec($ch);
            $info = curl_getinfo($ch);
            if (curl_errno($ch)) {
                $msg = 'Error: ' . curl_errno($ch) . ':' . curl_error($ch);
                curl_close($ch);
                fclose($fp);
                die($msg);
            }
            $data = json_decode($contentBody);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
            fclose($fp);

            $fp = fopen($destDir . '/info_' . $file, 'w+');
            fwrite($fp, json_encode((object) $info, JSON_PRETTY_PRINT));
            fclose($fp);

            $fp = fopen($destDir . '/header_' . $file, 'w+');
            fwrite($fp, json_encode($header, JSON_PRETTY_PRINT));
            fclose($fp);

            curl_close($ch);
       }

        note('Finished Metrics Data Fetch');
    }
}