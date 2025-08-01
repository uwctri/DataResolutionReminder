<?php

namespace UWMadison\DataResolutionReminder;

use ExternalModules\AbstractExternalModule;
use REDCap;

class DataResolutionReminder extends AbstractExternalModule
{

    /*
     *Redcap hook to load trivial css on project's config page
     */
    public function redcap_every_page_top($project_id)
    {
        if ($this->isPage("ExternalModules/manager/project.php") && $project_id != NULL) {
?>
            <style>
                .external-modules-input-td label {
                    display: inline;
                }

                .sub_start[field$=-em-drr] td {
                    background-color: #e6e6e6;
                }
            </style>
<?php
        }
    }

    /*
     *Redcap cron
     */
    public function cron($cronInfo)
    {
        // Stash original PID, probably not needed, but docs recommend
        $originalPid = $_GET['pid'];

        // Get server info (has protocol + domain)
        global $redcap_base_url;

        // Loop over every pid using this EM
        foreach ($this->getProjectsWithModuleEnabled() as $pid) {

            // Act like we are in that project, make a link, call core function
            $_GET['pid'] = $pid;
            $link = "{$redcap_base_url}redcap_v" . REDCAP_VERSION . "/DataQuality/resolve.php?pid=$pid&status_type=OPEN";
            $this->checkForSelfReminders($pid, $link);
            $this->checkForGroupReminders($pid, $link);
        }

        // Put the pid back the way it was before this cron job
        // likely doesn't matter, but is good housekeeping practice
        $_GET['pid'] = $originalPid;
        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }

    /*
     *Core functionality, send users reminders if they own an open data query
     */
    private function checkForSelfReminders($project_id, $project_link)
    {
        $enable = $this->getProjectSetting("self_send", $project_id);
        if (!$enable) {
            return;
        }

        $projectName = $this->getTitle();
        $sent = $this->getProjectSetting("self_sent", $project_id);
        $frequency = $this->getProjectSetting("self_frequency", $project_id) ?? 7; // Default to 7 days if not set
        $hour = intval($this->getProjectSetting("self_hour", $project_id) ?? "9"); // Default to 9 AM if not set
        $hour = max(0, min(23, $hour)); // Ensure hour is between 0 and 23
        $now = date("Y-m-d") . " $hour:00";

        if (!empty($sent) && date('Y-m-d H:i', strtotime("$sent + {$frequency} days")) > $now) {
            return; // Not enough time has passed to send the next reminder
        }

        $statusIDs = $this->getProjectResolutions($project_id, true);

        // If we have no status IDs then bail
        if (empty($statusIDs)) {
            return;
        }

        // Regroup by user, include only open statuses
        $users = $this->getProjectUsers();
        $map_id_name = array_combine(array_column($users, 'id'), array_keys($users));
        $statusIDs = array_filter($statusIDs, function ($status) {
            return $status['open'];
        });
        $userStatusIDs = [];
        foreach ($statusIDs as $id => $status) {
            $user_id = $status['user'];
            $userStatusIDs[$map_id_name[$user_id]][$id] = $status;
        }

        // Loop over each user with a status ID
        foreach ($userStatusIDs as $user => $statuses) {
            $link = "<a href=\"$project_link\">$projectName</a>";
            $msg = "There are open data queries in the REDCap project \"$link\" that need to be addressed.";
            $this->sendEmail($users[$user]['email'], $msg);
        }

        // Update the project setting to reflect that we sent a reminder
        $this->setProjectSetting("self_sent", $now, $project_id);
    }

    /*
     *Util: Get all project data quality resolutions
     */
    private function getProjectResolutions($project_id, $requireUser = false)
    {
        // Gather all valid Status IDs for project ID
        $sql = 'SELECT status_id, field_name, event_id, instance, record, assigned_user_id, query_status
                FROM redcap_data_quality_status 
                WHERE project_id = ?';
        if ($requireUser) {
            $sql .= ' AND assigned_user_id IS NOT NULL';
        }
        $result = $this->query($sql, [$project_id]);
        $statusIDs = [];
        while ($row = $result->fetch_assoc()) {
            $statusIDs[$row['status_id']] = [
                'field'    => $row['field_name'],
                'event'    => $row['event_id'],
                'instance' => $row['instance'],
                'record'   => $row['record'],
                'user'     => $row['assigned_user_id'],
                'open'     => $row['query_status'] === 'OPEN'
            ];
        }
        return $statusIDs;
    }

