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

// This class is for logging actions
class log {
   
    // path to file
    private $logfile;
    
    // file pointer
    private $fp;
    
    // read or write mode
    private $file_mode;
    
    // log mode: no logging, to file, to console or both
    private $mode;

    public function __construct($CFG, $m = "log") {
		
		$this->logfile = $CFG->logging->file;

		// do the level trick, converts the decimal level number to binary
		// the first bit stands for file logging, the second for console
		$this->mode = strrev(str_pad(decbin($CFG->logging->mode), 8, "0", STR_PAD_LEFT));
		
		if ($this->mode[0] && ! file_exists($this->logfile)) {
	    	if (! @touch($this->logfile)) die("cannot create logfile ({$this->logfile})...");
		}
		
		switch ($m) {
		    case "log":
				$this->file_mode = "a";
				break;
				
		    case "read":
				$this->file_mode = "r";
				break;
		}
    }

    public function write($s) {

		$s = $this->datetime()." - $s";
		
		// log to file
		if ($this->mode[0]) {
			$this->fp = fopen($this->logfile, $this->file_mode);
			
			if (! $this->fp) die("cannot open logfile (".$this->logfile." - mode: ".$this->file_mode.")...");
			if (! fwrite($this->fp, $s)) die("cannot write to logfile (".$this->logfile.")...");
		
			fclose($this->fp);
		}
		
		// log to console
		if ($this->mode[1]) {
			echo $s;
		}
    }
    
    public function datetime() {
		$d = date("Ymd-His");
		return $d;
    }
}

?>