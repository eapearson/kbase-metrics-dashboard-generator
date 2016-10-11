<?php
include_once('common.php');

class DataProcessor {

    function __construct($options) {
        $this->sources = $options->sources;
    }

    public function loadData($dataFile) {
        $dataFileResolved = realpath($dataFile);
        if (!$dataFileResolved) {
            throw new Exception('Data file "' . $dataFile . '" not found.');
        }

        try {
            $json = json_decode(file_get_contents($dataFileResolved));
            #if (json_exists($json, ['meta', 'generated_on'])) {
            #   $this->generationTime = new DateTime($json->meta->generated_on, new DateTimeZone('utc'));
            #}
            return $json;
        } catch (Exception $e) {
            throw new Exception('Error processing json: ' . $e->getMessage());
        }
    }

    public function autoBin($values, $binCount) {
        # min, max, and count.
        $min = null;
        $max = null;
        $count = 0;
        foreach ($values as $value) {
            if (($min === null) || ($value < $min)) {
                $min = $value;
            }
            if (($max === null) || ($value > $max)) {
                $max = $value;
            }
            $count++;
        }

        # prepare bins array.
        $binSize = ($max - $min) / $binCount;
        $binValues = [];
        $binBounds = [];
        for ($i = 0; $i < $binCount; $i++) {
            # lower is either the last upper or the min if the first.
            if ($i === 0) {
                $lower = $min;
            } else {
                $lower = $lastUpper;
            }
            $upper = round($binSize * $i + $binSize);
            $lastUpper = $upper;
            if ($i === $binCount - 1) {
                $upperType = 'upperInclusive';
                $upper = $max;
            } else {
                $upperType = 'upper';
            }
            $bins[$i] = (object) [
                        'count' => 0,
                        'label' => '[' . $lower . '-' . $upper . ')',
                        'lower' => $lower,
                        'upper' => $upper,
                        $upperType => $upper
            ];
        }
        return (object) [
                    'bins' => $bins,
                    'min' => $min,
                    'max' => $max,
                    'binSize' => $binSize,
                    'binCount' => $binCount
        ];
    }

    public function numBins($cfg) {
        $values = $cfg->values;
        if (property_exists($cfg, 'binCount')) {
            $binResult = $this->autoBin($values, $cfg->binCount);
            $bins = $binResult->bins;
        } else {
            $bins = $cfg->bins;
            $binResult = (object) [
                        'bins' => $bins,
                        'binSize' => null
            ];
        }
        $unbinnable = [];

        $min = null;
        $max = null;
        $count = 0;
        foreach ($values as $value) {
            $binned = false;
            $min = numMin($min, $value);
            $max = numMax($max, $value);
            $count++;
            foreach ($bins as $bin) {
                if ($value >= $bin->lower) {
                    if (property_exists($bin, 'upper') && ($value < $bin->upper || $bin->upper === null)) {
                        $bin->count++;
                        $binned = true;
                    } elseif (property_exists($bin, 'upperInclusive') && ($value <= $bin->upperInclusive)) {
                        $bin->count++;
                        $binned = true;
                    }
                } else {
                    break;
                }
            }
            if (!$binned) {
                array_push($unbinnable, $value);
            }
        }
        $binResult->min = $min;
        $binResult->max = $max;
        $binResult->count = $count;

        $binResult->binCount = count($binResult->bins);

        # extract labels and bin counts.
        $labels = array_map(function ($x) {
            return $x->label;
        }, (array) $binResult->bins);

        $binned = array_map(function ($x) {
            return $x->count;
        }, (array) $binResult->bins);

        $binResult->binned = $binned;
        $binResult->labels = $labels;
        $binResult->unbinnable = $unbinnable;

        return $binResult;
    }

