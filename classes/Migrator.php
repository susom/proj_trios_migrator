<?php

namespace Stanford\ProjTRIOSMigrator;

/** @var \Stanford\ProjTRIOSMigrator\ProjTRIOSMigrator $module */

use \REDCap;

//require_once ($module->getModulePath() . "classes/RepeatingForms.php");

$module->emDebug($_POST);

if (!$_POST) {
    die( "You cant be here");
}

if (!$_FILES['file']) {
    die("No File posted");
}

if (!$_POST['origin_pid']) {
    die("No originating project ID set.");
}


$first_ct = $_POST['start_record'] ? $_POST['start_record'] : 0;
$last_ct = $_POST['last_record'] ? $_POST['last_record'] : NULL;

$origin_pid = $_POST['origin_pid'];
$file = fopen($_FILES['file']['tmp_name'], 'r');

if (isset($_POST['dump_map'])) {
    $module->emDebug("dumping map");
    $module->dumpMap($file, $origin_pid);
    exit;
}

if (isset($_POST['new_dd'])) {
    $module->emDebug("Updating New Data Dictionary");
    $module->migrateDataDictionary($file, $origin_pid);
    exit;
}

if (isset($_POST['one_record'])) {
    $module->emDebug("Up single record: $first_ct");
    $module->processOneRecord($file, $origin_pid, $first_ct);
    exit;
}


if ($file) {
    $data = $module->processRecords($file, $origin_pid, $first_ct, $last_ct);

} else {
    die("Uploaded file is corrupted!");
}

if(is_resource($file)) {
    fclose($file);
}

