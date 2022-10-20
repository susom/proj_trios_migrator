<?php


namespace Stanford\ProjTRIOSMigrator;

include_once "DataCheck.php";

use Exception;
use REDCap;

class MappedRow {
    private $ctr;

    private $mrn;
    private $mrn_field;

    private $origin_id;    //original id. ex; 77-1234-01
    private $visit_id;     // visit       ex: 01

    private $main_data; //main event (left blank in map file
    private $event_data; //to_event field specified in map file

    private $visit_data;
    private $repeat_form_data;
    private $error_msg;

    private $mapper;
    private $transmogrifier;  // converter of fieldtypes

    private $handle_repeat;  //we are dealing with a repeating form
    private $instance_id;

    private $data_errors;

    public function __construct($ctr, $row, $id_field, $mapper, $transmogrifier, $handle_repeat = false, $instance_id=1) {
        global $module;

        $this->ctr       = $ctr;
        $this->origin_id = $row[$id_field];

        $this->mapper         = $mapper;
        $this->transmogrifier = $transmogrifier;

        $this->handle_repeat  = $handle_repeat;
        $this->instance_id    = $instance_id;

        $this->mapRow($row);

    }


    /**
     * Check if the ID exists in the project already
     *
     * @return mixed|null
     * @throws EMConfigurationException
     */
    function checkIDExistsInMain() {
        global $module;
        $id = $this->origin_id;

        $pid = $module->getProjectId();
        $target_id_field = REDCap::getRecordIdField();
        $target_event = $module->getProjectSetting('main-config-event-id');

        if (($target_id_field == null) || ($target_event == null)) {
            throw new EMConfigurationException("<br><br>EM Config is not set correctly!! EVENT: [ $target_event ] not set. Please RECHECK your EM Config for all mandatory fields");
        }
        //change request: if monocyte or cytof and not found, use protocol id and subject_id to locate ($this->legacy_main_id_field)
        $module->emDebug("checking if already exists by record id: $id");
        $found = $this->checkIDExists($id, $target_id_field, $target_event);

        //ignore dupe check
        $found =  false;
        return $found;
    }


    /**
     * check if the id passed in parameter exists already for the given ID field
     * TODO: convert to SQL rather than use a SQL search
     *
     *
     * @param $id
     * @param $target_id_field
     * @param $target_event
     * @return mixed|null
     */
    function checkIDExists($id, $target_id_field, $target_event) {
        global $module;

        if (empty($id)) {
            $module->emDebug("No id passed.");
            return null;
        }

        /**
        $filter = "[" . REDCap::getEventNames(true, false, $target_event) . "][" . $target_id_field . "] = '$id'";

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format' => 'json',
            'events'        =>  $target_event,
            'fields'        => array( REDCap::getRecordIdField()),
            'filterLogic'   => $filter
        );

        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        $module->emDebug($filter, $params, $records);
*/
        $sql = sprintf(
            "select rd.record, rd.instance 
from redcap_data rd
where
 rd.event_id = %d
and rd.project_id = %d
and rd.field_name = '%s'
and rd.value = '%s'",
            db_escape($target_event),
            $module->getProjectId(),
            db_escape($target_id_field),
            db_escape($id)
        );
        //$module->emDebug("SQL: ". $sql);
        $q = db_query($sql);
        $row = db_fetch_assoc($q);

        return $row;
        /**
        if ($row=db_fetch_assoc($q)) {
            $module->emDebug("SQL found ".$row['record']);
            return $row['record'];
        } else {
            return false;
        }
         */
    }


