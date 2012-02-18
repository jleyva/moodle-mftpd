#!/usr/bin/php-cli
<?php

/*
****************************************************
* mftp - FTP Server for exposing Moodle file sys   *
* Based on                                         *
* nanoFTPd - an FTP daemon written in PHP          *
****************************************************
* this file is licensed under the terms of GPL, v2 *
****************************************************
* developers mFTP:                                 *
*  - Juan Leyva <juanleyvadelgado@gmail.com>       *
* developers nanoFTP:                              *
*  - Arjen <arjenjb@wanadoo.nl>                    *
*  - Phanatic <linux@psoftwares.hu>                *
****************************************************
* http://sourceforge.net/projects/nanoftpd/        *
****************************************************
*/

include(dirname(__FILE__).'/config.php');

$server = new server($CFG);

class server {

	public $CFG;

	public $listen_addr;
	public $listen_port;

	public $socket;
	public $clients;
	
	public $log;

	public function __construct($CFG) {

		$this->CFG = $CFG;
		$this->log = &$CFG->log;
		
		$this->init();

		$allowed_directives = array(
			"listen_addr"
			,"listen_port"
			,"max_conn"
			,"max_conn_per_ip"
		);

		foreach(get_object_vars($CFG) as $var => $value) {
			if (in_array($var, $allowed_directives)) $this->$var = $value;
		}

		$this->run();
	}

	public function init() {
		$this->listen_addr = 0;
		$this->listen_port = 21;
		$this->max_conn = 10;
		$this->max_conn_per_ip = 3;

		$this->socket = false;
		$this->clients = Array();
	}

	public function run() {
		// assign listening socket 
		if (! ($this->socket = @socket_create(AF_INET, SOCK_STREAM, 0)))
			$this->socket_error();

		// reuse listening socket address 
		if (! @socket_setopt($this->socket, SOL_SOCKET, SO_REUSEADDR, 1))
			$this->socket_error();

		// set socket to non-blocking 
		if (! @socket_set_nonblock($this->socket))
			$this->socket_error();

		// bind listening socket to specific address/port 
		if ($this->CFG->dynip['on']) {
		    if (! @socket_bind($this->socket, $this->get_ip_address(), $this->listen_port))
			$this->socket_error();
		} else {
		    if (! @socket_bind($this->socket, $this->listen_addr, $this->listen_port))
			$this->socket_error();
		}

		// listen on listening socket
		if (! socket_listen($this->socket))
			$this->socket_error();

		// set initial vars and loop until $abort is set to true
		$abort = false;

		while (! $abort) {
			// sockets we want to pay attention to
			$set_array = array_merge(array("server" => $this->socket), $this->get_client_connections());
			
			$set = $set_array;
			if (socket_select($set, $set_w = NULL, $set_e = NULL, 1, 0) > 0) {
				
				// loop through sockets
				foreach($set as $sock) {
					$name = array_search ($sock, $set_array);

					if (! $name) {
						continue;

					} elseif ($name == "server") {

						if (! ($conn = socket_accept($this->socket))) {
							$this->socket_error();
						} else {

							// add socket to client list and announce connection
							$clientID = uniqid("client_");
							$this->clients[$clientID] = new client($this->CFG, $conn, $clientID);
							
							// if max_conn exceeded disconnect client
							if (count($this->clients) > $this->max_conn) {
								$this->clients[$clientID]->send("421 Maximum user count reached.");
								$this->clients[$clientID]->disconnect();
								$this->remove_client($clientID);
								continue;
							}
							
							// get a list of how many connections each IP has
							$ip_pool = array();
							foreach($this->clients as $client) {
								$key = $client->addr;
								$ip_pool[$key] = (array_key_exists($key, $ip_pool)) ? $ip_pool[$key] + 1 : 1;
							}
							
							// disconnect when max_conn_per_ip is exceeded for this client
							if ($ip_pool[$key] > $this->max_conn_per_ip) {
								$this->clients[$clientID]->send("421 Too many connections from this IP.");
								$this->clients[$clientID]->disconnect();
								$this->remove_client($clientID);
								continue;
							}
							
							// everything is ok, initialize client
							$this->clients[$clientID]->init();

						}

					} else {

						$clientID = $name;

						// client socket has incoming data
						if (($read = @socket_read($sock, 1024)) === false || $read == '') {
							if ($read != '')
								$this->socket_error();

							// remove client from array
							$this->remove_client($clientID);

						} else {
							// only want data with a newline
							if (strchr(strrev($read), "\n") === false) {
								$this->clients[$clientID]->buffer .= $read;
							} else {
								$this->clients[$clientID]->buffer .= str_replace("\n", "", $read);

								if (! $this->clients[$clientID]->interact()) {
									$this->clients[$clientID]->disconnect();
									$this->remove_client($clientID);
								} else {
									$this->clients[$clientID]->buffer = "";
								}
							}
						}
					}
				}
			}
		}
	}

