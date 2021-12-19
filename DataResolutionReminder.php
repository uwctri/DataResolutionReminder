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
        if ( $this->isPage('ExternalModules/manager/project.php') && $project_id != NULL) {
            echo "<script src={$this->getUrl("config.js")}></script>";
        }
    }
    
    /*
     *Redcap cron
     */
    public function cron( $cronInfo ) {
        
        // Stash original PID, probably not needed, but docs recommend
        $originalPid = $_GET['pid'];
        
        // Get server http(s)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' 
            || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
        
        // Loop over every pid using this EM
        foreach($this->getProjectsWithModuleEnabled() as $pid) {
            
            // Act like we are in that project, make a link, call core function
            $_GET['pid'] = $pid;
            $link = "{$protocol}://".$_SERVER['HTTP_HOST']."/redcap/redcap_v".REDCAP_VERSION."/DataQuality/resolve.php?pid={$pid}&status_type=OPEN";
            $this->checkForReminders( $pid, $link );
        }

        // Put the pid back the way it was before this cron job
        // likely doesn't matter, but is good housekeeping practice
        $_GET['pid'] = $originalPid;
        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }
    
    /*
     *Core functionality, check a pid for DQ reminders and send emails
     */
    private function checkForReminders( $project_id, $project_link ) {
        
        // Gather project settings
        $settings = $this->getProjectSettings();
        $sentSetting = $settings['sent'];
        $updateProjectSetting = false;
        $now = date("Y-m-d h:i");
        
        // Fetch project title (getProjectTitle doesn't work in crons)
        $sql = 'SELECT app_title
                FROM redcap_projects
                WHERE project_id = ?';
        $result = $this->query($sql, [$project_id]);
        $projectName = $result->fetch_assoc()['app_title'];
        
        // Gather User IDs and reformat
        $users = User::getProjectUsernames(null,false,$project_id);
        $users = '"'.implode('","',$users).'"';
        $sql = 'SELECT ui_id, username, user_email
                FROM redcap_user_information
                WHERE username IN ('.$users.')'; // ISSUE - Breaks when params passed via query
        $result = $this->query($sql, []);
        $projectUsers = [];
        while($row = $result->fetch_assoc()){
            $projectUsers[$row['username']] = [
                'id' => $row['ui_id'],
                'email' => $row['user_email']
            ];
        }
        
        // Gather all valid Status IDs for project ID
        $sql = 'SELECT status_id 
                FROM redcap_data_quality_status 
                WHERE project_id = ?';
        $result = $this->query($sql, [$project_id]);
        $statusIDs = [];
        while($row = $result->fetch_assoc()){
            $statusIDs[] = $row['status_id'];
        }
        $statusIDs = implode(',',$statusIDs);
        
        // Loop over the user lists and conditionally send emails
        foreach($settings['user'] as $index => $userList) {
            $condition = $settings['condition'][$index];
            $days = $settings['condition'][$index];
            $freq = $settings['frequency'][$index];
            $sent = $settings['sent'][$index];
            $dag = array_filter($settings['dag'][$index]);
            $sendComment = $settings['comment'][$index];
            
            if ( empty($condition) || empty($days) || empty($freq) || empty($userList) ) {
                continue; // We need every setting
            }
            
            if ( $sent != "" && $freq == 0 ) {
                continue; // Send only once, skip
            }
            
            if ( $sent != "" && $now < date('Y-m-d h:i', strtotime("$sent + $freq days")) ) {
                continue; // Not enough time has passed to send the next reminder
            }
            
            // Expand userList to include those in DAGs
            if ( !empty($dag) ) {
                $dag = implode(',',$dag);
                $sql = 'SELECT username 
                        FROM redcap_data_access_groups_users 
                        WHERE group_id IN ('.$dag.')'; // ISSUE as above
                $result = $this->query($sql, []);
                while($row = $result->fetch_assoc()){
                    $userList[] = $row['username'];
                }
            }
            
            // Remove any duplicates from the user list
            $userList = array_filter(array_unique($userList));
            
            // Prep for our query to find open DQs
            $sql = 'SELECT ts, user_id, comment
                    FROM redcap_data_quality_resolutions 
                    WHERE current_query_status = "OPEN" 
                    AND status_id IN ('.$statusIDs.') AND user_id IN ';
            $userIds = array_combine(array_keys($projectUsers),array_column($projectUsers, 'id'));
            $result = [];
            
            // If user in the list has open data query
            if ( $condition == 1 ) {
                $userIds = array_values(array_intersect_key($userIds, array_flip($userList)));
                $userIds = '("'.implode('","',$userIds).'")';
                $result = $this->query($sql.$userIds,[]); // ISSUE as above
            }
            
            // If any user on the project has an open data query
            if ( $condition == 2 ) {
                $userIds = '("'.implode('","',array_values($userIds)).'")';
                $result = $this->query($sql.$userIds,[]); // ISSUE as above
            }
            
            // Check if enough time has passed sense the DQ was opened
            $sendEmail = false;
            $comments = [];
            while($row = $result->fetch_assoc()){
                if ( $now > date("Y-m-d h:i", strtotime($row['ts'] . " + $days days")) ) {
                    $sendEmail = true;
                    if ( !$sendComment ) {
                        break;
                    } else {
                        $comments[] = $row['comment'];
                    }
                }
            }
            
            // Send the email and set flag to save
            if ( $sendEmail ) {
                foreach ( $userList as $user ) {
                    $to = $projectUsers[$user]['email'];
                    $from = $project_contact_email;
                    $subject = "[REDCap] Data query reminder";
                    $link = "<a href=\"$project_link\">$projectName</a>";
                    $msg = "There are open data queries in the REDCap project \"$link\" that need to be addressed.";
                    if ( !empty($comments) ) {
                        $msg = "$msg<br><br>Data Query Comments:<br>";
                        foreach ( $comments as $comment ) {
                            $msg = "$msg<p style='margin-left: 2em'>$comment</p>";
                        }
                    }
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
}

?>
 
