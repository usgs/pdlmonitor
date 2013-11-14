pdlmonitor
==========

pdlmonitor provides a tool to check the status of a Product Distribution Layer (PDL) installation.

INSTALLATION/CONFIGURATION
--------------------------

This code depends on PHP 5 or higher.

Download this <a href="https://github.com/usgs/pdlmonitor/archive/master.zip">zip file</a>

Unpack the zip file in a location of your choice.  Then edit the nagios_config.ini file to reflect the location
of your PDL installation.  The distributed .ini file has [PDLHOME] to indicate where these edits need to take place,
and assumes that your PDL data directory is underneath the directory where the init.sh script is installed.  Edit accordingly
if your setup differs from this.

USAGE
-----
After configuration, at the command line, run:

<pre>
php check.php
</pre>

You should see output that looks something like this:

<pre>
PDL is okay.
Host = hostname
Date = 2013-11-14 15:35:05

[Success] Product Distribution running (pid=5596)
[Success] File [/home/user/ProductClient/data/receiver_index.db] age [3 seconds] is okay.
[Success] File [/home/user/ProductClient/heartbeat.dat] age [3 seconds] is okay.
[Success] Total committed memory [104804352 bytes] okay.
</pre>