    /**
     * Mapping form has these columns:
     *   from_field
     *   to_repeat_event
     *   to_form_instance
     *   to_field
     *   custom
     *   form_name
     *   notes
     *
     * @param $new_record_id
     * @param $next_instance
     * @param $row
     * @param $mapper
     */
    function mapRow($row) {
        global $module;
        //RepeatingForms saves by 'array' format, so format to be an array save

        //array_filter will filter out values of '0' so add function to force it to include the 0 values
        $row = array_filter($row, function($value) {
            return ($value !== null && $value !== false && $value !== '');
        });

        $mapper = $this->mapper;
        $modifier = $this->transmogrifier->getModifier();

        //make the save data array
        $main_data = array();
        $event_data = array();
        $visit_data = array();
        $repeat_form_data = array();
        $error_msg = array();


        foreach ($row as $key => $val) {

            //ignore all the field '_complete'
            if (preg_match('/_complete$/', $key)) {
                //$module->emDebug("Ignoring form complete field: $key");
                continue;
            }

            //check if empty checkbox field
            if ($mapper[$key]['dd_from_fieldtype'] == 'checkbox') {
                if (empty(array_filter($val))) {
                    continue; //don't upload this checkbox
                }
            }

            //also skip if it's a calculated field
            if ($mapper[$key]['dd_from_fieldtype'] == 'calc') {
                continue; //don't upload this checkbox
            }

            //also skip if descriptive
            if ($mapper[$key]['dd_from_fieldtype'] == 'descriptive') {
                continue; //don't upload this descriptive
            }

            //skip if there is no to_field
            if (empty($mapper[$key]['to_field'])) {
                $msg = "This key, $key, has no to field. It will not be migrated.";
                $error_msg[] = $msg;
                continue;
            }

            if ($mapper[$key]['to_field'] == 'day_abscess_v2') {
                $foo=$val;
            }
            //check if there are data errors to handle?
            if (!DataCheck::valueValid($mapper[$key]['to_field'], $val)) {
                $module->emError("Data INVALID / DELETED : key is $key and val is $val mapping to ".$mapper[$key]['to_field'] );
                $this->data_errors[$key] = $val;
                $val = NULL;

            };

            $target_field = $mapper[$key]['to_field'];
            $target_field_array = array();
            $mod_field_array = array();

            //check if there are ny custom recoding needed
            if (array_key_exists($key, $modifier)) {

                if ($mapper[$key]['to_field'] == 'abscess') {
                    $foo=$val;
                }
                foreach ($modifier[$key] as $target_field => $def) {
                    //check if there are customizations to change that $target field
                    //use array_replace for those cases where a single field is mapped into to fields.
                    switch($def['type']){
                        case "splitName":
                            // expecting two parameters
                            $target_field_array = $this->transmogrifier->splitName($key,$val ); //this can have two fields so expect an array
                            break;
                        case "textToCheckbox":
                            $target_field_array = array_replace($this->transmogrifier->textToCheckbox($key, $val));
                            break;
                        case "checkboxToCheckbox":
                            $target_field_array = array_replace($target_field_array, $this->transmogrifier->checkboxToCheckbox($key, $val, $target_field, $def['map']));
                            break;
                        case "radioToCheckbox":
                            $mod_field_array = $this->transmogrifier->radioToCheckbox($key, $val, $target_field, $def['map']);
                            $target_field_array = array_replace($target_field_array,$mod_field_array);
                            break;
                        case "checkboxToRadio":
                            $mod_field_array =  $this->transmogrifier->checkboxToRadio($key, $val, $target_field, $def['map']);
                            $target_field_array = array_replace($target_field_array, $mod_field_array);
                            break;
                        case "recodeRadio":
                            $target_field_array = array_replace($target_field_array,$this->transmogrifier->recodeRadio($key, $val, $target_field, $def['map']));

                            break;
                        case "addToField":
                            //target field is custom_1,
                            //custom_2 is list of fields to concat

                            $target_field_array = $this->transmogrifier->addToField($key, $row);
                            break;

                        default:

                            $target_field_array[$target_field] = $val;  //only need to do this if we are needing to upload to data fields
                    }


                }
            } else {
                $target_field_array[$target_field] = $val;  //only need to do this if we are needing to upload to data fields
            }


            //$module->emDebug("=========> TARGET",$key,  $target_field_array);

            //HANDLE the EVENT FORMS
            //using else if since should only execute one and in this order
            //if 'to_event' is populated, it will add to the event_data array
            //default will use the target event in the config file
            if (!empty($mapper[$key]['to_event'])) {
                $to_event    =  $mapper[$key]['to_event'];

                // save to the specified to_event

                //wrapped everything in array to handle multiple field (like first and last name)
                foreach ($target_field_array as $t_field => $t_val) {
                    //REDCap saveData array format expectes event_id
                    $event_id = REDCap::getEventIdFromUniqueEvent($to_event);

                    $event_data[$event_id][$t_field] = $t_val;
                }

                //check if there are any customizations to the repeating event


            //NOT USED: DELETE? HANDLE the REPEAT EVENT FORMS
            //if 'to_repeat_event' is populated, it will add to the visit_data array
            } else if (!empty($mapper[$key]['to_repeat_event'])) {
                $rpt_event    =  $mapper[$key]['to_repeat_event'];
                $rpt_instance =  $mapper[$key]['to_repeat_instance'];
                //$module->emDebug("REPEAT EVENT: Setting $key into event: " . $rpt_event . " Instance: " . $rpt_instance);
                // save to the repeat event
                //this is going to a visit event
                //$visit_data[$this->mapper[$key]['to_field']] = $val;
                //$visit_data[($mapper[$key]['to_repeat_event'])][$mapper[$key]['to_field']] = $val;

                //wrapped everything in array to handle multiple field (like first and last name)
                foreach ($target_field_array as $t_field => $t_val) {
                    //$visit_data[($mapper[$key]['to_repeat_event'])][$target_field] = $val;

                    //for certain fields, we need to over lap multiple assignments
                    //visit_sample gets coded over by 4 separate fields

                    if (!empty($existing_val = $visit_data[($mapper[$key]['to_repeat_event'])][$t_field])) {
                        $module->emDebug("VISIT DATA has existing value for $key ".$t_field);
                        if ($t_field == 'visit_sample') {
                            $module->emDebug("visit_sample will be collated with new value. ".json_encode($t_val));
                            $t_val = array_replace($existing_val, $t_val);
                        }
                    }

                    $visit_data[$rpt_event][$rpt_instance][$t_field] = $t_val;
                }

                //check if there are any customizations to the repeating event

            //if to_form_instance is not blank, then we want to migrate to multiple instances.
            //format will be <form_name>:<instance_number>
            } else if (!empty($mapper[$key]['to_form_instance'])) {


                //if to_form_instance is blank, then it goes into the main event
                //$module->emDebug("Setting $key to value of $val into REPEAT FORM. ".$this->mapper[$key]['from_fieldtype']. " to " . $mapper[$key]['to_form_instance']);
                $instance_parts = explode(':', $mapper[$key]['to_form_instance']);
                //$repeat_form_data[$instance_parts[0]][$instance_parts[1]][$mapper[$key]['to_field']] = $val;
                foreach ($target_field_array as $t_field => $t_val) {
                    //$repeat_form_data[$instance_parts[0]][$instance_parts[1]][$target_field] = $val;
                    $repeat_form_data[$instance_parts[0]][$instance_parts[1]][$t_field] = $t_val;
                }

                //NOT USED DELETE?
            } else if (!empty($mapper[$key]['from_repeat_to_event'])) {
                //if handle_repeat not set then ignore (do not map)
                if ($this->handle_repeat) {
                    //json object with format of "instance_number":"target_event_name"
                    $repeat_map = json_decode($mapper[$key]['from_repeat_to_event'], true);
                    $foo = $repeat_map[$this->instance_id];
                    $target_event_id = REDCap::getEventIdFromUniqueEvent($repeat_map[$this->instance_id]);

                    foreach ($target_field_array as $t_field => $t_val) {
                        $event_data[$target_event_id][$t_field] = $t_val;
                    }
                }
            } else {
                //this is for the main event
                foreach ($target_field_array as $t_field => $t_val) {
                    //$main_data[$target_field] = $val;
                    if ($t_field==='mrn') {
                        $t_val = self::formatMRNRemoveHyphen($t_val);
                    }
                    $main_data[$t_field] = $t_val;
                }


            }

        }

        //check that there is data in main_data
        if (sizeof($main_data)<1) {
            $this->main_data = null;
        } else {
            $this->main_data = $main_data;
        }

        //set up the event data
        if (sizeof($event_data)<1) {
            $this->event_data = null;
        } else {
            $this->event_data = $event_data;
        }

        //set up the visit data
        if (sizeof($visit_data)<1) {
            $this->visit_data = null;
        } else {
            $this->visit_data = $visit_data;
        }

        //save the repeat data
        if (sizeof($repeat_form_data)<1) {
            $this->repeat_form_data = null;

        } else {
            //TODO: add the visit id??
            $this->repeat_form_data = $repeat_form_data;
        }

    }