	public function get_client_connections() {
		$conn = array();

		foreach($this->clients as $clientID => $client) {
			$conn[$clientID] = $client->connection;
		}

		return $conn;
	}

	public function remove_client($clientID) {
		unset($this->clients[$clientID]);
	}

	public function socket_error() {
	    $this->log->write("socket: error: ".socket_strerror(socket_last_error($this->socket))."\n");
	    if (is_resource($this->socket)) socket_close($this->socket);
	    die;
	}
	
	public function get_ip_address() {
	    exec("ifconfig | grep -1 ".$this->CFG->dynip['iface']." | cut -s -d ' ' -f12 | grep addr | cut -d ':' -f2", $output, $return);
	    if ($return != "0") die("couldn't get ip address... maybe you should turn off the dynamic ip detection feature...\n");
	    return rtrim($output[0]);
	}
}

class client {

	public $CFG;

	public $id;
	public $connection;
	public $buffer;
	public $transfertype;
	public $loggedin;
	public $user;
	
	public $user_uid;
	public $user_gid;

	public $addr;
	public $port;
	public $pasv;

	public $data_addr;
	public $data_port;
	// passive ftp data socket and connection
	public $data_socket;
	public $data_conn;
	// active ftp data socket pointer
	public $data_fsp;

	public $command;
	public $parameter;
	public $return;

	public $io;	

	public function __construct(&$CFG, $connection, $id) {
		
		$this->id = $id;
		$this->connection = $connection;
		$this->CFG = &$CFG;
		
		socket_getpeername($this->connection, $addr, $port);
		$this->addr = $addr;
		$this->port = $port;
		$this->pasv = false;
		$this->loggedin	= false;

		$this->log = &$this->CFG->log;
	}
	
	public function init() {		
		
		$this->io = new io_wsmoodle($this->CFG);

		$this->buffer = '';

		$this->command		= "";
		$this->parameter	= "";
		$this->buffer		= "";
		$this->transfertype = "A";

		$this->send("220 " . $this->CFG->server_name);

		if (! is_resource($this->connection)) die;
	}

