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
 */

$JSON_CONFIG_FILE = "/etc/ppp/routes.json";

$LOG_FILE = "/tmp/ppp.ip-up.log";
$lfh = null;

$argNames = array(
    '[0] path to this script',
    '[1] pppd Interface name',
    '[2] TTY device name',
    '[3] TTY devide speed',
    '[4] Local IP',
    '[5] Remote IP',
    '[6] pppd ipparam option',
);


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


function getArgumentsDump($args) {
    $dump = [];
    $n = count($args);

    for($i=0; $i<$n; $i++) {
        $dump[] = "\t" . $GLOBALS['argNames'][$i] . ": '" . $args[$i] . "'";
    }
    
    return implode("\n", $dump);
}


function expectObjectGetArray($obj, $errMsg='Data type error') {
    
    if(! is_object($obj)) {

        warn("$errMsg, expected an object, found ".gettype($obj));
        return null;
    }
    
    return get_object_vars($obj);
}


function getRoutes() {

    if(! ($data = @file_get_contents($GLOBALS['JSON_CONFIG_FILE']))) {
        warn("No config data found in {$GLOBALS['JSON_CONFIG_FILE']}, or file is not readable");
        return null;
    }

    $json = json_decode($data);
    if($json === null) {
        warn("Cannot parse json data in {$GLOBALS['JSON_CONFIG_FILE']}");
        return null;
    }

    $vars = expectObjectGetArray($json, "Invalid data contained in {$GLOBALS['JSON_CONFIG_FILE']}");

    if(! isset($vars['remotes'])) {
        warn("No remotes specified in {$GLOBALS['JSON_CONFIG_FILE']}, treating config as empty");
        return null;
    }

    return $vars;
}


function setRoutes($remoteRoutes) {
    
    $n = count($remoteRoutes);
    
    $interface = $_SERVER['argv'][1];
    
    for($i=0; $i<$n; $i++) {

        $net = $remoteRoutes[$i];

        // /sbin/route add -net $net -interface $interface 2>&1
        $command = '/sbin/route add -net ' . escapeshellarg($net)
        . ' -interface ' . escapeshellarg($interface)
        . ' 2>&1';

        logMessage("Exec: $command");

        exec($command, $output, $r);

        // Handle multi-line output
        if(is_array($output) && count($output)) {
            $output = implode("\n", $output);
        }
        // Handle any output at all
        if($output !== '') {
            logMessage($output);
        }

        if($r !== 0) {
            err("route add failed, abort");
            exit($r);
        }
    }
}


function main() {

    logMessage("VPN Connection at ", date("Y-m-d H:i:s"));
    logMessage("System arguments:\n", getArgumentsDump($_SERVER['argv']));

    $routes = getRoutes();
    if(! $routes)
        return;

    $remoteIp = $_SERVER['argv'][5];
    $remoteIpConfigs = expectObjectGetArray($routes['remotes'], "Invalid remotes value in routes.json");

    if(isset($remoteIpConfigs[$remoteIp])) {

        logMessage("Configuring routes for $remoteIp");
        setRoutes($remoteIpConfigs[$remoteIp]);
    }
    else {
        
        logMessage("No routes configured for remote $remoteIp");
    }
}


main();

