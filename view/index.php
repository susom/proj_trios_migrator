<?php

namespace Stanford\ProjTRIOSMigrator;

/** @var \Stanford\ProjTRIOSMigrator\ProjTRIOSMigrator $module */

use REDCap;

$generatorURL = $module->getUrl('classes/Migrator.php', false, true);
?>
<form enctype="multipart/form-data" action="<?php echo $generatorURL ?>" method="post" name="instrument-migrator"
      id="instrument-migrator">
    <h2>Migrate this</h2>
    <input type="file" name="file" id="file" placeholder="mapping csv file">
    <input type="text" name="origin_pid" id="origin_pid "  placeholder="Originating PID">
    <input type="text" name="start_record" id="start_record "  placeholder="Test: Start counter OR single record ID">
    <input type="text" name="last_record" id="last_record "  placeholder="Test: Last counter">
    <input type="submit" id="one_record" name="one_record" value="Migrate One Record">
    <input type="submit" id="submit" name="submit" value="Migrate Data">
    <input type="submit" id="dump_map" name="dump_map" value="Dump Map">
    <input type="submit" id="new_dd" name="new_dd" value="Update DD">
</form>
