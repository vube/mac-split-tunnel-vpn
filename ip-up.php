#!/usr/bin/php
<?php
/**
 * Mac OS X Split Tunnel VPN Routing Manager
 *
 * Install this to /etc/ppp/ip-up using the following command:
 *
 *     sudo install -c -m 0755 ip-up.php /etc/ppp/ip-up
 *
 * Then edit /etc/ppp/routes.json so that it contains your routing config,
 * for example like this:
 * 
 * { "remotes": {
 *     "1.2.3.4": [
 *         "9.8.7"
 *     ]
 * }}
 *
 * The config above will route the entire class C block 9.8.7 through your
 * VPN whose IP is 1.2.3.4
 * 
 * Want to connect to multiple VPNs at the same time, and only send each
 * VPN's traffic to the correct VPN?  Just add more remote VPN IPs, and list
 * all of the net blocks to route through each one.
 *
 * When the ppp link comes up, this script is called with the following
 * parameters
 *       $0      the path to this script
 *       $1      the interface name used by pppd (e.g. ppp3)
 *       $2      the tty device name
 *       $3      the tty device speed
 *       $4      the local IP address for the interface
 *       $5      the remote IP address
 *       $6      the parameter specified by the 'ipparam' option to pppd
 *
 * NOTE: This script MUST be running as root, otherwise you will
 * get notices about inability to change routing tables.  When /etc/ppp/ip-up
 * is run, it is run as root.  If you are testing this, you must sudo when you
 * run this if you actually want to modify routing tables.
 *
 * @author Ross Perkins <ross@vubeology.com>
 * @source https://github.com/vube/mac-split-tunnel-vpn
 */

/**
 * Path to the JSON config file
 * @var string
 */
$JSON_CONFIG_FILE = "/etc/ppp/routes.json";

/**
 * Path to the log file we will write
 * @var string
 */
$LOG_FILE = "/tmp/ppp.ip-up.log";

/**
 * Log File Handle
 *
 * NULL unless/until we successfully open the log file for writing,
 * thereafter, the return code of fopen()
 * @var int
 */
$lfh = null;

/**
 * Argument names/descriptions
 * @var array
 */
$argNames = array(
    '[0] path to this script',
    '[1] pppd Interface name',
    '[2] TTY device name',
    '[3] TTY device speed',
    '[4] Local IP',
    '[5] Remote IP',
    '[6] pppd ipparam option',
);

/**
 * Warn the user of an unexpected potential issue
 *
 * All arguments treated as substrings that are concatenated and
 * printed to STDERR.
 *
 * If the log file is open for writing, warnings are also written
 * to the log.
 *
 * @return void
 */
function warn() {
    
    $msg = implode("", func_get_args());

    $bt = debug_backtrace(0, 1);
    $file = $bt[0]['file'];
    $line = $bt[0]['line'];

    $warning = "ip-up.php Warning: $msg at $file line $line\n";

    fwrite(STDERR, $warning);
    
    // If the log file is open, write the warning to the log as well
    if($GLOBALS['lfh']) {
        fwrite($GLOBALS['lfh'], $warning);
    }
}

/**
 * Notify the user of an error
 *
 * All arguments treated as substrings that are concatenated and
 * printed to STDERR.
 *
 * If the log file is open for writing, errors are also written
 * to the log.
 *
 * @return void
 */
function err() {
    
    $msg = implode("", func_get_args());
    
    $bt = debug_backtrace(0, 1);
    $file = $bt[0]['file'];
    $line = $bt[0]['line'];
    
    $error = "ip-up.php ERROR: $msg at $file line $line\n";
    
    fwrite(STDERR, $error);

    // If the log file is open, write the error to the log as well
    if($GLOBALS['lfh']) {
        fwrite($GLOBALS['lfh'], $error);
    }
}

/**
 * Open the log file
 *
 * If there are any errors trying to open, on the first error write out a
 * warning message.  Inability to log is not a fatal condition.
 *
 * @return mixed fopen() result; FALSE if fopen() failed
 */
function openLogFile() {
    
    // Yuck, globals.  W/e this is just a simple script
    global $lfh, $LOG_FILE;
    
    // Remember if we failed to open the log earlier in this execution
    static $failed = false;
    
    // If we haven't yet failed to open, try again if needed
    if(! $failed) {
        
        // If file isn't yet open, try to open it
        if(! $lfh) {
            if(! ($lfh = fopen( $LOG_FILE, "w" ))) {
            
                // Failed to open.  Log a message.
                warn("Cannot open log file: $LOG_FILE");
            
                // Remember we failed so we don't see a warning for every
                // attempted log message in the future.  1 warning is enough.
                $failed = true;
            }
            else {
                // Make the file world writeable, so ordinary users can delete
                // it.  It's nothing critical and it's a pain to have to sudo
                // to view/remove this.
                @chmod($LOG_FILE, 0666);
            }
        }
    }

    return $lfh;
}

/**
 * Write a log message
 *
 * All arguments treated as substrings that are concatenated and
 * printed to to the log.
 *
 * @return void
 */
