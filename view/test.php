<?php
namespace Stanford\ProjTRIOSMigrator;
/** @var ProjTRIOSMigrator $module */

use REDCap;
echo "testing saveData and getdata as array fss";



$pid = 30;
$q = REDCap::getData($pid,'array',array("324"),NULL, 2136);
//$module->emDebug($q);

/**
 * getData gets as array with this format:
 *
 * [record_id]
 *     [event_id]
 *         'record_id'='1'
 *         'checkbox'=array(1="foo")
 */

$save_data[324][2146]=array(
    'ftrp_recurrence'=>array("1"=>"0"),
    'mucosal_prolapse'=>array("1"=>"1")
);

//here's the failing save record
$fail_save_data[105][2136]=array(
    'rpfu_yn'=>"1",
    'ftrp_recurrence'=>array(1=>"1"),
    'mucosal_prolapse'=>array(1=>"1"),


);
REDCap::saveData($pid, 'array', $save_data);



