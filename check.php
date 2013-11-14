<?php

	include_once 'Monitor.class.php';
	$monitor = new Monitor('nagios_config.ini');

	$monitor->runChecks();

	print $monitor->getOutputSummary() . $monitor->getOutputDetails();

	exit($monitor->getReturnStatus());
?>