	public function interact() {
		$this->return = true;
		
		if (strlen($this->buffer)) {

			$this->command		= trim(strtoupper(substr(trim($this->buffer), 0, 4)));
			$this->parameter 	= trim(substr(trim($this->buffer), 4));

			$command = $this->command;
			$this->io->parameter = $this->parameter;

			if ($command == "QUIT") {
				$this->log->write("client: " . trim($this->buffer) . "\n");
				$this->cmd_quit();
				return $this->return;

			} elseif ($command == "USER") {
				$this->log->write("client: " . trim($this->buffer) . "\n");
				$this->cmd_user();
				return $this->return;

			} elseif ($command == "PASS") {
				$this->log->write("client: PASS xxxx\n");
				$this->cmd_pass();
				return $this->return;

			}
			
			$this->log->write($this->user . ": ".trim($this->buffer)."\n");
			if (! $this->loggedin) {
				$this->send("530 Not logged in.");
			} elseif ($command == "LIST" || $command == "NLST") {
				$this->cmd_list();

			} elseif ($command == "PASV") {
				$this->cmd_pasv();

			} elseif ($command == "PORT") {
				$this->cmd_port();

			} elseif ($command == "SYST") {
				$this->cmd_syst();

			} elseif ($command == "PWD") {
				$this->cmd_pwd();

			} elseif ($command == "CWD") {
				$this->cmd_cwd();

			} elseif ($command == "CDUP") {
				$this->cmd_cwd();

			} elseif ($command == "TYPE") {
				$this->cmd_type();

			} elseif ($command == "NOOP") {
				$this->cmd_noop();

			} elseif ($command == "RETR") {
				$this->cmd_retr();

			} elseif ($command == "SIZE") {
				$this->cmd_size();

			} elseif ($command == "STOR") {
				$this->cmd_stor();

			} elseif ($command == "DELE") {
				$this->cmd_dele();

			} elseif ($command == "HELP") {
				$this->cmd_help();
				
			} elseif ($command == "SITE") {
				$this->cmd_site();

			} elseif ($command == "APPE") {
				$this->cmd_appe();
		
			} elseif ($command == "MKD") {
				$this->cmd_mkd();
					    
			} elseif ($command == "RMD") {
				$this->cmd_rmd();
									
			} elseif ($command == "RNFR") {
				$this->cmd_rnfr();
												    
			} elseif ($command == "RNTO") {
				$this->cmd_rnto();

			} else {
				$this->send("502 Command not implemented.");
			}

			return $this->return;
		}
	}
	
	public function disconnect() {
		if (is_resource($this->connection)) socket_close($this->connection);

		if ($this->pasv) {
			if (is_resource($this->data_conn)) socket_close($this->data_conn);
			if (is_resource($this->data_socket)) socket_close($this->data_socket);
		}
	}

	/*
	NAME: help
	SYNTAX: help
	DESCRIPTION: shows the list of available commands...
	NOTE: -
	*/
	public function cmd_help() {
		$this->send(
			"214-" . $this->CFG->server_name . "\n"
			."214-Commands available:\n"
			."214-APPE\n"
			."214-CDUP\n"
			."214-CWD\n"
			."214-DELE\n"
			."214-HELP\n"
			."214-LIST\n"
			."214-MKD\n"
			."214-NOOP\n"
			."214-PASS\n"
			."214-PASV\n"
			."214-PORT\n"
			."214-PWD\n"
			."214-QUIT\n"
			."214-RETR\n"
			."214-RMD\n"
			."214-RNFR\n"
			."214-RNTO\n"
			."214-SIZE\n"
			."214-STOR\n"
			."214-SYST\n"
			."214-TYPE\n"
			."214-USER\n"
			."214 HELP command successful."
		);
	}

	/*
	NAME: quit
	SYNTAX: quit
	DESCRIPTION: closes the connection to the server...
	NOTE: -
	*/
	public function cmd_quit() {
		$this->send("221 Disconnected from ".$this->CFG->server_name." FTP Server. Have a nice day.");
		$this->disconnect();

		$this->return = false;
	}

	/*
	NAME: user
	SYNTAX: user <username>
	DESCRIPTION: logs <username> in...
	NOTE: -
	*/
	public function cmd_user() {

		$this->loggedin = false;
		$this->user = $this->parameter;

		$this->send("331 Password required for " . $this->user . ".");
	}

	/*
	NAME: pass
	SYNTAX: pass <password>
	DESCRIPTION: checks <password>, whether it's correct...
	NOTE: added authentication library support by Phanatic (26/12/2002)
	*/
	public function cmd_pass() {

	    if (! $this->user) {
			$this->user = "";
			$this->loggedin = false;
			$this->send("530 Not logged in. Invalid user or password");
			return;
	    }
        
        $pass = $this->parameter;
        
        if($this->io->auth($this->user, $pass)){        
            $this->send("230 User " . $this->user . " logged in from ".$this->addr.".");
            $this->loggedin = true;
        }
        else{
            $this->send("530 Not logged in. Invalid user or password");
            $this->loggedin = false;	    
        }
	}

