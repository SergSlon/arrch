<?php

namespace Arrch;

/**
 * Arrch
 * 
 * @copyright  2012 - 2013
 * @author     Michael Giuliana
 * @link       http://rpnzl.com
 * @version    1.1
 * @since      2013/2
 * @license    MIT License - http://opensource.org/licenses/MIT
 * 
 * A small library of array search and sort methods. Perhaps most
 * useful paired with a simple, flat-file cache system, Arrch
 * allows psuedo-queries of large arrays.
 */
class Arrch
{
    /**
     * @var  arr  $defaults  The find() method default values.
     */
    public static $defaults = array(
        'where'         => array(),
        'limit'         => 0,
        'offset'        => 0,
        'sort_key'      => null,
        'sort_order'    => 'ASC'
    );

    /**
     * @var  str  $key_split  The string to split keys when checking a deep multidimensional array value.
     */
    public static $key_split = '.';

    /**
     * 
     */
    public static $operators = array('=', '==', '===', '!=', '!==', '>', '<', '>=', '<=', '~');

    /**
     * Find
     * 
     * This method combines the functionality of the
     * where() and sort() methods, with additional
     * limit and offset parameters. Returns an array of matching
     * array items. Will only sort if a sort key is set.
     * 
     * @param   arr    &$data     The array of objects or associative arrays to search.
     * @param   arr    $options   The query options, see Arrch::$defaults.
     * @param   misc   $key       An item's key or index value.
     * @return  arr    The result array.
     */
    public static function find(array $data, array $options = array(), $key = 'all')
    {
        $options = array_merge(static::$defaults, $options);
        $data = static::where($data, $options['where']);
        $data = $options['sort_key'] ? static::sort($data, $options['sort_key'], $options['sort_order']) : $data;
        $data = array_slice($data, $options['offset'], ($options['limit'] == 0) ? null : $options['limit'], true);
        switch ($key) {
            case 'all':
                return $data;
                break;
            case 'first':
                return array_shift($data);
                break;
            case 'last':
                return array_pop($data);
                break;
            default:
                return isset($data[$key]) ? $data[$key] : null;
                break;
        }
    }

    /**
     * Sort
     * 
     * Sorts an array of objects by the specified key.
     * 
     * @param   arr   &$data   The array of objects or associative arrays to sort.
     * @param   str   $key     The object key or key path to use in sort evaluation.
     * @param   str   $order   ASC or DESC.
     * @return  arr   The result array.
     */
    public static function sort(array $data, $key, $order = null)
    {
        uasort($data, function ($a, $b) use ($key) {
            $a_val = Arrch::extractValues($a, $key);
            $b_val = Arrch::extractValues($b, $key);

            // Strings
            if (is_string($a_val) && is_string($b_val)) {
                return strcasecmp($a_val, $b_val);
            } else {
                if ($a_val == $b_val) {
                    return 0;
                } else {
                    return ($a_val > $b_val) ? 1 : -1;
                }
            }
        });

        if (strtoupper($order ?: Arrch::$defaults['sort_order']) == 'DESC') {
            $data = array_reverse($data, true);
        }

        return $data;
    }

    /**
     * Where
     * 
     * Evaluates an object based on an array of conditions
     * passed into the function, formatted like so...
     * 
     *      array( 'name', 'arlington' );
     *      array( 'name', '!=', 'arlington' );
     * 
     * @param   arr   &$data        The array of objects or associative arrays to search.
     * @param   arr   $conditions   The conditions to evaluate against.
     * @return  arr   The result array.
     */
    public static function where(array $data, array $conditions = array())
    {
        foreach ($conditions as $condition) {
            if (is_array($condition)) {
                array_map(function ($item, $key) use (&$data, $condition) {
                    $return = 0;
                    $operator = isset($condition[2]) ? $condition[1] : '===';
                    $search_value = isset($condition[2]) ? $condition[2] : $condition[1];
                    if (is_array($condition[0])) {
                        // array of keys
                        if (is_array($search_value)) {
                            // array of values
                            foreach ($condition[0] as $prop) {
                                $value = Arrch::extractValues($item, $prop);
                                foreach ($search_value as $query_val) {
                                    $return += Arrch::compare(array($value, $operator, $query_val)) ? 1 : 0;
                                }
                            }
                        } else {
                            // single value
                            foreach ($condition[0] as $prop) {
                                $value = Arrch::extractValues($item, $prop);
                                $return += Arrch::compare(array($value, $operator, $search_value)) ? 1 : 0;
                            }
                        }
                    } elseif (is_array($search_value)) {
                        // single key, array of query values
                        $value = Arrch::extractValues($item, $condition[0]);
                        foreach ($search_value as $query_val) {
                            $return += Arrch::compare(array($value, $operator, $query_val)) ? 1 : 0;
                        }
                    } else {
                        // single key, single value
                        $value = Arrch::extractValues($item, $condition[0]);
                        $return = Arrch::compare(array($value, $operator, $search_value));
                    }

                    // Unset
                    if (!$return) {
                        unset($data[$key]);
                    }
                }, $data, array_keys($data));
            }
        }
        return $data;
    }

