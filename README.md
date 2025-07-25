# DataResolutionReminder - Redcap External Module

## What does it do?

DataResolutionReminder can send email reminders to project users to remind them to complete a data resolution workflow that has been assigned to them. Frequency of reminders, number of days to wait to send reminder, and what users to notify are all configurable.

## Installing

You can install the module from the REDCap EM repo or drop it directly in your modules folder (i.e. `redcap/modules/data_resolution_reminder_v1.0.0`) manually.

## Configuration

Configuration is fairly simple, you can either send reminders to the assigned owners of open issues (Basic Settings in the configuration), or create custom notifcation groups which require the below information.

1. Define users and/or DAG groups to notify
2. Set number of days after the query is created to send the reminder
3. Should the reminder be sent once, daily, or weekly?
4. Should the reminder be sent for any user on the project, or only a specifc group/single user.
