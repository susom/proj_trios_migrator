<?php

/** @var MappedRow mrow */

namespace Stanford\ProjTRIOSMigrator;

require_once "EMConfigurationException.php";
include_once "emLoggerTrait.php";
require_once "classes/RepeatingForms.php";
include_once 'classes/Mapper.php';
include_once 'classes/DDMigrator.php';
include_once 'classes/MappedRow.php';
include_once 'classes/Transmogrifier.php';

use REDCap;
use Exception;
use Survey;

class ProjTRIOSMigrator extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $file; //defining mapping csv file
    private $origin_pid; //pid of the origin project

    private $mapper;
    private $transmogrifier;
    private $map;

    private $not_entered;
    private $data_invalid;

    //from brooke: second instance should go to followup 3
    //
    private $repeat_redirect = array(
        'pelvic_floor_distress_inventory_pfdi20' => array('2'=>'followup_3_arm_1'),
        'pelvic_floor_impact_questionnaire_pfiq7' => array('2'=>'followup_3_arm_1'),
        'virtual_1'                               => array('2'=>'followup_13_arm_1')
    );


    public function dumpMap($file, $origin_pid) {
        echo 'bar';
        $this->emDebug("Starting Map Dump");

        //test PDF
        //Survey::archiveResponseAsPDF('111-0011-01','1776','consent_for_child_healthy', 1);

        //exit;

        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $mapper = new Mapper($origin_pid, $file);

        $mapper->downloadCSVFile();
        //$mapper->printDictionary(); exit;

    }

    public  function processOneRecord($file, $origin_pid, $record_id) {
        $origin_main_event = $this->getProjectSetting('origin-main-event');

        // 1. get the list of record ids from project 1
        $this->emDebug("About to get single record $record_id");
        $record_list[] = $record_id;

        //there seems to be an issue with getdata running into PHP Fatal error:  Allowed memory size of 2147483648 bytes exhausted
        $params = array(
            'project_id'   => $origin_pid,
            'return_format' => 'array',
            'events'        => array($origin_main_event),
            'records'       => $record_list,
            'fields'        => null
        );
        $data = REDCap::getData($params);

        //2. Set up the Mapper
        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $this->mapper = $this->getMapper($origin_pid, $file);

        //3. Set the file and origin_pid
        $this->origin_pid = $origin_pid;
        $this->file       = $file;

        $this->process($origin_pid, $data);

    }

    public  function processRecords($file, $origin_pid, $first_ct = 1, $last_ct = null) {

        $origin_main_event = $this->getProjectSetting('origin-main-event');

        // 1. get the list of record ids from project 1
        $this->emDebug("About to get record from count $first_ct to $last_ct");
        $record_list = array();

        //failing due to memory size.  will try restricting records according to chunks from first to last_ct
        $r_params = array(
            'project_id'    => $origin_pid,
            'return_format' => 'json',
            'events'        => array($origin_main_event),
            'fields'        => array($this->getProjectSetting('origin-main-id'))
        );
        $r_json_data = REDCap::getData($r_params);
        $r_data = json_decode($r_json_data, true);

        for ($i = $first_ct; $i <= $last_ct; $i++) {
            //adjust the ctr by 1 since REDCap starts array at 0
            $rec_id = $r_data[($i-1)][$this->getProjectSetting('origin-main-id')];
            $this->emDebug("adding ct: $i record_id : $rec_id");
            $record_list[] = $rec_id;
        }

        //there seems to be an issue with getdata running into PHP Fatal error:  Allowed memory size of 2147483648 bytes exhausted
        $params = array(
            'project_id'   => $origin_pid,
            'return_format' => 'array',
            'events'        => array($origin_main_event),
            'records'       => $record_list,
            'fields'        => null
        );
        $data = REDCap::getData($params);

        //2. Set up the Mapper
        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $this->mapper = $this->getMapper($origin_pid, $file);

        //3. Set the file and origin_pid
        $this->origin_pid = $origin_pid;
        $this->file       = $file;

        $this->process($origin_pid, $data);
    }



    /**
     * Process  records, passed in as $data
     *
     * @param $origin_pid  pid of the originating project
     * @param $data  REDCap getData for the single record
     * @param $file  mapping file in csv format
     * @throws Exception
     */
    public function process($origin_pid,$data) {
        $target_main_event = $this->getProjectSetting('main-config-event-id');

        //this is the event that has repeating forms
        $target_repeat_event = $this->getProjectSetting('repeat-event-id') ;

        //$origin_main_event = $this->getProjectSetting('origin-main-event');

        $this->not_entered = array();
        $this->data_invalid = array();

        $this->emDebug("Starting Migration");

        //$data = REDCap::getData($origin_pid, 'array', null, null, array($origin_main_event));
        $ctr = 1;

        // foreach row in first event
        foreach($data as $record => $events) {

            echo "<br> Analyzing row $ctr: RECORD: $record ";
            $this->emDebug(" Analyzing row $ctr: RECORD: $record EVENT: $events");

            foreach($events as $event => $row) {

                //if event is 'repeat_instances', then the first nest is the event,
                if ($event == 'repeat_instances') {
                    //the ne
                    $this->emDebug("ERROR: FOUND A REPEATING INSTANCE $record from array IN EVENT $event");
                } else {
                    $this->processEvent($ctr, $row, $record, $event);
                }

            }
            $ctr++;
        }

        if (!empty($this->not_entered)) {
            $this->emDEbug("NOT ENTERED: ".json_encode($this->not_entered));

            echo "<br>PROBLEM ROWS: <pre>";
            print_r($this->not_entered);
            echo "</pre>";
        }
        if (!empty($this->data_invalid)) {
            $this->emDebug("INVALID DATA: " . json_encode($this->data_invalid));
            echo "<br>INVALID DATA: <pre>";
            print_r($this->data_invalid);
            echo "</pre>";
        }
        //printout the error file
        //file_put_contents("foo.csv", $this->not_entered);


        //exit;



        //$this->downloadCSVFile("troublerows.csv",$this->not_entered);
        echo "<br> Completed upload!";
    }

    /**
     * passed in a $row
     * Expecting array of field
     * [event-id]
     *     field_name = value
     *     field_name = value
     *     ...
     *
     * @param $ctr
     * @param $row
     * @param $record
     * @param $event
     */
    private function processEvent($ctr, $row, $record, $event_id, $handle_repeat = false, $instance_id=1, $form_name = null) {
        $target_main_event = $this->getProjectSetting('main-config-event-id');

        $map            = $this->mapper->getMap();
        $transmogrifier = $this->getTransmogrifier();


        $this->emDebug(" Analyzing row $ctr: RECORD: $record EVENT: $event_id");

        //check that the ID doesn't already exist

        $origin_id_field = $this->getProjectSetting('origin-main-id');


        try {
            $mrow = new MappedRow($ctr, $row, $origin_id_field, $map, $transmogrifier,$handle_repeat, $instance_id);
            if (!empty($mrow->getDataError())) {
                $this->data_invalid[$record] = $mrow->getDataError();
                $this->emError($mrow->getDataError());
            }
        } catch (EMConfigurationException $ece) {
            $msg = 'Unable to process row $ctr: ' . $ece->getMessage();
            $this->emError($msg);
            $this->logProblemRow($ctr, $row, $msg, $not_entered);
            die ($msg);  // EM config is not set properly. Just abandon ship;'
        } catch (Exception $e) {
            $msg = 'Unable to process row $ctr: ' . $e->getMessage();
            $this->emError($msg);
            $this->logProblemRow($ctr, $row, $msg, $this->not_entered);
            return;
        }

        if (!$handle_repeat) {
            try {
                $found = $mrow->checkIDExistsInMain();
            }
            catch (\Exception $e) {
                $msg = 'Unable to process row $ctr: ' . $e->getMessage();
                $this->emError($msg);
                $this->logProblemRow($ctr, $row, $msg, $this->not_entered);
                return;
            }

            if (!empty($found)) {
                $record_id = $found['record']; //with the new SQL version
                $this->emDEbug("Row $ctr: Found an EXISTING record ($record_id) with count " . count($row));
                $msg = "NOT LOADING: Found an EXISTING record ($record_id) with count " . count($row);
                $this->emError($msg);
                $this->logProblemRow($ctr, $row, $msg, $this->not_entered);
                return;
            }

            $this->emDEbug("Row $ctr: EMPTY: $record NOT FOUND so proceed with migration");

            $record_id = $record; //reuse old record
            $this->emDebug("Row $ctr: Starting migration of $record to id: $record_id");
        } else {
            $record_id = $record; //reuse old record
            $this->emDebug("Row $ctr: Starting REPEAT DATA migration of $record to id: $record_id for intance $instance_id");
        }

        //HANDLE MAIN EVENT DATA
        $main_data = $mrow->getMainData();
        if (null !== ($mrow->getMainData())) {
            //save the main event data
            //$return = REDCap::saveData('json', json_encode(array($main_data)));
            //RepeatingForms uses array. i think expected format is [id][event] = $data
            $temp_instance = array();  //reset to empty
            $target_event_id = $this->getProjectSetting('main-config-event-id');

            //handle the repeating forms
            //if handle_repeat = true and instance_id = 2 then update the event
            /** hand in the mapper
            if ($instance_id > 1) {
                $target_event_name = $this->repeat_redirect[$form_name][$instance_id];
                $target_event_id = REDCap::getEventIdFromUniqueEvent($target_event_name);
            }
             * */


            $temp_instance[$record_id][$target_event_id] = $main_data;

            //handle the repeating forms
            //if handle_repeat = true and instance_id = 2 then update the event

            $return = REDCap::saveData('array', $temp_instance);

            if (isset($return["errors"]) and !empty($return["errors"])) {
                $msg = "Row $ctr: Not able to save project data for record $record_id with original id: " . $mrow->getOriginalID() . implode(" / ", $return['errors']);
                $this->emDebug("+++++++++++++++++++++++++++++++++TROUBLE");
                $this->emError($msg, $return['errors']);//, $temp_instance);
                $this->logProblemRow($ctr, $row, $msg . $return['errors'], $this->not_entered);
            } else {
                if ($handle_repeat) {
                    $msg_handle_repeat = " for REPEATING INSTANCE $instance_id";
                }
                $this->emLog("Row $ctr: Successfully saved BASELINE data $msg_handle_repeat for record " . $mrow->getOriginalID() . " with new id $record_id");
            }
        }

        //HANDLE EVENT DATA
        $event_data = $mrow->getEventData();
        if (null !== $event_data) {
            $save_event_data = array(); //reset to empty
            $save_event_data[$record_id] = $event_data;

            $this->emDebug("Row $ctr EVENT: Starting Event migration w count of " . sizeof($event_data)); //, $mrow->getVisitData());

            $event_save_status = REDCap::saveData('array',$save_event_data);
            if (isset($event_save_status["errors"]) and !empty($event_save_status["errors"])) {
                $msg = "Row $ctr: Not able to save event data for record $record_id  with original id: " . $mrow->getOriginalID() .  $event_save_status['errors'];
                $this->emError($msg, $event_save_status['errors'], $save_event_data);
                $this->logProblemRow($ctr, $row, $msg, $this->not_entered);
                return;
            } else {
                $this->emLog("Row $ctr: Successfully saved EVENT data for record " . $mrow->getOriginalID() . " with new id $record_id");
            }

        }

        //HANDLE REPEATIN FORM DATA
        foreach ($mrow->getRepeatFormData() as $form_name => $instances) {
            $this->emDebug("Repeat Form instrument $form_name ");
            foreach ($instances as $form_instance => $form_data) {
                //$rf_form = ${"rf_" . $form_name};
                $rf_form = new RepeatingForms($form_name,$target_main_event, $this->getProjectId());
                //$testform = &$("rf_".$r_form);
                //$rf_form =  RepeatingForms::byForm($this->getProjectId(), $form_name);

                $next_instance = $rf_form->getNextInstanceID($record_id, $target_main_event);
                $this->emDebug("Row $ctr: Working on $form_name on instance number " . $form_instance . " Adding as $next_instance");

                $rf_form->saveInstance($record_id, $next_instance,$form_data);

                //if ($rf_form->last_error_message) {
                if ($rf_form === false) {
                    $this->emError("Row $ctr: There was an error: ", $rf_form->last_error_message);
                    $this->logProblemRow($ctr, $row, $rf_form->last_error_message, $this->not_entered);
                }
            }
        }

        unset($mrow);
    }

    public function migrateDataDictionary($file, $origin_pid) {


        $dd_mig = new DDMigrator($file, $origin_pid);

        //pass on the original data dictionary
        $dd_mig->updateDDFromOriginal();
        //$dd_mig->print(100);
    }

    function logProblemRow($ctr, $row, $msg, &$not_entered)  {
        //$msg = " VISIT FOUND with instance ID: " . $found_event_instance_id . " NOT entering data.";

        $not_entered[$ctr]['reason'] = $msg;
        //$not_entered[$ctr]['data'] = $row;  //probably should implode it.  have to handle checkboxes first



    }


    function getNextId( $event_id, $prefix = '', $padding = false) {
        $id_field = REDCap::getRecordIdField();
        $q = REDCap::getData($this->getProjectId(),'array',NULL,array($id_field), $event_id);


        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                $max = 10 ** $padding;
                if ($i >= $max) {
                    $this->emError("Error - $i exceeds max of $max permitted by padding of $padding characters");
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
            } else {
                $id = $i;
            }

            // Add the prefix
            $id = $prefix . $id;

            $i++;
        } while (!empty($q[$id][$event_id][$id_field]));

        return $id;
    }

/*******************************************************************************************************************/
/* GETTER/SETTER METHODS                                                                                           */
/***************************************************************************************************************** */

    public function getMapper($origin_pid = null, $file = null) {
        if ($origin_pid === null) {
            if (empty($this->origin_pid)) {
                throw new Exception("ORIGIN PID IS NOT SET! ABORT!");
            }
            $origin_pid = $this->origin_pid;
        }

        if ($file === null) {
            if (empty($this->file)) {
                throw new Exception("MAPPING CSV FILE IS NOT SET! ABORT!");
            }
            $file = $this->file;
        }

        if (empty($this->mapper)) {
            $this->mapper = new Mapper($origin_pid, $file);
        }

        return $this->mapper;
    }

    public function getTransmogrifier() {
        if (empty($this->transmogrifier)) {
            $this->transmogrifier = new Transmogrifier($this->mapper->getMap());
        }

        return $this->transmogrifier;
    }




}