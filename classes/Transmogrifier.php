<?php

namespace Stanford\ProjTRIOSMigrator;

use Exception;
use \REDCap;

class Transmogrifier {


    private $supported_custom = array(
        "splitName",       //split a single field into two fields. ex: name -> first_name + last_name
        "textToCheckbox",     //convert freetext to coded values in checkbox
        "checkboxToCheckbox",  //recode the values in a checkbox
        "recodeRadio",  //recode codes in radio
        "addToField",
        "fixDate",
        "checkboxToRadio",  //convert checkbox to radio codes
        "radioToCheckbox"  //convert radio to checkbox
    );


    // array with from_field and array as value with map
    private $modifier = array();

    ///make a second set of modifiers to handle the second set of custom fields
    private $modifier2 = array();

    public function __construct($mapper) {
        global $module;
        foreach ($mapper as $k => $v) {
            if (!empty($v['custom'])) {
                //$module->emDebug("doing $k with ". $v['custom']);
                if (!in_array($v['custom'],$this->supported_custom)) {
                    //$module->emDebug("doing $k with ". $v['custom'] . " : ". $v['from_field'], $v);
                    throw new Exception("Aborting migration!!!  Unsupported custom type for field [$k]: ".$v['custom'] );
                }

                //$modifier[$k]["custom"] = $v['custom'];
                //$modifier[$k]["custom_1"] = $v['custom_1'];
                //$modifier[$k]["custom_2"] = $v['custom_2'];

                $foo = array(
                    'type'         => $v['custom'],
                    'target_field' => $v['custom_1'],
                    'map_json'     => $v['custom_2']
                );

                switch($v['custom']){
                    case "splitName":
                        //split by '+'
                        $foo['target_field'] = $k;

                        $foo['map'] = explode("+", $v['custom_1']);  //expecting '+' delimited
                        //$modifier[$k]['fields'] = explode("+", $v['custom_1']);  //expecting '+' delimited
                        break;
                    case "textToCheckbox":
                        //$modifier[$k]['fields'] = $v['custom_1']; //target field
                        //$modifier[$k]['mapping'] = self::formatCheckboxLookup($v['custom_2']);

                        $foo['map'] = self::formatCheckboxLookup($v['custom_2']);
                        break;
                    case "checkboxToCheckbox":
                        //$modifier[$k]['fields'] = $v['custom_1']; //target field
                        //$modifier[$k]['mapping']   = json_decode($v['custom_2'], true);
                        $foo['map'] = json_decode($v['custom_2'], true);
                        break;
                    case "radioToCheckbox":
                        //$modifier[$k]['fields'] = $v['custom_1']; //target field
                        //$modifier[$k]['mapping']   = json_decode($v['custom_2'], true);
                        $foo['map'] = json_decode($v['custom_2'], true);
                        break;
                    case "recodeRadio":
                        //$modifier[$k]['fields'] = $v['custom_1']; //target field
                        //$modifier[$k]['mapping'] = json_decode($v['custom_2'], true);
                        $foo['map'] = json_decode($v['custom_2'], true);
                        break;
                    case "addToField":
                        //$modifier[$k]['fields'] = $v['custom_1'];  //target field
                        //$modifier[$k]['mapping'] = explode("+", $v['custom_2']);  //expecting '+' delimited, concat both fields and enter into target

                        $foo['map'] = explode("+", $v['custom_2']);  //expecting '+' delimited, concat both fields and enter into target
                        break;
                    default:
                        $foo['map'] = json_decode($v['custom_2'], true);
                }

                $this->modifier[$k][$foo['target_field']] = $foo;

            }

            if (!empty($v['custom2'])) {
                $modifier2[$k]["custom"] = $v['custom2'];
                $modifier2[$k]["custom_1"] = $v['custom2_1'];
                $modifier2[$k]["custom_2"] = $v['custom2_2'];

                $foo2 = array(
                    'type'         => $v['custom2'],
                    'target_field' => $v['custom2_1'],
                    'map_json'     => $v['custom2_2']
                );

                $module->emDebug("doing $k with ". $v['custom2']);
                if (!in_array($v['custom2'],$this->supported_custom)) {
                    $module->emDebug("doing $k with ". $v['custom2'] . " : ". $v['from_field'], $v);
                    throw new Exception("Aborting migration!!!  Unsupported custom type for field [$k]: ".$v['custom2'] );
                }


                //as of may 14, there is now a second set of custom fields
                switch($v['custom2']){
                    case "checkboxToCheckbox":
                        $foo2['map'] = json_decode($v['custom2_2'], true);
                        break;
                    case "radioToCheckbox":
                        $foo2['map'] = json_decode($v['custom2_2'], true);
                        break;
                    case "checkboxToRadio":
                        $foo2['map'] = json_decode($v['custom2_2'], true);
                        break;
                    case "recodeRadio":
                        //$modifier[$k]['fields'] = $v['custom_1']; //target field
                        //$modifier[$k]['mapping'] = json_decode($v['custom_2'], true);
                        $foo2['map'] = json_decode($v['custom2_2'], true);
                        break;
                    default:
                        $foo2['map'] = json_decode($v['custom2_2'], true);
                }
                $this->modifier[$k][$foo2['target_field']] = $foo2;
            }
        }

    }


    private function formatCheckboxLookup($map_json) {
        $json_to_array = json_decode($map_json, true);

        foreach ($json_to_array as $from => $to) {
            //remove whitespace and upper
            $from_fixed = self::formatForStringCompare($from);
            $checkbox_map[$from_fixed] = explode('+', $to);
        }

        return $checkbox_map;
    }

    public function formatForStringCompare($string)  {
        return preg_replace('/\s*\W*/', '', strtoupper($string));
    }

