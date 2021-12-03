# DataResolutionReminder - Redcap External Module

## What does it do?

DataResolutionReminder can send email reminders to project users to remind them to complete a data resolution workflow that has been assigned to them. Frequency of reminders, number of days to wait to send reminder, and what users to notify are all configurable.

## Installing

This EM isn't yet available to install via redcap's EM database so you'll need to install to your modules folder (i.e. `redcap/modules/data_resolution_reminder_v1.0.0`) manually.

## Configuration

Configuration is simple: 

1. Define users to notify
2. Set number of days after the query is created to send the reminder
3. Should the reminder be sent once, daily, or weekly?
4. Should the reminder be sent for any user on the project, or only a specifc group/single user.

## Call Outs

* Todo - Add support for DAGs rather than users