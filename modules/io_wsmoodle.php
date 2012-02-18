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

class io_wsmoodle {

    public $parameter;
    public $root;
    public $cwd;
    
    private $CFG;
    private $wwwroot;
    private $user;
    private $pass;
    private $cache;
    private $dircontext;
    private $fileinfocache;
    
    private $lastresp;

    private $fp;
    private $userdir;
    private $wstoken;
    
    public function __construct($CFG) {        
        $this->CFG = $CFG;
        $this->wwwroot = $CFG->moodle['wwwroot'];
        $this->cache = array();
        $this->dircontext[md5('/')] = array('contextid' => '0', 'component' => '','filearea' => '','itemid' => '0','filepath' => '','filename' => '');
    }
    
    public function auth($user,$pass){

        $this->cwd = '/';
        $this->user = $user;
        $this->pass = $pass;        
        
        $this->userdir = $this->CFG->tmpdir.'/'.md5($this->user);
        
        // If this fails if because the user and password are not valid
        if($this->ls() === false){
            return false;
        }
        
        if(!file_exists($this->userdir)){
            mkdir($this->userdir);
        }
        
        return true;
    }
    
    public function can_read($f) {
        //mftp - Allways true
        return true;
    }
    
    public function can_write($f) {
        //mftp - Allways true
        return true;
    } 

    public function cwd() {

        $dir = trim($this->parameter);
        $cwd_path = preg_split("/\//", $this->cwd, -1, PREG_SPLIT_NO_EMPTY);
        $new_cwd = "";

        switch (TRUE) {
            case (! strlen($dir)):
                return $this->cwd;

            case ($dir == ".."):
                if (count($cwd_path)) {
                    array_pop($cwd_path);
                    $terminate = (count($cwd_path) > 0) ? "/" : "";
                    $new_cwd = "/" . implode("/", $cwd_path) . $terminate;
                } else {
                    return false;
                }
                break;

            case (substr($dir, 0, 1) == "/"):
                if (strlen($dir) == 1) {
                    $new_cwd = "/";
                } else {
                    $new_cwd = rtrim($dir, "/") . "/";
                }
                break;

            default:
                $new_cwd = $this->cwd . rtrim($dir, "/") . "/";
                break;
        }
        
        if (strpos($new_cwd, "..") !== false) return false;

        
        $this->cwd = $new_cwd;
                        
        return $this->cwd;
        
    }

    public function pwd() {
        return $this->cwd;
    }

    public function ls() {
        $list = array();
                
        $this->debug("LS $this->cwd");
                
        if(! isset($this->cache[md5($this->cwd)])){
            
            $this->debug(" Dir not cached: $this->cwd");
            
            $filesinfo = $this->fileinfo_cached($this->cwd);            
            
            if($filesinfo === false){
                
                $this->debug(" File info not cached: $this->cwd");
                
                // The FTP clients cache allow you to refresh or select a directory without browsing the parent ones
                if(!isset($this->dircontext[md5($this->cwd)])){                    
                    $this->load_previous_contexts();
                }
                
                $this->debug(" Getting files: $this->cwd");
                $files = $this->ws_get_dir_files($this->cwd);           
                    
                // Something bad, the WS call failed
                if(isset($files->DEBUGINFO)){
                    $this->debug(" WS call failed: $this->cwd");
                    return false;
                }
                
                // Empty list
                if(!isset($files->SINGLE->KEY[1]->MULTIPLE->SINGLE)){
                    $this->debug(" No files found: $this->cwd\n ".var_export($files, true));
                    return array();
                }
                            
                $filesinfo = $this->prepare_fileinfo($files);
                
                // cache
                $this->debug(" Caching filesinfo: $this->cwd");
                file_put_contents($this->userdir.'/'.md5($this->cwd),serialize($filesinfo));
            }
            
            // Time and size are unknown, the webservice does not returns it
            foreach($filesinfo as $fileinfo){
                $info = array(
                    "name" => $fileinfo['filename']
                    ,"size" => '1'
                    ,"owner" => $this->user
                    ,"group" => $this->user
                    ,"time" => ''
                    ,"perms" => ($fileinfo['isdir'])? 'drwxrwxrwx' : '-rw-rw-rw-'
                );
                
                $this->load_context($fileinfo);
                
                $this->cache[md5($this->cwd)][] = $info;
            }
        }
        else{
            $this->debug(" Dir cached: $this->cwd");
        }
    
        $list = isset($this->cache[md5($this->cwd)])? $this->cache[md5($this->cwd)] : array();
            
        return $list;
    }

