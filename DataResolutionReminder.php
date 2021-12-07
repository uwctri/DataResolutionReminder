<?php

namespace UWMadison\DataResolutionReminder;
use ExternalModules\AbstractExternalModule;
use Redcap;
use User;

class DataResolutionReminder extends AbstractExternalModule {
    
    /*
     *Redcap hook to load for config page to cleanup the EM's menu
     */
    public function redcap_every_page_top ( $project_id ) { 
        if (strpos(PAGE, 'manager/project.php') !== false && $project_id != NULL) {
            echo "<script src={$this->getUrl("config.js")}></script>";
        }
    }
    
    /*
     *Redcap cron for core functionality, this whole EM is kinda just a cron job
     */
    public function checkForReminders($cronInfo) {
        
        // Stash original PID, probably not needed, but docs recommend
        $originalPid = $_GET['pid'];
        
        // Constants across all pids
        $now = date("Y-m-d h:i");
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' 
            || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
        
        // Loop over every pid using this EM
        foreach($this->getProjectsWithModuleEnabled() as $pid) {
            
            // Act like we are in that project and get settings/projectName
            $_GET['pid'] = $pid;
            $link = "{$protocol}://{$_SERVER["SERVER_NAME"]}/redcap/redcap_v".REDCAP_VERSION."/index.php?pid={$pid}";
            $sql = 'SELECT app_title
                    FROM redcap_projects
                    WHERE project_id = (?)';
            $result = $this->query($sql, [$pid]);  // getProjectTitle doesn't work in crons
            $projectName = $result->fetch_assoc()['app_title'];
            $settings = $this->getProjectSettings();
            $sentSetting = $settings['sent'];
            $$updateProjectSetting = false;
            
            // Gather User IDs and reformat
            $users = User::getProjectUsernames(null,false,$pid);
            $result = $this->query('
                SELECT ui_id, username, user_email
                FROM redcap_user_information 
                WHERE username IN (?)',
                ['"'.implode('","',$users).'"']); 
            $projectUsers = [];
            while($row = $result->fetch_assoc()){
                $projectUsers[$row['username']] = [
                    'id' => $row['ui_id'],
                    'email' => $row['user_email']
                ];
            }
            
            // Loop over the user lists and conditionally send emails
            foreach($settings['user'] as $index => $userList) {
                $condition = $settings['condition'][$index];
                $days = $settings['condition'][$index];
                $freq = $settings['frequency'][$index];
                $sent = $settings['sent'][$index];
                
                if ( empty($condition) || empty($days) || empty($freq) || empty($userList) ) {
                    continue; // We need every setting
                }
                
                if ( $sent != "" && $freq == 0 ) {
                    continue; // Send only once, skip
                }
                
                if ( $sent != "" && $now > date('Y-m-d h:i', strtotime($sent . " + $freq days")) ) {
                    continue; // Not enough time has passed to send the next reminder
                }
                
                // Prep for our query to find open DQs
                $sql = 'SELECT ts, user_id, comment
                        FROM redcap_data_quality_resolutions 
                        WHERE current_query_status = "OPEN" 
                        AND user_id in (?)';
                $userIds = array_combine(array_keys($projectUsers),array_column($projectUsers, 'id'));
                $result = [];
                
                // If user in the list has open data query
                if ( $condition == 1 ) {
                    $fUsers = array_values(array_intersect_key($userIds, array_flip($userList)));
                    $result = $this->query($sql,['"'.implode('","',$fUsers).'"']);
                }
                
                // If any user on the project has an open data query
                if ( $condition == 2 ) {
                    $result = $this->query($sql,['"'.implode('","',array_values($userIds)).'"']);
                }
                
                // Check if enough time has passed sense the DQ was opened
                $sendEmail = false;
                while($row = $result->fetch_assoc()){
                    if ( $now < date("Y-m-d h:i", strtotime($row['ts'] . " + $days days")) ) {
                        $sendEmail = true;
                        break;
                    }
                }
                
                // Send the email and set flag to save
                if ( $sendEmail ) {
                    foreach ( $userList as $user ) {
                        $to = $projectUsers[$user]['email'];
                        $from = ""; // TODO
                        $subject = "[REDCap] Data query reminder";
                        $projLink = "<a link=\"$link\">\"$projectName\"</a>";
                        $msg = "There are open data queries in the REDCap project $projLink that need to be addressed.";
                        REDCap::email($to, $from, $subject, $msg);
                    }
                    $sentSetting[$index] = $now; 
                    $updateProjectSetting = true;
                }
            }
            
            // We've flipped through all of the userLits in the project
            // Update the project setting if anything was sent
            if ( $updateProjectSetting ) {
                $this->setProjectSetting("sent",$sentSetting);
            }
        }

        // Put the pid back the way it was before this cron job
        // likely doesn't matter, but is good housekeeping practice
        $_GET['pid'] = $originalPid;
        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }
}

?>
