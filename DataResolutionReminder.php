<?php

namespace UWMadison\DataResolutionReminder;
use ExternalModules\AbstractExternalModule;

class DataResolutionReminder extends AbstractExternalModule {
    
    public function redcap_every_page_top ( $project_id ) {
        ?><script>console.log(<?=json_encode($this->getProjectSettings()); ?>);</script><?php
        
        if (strpos(PAGE, 'manager/project.php') !== false && $project_id != NULL) {
            echo "<script src={$this->getUrl("config.js")}></script>";
        }
    }
    
    public function checkForReminders($cronInfo) {
        $originalPid = $_GET['pid'];
        $now = date("Y-m-d h:i");
        
        foreach($this->getProjectsWithModuleEnabled() as $pid) {
            $_GET['pid'] = $pid;
            $settings = $this->getProjectSettings();
            
            foreach($settings['user'] as $index => $userList) {
                $condition = $settings['condition'][$index];
                $days = $settings['condition'][$index];
                $freq = $settings['frequency'][$index];
                $sent = $settings['sent'][$index];
                
                if ( $sent != "" && $freq == 0 ) {
                    continue; # Send only once, skip
                }
                
                // TODO if condition, if days, if freq, if not sent
            }
            
        }

        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
        $_GET['pid'] = $originalPid;

        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }
}

?>