    public function rm($filename) {
        if (substr($filename, 0, 1) == "/") {
        return unlink($this->root . $filename);
        } else {    
        return unlink($this->root . $this->cwd . $filename);
        }
    }

    public function size($filename) {
        return 1;
    }

    public function exists($filename) {
        $fullpath = md5($this->cwd.$filename.'/');
        $this->debug( "EXISTS ".$this->cwd.$filename."/");
        if(! isset($this->fileinfocache[$fullpath])){
            $this->load_previous_contexts();
        }
    
        return isset($this->fileinfocache[$fullpath]);
    }

    public function type($filename) {
                
        $fullpath = md5($this->cwd.$filename.'/');
        $this->debug( "TYPE ".$this->cwd.$filename."/");
        if(! isset($this->fileinfocache[$fullpath])){
            $this->load_previous_contexts();
        }
                
        if(isset($this->fileinfocache[$fullpath])){
            return ($this->fileinfocache[$fullpath]['isdir'])? 'dir' : 'file';
        }
        
        $this->debug( " type check fails");
        return false;
    }
    
    public function md($dir) {
            if (substr($dir, 0, 1) == "/") {
        return (@mkdir($this->root . $dir));
        } else {
        return (@mkdir($this->root . $this->cwd . $dir));
        }
    }
    
    public function rd($dir) {
        if (substr($dir, 0, 1) == "/") {
        return (@rmdir($this->root . $dir));
        } else {
        return (@rmdir($this->root . $this->cwd . $dir));
        }
    }
    
    public function rn($from, $to) {
            if (substr($from, 0, 1) == "/") {
        $ff = $this->root . $from;
        } else {
        $ff = $this->root . $this->cwd . $from;
        }
    
        if (substr($to, 0, 1) == "/") {
        $ft = $this->root . $to;
        } else {
        $ft = $this->root . $this->cwd . $to;
            }
        
        return (rename($ff, $ft));
    }

    public function read($size) {
        return fread($this->fp, $size);
    }

    public function write($str) {
        fwrite($this->fp, $str);
    }

    public function open($filename, $create = false, $append = false) {
        clearstatcache();    
        $type = ($create) ? "w" : "r";
        $type = ($append) ? "a" : $type;
        
        $this->currentcmd = $type;
        
        $fullpath = md5($this->cwd.$filename.'/');
        
        // tmp dir for working with files in local
        $dirpath = $this->CFG->tmpdir.'/'.md5($this->cwd);
        if(!is_dir($dirpath)){
            // TODO - Handle return in callers
            if(!mkdir($dirpath)){	
                return false;
            }
        }
        
        // Download the file from Moodle
        if($type == 'r'){         
            $url = '';
            $localpath = $dirpath.'/file_'.$fullpath;
            $this->fp = fopen($localpath, 'w');
            // Getting url for download
            $dirdata = $this->fileinfocache[$fullpath];
            $url = $dirdata['url'];
            $url = str_replace('/pluginfile.php', '/webservice/pluginfile.php', $url);
            $url .= '?token='.$this->ws_get_token();
            // Download using curl
            echo "$url - $localpath";
            $c = new curl;
            $data = $c->download(array(array('url'=>$url, 'file'=>$this->fp)));
            // Open again the file pointer
            $this->fp = fopen($localpath, 'r');
            $this->tmpfile = $localpath;
        }
        
        // Upload file to Moodle, currently all the upload goes to private files
        if($type == 'w'){
            $this->tmpfile = $dirpath.'/mftpd_'.$filename;
            $this->fp = fopen($this->tmpfile, 'w');            
        }
    }