	/*
	NAME: syst
	SYNTAX: syst
	DESCRIPTION: returns system type...
	NOTE: -
	*/
	public function cmd_syst() {
		$this->send("215 UNIX Type: L8");
	}

	/*
	NAME: cwd / cdup
	SYNTAX: cwd <directory> / cdup
	DESCRIPTION: changes current directory to <directory> / changes current directory to parent directory...
	NOTE: -
	*/
	public function cmd_cwd() {
		if ($this->command  == "CDUP") {
			$this->io->parameter = "..";
		}

		if ($this->io->cwd() !== false) {
			$this->send("250 CWD command succesful.");
		} else {
			$this->send("450 Requested file action not taken.");
		}
	}

	/*
	NAME: pwd
	SYNTAX: pwd
	DESCRIPTION: returns current directory...
	NOTE: -
	*/
	public function cmd_pwd() {
		$dir = $this->io->pwd();
		$this->send("257 \"" . $dir . "\" is current directory.");
	}

	/*
	NAME: list
	SYNTAX: list
	DESCRIPTION: returns the filelist of the current directory...
	NOTE: should implement the <directory> parameter to be RFC-compilant...
	*/
	public function cmd_list() {

		$ret = $this->data_open();

		if (! $ret) {
			$this->send("425 Can't open data connection.");
			return;
		}

		$this->send("150 Opening  " . $this->transfer_text() . " data connection.");

		$list = $this->io->ls();
		
		if($list === false){
			$this->send("550 Network error.");
			return;
		}
		
		foreach($list as $info) {
			// formatted list output added by Phanatic 
			$formatted_list = sprintf("%-11s%-2s%-15s%-15s%-10s%-13s".$info['name'], $info['perms'], "1", $info['owner'], $info['group'], $info['size'], $info['time']);
			
			$this->data_send($formatted_list);
			$this->data_eol();
		}

		$this->data_close();

		$this->send("226 Transfer complete.");
	}