function logMessage() {
    
    // Yuck, globals.  W/e this is just a simple script
    global $lfh;
    
    $msg = implode("", func_get_args());
    
    # Add trailing newline to log message if there is not already one
    if(! preg_match("/\n$/s", $msg)) {
        $msg .= "\n";
    }
    
    # Open the log if needed, then write the message to the log
    if(openLogFile()) {
        if( ! fwrite($lfh, $msg)) {
            warn("Error writing to log file");
        }
    }
}

/**
 * Get a string dump of program arguments
 *
 * The value returned from here is intended to be printed to the log so
 * users can see how the script is being invoked.
 *
 * @return string
 */
function getArgumentsDump($args) {

    $dump = [];
    $n = count($args);

    for($i=0; $i<$n; $i++) {
        $dump[] = "\t" . $GLOBALS['argNames'][$i] . ": '" . $args[$i] . "'";
    }
    
    return implode("\n", $dump);
}

/**
 * Convert a JSON object into an Assoc Array
 *
 * If the JSON object isn't really an object, print a warning message and
 * return NULL.
 *
 * If the JSON object is an object, convert it to an assoc array and return
 * that.
 *
 * @return array|NULL
 */
function expectObjectGetArray($obj, $errMsg='Data type error') {
    
    if(! is_object($obj)) {

        warn("$errMsg, expected an object, found ".gettype($obj));
        return null;
    }
    
    return get_object_vars($obj);
}

/**
 * Read in the routes.json config
 *
 * @return array Assoc array of decoded JSON config
 */
function getRoutes() {

    // Read in the JSON_CONFIG_FILE
    if(! ($data = @file_get_contents($GLOBALS['JSON_CONFIG_FILE']))) {
        warn("No config data found in {$GLOBALS['JSON_CONFIG_FILE']}, or file is not readable");
        return null;
    }

	// Remove comments from the JSON string before we parse it
	// Comments aren't technically part of the JSON spec but especially when hand-editing config files,
	// it's real nice to have them.
	//
	// This is a very simple parser, it just removes // comments and doesn't pay attention
	// to any sort of spacing etc.  That's OK since the string "//" should never appear in
	// our config anyway, as simple as it currently is.
	$data = preg_replace(",\s*//.*,", "", $data);
//	logMessage("JSON data after preg_replace:\n", $data); // DEBUG

    // decode the JSON into an object
    $json = json_decode($data);
    if($json === null) {
        warn("Cannot parse json data in {$GLOBALS['JSON_CONFIG_FILE']}");
        return null;
    }

    // Convert the JSON object into an assoc array
    $vars = expectObjectGetArray($json, "Invalid data contained in {$GLOBALS['JSON_CONFIG_FILE']}");

    // Make sure at the VERY LEAST there are remotes defined in this array
    if(! isset($vars['remotes'])) {
        warn("No remotes specified in {$GLOBALS['JSON_CONFIG_FILE']}, treating config as empty");
        return null;
    }

    return $vars;
}

/**
 * Configure the routes that should go to the current VPN
 *
 * NOTE: This script MUST be running as root, otherwise you will
 * get notices about inability to change routing tables.  When /etc/ppp/ip-up
 * is run, it is run as root.  If you are testing this, you must sudo when you
 * run this if you actually want to modify routing tables.
 *
 * @return void
 */
function setRoutes($remoteRoutes) {
    
    $n = count($remoteRoutes);
    
    // Which interface to send the network to (ppp0, ppp1, etc)
    $interface = $_SERVER['argv'][1];
 
    // For every network that should use this route, configure it like:
    //   /sbin/route add -net $net -interface $interface 2>&1
    
    for($i=0; $i<$n; $i++) {

        // The network to route
        $net = $remoteRoutes[$i];

        // System command to execute
        $command = '/sbin/route add -net ' . escapeshellarg($net)
        . ' -interface ' . escapeshellarg($interface)
        . ' 2>&1';

        logMessage("Exec: $command");

        // Execute the command, capture its output and its exit code
        exec($command, $output, $r);

        // Convert multi-line output to a single string
        if(is_array($output) && count($output)) {
            $output = implode("\n", $output);
        }
        // Log any output we received
        if($output !== '') {
            logMessage($output);
        }

        // If the command failed to exit with success code, bail out
        // and exit with the same failure code as the shell command used.
        if($r !== 0) {
            err("ABORT: route add failed, see log for details");
            exit($r);
        }
    }
}


/**
 * Main
 * @return int Program exit code
 */
function main() {

    logMessage("VPN Connection at ", date("Y-m-d H:i:s"));
    logMessage("System arguments:\n", getArgumentsDump($_SERVER['argv']));

    // Load in the routes.json config
    $routes = getRoutes();
    if(! $routes)
        return 1;

    $remoteIp = $_SERVER['argv'][5];
    $remoteIpConfigs = expectObjectGetArray($routes['remotes'], "Invalid remotes value in routes.json");

    // If we have a list of networks to send to this remote IP, set them
    if(isset($remoteIpConfigs[$remoteIp])) {

        logMessage("Configuring routes for $remoteIp");
        setRoutes($remoteIpConfigs[$remoteIp]);
    }
    else { // this remote IP is not known by the config
        
        logMessage("Notice: No routes configured for remote $remoteIp");
    }
    
    return 0;
}


return main();
