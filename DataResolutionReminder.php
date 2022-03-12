<?php

namespace UWMadison\DataResolutionReminder;
use ExternalModules\AbstractExternalModule;
use REDCap;

class DataResolutionReminder extends AbstractExternalModule {
    
    /*
     *Redcap hook to load trivial css on project's config page
     */
    public function redcap_every_page_top( $project_id ) {
        if ( $this->isPage("ExternalModules/manager/project.php") && $project_id != NULL) {
            ?> <style>.external-modules-input-element[value="1"][name^="condition"] {
                translate: 0 -10px;
            }</style><?php
        }
    }

    /*
     *Redcap cron
     */
    public function cron( $cronInfo ) {
        
        // Stash original PID, probably not needed, but docs recommend
        $originalPid = $_GET['pid'];
        
        // Get server info (has protocol + domain)
        global $redcap_base_url;
        
        // Loop over every pid using this EM
        foreach($this->getProjectsWithModuleEnabled() as $pid) {
            
            // Act like we are in that project, make a link, call core function
            $_GET['pid'] = $pid;
            $link = "{$redcap_base_url}redcap_v".REDCAP_VERSION."/DataQuality/resolve.php?pid=$pid&status_type=OPEN";
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
        $projectName = $this->getTitle();
        
        // Gather User IDs and reformat
        $users = array_map(function($obj){ return $obj->getUsername(); }, $this->getUsers() );
        $query = $this->createQuery();
        $query->add('
            SELECT ui_id, username, user_email
            FROM redcap_user_information
            WHERE');
        $query->addInClause('username', $users);
        $result = $query->execute();
        $projectUsers = [];
        while($row = $result->fetch_assoc()){
            $projectUsers[$row['username']] = [
                'id' => $row['ui_id'],
                'email' => $row['user_email']
            ];
        }
        
        // Gather all valid Status IDs for project ID
        $sql = 'SELECT status_id, field_name, event_id, instance, record
                FROM redcap_data_quality_status 
                WHERE project_id = ?';
        $result = $this->query($sql, [$project_id]);
        $statusIDs = [];
        while($row = $result->fetch_assoc()){
            $statusIDs[$row['status_id']] = [
                'field'    => $row['field_name'],
                'event'    => $row['event_id'],
                'instance' => $row['instance'],
                'record'   => $row['record']
            ];
        }
        
        // Loop over all setting groups (using freq for no real reason)
        foreach($settings['frequency'] as $index => $freq) {
            $condition = $settings['condition'][$index];
            $days = intval($settings['days'][$index]);
            $userList = $settings['user'][$index];
            $sent = $settings['sent'][$index];
            $dag = array_filter($settings['dag'][$index]);
            $sendDetails = $settings['details'][$index];
            
            if ( empty($condition) || empty($freq) || (empty($userList) && empty($dag)) ) {
                continue; // We need every setting, except days which we parse to int (i.e. ""->0)
            }
            
            if ( $sent != "" && $freq == 0 ) {
                continue; // Send only once, skip
            }
            
            if ( $sent != "" && $now < date('Y-m-d h:i', strtotime("$sent + $freq days")) ) {
                continue; // Not enough time has passed to send the next reminder
            }
            
            // Expand userList to include those in DAGs
            if ( !empty($dag) ) {
                $query = $this->createQuery();
                $query->add('
                    SELECT username 
                    FROM redcap_data_access_groups_users 
                    WHERE');
                $query->addInClause('group_id', $dag);
                $result = $query->execute();
                while($row = $result->fetch_assoc()){
                    $userList[] = $row['username'];
                }
            }
            
            // Remove any duplicates from the user list
            $userList = array_filter(array_unique($userList));
            
            // Prep for our query to find open DQs
            // Here we opt to avoid using addInClause due to the complexity of the sql
            // We find open DQs for all users of the project, we check user below
            $query = $this->createQuery();
            $statusString = implode(',',array_keys($statusIDs));
            $query->add('
                SELECT A.res_id, A.status_id, B.ts, B.user_id, B.comment FROM 
                (SELECT MAX(res_id) as res_id, status_id FROM redcap_data_quality_resolutions GROUP BY status_id) AS A
                JOIN 
                (SELECT res_id, status_id, ts, user_id, comment FROM redcap_data_quality_resolutions WHERE current_query_status = "OPEN" AND response_requested = "1" AND status_id IN ('.$statusString.') ) AS B
                ON A.res_id=B.res_id');
            $result = $query->execute();
            
            $userIds = array_combine(array_keys($projectUsers),array_column($projectUsers, 'id'));
            
            // If user in the list has open data query
            if ( $condition == 1 ) {
                $userIds = array_intersect_key($userIds, array_flip($userList));
            }
            
            // If any user on the project has an open data query
            if ( $condition == 2 ) {
                // Nothing to do here, users id list is correct already
            }
            
            $userIds = array_values($userIds);
            
            // Check if enough time has passed sense the DQ was opened
            $sendEmail = false;
            $comments = [];
            while($row = $result->fetch_assoc()){
                $ts = date("Y-m-d h:i", strtotime($row['ts'] . " + $days days"));
                if ( in_array($row['user_id'], $userIds) && $now > $ts ) {
                    $sendEmail = true;
                    if ( !$sendDetails ) {
                        break;
                    }
                    $comments[$row['status_id']] = $row['comment'];
                }
            }
            
            // Skip to next if nothing to send
            if ( !$sendEmail ) {
                continue;
            }
            
            // Build out the email
            $from = $project_contact_email;
            $subject = "[REDCap] Data query reminder";
            $link = "<a href=\"$project_link\">$projectName</a>";
            $msg = "There are open data queries in the REDCap project \"$link\" that need to be addressed.";
            if ( !empty($comments) ) {
                $msg = "$msg<br><br><style>th, td { padding-left: 1%; padding-right: 1% }</style>
                  <table style='border:none'>
                    <thead>
                      <tr>
                        <th>Record</th>
                        <th>Field</th>
                        <th>Event</th>
                        <th>Instance</th>
                        <th>Recent Comment</th>
                      </tr>
                    </thead>
                    <tbody>";
                foreach ( $comments as $id => $comment ) {
                    $msg = "$msg
                      <tr>
                        <td>{$statusIDs[$id]['record']}</td>
                        <td>{$statusIDs[$id]['field']}</td>
                        <td>{$statusIDs[$id]['event']}</td>
                        <td>{$statusIDs[$id]['instance']}</td>
                        <td>{$comment}</td>
                      </tr>";
                }
                $msg = "$msg</tbody></table>";
            }
            
            // Send the email and set flag to save
            foreach ( $userList as $user ) {
                $to = $projectUsers[$user]['email'];
                REDCap::email($to, $from, $subject, $msg);
            }
            $sentSetting[$index] = $now; 
            $updateProjectSetting = true;
        }
        
        // We've flipped through all of the userLits in the project
        // Update the project setting if anything was sent
        if ( $updateProjectSetting ) {
            $this->setProjectSetting("sent",$sentSetting);
        }
    }
}

?>
 
