<?php

/*
  Getting values from structures (arrays, objects) with default values rather than
  checking for presence of the key first.
 */

$noteAppName = '';

function set_note_app_name($appName) {
    global $noteAppName;
    $noteAppName = $appName;
}

function note($msg) {
    global $noteAppName;
    $now = new DateTime('now', new DateTimeZone('utc'));
    echo $now->format('c') . ' : ' . $noteAppName . ' : ' . $msg . "\n";
}

function array_get($array, $key, $default = false) {
    if (isset($array[$key])) {
        return $array[$key];
    } else {
        return $default;
    }
}

function array_incr(&$arr, $key, $value = 1) {
    if (isset($arr[$key])) {
        $arr[$key] += $value;
    } else {
        $arr[$key] = $value;
    }
}

function property_get($obj, $key, $default = false) {
    if (property_exists($obj, $key)) {
        return $obj->{$key};
    } else {
        return $default;
    }
}

function prop_incr($obj, $key, $value = 1) {
    if (property_exists($obj, $key)) {
        $obj->{$key} += $value;
    } else {
        $obj->{$key} = $value;
    }
}

function obj_get($obj, $key, $default = false) {
    if (property_exists($obj, $key)) {
        return $obj->{$key};
    } else {
        return $default;
    }
}

/*
  Functions for dealing with data inside the data structure that the json api knows about. Handy especially for reaching
  deep into a structure and for stress-free missing properties.
 */

function json_get($json, $keys, $defaultValue = null) {
    if (is_string($keys)) {
        if (is_object($json)) {
            if (property_exists($json, $keys)) {
                return $json->{$keys};
            } else {
                return $defaultValue;
            }
        } else if (is_array($json)) {
            if (array_key_exists($keys, $json)) {
                return $json[$keys];
            } else {
                return $defaulValue;
            }
        } else {
            throw new Exception('Sorry, cannot get property of this value');
        }
    } else {
        $total = count($keys);
        for ($i = 0; $i < $total; $i++) {
            $key = $keys[$i];
            if (is_object($json)) {
                if (property_exists($json, $key)) {
                    # if (is_string($key)) {
                    $json = $json->{$key};
                } else {
                    return $defaultValue;
                }
            } else if (is_array($json)) {
                if (array_key_exists($key, $json)) {
                    $json = $json[$key];
                } else {
                    return $defaultValue;
                }
            } else {
                throw new Exception('Sorry, cannot get property of this value');
            }
        }
        return $json;
    }
}

function json_exists($json, $keys) {
    if (is_string($keys)) {
        if (is_object($json)) {
            if (property_exists($json, $keys)) {
                return true;
            } else {
                return false;
            }
        } else if (is_array($json)) {
            if (array_key_exists($keys, $json)) {
                return true;
            } else {
                return false;
            }
        } else {
            throw new Exception('Sorry, cannot get property of this value');
        }
    } else {
        $total = count($keys);
        for ($i = 0; $i < $total; $i++) {
            $key = $keys[$i];
            if (is_object($json)) {
                if (property_exists($json, $key)) {
                    # if (is_string($key)) {
                    $json = $json->{$key};
                } else {
                    return false;
                }
            } else if (is_array($json)) {
                if (array_key_exists($key, $json)) {
                    $json = $json[$key];
                } else {
                    return false;
                }
            } else {
                throw new Exception('Sorry, cannot get property of this value');
            }
        }
        return true;
    }
}

function json_set(&$node, $keys, $value) {
    $key = array_shift($keys);

    # echo "KEY: $key, " . count($keys) . "=" . $value . "<br>";
    if (count($keys) == 0) {
        # leaf node
        if (is_object($node)) {
            $node->{$key} = $value;
            return true;
        } else if (is_array($node)) {
            $node[$key] = $value;
            return true;
        } else {
            throw new Exception('Sorry, cannot get property of this value');
        }
    } else {
        if (is_object($node)) {
            # echo 'OBJECT<br>';
            if (!property_exists($node, $key)) {
                if (is_int($key)) {
                    $node->{$key} = [];
                } else {
                    $node->{$key} = (object) [];
                }
            }
            json_set($node->{$key}, $keys, $value);
        } else if (is_array($node)) {
            if (!array_key_exists($key, $node)) {
                if (is_int($key)) {
                    $node->{$key} = [];
                } else {
                    $node->{$key} = (object) [];
                }
            } else {
                #echo 'FOUND IT ' . $key . '<br>';
            }
            json_set($node[$key], $keys, $value);
        } else {
            throw new Exception('Sorry, cannot get property of this value');
        }
    }
}

function json_subst(&$json, $original = false) {
    if (!$original) {
        $original = $json;
    }
    switch (gettype($json)) {
        case 'object':
            foreach ($json as $key => &$value) {
                if (is_string($value)) {
                    if (mb_substr($value, 0, 1) == '@') {
                        // look it up and substitute
                        // var_dump($value);
                        // $k = ["hi"];
                        $k = preg_split('/\./', mb_substr($value, 1));
                        $value = json_get($original, $k);
                    }
                } else {
                    json_subst($value, $original);
                }
            }
            break;
        case 'array':
            foreach ($json as &$arrayValue) {
                if (is_string($arrayValue)) {
                    if (mb_substr($arrayValue, 0, 1) == '@') {
                        $k = preg_split('/\./', mb_substr($arrayValue, 1));
                        $arrayValue = json_get($original, $k);
                    }
                } else {
                    json_subst($arrayValue, $original);
                }
            }
            break;
    }
}

/*
  Min/Max functions per type that "do the right thing" in the presence of null.
 */

function dateMin($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if ($d->getTimestamp() < $e->getTimestamp()) {
            return $d;
        } else {
            return $e;
        }
    }
}

function dateMax($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if ($d->getTimestamp() > $e->getTimestamp()) {
            return $d;
        } else {
            return $e;
        }
    }
}

function numMin($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if ($d < $e) {
            return $d;
        } else {
            return $e;
        }
    }
}

function numMax($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if ($d > $e) {
            return $d;
        } else {
            return $e;
        }
    }
}

function strMin($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if (strcasecmp($d, $e) < 0) {
            return $d;
        } else {
            return $e;
        }
    }
}

function strMax($d, $e) {
    if ($d === null) {
        return $e;
    } else if ($e === null) {
        return $d;
    } else {
        if (strcasecmp($d, $e) > 0) {
            return $d;
        } else {
            return $e;
        }
    }
}