    public function autoBin2($values, $binCount) {
        # min, max, and count.
        $summary = $this->calc_summary_stats($values);

        # Bin size is based on 10 bins to cover 2 stddev above and below the mean, or the min or max.

        $lowerIQR = $summary->median - $summary->iqr * 3;
        $upperIQR = $summary->median + $summary->iqr * 3;
        $lowerBound = max($lowerIQR, $summary->min);
        $upperBound = min($upperIQR, $summary->max);
        $binSize = ceil(($upperBound - $lowerBound) / $binCount);

        // upper may now be different because we are choosing integer bin sizes, and bumping up to the next integer
        $upperBound = $lowerBound + $binCount * $binSize;

        # Number of bins is chosen to cover from min to max + 10%
        #$range = ($summary->max - $summary->min);
        #$range += $range * 0.1;
        #$binCount = ceil($range / $binSize);
        # prepare bins array.
        #$binSize = ($max-$min)/$binCount;
        $binValues = [];
        $binBounds = [];
        $last = false;
        for ($i = 0; $i < $binCount; $i++) {
            # lower is either the last upper or the min if the first.
            if ($i === 0) {
                $lower = $lowerBound;
            } else {
                $lower = $lastUpper;
            }
            $upper = $lower + $binSize;
            $lastUpper = $upper;
            if ($i === $binCount - 1) {
                $last = true;
            }

            $bins[$i] = (object) [
                        'count' => 0,
                        'label' => '[' . $lower . '-' . $upper . ($last ? ']' : ')'),
                        'upperInclusive' => $last,
                        'lower' => $lower,
                        'upper' => $upper
            ];
        }
        return (object) [
                    'summary' => $summary,
                    'bins' => $bins,
                    'lowerIQR' => $lowerIQR,
                    'upperIQR' => $upperIQR,
                    'lowerBound' => $lowerBound,
                    'upperBound' => $upperBound,
                    'binSize' => $binSize,
                    'binCount' => $binCount
        ];
    }

    public function intBins($options) {
        $values = $options->values;
        $binCount = $options->binCount;

        # min, max, and count.
        $min = null;
        $max = null;
        $count = 0;
        foreach ($values as $value) {
            if (($min === null) || ($value < $min)) {
                $min = $value;
            }
            if (($max === null) || ($value > $max)) {
                $max = $value;
            }
            $count++;
        }

        # prepare bins array.
        $binSize = ($max - $min) / $binCount;
        $binValues = [];
        $binBounds = [];
        for ($i = 0; $i < $binCount; $i++) {
            # lower is either the last upper or the min if the first.
            if ($i === 0) {
                $lower = $min;
            } else {
                $lower = $lastUpper;
            }
            $upper = round($binSize * $i + $binSize);
            $lastUpper = $upper;
            $binValues[$i] = 0;
            $binBounds[$i] = [$lower, $upper];
        }
        # Do ths binning

        foreach ($values as $value) {
            for ($i = 0; $i < $binCount; $i++) {
                # last one includes the upper bound.
                # echo "COMP: $value, ".$binBounds[$i][0].', '.$binBounds[$i][1].'<br>';
                if (($i === ($binCount - 1)) && ($value >= $binBounds[$i][0]) && ($value <= $binBounds[$i][1])) {
                    $binValues[$i] ++;
                    break;
                } else if (($value >= $binBounds[$i][0]) && ($value < $binBounds[$i][1])) {
                    $binValues[$i] ++;
                    break;
                }
            }


            /* $x = $value / $binSize;
              $bin = floor($x);
              # handle case of a bin value that is
              # exactly at the upper limit.
              if (($bin === $binCount) && ($bin == ($binCount - 1))) {
              $bin--;
              }
              $bins[$bin]++;
             */
        }


        # bin labels
        $binLabels = [];
        for ($i = 0; $i < $binCount; $i++) {
            #$lower = $min + $i *  $binSize;
            #$upper = $lower + $binSize;
            $lower = $binBounds[$i][0];
            $upper = $binBounds[$i][1];
            if ($i === ($binCount - 1)) {
                $binLabel = ($i + 1) . ": [$lower - $upper]";
            } else {
                $binLabel = ($i + 1) . ": [$lower - $upper)";
            }
            $binLabels[$i] = $binLabel;
        }
        return (object) [
                    'values' => $binValues,
                    'labels' => $binLabels,
                    'min' => $min,
                    'max' => $max,
                    'binSize' => $binSize,
                    'binCount' => $binCount];
    }