    public function close() {
        fclose($this->fp);
        if ($this->tmpfile and $this->currentcmd == 'r') {
            unlink($this->tmpfile);
            $this->tmpfile = '';
        }
        if ($this->tmpfile and $this->currentcmd == 'w') {            
            $url = $this->wwwroot.'/webservice/upload.php';
            $params = array('file_box' => "@".$this->tmpfile, 'filepath' => '/', 'token' => $this->ws_get_token());                
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_VERBOSE, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params); 
            $response = curl_exec($ch);
            unlink($this->tmpfile);
            $this->tmpfile = '';
        }
    }
    
    //
    // Custom Moodle functions
    //
    
    private function ws_get_token(){    
        
        if($this->wstoken){
            return $this->wstoken;
        }
        
        $serverurl = $this->wwwroot.'/login/token.php?username='.$this->user.'&password='.$this->pass.'&service='.$this->CFG->moodle['wsshortname'];
        $curl = new curl;      
        $resp = $curl->get($serverurl);
        
        if($data = json_decode($resp)){
            $this->wstoken = $data->token;
            return $this->wstoken;
        }
        
        return false;    
    }
    private function prepare_fileinfo($files){
        
        $filesinfo = array();
        if (isset($files->SINGLE->KEY[1]->MULTIPLE->SINGLE)) {
            foreach($files->SINGLE->KEY[1]->MULTIPLE->SINGLE as $file){                                
                $info = array();
                foreach($file->KEY as $element){
                // hack for getting the attribute name
                $name = 'name';
                $name = (string) $element->attributes()->$name;            
                $info[$name] = trim((string) $element->VALUE[0]);  
                }
                // This is for calculate a valid cache time
                $info['timemodified'] = time();
                $filesinfo[] = $info;
            }
        }
        //print_r($filesinfo);
        return $filesinfo;
    }
    
    private function ws_get_dir_files($context){

        $this->wstoken = $this->ws_get_token();                        
    
        $serverurl = $this->wwwroot.'/webservice/rest/server.php?wstoken='.$this->wstoken.'&wsfunction=core_files_get_files';
        $curl = new curl;
        // this->context has the moodle specific params  
        
        $resp = $curl->post($serverurl, $this->dircontext[md5($context)]);
        $this->lastresp = $resp;
        
        return new SimpleXMLElement($resp); 
    }
    
    /*
     * Function for saving a context in the array of contexts and cache
     *
     */     
    private function load_context($fileinfo, $cwd=''){
        
        if(!$cwd){
            $cwd = $this->cwd;
        }
        $key = $cwd.$fileinfo['filename']."/";
        
        $this->debug("    Loading context: $key");
        
        $fcontext = array('contextid' => $fileinfo['contextid'], 
                                'component' => $fileinfo['component'],
                                'filearea' => $fileinfo['filearea'],
                                'itemid' => ($fileinfo['itemid'])? $fileinfo['itemid'] : 0,
                                'filepath' => $fileinfo['filepath'],
                                'filename' => ''                                
                                );
        
        $this->dircontext[md5($key)] = $fcontext;
        
        $fileinfo['cwd'] = $key;        
        $this->fileinfocache[md5($key)] = $fileinfo;                
                        
        $this->debug("    Context loaded: $key");
        
    }
    
    private function load_previous_contexts($context = ''){
        
        $cwd = $this->cwd;
        if($context){
            $cwd = $context;
        }
        
        $this->debug(" Loading previous context: $cwd");
        $contexts = explode('/',$cwd);
        
        // The last element in the array is a ''
        array_pop($contexts);
                
        $basepath = '';        
        foreach($contexts as $context){
            $basepath .= "$context/";
            
            $this->debug("   Loading previous context: $basepath");
            $filesinfo = $this->fileinfo_cached($basepath);
                        
            if($filesinfo === false){
                $this->debug("    Context not cached: $basepath");
                $files = $this->ws_get_dir_files($basepath);
                $filesinfo = $this->prepare_fileinfo($files);
                file_put_contents($this->userdir.'/'.md5($basepath),serialize($filesinfo));                
            }
            else {
                $this->debug("    Context cached (".count($filesinfo).") files: $basepath");
            }
            
            foreach($filesinfo as $fileinfo){                                
                $this->load_context($fileinfo, $basepath);
            }
            
        }
    }
    
    private function fileinfo_cached($path){
        $cachefile = $this->userdir.'/'.md5($path);
        if(file_exists($cachefile)){
            // Now we save in the tmp dir a cached version of the fileinfo for this user                
            $filesinfo = unserialize(file_get_contents($cachefile));                
            // TODO - Implement the expiration time of the cache
            
            if(is_array($filesinfo) && count($filesinfo) > 0){
                $finfo = $filesinfo[0];
                
                if(time() - $finfo['timemodified'] < $this->CFG->expirationtime){
                    return $filesinfo;
                }
            }
        }
        return false;
    }
    
    private function debug($text){
        echo $text."\n";
    }
    
}
?>