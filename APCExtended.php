<?php
/**
 * APC Extended - Static class to extend APC functions
 *
 * @package     APC Extended
 * @author      Nick Adams <nick89@zoho.com>
 * @copyright   2012 Nick Adams.
 * @link        http://iamtelephone.com
 * @license     http://opensource.org/licenses/MIT MIT License
 * @version     0.1.0
 */
namespace Telephone\Cache;

class APC
{
    /**
     * Check whether APC is enabled and at least v3.1.1
     *
     * @return boolean  True if APC is enabled and >= v3.1.1
     */
    public static function enabled()
    {
        if (ini_get('apc.enabled') && (phpversion('apc') >= '3.1.1')) {
            return true;
        }
        return false;
    }

    /**
     * Check whether APC key exists
     *
     * @return string  True if variable exists
     */
    public static function exists($key)
    {
        if (apc_exists($key)) {
            return true;
        }
        return false;
    }

    /**
     * Retrieve data/value stored in APC
     * - Object is returned, unless an array was stored
     *
     * @param  string $key           Key name
     * @return array|boolean|object  Stored data/value on success
     */
    public static function fetch($key)
    {
        if (self::exists($key)) {
            $apc = json_decode(apc_fetch($key));
            // check for array
            if ($apc->type == 'array') {
                $apc = json_decode(apc_fetch($key), true);
                return $apc['data'];
            }
            return $apc->data;
        }
        return false;
    }

    /**
     * Store data/value within APC
     * - If $overwrite is off, the function will perfrom like apc_add() and only
     *   store if the key does not exist
     *
     * @param  string              $key        Key name
     * @param  string|array|object $value      Variable to store
     * @param  integer             $ttl        Seconds until expiry
     * @param  boolean             $overwrite  If false and APC key exists
     *                                         funtion will not overwrite
     * @return boolean                         True on success
     */
    public static function store($key, $variable, $ttl = 0, $overwrite = false)
    {
        if (self::exists($key) && !$overwrite) {
            return false;
        }

        $apc = array('data' => $variable);
        ($ttl !== 0) ? $apc['ttl'] = (time() + $ttl) : $apc['ttl'] = 0;
        $apc['type'] = gettype($variable);
        return apc_store($key, json_encode($apc), $ttl);
    }

    /**
     * Update APC key keep original TTL intact
     *
     * @param  string         $key       Key name
     * @param  array          $variable  New variable to update
     * @return boolean|string            Updated value or true on success
     */
    public static function update($key, $variable)
    {
        if (self::exists($key)) {
            $apc = json_decode(apc_fetch($key));
            // increase/decrease key
            if (strpos($variable, ':') !== false) {
                $val = substr($variable, 4);
                (strpos($variable, 'dec:') !== false) ? $apc->data -= $val
                    : $apc->data += $val;
                // return new value
                if (self::store($key, $apc->data, self::ttl(false, $apc->ttl),
                    true)
                ) {
                    return $apc->data;
                }
            }
            // update key
            else {
                $apc->data = $variable;
                return self::store($key, $apc->data,
                    self::ttl(false, $apc->ttl), true);
            }
        }
        return false;
    }

    /**
     * Delete APC key
     *
     * @param  string  $key  Key name
     * @return boolean       True on success
     */
    public static function delete($key)
    {
        if (self::exists($key)) {
            return apc_delete($key);
        }
        return false;
    }

    /**
     * Increase APC key by defined integer (default = 1)
     *
     * @param  string         $key        Key name
     * @param  integer        $increment  Integer to increase key by
     * @return boolean|string             Return updated value on success
     */
    public static function inc($key, $increment = 1)
    {
        return self::update($key, 'inc:' . $increment);
    }

    /**
     * Decrease APC key by defined integer (default = 1)
     *
     * @param  string         $key        Key name
     * @param  integer        $increment  Integer to decrease key by
     * @return boolean|string             Return updated value on success
     */
    public static function dec($key, $increment = 1)
    {
        return self::update($key, 'dec:' . $increment);
    }

    /**
     * Return time till expiry in either seconds or Unix timestamp
     * - Set $ttl to 'time' to return Unix timestamp
     *
     * @param  string          $key  APC key
     * @param  string|integer  $ttl  Define ttl variable instead of fetching
     * @return boolean|integer       TTL in seconds or Unix timestamp
     *                               - 0 = no ttl
     */
    public static function ttl($key = false, $ttl = null)
    {
        if ($key !== false) {
            if (self::exists($key)) {
                $apc = json_decode(apc_fetch($key));
                // return Unix timestamp
                if ($ttl == 'time') {
                    return $apc->ttl;
                }
                $ttl = $apc->ttl;
            } else {
                return false;
            }
        }
        // return seconds or 0 (integer)
        return ($ttl == 0) ? 0 : ($ttl - time());
    }