    public function calc_summary_stats($values) {
        $min = null;
        $max = null;
        $count = 0;
        $total = 0;
        foreach ($values as $value) {
            if (($min === null) || ($value < $min)) {
                $min = $value;
            }
            if (($max === null) || ($value > $max)) {
                $max = $value;
            }
            $total += $value;
            $count++;
        }

        if ($count > 0) {
            $mean = $total / $count;
        } else {
            $mean = null;
        }

        if ($count > 9) {
            $sqdiff = 0;
            foreach ($values as $value) {
                #echo $value-$mean . "\n";
                $sqdiff += pow(2, ($value - $mean));
            }
            $stddev = sqrt($sqdiff / $count);
            #echo $sqdiff .','. $stddev;
        } else {
            $stddev = null;
        }
        sort($values);
        if ($count % 2) {
            $median = ($values[floor($count / 2)] + $values[ceil($count / 2)]) / 2;
            $lq = ($values[floor($count / 4)] + $values[ceil($count / 4)]) / 2;
            $uq = ($values[floor($count * 3 / 4)] + $values[ceil($count * 3 / 4)]) / 2;
        } else {
            $medCount = ($count - 1) / 2 + 1;
            $median = $values[$medCount];
            $lq = $values[$medCount / 2];
            $uq = $values[$medCount * 3 / 2];
        }

        return (object) [
                    'min' => $min,
                    'max' => $max,
                    'mean' => $mean,
                    'count' => $count,
                    'median' => $median,
                    'stddev' => $stddev,
                    'lq' => $lq,
                    'uq' => $uq,
                    'iqr' => $uq - $lq
        ];
    }

}

class NarrativeProcessor extends DataProcessor {