    /*
     *Util: Get all users in the project, reformat to username => [id, email]
     */
    private function getProjectUsers()
    {
        // Gather User IDs and reformat
        $users = array_map(function ($obj) {
            return $obj->getUsername();
        }, $this->getUsers());
        $query = $this->createQuery();
        $query->add('
            SELECT ui_id, username, user_email
            FROM redcap_user_information
            WHERE');
        $query->addInClause('username', $users);
        $result = $query->execute();
        $projectUsers = [];
        while ($row = $result->fetch_assoc()) {
            $projectUsers[$row['username']] = [
                'id' => $row['ui_id'],
                'email' => $row['user_email']
            ];
        }
        return $projectUsers;
    }

    /*
     *Util: Basic wrapper for sending emails
     */
    private function sendEmail($to, $msg)
    {
        global $project_contact_email;
        $from = $project_contact_email;
        $subject = "[REDCap] Data query reminder";
        REDCap::email($to, $from, $subject, $msg);
    }

    /*
     *Core functionality, check a pid for DQ reminders and send emails
     */
    private function checkForGroupReminders($project_id, $project_link)
    {
        // Gather project settings
        $settings = $this->getProjectSettings($project_id);
        $sentSetting = $settings['sent'];
        $updateProjectSetting = false;
        $now = date("Y-m-d H:i");
        $projectName = $this->getTitle();

        $projectUsers = $this->getProjectUsers();
        $statusIDs = $this->getProjectResolutions($project_id);

        // If we have no status IDs then bail
        if (empty($statusIDs)) {
            return;
        }

        // Loop over all setting groups (using freq for no real reason)
        foreach ($settings['frequency'] as $index => $freq) {
            $condition = $settings['condition'][$index];
            $days = intval($settings['days'][$index]);
            $userList = $settings['user'][$index];
            $sent = $settings['sent'][$index];
            $dagList = array_filter($settings['dag'][$index]);
            $sendDetails = $settings['details'][$index];

            if (empty($condition) || empty($freq) || (empty($userList) && empty($dagList))) {
                continue; // We need every setting, except days which we parse to int (i.e. ""->0)
            }

            if ($sent != "" && $now < date('Y-m-d H:i', strtotime("$sent + $freq days"))) {
                continue; // Not enough time has passed to send the next reminder
            }

            // Expand userList to include those in DAGs
            if (!empty($dagList)) {
                $query = $this->createQuery();
                $query->add('
                    SELECT username 
                    FROM redcap_data_access_groups_users 
                    WHERE');
                $query->addInClause('group_id', $dagList);
                $result = $query->execute();
                while ($row = $result->fetch_assoc()) {
                    $userList[] = $row['username'];
                }
            }

            // Remove any duplicates from the user list and check it
            $userList = array_filter(array_unique($userList));
            if (empty($userList)) {
                continue;
            }

            // Prep for our query to find open DQs
            // Here we opt to avoid using addInClause due to the complexity of the sql
            // We find open DQs for all users of the project, we check user below
            $query = $this->createQuery();
            $statusString = implode(',', array_fill(0, count($statusIDs), '?'));
            $query->add('
                SELECT A.res_id, A.status_id, B.ts, B.user_id, B.comment FROM 
                (SELECT MAX(res_id) as res_id, status_id FROM redcap_data_quality_resolutions GROUP BY status_id) AS A
                JOIN 
                (SELECT res_id, status_id, ts, user_id, comment FROM redcap_data_quality_resolutions WHERE current_query_status = "OPEN" AND response_requested = "1" AND status_id IN (' . $statusString . ') ) AS B
                ON A.res_id=B.res_id', array_keys($statusIDs));
            $result = $query->execute();

            $userIds = array_combine(array_keys($projectUsers), array_column($projectUsers, 'id'));

            // If user in the list has open data query
            if ($condition == 1) {
                $userIds = array_intersect_key($userIds, array_flip($userList));
            }

            // If any user on the project has an open data query
            if ($condition == 2) {
                // Nothing to do here, users id list is correct already
            }

            $userIds = array_values($userIds);

            // Check if enough time has passed sense the DQ was opened
            $sendEmail = false;
            $comments = [];
            while ($row = $result->fetch_assoc()) {
                $ts = date("Y-m-d H:i", strtotime($row['ts'] . " + $days days"));
                if (in_array($row['user_id'], $userIds) && $now > $ts) {
                    $sendEmail = true;
                    if (!$sendDetails) {
                        break;
                    }
                    $comments[$row['status_id']] = $row['comment'];
                }
            }

            // Skip to next if nothing to send
            if (!$sendEmail) {
                continue;
            }

            // Build out the email
            global $project_contact_email;
            $from = $project_contact_email;
            $subject = "[REDCap] Data query reminder";
            $link = "<a href=\"$project_link\">$projectName</a>";
            $msg = "There are open data queries in the REDCap project \"$link\" that need to be addressed.";
            if (!empty($comments)) {
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
                foreach ($comments as $id => $comment) {
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
            foreach ($userList as $user) {
                $to = $projectUsers[$user]['email'];
                if (!empty($to)) {
                    $sentSetting[$index] = $now;
                    $updateProjectSetting = true;
                    REDCap::email($to, $from, $subject, $msg);
                }
            }
        }

        // We've flipped through all of the userLits in the project
        // Update the project setting if anything was sent
        if ($updateProjectSetting) {
            $this->setProjectSetting("sent", $sentSetting, $project_id);
        }
    }
}