    /**
     * Split the incoming field into the number of outgoing fields
     * Expecting $incoming to be the value to split
     * $outgoing is delimited by + sign
     * For example: $incoming = "John Abel Jones"
     *              $outgoing = "first_name+last_name"
     */
    public function splitName($from_field, $incoming_value) {
        global $module;

        $re = '/(?<first>.+?)[\s]+(?<middle>.+?[\s,]+)?(?<last>.+)$/m';

        preg_match_all($re, $incoming_value, $matches, PREG_SET_ORDER, 0);

        $first = $matches[0]['first'];
        $middle = $matches[0]['middle'];
        $last = $matches[0]['last'];

        $target_field = $this->modifier[$from_field][$from_field]['map'];

        $return_array[$target_field[0]] = trim($first . " " . $middle);
        $return_array[$target_field[1]] = $last;

        //$module->emDebug($incoming_value, $target_field, $return_array);
        return $return_array;

    }

    /**
     * Mapping is in $modifier
     * Example:
     * [ethnicity] => Array
        (
            [custom] => textToCheckbox
            [custom_1] => ethnicity_2
            [custom_2] => {"Asian":"2","Asian/ Indian":"2","Asian/Caucasian":"0+2","Caucasian":"0","Caucasian/ Asian":"0+2"...
            [fields] => ethnicity_2
            [mapping] => Array
                (
                    [ASIAN] => Array
                        (
                            [0] => 2
                        )

     * @param $incoming
     */
    public function textToCheckbox($from_field, $incoming_value) {

        global $module;
        //$module->emDebug("TextToCheckbox:  $incoming_value for $from_field....", $this->modifier);

        //convert the incoming
        $formatted = self::formatForStringCompare($incoming_value);
        $target_field = $this->modifier[$from_field]['fields'];
        $outgoing_value = $this->modifier[$from_field]['mapping'][$formatted];

        $module->emDebug("Formatted $formatted / targetfield: $target_field / outgoing : $outgoing_value");
        foreach ($outgoing_value as $k => $v) {

            //$return_array[$target_field. '___'.$v] = 1;  //nope, wrong format, using arrays so just the coded value
            $return_array[$target_field][$v] = 1;  //nope, using arrays so just the coded value
        }

        return $return_array;
    }

    /**
     * Used for recoding checkbox values
     *
     *
     */
    public function checkboxToCheckbox($from_field, $incoming_value, $target_field, $map) {
        global $module;

        $return_array = null;
        //
        //$target_field = $this->modifier[$from_field]['fields'];
        //$map = $this->modifier[$from_field]['mapping'];

        foreach ($incoming_value as $code => $value) {
            if (isset($map[$code])) {  //empty won't work for 0
                //handle cases where it's coding to the same value.
                //if the value already is 1, then don't reset
                if (empty($outgoing[$map[$code]])) {  //either not there or equal to 0
                    $outgoing[$map[$code]] = $value;
                }
            }
        }

        if (!empty($outgoing)) {
            $return_array[$target_field] = $outgoing;
        }

        return $return_array;
    }


    /**
     *
     *
     * @param $from_field
     * @param $incoming_value
     * @param $target_field
     * @param $map
     * @return array
     */
    public function radioToCheckbox($from_field, $incoming_value, $target_field, $map) {

        $return_array = null;

        //return array should be of format
        //$return_array[$target_field][$target_code]=1
        if (!empty($map[$incoming_value])) {
            $outgoing[$map[$incoming_value]] = 1; //this should be one, but just in case interested in overwriting 0.
        }

        if (!empty($outgoing)) {
            $return_array[$target_field] = $outgoing;
        }

        return $return_array;
    }


    /**
     * Used for recoding checkbox values into radio
     *
     * map looks like 3 : 2
     * So, if checkbox 3 is set to 1 (checked) then set radiobutton value to  2
     *
     *
     */
    public function checkboxToRadio($from_field, $incoming_value, $target_field, $map) {
        global $module;
        $return_array = null;

        //$target_field = $this->modifier[$from_field]['fields'];
        //$map = $this->modifier[$from_field]['mapping'];  //array with checkbox  as key and the radio value as value

        foreach ($map as $code => $value) {
            //check that the code is set to one in the incoming_value (checkbox value)
            if ($incoming_value[$code] == "1") {
                $return_array[$target_field] = $value;
            }
        }
        return $return_array;
    }

    /**
     * Used for recoding radio values
     *
     *
     */
    public function recodeRadio($from_field, $incoming_value, $target_field, $map) {
        global $module;
        //
        //$target_field = $this->modifier[$from_field]['fields'];
        //$outgoing_lookup = $this->modifier[$from_field]['mapping'];

        $outgoing = $map[$incoming_value];

        $module->emDebug("RECODING $from_field value of $incoming_value TO $outgoing");

        $return_array[$target_field]=$outgoing;

        return $return_array;


    }

    public function addToField($from_field, $row) {
        global $module;

        $target_field = $this->modifier[$from_field]['fields'];
        $concat_fields = $this->modifier[$from_field]['mapping'];
        //$module->emDebug($from_field,$concat_fields);

        foreach ($concat_fields as $c_fields) {
            $val[trim($c_fields)] = $row[trim($c_fields)];
        }
        $return_array[$target_field]= implode("\n",$val);
        //$module->emDebug("CONCATTED: ",$return_array);
        return $return_array;

    }

    public function print() {
        echo "<br>CUSTOM REMAP SETTING<br><br>";

        foreach ($this->getModifier() as $field=>$map) {
            //echo "<br>". $field  . ' to '.$map['fields'] .  implode("/", $map['mapping']);
            echo "<br>". strtoupper($field)." <pre>";
            print_r( $map);
            echo "</pre>";

        }
    }

    public function getModifier() {
        return $this->modifier;
    }

}