    public function histogram_shared($options) {

        // Read in and prepare narratives and workspaces.
        // $narratives2 = $this->loadData($this->sources . '/narratives2.json');
        $narrativeData = $this->loadData($this->sources . '/ws_object_list.json');
        $narrativeHeader = $this->loadData($this->sources . '/header_ws_object_list.json');
        $genTime = new DateTime($narrativeHeader->last_modified, new DateTimezone('UTC'));
        // $narrativeData = $narratives2->by_workspace;

        $binCount = $options->bins;
        $userValue = @$options->uservalue;

        $i = 0;
        $withMeta = 0;
        $matched = 0;
        $nonmatched = 0;
        $narratives = (object) [];
        foreach ($narrativeData as $wsObjId => $rec) {
            // Convert metadata.
            if (property_exists($rec, 'meta')) {
                $meta = $rec->meta;

                if (property_exists($meta, 'job_info')) {
                    $rec->meta->job_info = json_decode($rec->meta->job_info);
                }

                if (property_exists($meta, 'methods')) {
                    $rec->meta->methods = json_decode($rec->meta->methods);
                    $withMeta++;

                    $idParts = preg_match('/^ws\.(.+?)\.obj\.(.+?)$/', $wsObjId, $matches);

                    # vain, I know: but refs are prettier.
                    $ref = $matches[1] . '/' . $matches[2];
                    $narratives->{$ref} = $rec;
                }
            }
            $i++;
        }

        $narrativeWorkspaces = [];
        $workspaces = $this->loadData($this->sources . '/ws_data.json');

        $good = 0;
        $temp = 0;
        $badid = 0;
        $missingprop = 0;
        $nometa = 0;
        $matched = 0;
        $nonmached = 0;

        $goodNarratives = [];
        foreach ($workspaces as $id => $workspace) {
            if (property_exists($workspace, 'meta')) {
                $meta = $workspace->meta;
                if ((property_exists($meta, 'narrative')) &&
                        (property_exists($meta, 'narrative_nice_name')) &&
                        (property_exists($meta, 'is_temporary'))) {
                    if (preg_match('/^\d+$/', $meta->narrative)) {
                        if ($meta->is_temporary != 'true') {
                            // found one!
                            $good++;

                            $ref = $id . '/' . $meta->narrative;

                            if (property_exists($narratives, $ref)) {
                                $matched++;
                                array_push($goodNarratives, (object) [
                                            'workspace' => $workspace,
                                            'narrative' => $narratives->{$ref}
                                ]);
                            } else {
                                $nonmatched++;
                            }
                        } else {
                            $temp++;
                        }
                    } else {
                        $badid++;
                    }
                } else {
                    $missingprop++;
                }
            } else {
                $nometa++;
            }
        }

        // Now we count up the users
        $usersMap = [];
        // Need a good method of throwing out outliers. For now just manually
        // remove users who use their account for testing.
        $remove = ['brettin', 'jimdavis2'];
        $remove = [];
        foreach ($goodNarratives as $nar) {
            $username = $nar->workspace->owner;
            if (array_search($username, $remove) !== false) {
                continue;
            }
            if (!array_key_exists($username, $usersMap)) {
                $usersMap[$username] = 0;
            }
            $usersMap[$username] ++;
        }


        // Now, from the narratives, we create a set of all narratives which are shared. 
        // Rather, we just need to count them, 
        /*
          10 r  read
          20 w  write
          30 a  admin
         */
        $usersSharing = (object) [];
        foreach ($goodNarratives as $narrative) {
            $perms = $narrative->workspace->shdwith;
            foreach ($perms as $username => $perm) {
                if ($username != '*') {
                    // tho' it appears that the '*' global user is not placed in the 
                    // data file.
                    if (!property_exists($usersSharing, $username)) {
                        $usersSharing->{$username} = 0;
                    }
                }
                $usersSharing->{$username} ++;
            }
        }


        $values = [];
        foreach ($usersSharing as $username => $count) {
            array_push($values, $count);
        }

        $bins = $this->numBins2((object) [
                    'binCount' => $binCount,
                    'values' => $values
        ]);
        return (object) [
                    'meta' => (object) [
                        'generated' => time(),
                        'originalGenerated' => $genTime->getTimestamp()
                    ],
                    'histogram' => $bins,
                    'summary' => $this->calc_summary_stats($values)
        ];
    }

    /*
      Count how narratives that are shared by each user.
      Count narratives by owner with one or more permissions to another user.
     */

