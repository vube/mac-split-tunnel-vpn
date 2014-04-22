Mac OS X Split Tunnel VPN Routing Manager
=========================================

This is a simple script that makes it super easy for you to manage one or more VPN
connections with split tunneling.

In particular this makes it very easy to connect to multiple VPNs simultaneously, and
all traffic is kept going to the right place at the right time.


# Installation

```bash
git clone https://github.com/ross-p/mac-split-tunnel-vpn.git
cd mac-split-tunnel-vpn
sudo install -c -m 0755 ip-up.php /etc/ppp/ip-up
cd /etc/ppp
sudo ln -sf $HOME/.routes.json routes.json
```


# Configuration

You only need a file in your home directory that contains the routes.  In the install
instructions, we symlink'd the `routes.json` config file to your home directory, a file
named `$HOME/.routes.json`

## Example `$HOME/.routes.json` file

```json
{ "remotes": {
	"1.2.3.4": [
		"9.8.7"
	]
} }
```

The above example will route all the traffic for the class C block 9.8.7.* to your VPN
server whose IP is 1.2.3.4

### More Advanced Example `$HOME/.routes.json` file

```json
{ "remotes": {
	"1.2.3.4": [
		"9.8.7",
		"8.7.6",
		"7.6.5"
	],
	"2.3.4.5": [
		"4.5.6",
		"5.6.7"
	]
} }
```

The above file configures 2 VPNs, 1.2.3.4 and 2.3.4.5

There are 3 networks routed through the 1.2.3.4 VPN: 9.8.7, 8.7.6 and 7.6.5

There are 2 networks routed through the 2.3.4.5 VPN: 4.5.6 and 5.6.7


# Reconnect to VPN for changes to take effect

After editing your $HOME/.routes.json file, you must disconnect from and reconnect to
your VPN for the changes to take effect.


Why use this
------------

This allows you to set up your VPN links such that the ONLY traffic that goes over
the VPN is traffic that really NEEDS to be on the VPN link.  All other traffic will
go over your default internet connection, which means you will have the fastest possible
Internet speed at all times.

This routing manager uses a JSON file to keep track of which routes you really need
to go to your VPN so then you can just edit that file if/when there are updates to it.
No need to think about system utilities etc.  Edit a file, reconnect to VPN, voila!


## Log for troubleshooting purposees

Each time you connect to a VPN, a log message is written in `/tmp/ppp.ip-up.log` so you
can see exactly what is happening.

### Example log

```
VPN Connection at 2014-04-22 12:15:01
System arguments:
	[0] path to this script: '/etc/ppp/ip-up'
	[1] pppd Interface name: 'ppp1'
	[2] TTY device name: ''
	[3] TTY devide speed: '0'
	[4] Local IP: '192.168.200.2'
	[5] Remote IP: '1.2.3.4'
	[6] pppd ipparam option: 'x.x.x.x'
Configuring routes for 1.2.3.4
Exec: /sbin/route add -net '9.8.7' -interface 'ppp1' 2>&1
add net 9.8.7: gateway ppp1
```