	/*
	NAME: dele
	SYNTAX: dele <filename>
	DESCRIPTION: delete <filename>...
	NOTE: authentication check added by Phanatic (26/12/2002)
	*/
	public function cmd_dele() {
	    if (strpos(trim($this->parameter), "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	
	    if (substr($this->parameter, 0, 1) == "/") {
		$file = $this->io->root.$this->parameter;
	    } else {
		$file = $this->io->root.$this->io->cwd.$this->parameter;
	    }
	    if (!is_file($file)) {
		$this->send("550 Resource is not a file.");
	    } else {	
		if (!$this->io->can_write($file)) {
		    $this->send("550 Permission denied.");
		} else {
		    if (!$this->io->rm($this->parameter)) {
			$this->send("550 Couldn't delete file.");
		    } else {
			$this->send("250 Delete command successful.");
		    }
		}
	    }
	}
	
	/*
	NAME: mkd
	SYNTAX: mkd <directory>
	DESCRIPTION: creates the specified directory...
	NOTE: -
	*/
	public function cmd_mkd() {
	    $dir = trim($this->parameter);
	    
	    if (strpos($dir, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
    	    $io = &$this->io;
	    
	    if (!$io->md($dir)) {
		$this->send("553 Requested action not taken.");
	    } else {
		$this->send("250 MKD command successful.");
	    }
	}
	
	/*
	NAME: rmd
	SYNTAX: rmd <directory>
	DESCRIPTION: removes the specified directory (must be empty)...
	NOTE: -
	*/
	public function cmd_rmd() {
	    $dir = trim($this->parameter);
	    
	    if (strpos($dir, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
	    $io = &$this->io;
	    
	    if (!$io->rd($dir)) {
		$this->send("553 Requested action not taken.");
	    } else {
		$this->send("250 RMD command successful.");
	    }
	}
	
	/*
	NAME: rnfr
	SYNTAX: rnfr <file>
	DESCRIPTION: sets the specified file for renaming...
	NOTE: -
	*/
	public function cmd_rnfr() {
	    $file = trim($this->parameter);
	    
	    if (strpos($file, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
	    $io = &$this->io;
	    
	    if (!$io->exists($file)) {
		$this->send("553 Requested action not taken.");
		return;
	    }
	    
	    $this->rnfr = $file;
	    $this->send("350 RNFR command successful.");
	}
	
	/*
	NAME: rnto
	SYNTAX: rnto <file>
	DESCRIPTION: sets the target of the renaming...
	NOTE: -
	*/
	public function cmd_rnto() {
	    $file = trim($this->parameter);
	    
	    if (!isset($this->rnfr) || strlen($this->rnfr) == 0) {
		$this->send("550 Requested file action not taken (need an RNFR command).");
		return;
	    }
	    
	    if (strpos($file, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
	    $io = &$this->io;
	    
	    if ($io->rn($this->rnfr, $file)) {
		$this->send("250 RNTO command successful.");
	    } else {
		$this->send("553 Requested action not taken.");
	    }
	}

	/*
	NAME: stor
	SYNTAX: stor <file>
	DESCRIPTION: stores a local file on the server...
	NOTE: -
	*/
	public function cmd_stor() {
		$file = trim($this->parameter);

		$io = &$this->io;

		if ($io->exists($file)) {
			if ($io->type($file) == "dir") {
				$this->send("553 Requested action not taken.");
				return;
			} elseif (! $io->rm($file)) {
				$this->send("553 Requested action not taken.");
				return;
			}
		}

		$this->send("150 File status okay; openening " . $this->transfer_text() . " connection.");
		$this->data_open();
		$io->open($file, true);

		if ($this->pasv) {
		    while(($buf = socket_read($this->data_conn, 512)) !== false) {
			if (! strlen($buf)) break;
			$io->write($buf);
		    }
		} else {
		    while (!feof($this->data_fsp)) {
			$buf = fgets($this->data_fsp, 16384);
			$io->write($buf);
		    }
		}
		$io->close();
		$this->data_close();
		$this->send("226 transfer complete.");

	}
	
	/*
	NAME: appe
	SYNTAX: appe <file>
	DESCRIPTION: if <file> exists, the recieved data should be appended to that file...
	NOTE: -
	*/
	public function cmd_appe() {
	    $file = trim($this->parameter);
	    
	    if (strpos($file, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
	    $io = &$this->io;
	    
	    $this->send("150 File status okay; openening " . $this->transfer_text() . " connection.");
	    $this->data_open();
	    
	    if ($io->exists($file)) {
		if ($io->type($file) == "dir") {
		    $this->send("553 Requested action not taken.");
		    return;
		} else {
		    $io->open($file, false, true);
		}
	    } else {
		$io->open($file, true);
	    }	    
	    
	    if ($this->pasv) {
		while(($buf = socket_read($this->data_conn, 512)) !== false) {
		    if (! strlen($buf)) break;
		    $io->write($buf);
		}
	    } else {
		while (!feof($this->data_fsp)) {
		    $buf = fgets($this->data_fsp, 16384);
		    $io->write($buf);
		}
	    }
	    $io->close();
	    $this->data_close();
	    $this->send("226 transfer complete.");
	}

	/*
	NAME: retr
	SYNTAX: retr <file>
	DESCRIPTION: retrieve a file from the server...
	NOTE: authentication check added by Phanatic (26/12/2002)
	*/
	public function cmd_retr() {
	    $file = trim($this->parameter);
	    
	    if (strpos($file, "..") !== false) {
		$this->send("550 Permission denied.");
		return;
	    }
	    
	    $io = &$this->io;
	    	    
	    if ($this->io->type($file) != 'file') {
		$this->send("550 Resource is not a file.");
		return;
	    } else {
            if (!$this->io->exists($file)) {
                $this->send("553 Requested action not taken.");
                return;
            }
            
            if (!$this->io->can_read($file)) {
                $this->send("550 Permission denied.");
                return;
            } else {
                $size = $io->size($file);

                $io->open($file);
                $this->data_open();
                $this->send("150 " . $this->transfer_text() . " connection for " . $file . " (" . $size . " bytes).");

                if ($this->transfertype == "A") {
                        $file = str_replace("\n", "\r", $io->read($size));
                        $this->data_send($file);
                    } else {
                        while ($data = $io->read(1024)) {
                            $this->data_send($data);
                        }
                }

                $this->send("226 transfer complete.");
                $this->data_close();
                $io->close();
            }
	    }
	}

	public function cmd_pasv() {

		$pool = &$this->CFG->pasv_pool;

		if ($this->pasv) {
			if (is_resource($this->data_conn)) socket_close($this->data_conn);
			if (is_resource($this->data_socket)) socket_close($this->data_socket);

			$this->data_conn = false;
			$this->data_socket = false;

			if ($this->data_port) $pool->remove($this->data_port);

		}

		$this->pasv = true;

		$low_port = $this->CFG->low_port;
		$high_port = $this->CFG->high_port;

		$try = 0;

		if (($socket = socket_create(AF_INET, SOCK_STREAM, 0)) < 0) {
			$this->send("425 Can't open data connection.");
			return;
		}

		// reuse listening socket address 
		if (! @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
			$this->send("425 Can't open data connection.");
			return;
		}

		for ($port = $low_port; $port <= $high_port && $try < 4; $port++) {
			if (! $pool->exists($port)) {
				$try++;

				$c = socket_bind($socket, $this->CFG->listen_addr, $port);

				if ($c >= 0) {
					$pool->add($port);
					break;
				}
			}
		}


		if (! $c) {
			$this->send("452 Can't open data connection.");
			return;
		}

		socket_listen($socket);

		$this->data_socket = $socket;
		$this->data_port = $port;

		$p1 = $port >>  8;
		$p2 = $port & 0xff;

		$tmp = str_replace(".", ",", $this->CFG->listen_addr);
		$this->send("227 Entering Passive Mode ({$tmp},{$p1},{$p2}).");
	}

	public function cmd_port() {
		$data = explode(",", $this->parameter);

		if (count($data) != 6) {
			$this->send("500 Wrong number of Parameters.");
			return;
		}

		$p2 = array_pop($data);
		$p1 = array_pop($data);

		$port = ($p1 << 8) + $p2;

		foreach($data as $ip_seg) {
			if (! is_numeric($ip_seg) || $ip_seg > 255 || $ip_seg < 0) {
				$this->send("500 Bad IP address " . implode(".", $data) . ".");
				return;
			}
		}

		$ip = implode(".", $data);

		if (! is_numeric($p1) || ! is_numeric($p2) || ! $port) {
			$this->send("500 Bad Port number.");
			return;
		}

		$this->data_addr = $ip;
		$this->data_port = $port;

		$this->log->write($this->user.": server: Client suggested: $ip:$port.\n");
		$this->send("200 PORT command successful.");
	}

	public function cmd_type() {
		$type = trim(strtoupper($this->parameter));

		if (strlen($type) != 1) {
			$this->send("501 Syntax error in parameters or arguments.");
		} elseif ($type != "A" && $type != "I") {
			$this->send("501 Syntax error in parameters or arguments.");
		} else {
			$this->transfertype = $type;
			$this->send("200 type set.");
		}
	}

	public function cmd_size() {
		$file = trim($this->parameter);
		
		if (strpos($file, "..") !== false) {
		    $this->send("550 Permission denied.");
		    return;
		}

		$io = &$this->io;

		if (! $this->io->exists($file)) {
			$this->send("553 Requested action not taken.");
			return;
		}

		$size = $io->size($file);

		if ($size === false) {
			$this->send("553 Requested action not taken.");
			return;
		}

		$this->send("213 " . $size);
	}

	public function cmd_noop() {
		$this->send("200 Nothing Done.");
	}
	
	/*
	NAME: site
	SYNTAX: site <command> <parameters>
	DESCRIPTION: server specific commands...
	NOTE: chmod feature built in by Phanatic (01/01/2003)
	*/
	public function cmd_site() {
	
	    $p = explode(" ", $this->parameter);
	
	    switch (strtolower($p[0])) {
			case "uid":
			    $this->send("214 UserID: ".$this->user_uid);
		    	break;
			case "gid":
			    $this->send("214 GroupID: ".$this->user_gid);
			    break;
			case "chmod":
			    if (!isset($p[1]) || !isset($p[2])) {
				$this->send("214 Not enough parameters. Usage: SITE CHMOD <mod> <filename>.");
			    } else {
				if (strpos($p[2], "..") !== false) {
				    $this->send("550 Permission denied.");
				    return;
				}
			    
				if (substr($p[2], 0, 1) == "/") {
				    $file = $this->io->root.$p[2];
				} else {
				    $file = $this->io->root.$this->io->cwd.$p[2];
				}
				if (!$this->io->exists($p[2])) {
				    $this->send("550 File or directory doesn't exist.");
				    return;
				}
				
				if (!$this->io->can_write($file)) {
				    $this->send("550 Permission denied.");
				} else {
				    $p[1] = escapeshellarg($p[1]);
				    $file = escapeshellarg($file);
				    exec("chmod ".$p[1]." ".$file, $output, $return);
				    if ($return != 0) {
					$this->send("550 Command failed.");
				    } else {
					$this->send("200 SITE CHMOD command successful.");
				    }
				}
			    }
			    break;
	
			default:
			    $this->send("502 Command not implemented.");
	    }
	}

	public function data_open() {

		if ($this->pasv) {
			
			if (! $conn = @socket_accept($this->data_socket)) {

				$this->log->write($this->user.": server: Client not connected\n");
				return false;
			}

			if (! socket_getpeername($conn, $peer_ip, $peer_port)) {
				$this->log->write($this->user.": server: Client not connected\n");
				$this->data_conn = false;
				return false;
			} else {
				$this->log->write($this->user.": server: Client connected ($peer_ip:$peer_port)\n");
			}

			$this->data_conn = $conn;

		} else {

			$fsp = fsockopen($this->data_addr, $this->data_port, $errno, $errstr, 30);

			if (! $fsp) {
				$this->log->write($this->user.": server: Could not connect to client\n");
				return false;
			}

			$this->data_fsp = $fsp;
		}

		return true;
	}

	public function data_close() {
		if (! $this->pasv) {
			if (is_resource($this->data_fsp)) fclose($this->data_fsp);
			$this->data_fsp = false;
		} else {
			socket_close($this->data_conn);
			$this->data_conn = false;
		}
	}

	public function data_send($str) {

		if ($this->pasv) {
			socket_write($this->data_conn, $str, strlen($str));
		} else {
			fputs($this->data_fsp, $str);
		}
	}

	public function data_read() {
		if ($this->pasv) {
			return socket_read($this->data_conn, 1024);
		} else {
			return fread($this->data_fsp, 1024);
		}
	}

	public function data_eol() {
		$eol = ($this->transfertype == "A") ? "\r\n" : "\n";
		$this->data_send($eol);
	}


	public function send($str) {
		socket_write($this->connection, $str . "\n");
		if (! $this->loggedin) {
		    $this->log->write("server: $str\n");
		} else {
		    $this->log->write($this->user.": server: $str\n");
		}
	}

	public function transfer_text() {
		return ($this->transfertype == "A") ? "ASCII mode" : "Binary mode";
	}

}
?>