    public function histogram_sharing($options) {

        // Read in and prepare narratives and workspaces.
        $narrativeData = $this->loadData($this->sources . '/ws_object_list.json');
        $narrativeHeader = $this->loadData($this->sources . '/header_ws_object_list.json');
        $genTime = new DateTime($narrativeHeader->last_modified, new DateTimezone('UTC'));

        $binCount = $options->bins;
        $userValue = @$options->uservalue;

        $i = 0;
        $withMeta = 0;
        $matched = 0;
        $nonmatched = 0;
        $narratives = (object) [];
        foreach ($narrativeData as $wsObjId => $rec) {
            // Convert metadata.
            if (property_exists($rec, 'meta')) {
                $meta = $rec->meta;

                if (property_exists($meta, 'job_info')) {
                    $rec->meta->job_info = json_decode($rec->meta->job_info);
                }

                if (property_exists($meta, 'methods')) {
                    $rec->meta->methods = json_decode($rec->meta->methods);
                    $withMeta++;

                    $idParts = preg_match('/^ws\.(.+?)\.obj\.(.+?)$/', $wsObjId, $matches);

                    # vain, I know: but refs are prettier.
                    $ref = $matches[1] . '/' . $matches[2];
                    $narratives->{$ref} = $rec;
                }
            }
            $i++;
        }

        $narrativeWorkspaces = [];
        $workspaces = $this->loadData($this->sources . '/ws_data.json');

        $good = 0;
        $temp = 0;
        $badid = 0;
        $missingprop = 0;
        $nometa = 0;
        $matched = 0;
        $nonmached = 0;

        $goodNarratives = [];
        foreach ($workspaces as $id => $workspace) {
            if (property_exists($workspace, 'meta')) {
                $meta = $workspace->meta;
                if ((property_exists($meta, 'narrative')) &&
                        (property_exists($meta, 'narrative_nice_name')) &&
                        (property_exists($meta, 'is_temporary'))) {
                    if (preg_match('/^\d+$/', $meta->narrative)) {
                        if ($meta->is_temporary != 'true') {
                            // found one!
                            $good++;

                            $ref = $id . '/' . $meta->narrative;

                            if (property_exists($narratives, $ref)) {
                                $matched++;
                                array_push($goodNarratives, (object) [
                                            'workspace' => $workspace,
                                            'narrative' => $narratives->{$ref}
                                ]);
                            } else {
                                $nonmatched++;
                            }
                        } else {
                            $temp++;
                        }
                    } else {
                        $badid++;
                    }
                } else {
                    $missingprop++;
                }
            } else {
                $nometa++;
            }
        }

        // Now we count up the users
        $usersMap = [];
        // Need a good method of throwing out outliers. For now just manually
        // remove users who use their account for testing.
        $remove = ['brettin', 'jimdavis2'];
        $remove = [];
        foreach ($goodNarratives as $nar) {
            $username = $nar->workspace->owner;
            if (array_search($username, $remove) !== false) {
                continue;
            }
            $perms = $nar->workspace->shdwith;
            if ($perms && count((array) $perms) > 0) {
                if (!array_key_exists($username, $usersMap)) {
                    $usersMap[$username] = 0;
                }

                $usersMap[$username] ++;
            }
        }

        $values = [];
        $test = [];
        foreach ($usersMap as $username => $count) {
            array_push($values, $count);
            array_push($test, (object) ['username' => $username, 'count' => $count]);
        }

        usort($test, function($a, $b) {
            return strcasecmp($a->username, $b->username);
        });
        #foreach ($test as $t) {
        #   echo $t->username . ' : ' . $t->count . "\n";         
        #}

        $bins = $this->numBins2((object) [
                    'binCount' => $binCount,
                    'values' => $values
        ]);
        return (object) [
                    'meta' => (object) [
                        'generated' => time(),
                        'originalGenerated' => $genTime->getTimestamp()
                    ],
                    'histogram' => $bins,
                    'summary' => $this->calc_summary_stats($values)
        ];
    }

