ABOUT
=====

mftp - FTP Server for exposing Moodle file system
Based on:
nanoFTPd - an FTP daemon written in PHP

Developers mFTP:
 - Juan Leyva <juanleyvadelgado@gmail.com>
Developers nanoFTP:
 - Arjen <arjenjb@wanadoo.nl>
 - Phanatic <linux@psoftwares.hu>
 
REQUERIMENTS
============
 
Moodle 2.2
 
INSTALLATION
============
 
Unpack the zip files
 
CONFIGURATION
=============
 
In mftpd:
---------
 
Edit config.php
 
Change:
    $CFG->moodle['wwwroot'] Your Moodle wwwroot
    $CFG->listen_addr Listen addr for the FTP server (127.0.0.1)
    $CFG->listen_port Listen por for the FTP server (21)
 
Run the mftpd.php in a command shell

path/to/php/php /path/to/mftpd/mftpd.php

In your Moodle installation:
----------------------------

First of all, there is a bug that have to be fixed:

https://github.com/moodle/moodle/blob/master/files/externallib.php#L164

Change:
'filename' => new external_value(PARAM_FILE, ''),

to

'filename'  => new external_value(PARAM_TEXT, ''),

Edit the Authenticad user role, give permissions for use the rest webservice protocol and for autocreating tokens

webservice/rest:use
moodle/webservice:createtoken

Create a new set of external services:
Home /  Site administration /  Plugins /  Web services /  External services / Add

Name: mftpd
Enabled: checked
Can download files: checked

Rest of the settings as default

Add this functions:
core_files_get_files
core_files_upload

Due to a Moodle issue, there is no interface for adding an additional required field:

Open a database manager, in the external_services table, edit the mftpd entry and add in the shortname field: mftpd

(Some of the previous steps may be avoided using a local plugin that preloads services)

Save changes

Running:
--------

Open a FTP Client (Filezilla works fine)

Create a FTP account using the listen_addr and listen_port

Login using the credentials of any Moodle user excepts the Moodle Admin (for testing pourposes, a teacher admin is the best choice)
 