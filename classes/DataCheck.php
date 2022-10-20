<?php


namespace Stanford\ProjTRIOSMigrator;



class DataCheck
{

    //TODO: this should be exposed in config so that it can be done in fly.
    private static $checker = array(
        //June: converted to text so that Jaynelle can convert later
        //"missed_school"=>'/(?<find>\b([0-9]|1[0-9]|20)\b)$/',  //only allow numbers (since it's an integer field
        //'sympsib_v2'      => '/(?<find>\b([01]\b))/',
        //'gi_new'          => '/(?<find>^([0-9]|[1-9]\d|100)$)/',
        'day_abscess'     => '/(?<find>\b([2|3|98|99]\b))/',
        'day_abscess_v2'  => '/(?<find>\b([2|3|98|99]\b))/',
        'anastomotic_leak_30_day' => '/(?<find>\b([0|1|98]\b))/',
        'ods_condition'           => '/(?<find>\b([0|1|2|3|4|5]\b))/',
        'ods_pressure'           => '/(?<find>\b([0|1|2|3|4|5]\b))/',
        'wexner_gas'           => '/(?<find>\b([0|1|2|3|4|5]\b))/',
        'h_height'             => '/?<find>\b^[0-9]*\.[0-9]{2}$\b/',
        'h_weight'             => '/?<find>\b^[0-9]*\.[0-9]{2}$\b/',
    );

    public static function valueValid($field, $val) {
        global $module;

        if (array_key_exists($field, self::$checker)) {
            //$module->emDebug("this key is about to be checked:  $field with regex: " . self::$checker[$field]);

            $reg = self::$checker[$field];
            preg_match_all($reg, trim($val), $matches, PREG_SET_ORDER, 0);

            $found = ($matches[0])['find'];
            if ($found != '') {
                return true;
            } else {
                $module->emDebug("DATA CHECKER FAIL: this value for field $field is not valid. NOT ENTERED:  <$val>");
                //$module->emDebug(isset($matches[0]['find']), $matches);
                return false;
            }
        }
        return true;
    }
}