    public function histogram($options) {

        // Read in and prepare narratives and workspaces.
        $narrativeData = $this->loadData($this->sources . '/ws_object_list.json');
        $narrativeHeader = $this->loadData($this->sources . '/header_ws_object_list.json');
        $genTime = new DateTime($narrativeHeader->last_modified, new DateTimezone('UTC'));

        $binCount = $options->bins;
        $userValue = @$options->uservalue;

        $i = 0;
        $withMeta = 0;
        $matched = 0;
        $nonmatched = 0;
        $narratives = (object) [];
        foreach ($narrativeData as $wsObjId => $rec) {
            // Convert metadata.
            if (property_exists($rec, 'meta')) {
                $meta = $rec->meta;

                if (property_exists($meta, 'job_info')) {
                    $rec->meta->job_info = json_decode($rec->meta->job_info);
                }

                if (property_exists($meta, 'methods')) {
                    $rec->meta->methods = json_decode($rec->meta->methods);
                    $withMeta++;

                    $idParts = preg_match('/^ws\.(.+?)\.obj\.(.+?)$/', $wsObjId, $matches);

                    # vain, I know: but refs are prettier.
                    $ref = $matches[1] . '/' . $matches[2];
                    $narratives->{$ref} = $rec;
                }
            }
            $i++;
        }

        $narrativeWorkspaces = [];
        $workspaces = $this->loadData($this->sources . '/ws_data.json');

        $good = 0;
        $temp = 0;
        $badid = 0;
        $missingprop = 0;
        $nometa = 0;
        $matched = 0;
        $nonmached = 0;

        $goodNarratives = [];
        foreach ($workspaces as $id => $workspace) {
            if (property_exists($workspace, 'meta')) {
                $meta = $workspace->meta;
                if ((property_exists($meta, 'narrative')) &&
                        (property_exists($meta, 'narrative_nice_name')) &&
                        (property_exists($meta, 'is_temporary'))) {
                    if (preg_match('/^\d+$/', $meta->narrative)) {
                        if ($meta->is_temporary != 'true') {
                            // found one!
                            $good++;

                            $ref = $id . '/' . $meta->narrative;

                            if (property_exists($narratives, $ref)) {
                                $matched++;
                                array_push($goodNarratives, (object) [
                                            'workspace' => $workspace,
                                            'narrative' => $narratives->{$ref}
                                ]);
                            } else {
                                $nonmatched++;
                            }
                        } else {
                            $temp++;
                        }
                    } else {
                        $badid++;
                    }
                } else {
                    $missingprop++;
                }
            } else {
                $nometa++;
            }
        }

        // Now we count up the users
        $usersMap = [];
        // Need a good method of throwing out outliers. For now just manually
        // remove users who use their account for testing.
        $remove = ['brettin', 'jimdavis2'];
        $remve = [];
        foreach ($goodNarratives as $nar) {
            $username = $nar->workspace->owner;
            if (array_search($username, $remove) !== false) {
                continue;
            }
            if (!array_key_exists($username, $usersMap)) {
                $usersMap[$username] = 0;
            }
            $usersMap[$username] ++;
        }
        $values = [];
        foreach ($usersMap as $username => $count) {
            array_push($values, $count);
        }
        if ($userValue) {
            array_push($values, $userValue);
        }

        $bins = $this->numBins2((object) [
                    'binCount' => $binCount,
                    'values' => $values
        ]);
        return (object) [
                    'meta' => (object) [
                        'generated' => time(),
                        'originalGenerated' => $genTime->getTimestamp()
                    ],
                    'histogram' => $bins,
                    'summary' => $this->calc_summary_stats($values)
        ];
    }

    public function numBins2($cfg) {
        $values = $cfg->values;
        if (property_exists($cfg, 'binCount')) {
            $binResult = $this->autoBin2($values, $cfg->binCount);
            $bins = $binResult->bins;
        } else {
            $bins = $cfg->bins;
            $binResult = (object) [
                        'bins' => $bins,
                        'binSize' => null
            ];
        }
        $above = [];
        $below = [];

        $valueCount = count($values);
        for ($i = 0; $i < $valueCount; $i++) {
            $value = $values[$i];

            if ($value < $binResult->lowerBound) {
                array_push($below, $value);
            } else if ($value > $binResult->upperBound) {
                array_push($above, $value);
            } else
                foreach ($binResult->bins as $bin) {
                    if ($value >= $bin->lower &&
                            ( ($bin->upperInclusive && $value <= $bin->upper) ||
                            ($value < $bin->upper) )) {
                        $bin->count++;
                        break;
                    }
                }
        }

        // silly. used for anything?
        $binResult->binCount = count($binResult->bins);

        // Add below and above if necessary.
        if (count($below) > 0) {
            
        }
        if (count($above) > 0) {
            array_push($binResult->bins, (object) [
                        'count' => count($above),
                        'label' => '(' . $binResult->upperBound . '-' . max($above) . ']',
                        'upperInclusive' => true,
                        'lower' => $binResult->upperBound,
                        'upper' => max($above)
            ]);
        }

        # extract labels and bin counts.
        $labels = array_map(function ($x) {
            return $x->label;
        }, (array) $binResult->bins);

        $binned = array_map(function ($x) {
            return $x->count;
        }, (array) $binResult->bins);



        $binResult->binned = $binned;
        $binResult->labels = $labels;
        $binResult->below = $below;
        $binResult->above = $above;

        return $binResult;
    }