    /**
     * Extract Values
     * 
     * Finds the requested value in a multidimensional
     * array or an object and returns it.
     * 
     * @param    mixed   $item   The item containing the value.
     * @param    str     $key    The key or key path to the desired value.
     * @return   mixed   The found value or null.
     */
    public static function extractValues($item, $key)
    {
        // Array of results
        $results = array();

        // Cast as array if object
        $item = is_object($item) ? (array) $item : $item;

        // Array of keys
        $keys = strstr($key, static::$key_split) ? explode(static::$key_split, $key) : array($key);
        $keys = array_filter($keys);

        // Key count
        $key_count = count($keys);

        // key count > 0
        if ($key_count > 0) {

                $curr_key = array_shift($keys);
                $child = array_shift(array_values($item));

                // it's an array
                if (is_array($item)) {
                    
                    // it has the key
                    if (array_key_exists($curr_key, $item)) {
                        $results = array_merge($results, static::extractValues($item[$curr_key], implode(static::$key_split, $keys)));

                    // else it's child has the key
                    } elseif ((is_array($child) || is_object($child)) && array_key_exists($curr_key, $child)) {
                        foreach ($item as $child) {
                            $results = array_merge($results, static::extractValues($child, $key));
                        }
                    }
                }

        // no key
        } else {

            // set to item or null
            $results[] = isset($item[$key]) ? $item[$key] : $item;
        }

        return $results;
    }

    /**
     * Compare
     * 
     * Runs comparison operations on an array of values
     * formatted like so...
     * 
     *      e.g. array( $value, $operator, $search_value )
     * 
     * Returns true or false depending on the outcome of the
     * requested comparative operation (<, >, =, etc..).
     * 
     * @param   arr   $array  The comparison array.
     * @return  bool  Whether the operation evaluates to true or false.
     */
    public static function compare(array $array)
    {
        $return = 0;
        foreach ($array[0] as $value) {
            if ($array[1] === '~') {
                // If the variables don't immediately match
                if ($value !== $array[2]) {
                    // strings
                    if (is_string($value) && is_string($array[2])) {
                        return stristr($value, $array[2]);
                    } elseif (is_array($value) && is_array($array[2])) {
                        //
                    } elseif (is_object($value) && is_object($array[2])) {
                        //
                    } elseif (gettype($value) !== gettype($array[2])) {
                        return false;
                    } else {
                        return false;
                    }
                } else {
                    return true;
                }
            } elseif (in_array($array[1], static::$operators)) {
                switch ($array[1]) {
                    case '=':
                        $return += ($value = $array[2]) ? 1 : 0;
                        break;
                    case '==':
                        $return += ($value == $array[2]) ? 1 : 0;
                        break;
                    case '===':
                        $return += ($value === $array[2]) ? 1 : 0;
                        break;
                    case '!=':
                        $return += ($value != $array[2]) ? 1 : 0;
                        break;
                    case '!==':
                        $return += ($value !== $array[2]) ? 1 : 0;
                        break;
                    case '>':
                        $return += ($value > $array[2]) ? 1 : 0;
                        break;
                    case '<':
                        $return += ($value < $array[2]) ? 1 : 0;
                        break;
                    case '>=':
                        $return += ($value >= $array[2]) ? 1 : 0;
                        break;
                    case '<=':
                        $return += ($value <= $array[2]) ? 1 : 0;
                        break;
                    default:
                        break;
                }
            }
        }
        return $return;
    }
}