    /**
     * Fetch APC key/s in 'user' scope based on a PCRE
     * (Perl Compatible Regular Expression)
     *
     * @param  string         $regex        PCRE pattern to search for
     *                                      - E.g '/^match$/'
     * @param  boolean        $returnValue  True to return an object with
     *                                      matched key/s and values
     * @return boolean|object               Object with matched key/s
     */
    public static function rSearch($regex, $returnValue = false)
    {
        /**
         * PHP memory can easily be eaten up if returning the value of hundreds
         * of keys
         */
        $search = new \stdClass();
        if ($returnValue) {
            $iterator = new \APCIterator('user', $regex, APC_ITER_KEY
                + APC_ITER_VALUE);
            foreach ($iterator as $val) {
                $apc = json_decode($val['value']);
                $search->$val['key'] = $apc->data;
            }
        } else {
            $iterator = new \APCIterator('user', $regex, APC_ITER_KEY);
            foreach ($iterator as $val) {
                $search->$val['key'] = $val['key'];
            }
        }

        if ($returnValue && empty($search)) {
            return false;
        }
        return $search;
    }

    /**
     * Delete APC key/s in 'user' scope based on a PCRE
     * (Perl Compatible Regular Expression)
     * - Function only removes non-expired keys
     *
     * @param  string  $regex  PCRE pattern to search for. E.g '/^match$/'
     * @return integer         Number of keys removed
     */
    public static function rDelete($regex)
    {
        $keys  = (array) self::rSearch($regex);
        $check = apc_delete($keys);
        if (is_array($check)) {
            if (apc_delete($keys)) {
                $return = true;
            }
        } elseif ($check === true) {
            $return = true;
        }
        return (isset($return)) ? count($keys) : 0;
    }

    /**
     * Iterate through APC 'user' cache and remove expired entries
     *
     * @return integer  Number of keys removed
     */
    public static function expired()
    {
        // APC Iterator will not return expired. Use apc_cache_info()
        $apc = apc_cache_info('user');
        $time = time();
        $a = 0;
        foreach ($apc['cache_list'] as $val) {
            if ($val['ttl'] && ($val['ttl'] + $val['creation_time']) < $time) {
                if (apc_delete($val['info'])) {
                    $a++;
                }
            }
        }
        return $a;
    }

    /**
     * Iterate through APC and remove unpopular files/keys
     * -Function only removes non-expired files/keys
     *
     * @param  string  $type  Define which cache to purge: 'user' or 'opcode'
     *                        - Default = 'user'
     * @param  integer $hits  Minimum number of hits. If item has been serverd
     *                        less than $hits, delete from cache
     *                        - Default = 10
     * @return integer        Number of files/keys removed
     */
    public static function purge($type = 'user', $hits = 10)
    {
        $a = 0;
        // iterate files
        if ($type == 'opcode') {
            $info = apc_cache_info('opcode');
            foreach ($info['cache_list'] as $file) {
                if ($file['num_hits'] <= $hits) {
                    /**
                     * apc_delete_file will not remove expired files
                     * In apc.ini, set "apc.ttl" to '0' to avoid this problem
                     */
                    if (apc_delete_file($file['filename'])) {
                        $a++;
                    }
                }
            }
        }
        // iterate user cache
        elseif ($type == 'user') {
            $iterator = new \APCIterator('user', null, APC_ITER_NUM_HITS);
            foreach ($iterator as $key => $val) {
                if ($val['num_hits'] <= $hits) {
                    if (apc_delete($key)) {
                        $a++;
                    }
                }
            }
        }
        return $a;
    }

    /**
     * Clear APC cache
     *
     * @param  string  $type  Define cache to clear: 'user' or 'opcode'
     *                        - Default = 'user'
     * @return boolean        True on success
     */
    public static function flush($type = 'user')
    {
        if ($type == 'user' || $type == 'opcode') {
            return apc_clear_cache($type);
        } elseif ($type == 'all') {
            if ((apc_clear_cache('user')) && (apc_clear_cache('opcode'))) {
                return true;
            }
            return false;
        }
    }

    /**
     * Return the percentage of memory free within APC
     *
     * @return float  Percentage of memory free (to one decimal point)
     */
    public static function freeMemory()
    {
        $info = apc_sma_info();
        return round((($info['avail_mem'] / ($info['num_seg']
            * $info['seg_size'])) * 100), 1);
    }
}