    public function summary_stats($options) {

        // Read in and prepare narratives and workspaces.

        $narrativeData = $this->loadData($this->sources . '/ws_object_list.json');
        $narrativeHeader = $this->loadData($this->sources . '/header_ws_object_list.json');
        $genTime = new DateTime($narrativeHeader->last_modified, new DateTimezone('UTC'));

        @$userValue = $options->uservalue;

        $i = 0;
        $withMeta = 0;
        $matched = 0;
        $nonmatched = 0;
        $narratives = (object) [];
        foreach ($narrativeData as $wsObjId => $rec) {
            // Convert metadata.
            if (property_exists($rec, 'meta')) {
                $meta = $rec->meta;

                if (property_exists($meta, 'job_info')) {
                    $rec->meta->job_info = json_decode($rec->meta->job_info);
                }

                if (property_exists($meta, 'methods')) {
                    $rec->meta->methods = json_decode($rec->meta->methods);
                    $withMeta++;

                    $idParts = preg_match('/^ws\.(.+?)\.obj\.(.+?)$/', $wsObjId, $matches);

                    # vain, I know: but refs are prettier.
                    $ref = $matches[1] . '/' . $matches[2];
                    $narratives->{$ref} = $rec;
                }
            }
            $i++;
        }

        $narrativeWorkspaces = [];
        $workspaces = $this->loadData($this->sources . 'ws_data.json');

        $good = 0;
        $temp = 0;
        $badid = 0;
        $missingprop = 0;
        $nometa = 0;
        $matched = 0;
        $nonmached = 0;

        $goodNarratives = [];
        foreach ($workspaces as $id => $workspace) {
            if (property_exists($workspace, 'meta')) {
                $meta = $workspace->meta;
                if ((property_exists($meta, 'narrative')) &&
                        (property_exists($meta, 'narrative_nice_name')) &&
                        (property_exists($meta, 'is_temporary'))) {
                    if (preg_match('/^\d+$/', $meta->narrative)) {
                        if ($meta->is_temporary != 'true') {
                            // found one!
                            $good++;

                            $ref = $id . '/' . $meta->narrative;

                            if (property_exists($narratives, $ref)) {
                                $matched++;
                                array_push($goodNarratives, (object) [
                                            'workspace' => $workspace,
                                            'narrative' => $narratives->{$ref}
                                ]);
                            } else {
                                $nonmatched++;
                            }
                        } else {
                            $temp++;
                        }
                    } else {
                        $badid++;
                    }
                } else {
                    $missingprop++;
                }
            } else {
                $nometa++;
            }
        }

        // Now we count up the users
        $usersMap = [];
        // Need a good method of throwing out outliers. For now just manually
        // remove users who use their account for testing.
        $remove = ['brettin', 'jimdavis2'];
        foreach ($goodNarratives as $nar) {
            $username = $nar->workspace->owner;
            if (array_search($username, $remove) !== false) {
                continue;
            }
            if (!array_key_exists($username, $usersMap)) {
                $usersMap[$username] = 0;
            }
            $usersMap[$username] ++;
        }
        $users = [];
        $values = [];
        foreach ($usersMap as $username => $count) {
            array_push($users, (object) [
                        'username' => $username, 'count' => $count
            ]);
            array_push($values, $count);
        }
        if ($userValue) {
            array_push($users, (object) [
                        'username' => 'thisuser',
                        'count' => $userValue
            ]);
            array_push($values, $userValue);
        }
        usort($users, function ($a, $b) {
            if ($a->count > $b->count) {
                return -1;
            } elseif ($a->count < $b->count) {
                return 1;
            } else {
                return 0;
            }
        });
        $bins = $this->calc_summary_stats($values);
        return $bins;
    }

}
