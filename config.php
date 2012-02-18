<?php

/*
****************************************************
* nanoFTPd - an FTP daemon written in PHP          *
****************************************************
* this file is licensed under the terms of GPL, v2 *
****************************************************
* developers:                                      *
*  - Arjen <arjenjb@wanadoo.nl>                    *
*  - Phanatic <linux@psoftwares.hu>                *
****************************************************
* http://sourceforge.net/projects/nanoftpd/        *
****************************************************
*/

error_reporting(E_ALL);
set_time_limit(0);


$CFG = new stdClass();

$CFG->moodle			= array();				// Moodle connection data
$CFG->moodle['wwwroot']         = "http://localhost/moodle22";          // Your Moodle installationwwwroot
$CFG->moodle['wsshortname']     = "mftpd";                  // The shortname of the service
    
$CFG->listen_addr 		= "127.0.0.1";			        // IP address where nanoFTPd should listen
$CFG->listen_port 		= 2121;					// port where nanoFTPd should listen
$CFG->low_port			= 15000;
$CFG->high_port			= 16000;
$CFG->max_conn			= 10;					// max number of connections allowed
$CFG->max_conn_per_ip	= 3;						// max number of connections per ip allowed
$CFG->server_name 		= "mFTP server";		        // nanoFTPd server name

$CFG->rootdir 			= dirname(__FILE__);		        // nanoFTPd root directory
$CFG->libdir 			= "$CFG->rootdir/lib";			// nanoFTPd lib/ directory
$CFG->moddir 			= "$CFG->rootdir/modules";		// nanoFTPd modules/ directory
$CFG->tmpdir            = "$CFG->rootdir/tmp";                  // For storing uploaded and download files

ini_set('include_path', get_include_path().":".dirname(__FILE__).":".$CFG->libdir.":".$CFG->moddir);

$CFG->dynip			= array();				// dynamic ip support -- see docs/REAME.dynip
$CFG->dynip['on']		= 0;					// 0 = off (use listen_addr directive) 1 = on (override listen_addr directive)
$CFG->dynip['iface']	= "ppp0";					// interface connecting to the internet

$CFG->logging = new stdClass();
$CFG->logging->mode		= 1;					// 0 = no logging, 1 = to file (see directive below), 2 = to console, 3 = both
$CFG->logging->file		= "$CFG->rootdir/log/mftpd.log";	// the file where nanoFTPd should log the accesses & errors

$CFG->expirationtime            = 60;                                   // Cache expirtaion time in seconds

require("$CFG->moddir/io_wsmoodle.php");
require("$CFG->libdir/pool.php");
require("$CFG->libdir/log.php");
require("$CFG->libdir/curl.php");

$CFG->pasv_pool = new pool();
$CFG->log 		= new log($CFG);

?>