    public static function formatMRNAddHyphen($mrn) {
        //format it with hyphen for 8 digits so it doesn't overwrite the current (formatted)
        //check for hyphen
        if ((strlen($mrn)== 8) && (!preg_match("/-/i", $mrn))) {
            $mrn = implode("-", str_split($mrn, 7));
        }

        return $mrn;
    }

    public static function formatMRNRemoveHyphen($mrn) {
        //26nov2019: request that MRN be stored without hyphen
        //check for hyphen
        //if  (preg_match("/-/i", $mrn)) {
        $mrn = str_replace("-", "", $mrn);
        //}

        return $mrn;
    }

    /******************************************************/
    /*  SETTER / GETTER METHODS
    /******************************************************/

    public function getOriginalID() {
        return $this->origin_id;
    }
    public function getProtocolID() {
        return $this->protocol_id;
    }
    public function getVisitID() {
        return $this->visit_id;
    }

    public function getMainData() {
        return $this->main_data;
    }
    public function getEventData() {
        return $this->event_data;
    }
    public function getVisitData() {
        return $this->visit_data;
    }


    public function getRepeatFormData() {
        return $this->repeat_form_data;
    }
    public function getErrorMessage() {
        return $this->error_msg;
    }
    public function getDataError() {
        return $this->data_errors;
    }

    public function setMRN($mrn) {
        global $module;

        $this->mrn = self::formatMRNRemoveHyphen($mrn);
    }

    public function setMainData($main_data) {
        $this->main_data = $main_data;
    }
    public function setRepeatFormData($repeat_form_data) {
         $this->repeat_form_data = $repeat_form_data;
    }
    public function setErrorMessage($error_msg) {
        $this->error_msg = $error_msg;
    }


}