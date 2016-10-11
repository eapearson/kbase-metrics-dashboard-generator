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


        # See if we are at least 24 hours past the last data generation time.
        # now comes in handy later too.
        $now = new DateTime('now', new DateTimeZone('utc'));
        note('Current time for comparison is ' . $now->format('c'));

        note("Ready to fetch data");

        # If so, create a new directory named after the expected data generation time.
        # The generation time should be based on todays date, at time 0 UTC.
        #
        #
        # Fetch the files via curl, copying into a temporary directory
        #


        // $tempDir = mk_temp_dir();
        $destDir = 'var/data';

        $dataGenerationTime = null;

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

            # Read the data generation time from the files.
            # NB: we assume local pacific time until we get the times into full iso8601.
            # 11/6: it appears to be in GMT already, although the TZ is not attached to the date, so switching to utc.
            if ($file === 'recent.json') {
                $dataGenerationTime = new DateTime($data->meta->generated, new DateTimeZone('utc'));
            }
        }

        note('Finished Metrics Data Fetch');
    }
}