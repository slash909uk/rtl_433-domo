<?php
// S Ashby, 17/10/2022, created
// data adaptor to translate rtl_433 radio messages into domoticz
// mapping: Oil-SonicStd: ID->IDX (two values), depth_cm -> oil (cm) left 
// mapping: Oregon THN132N: ID->IDX, Temp
// mapping: Oregon THGR810: ID->IDX, Temp, Humidity

echo "RTL-433->Domo started\n";

require "../phpMQTT-master/phpMQTT.php";

$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = "rtl433-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()

// config
$rtl_host = 'DiskStation';
$config = [
	['model' => 'Oil-SonicStd','rtl_id' => 31637, 'rtl_timeout' => 7200, 'idx' => 3390, 'tank_empty' => 120, 'min_rssi' => -6, 'alarm_drop' => 3],
	['model' => 'Oregon-THN132N', 'rtl_id' => 75, 'rtl_timeout' => 720,  'idx' => 3507],
	['model' => 'Oregon-THN132N', 'rtl_id' => 172, 'rtl_timeout' => 720, 'idx' => 3510],
	['model' => 'Oregon-THGR810', 'rtl_id' => 187, 'rtl_timeout' => 720, 'idx' => 3508],
	['model' => 'Oregon-THGR810', 'rtl_id' => 37, 'rtl_timeout' => 720, 'idx' => 3509],
];
//$rtl_id = 31637;
//$idx_level = 3390;
//$idx_rssi = 3389;
//$tank_empty = 120;
//$min_rssi = -6; // min received signal for valid reading since I notied spurious data at about -10dB RSSI, maybe another tank sensor?
//$rtl_timeout = 7200; // 2hrs before timeout for sensor readings
//$alarm_drop = 3; // max amount dropped between readings before an aalrm is ent to indicate leak/theft
//$last_depth = $tank_empty; // start here so no alarm on first reading

// debug
$debug=false;

// include Syslog class for remote syslog feature
require "../Syslog-master/Syslog.php";
Syslog::$hostname = "localhost";
Syslog::$facility = LOG_DAEMON;
Syslog::$hostToLog = "rtl433-domo";

function report($msg, $level = LOG_INFO, $cmp = "rtl433-domo") {
	global $debug;
	if($debug) echo "rtl433-domo:".$level.":".$msg."\n";
    Syslog::send($msg, $level, $cmp);
}

// build tracking data array using rtl_id as key for faster lookup to events
$tracking = array();
$now = time();
foreach ($config as $cid => $c) {
	$tracking[$c['rtl_id']] = ['cid' => $cid, 'last_seen' => $now];
}
report('RTL433->Domo tracking devices: '.count($tracking),LOG_NOTICE);

$mqtt = new phpMQTT($server, $port, $client_id);
$lasttelemetry = time();
//$last_seen = 0;
$telemetry_sec = 600;
$rtl_events = 'rtl_433/'.$rtl_host.'/events';

// infinite loop here
$ct = 0;
while (true) {
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('RTL433->Domo cannot connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('RTL433->Domo connected to queue:'.$server.':'.$port,LOG_NOTICE);

		$topics[$rtl_events] = array("qos" => 0, "function" => "procmsg");
		$topics['rtl_433/cmd'] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);

		while($mqtt->proc()){
			$now=time();
			// check for timeouts on devices
			foreach ($tracking as $rtl_id => $t) {
				$rtl_timeout = $config[$t['cid']]['rtl_timeout'];
				if($t['last_seen'] < $now-$rtl_timeout) {
					report('RTL433->Domo alarm; devicetimeout! RTL ID: '.$rtl_id,LOG_ALERT);
					$tracking[$rtl_id]['last_seen'] = $now;
					$ct += 1;
				}
			}
			// do telemetry for process
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'RTL433->Domo telemetry; timeouts in last period: '.$ct;
				$ct = 0;
				report($tele,LOG_INFO);
				$lasttelemetry = $now;
			}
			sleep(1); // proc() is non-blocking so dont hog the CPU!
		}

		// proc() returned false - reconnect
		report('RTL433->Domo lost connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $config;
	global $tracking;
	global $mqtt;
	global $debug;
	global $ct;
	//global $last_seen;
	global $telemetry_sec;
	global $rtl_events;
	//global $rtl_id;
	//global $idx_level;
	//global $idx_rssi;
	//global $tank_empty;
	//global $min_rssi;
	//global $alarm_drop;
	//global $last_depth;
	$now = time();
	$id=null;
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	if($debug) echo 'msg from:'.$topic."\n";
	if ($topic=='rtl_433/cmd') {
		if($debug) echo "cmd:".$msg."\n";
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->now = $now;
			$data->timeout_count = $ct;
			$data->tracking_data = (object)$tracking;
			$msg = JSON_encode($data);
			$mqtt->publish('rtl_433/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->debug = $debug;
			$data->mqtt_topic = $rtl_events;
			$data->telemetry_sec = $telemetry_sec;
			$data->device_config = (object)$config;
			$msg = JSON_encode($data);
			$mqtt->publish('rtl_433/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='debug') {
			$debug = !$debug; // toggle and report debug state
			$data = new stdClass();
			$data->cmd = "debug";
			$data->debug = $debug;
			$msg = JSON_encode($data);
			$mqtt->publish('rtl_433/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
	}
	else if ($topic==$rtl_events) {
		// RTL event - decode and filter / translate
		$data = JSON_decode($msg);
		if($debug) echo $rtl_events.":".$msg."\n";
		// skip if id not found
		if(empty($data->id)) {
			if($debug) echo 'no id';
			return;
		}
		// search for ID and proccess by class
		if(array_key_exists($data->id,$tracking)) {
			$rtl_id = $data->id;
			$rssi = $data->rssi;
			$c = $config[$tracking[$rtl_id]['cid']];
			$nvalue = null;
			$svalue = null;
			$batt = 100;
			report('found ID:'.$rtl_id,LOG_DEBUG);
			switch ($data->model) {
			case 'Oil-SonicStd':
				// skip if RSSI too low and alert since no battery indicator!
				$min_rssi = $c['min_rssi'];
				if($rssi < $min_rssi) {
					report('RTL433->Domo: signal too low from device: '.$rtl_id,LOG_NOTICE);
					$batt = 1;
					break;
				}
				// calcualte depth of oil from depth of air
				$depth_cm = $data->depth_cm;
				$tank_empty = $c['tank_empty'];
				$nvalue = 0;
				$svalue = strval($tank_empty - $depth_cm);
				// alert if level moves by large amount
				$alarm_drop = $c['alarm_drop'];
				$last_depth = $tracking[$rtl_id]['last_depth'];
				if($depth_cm > $last_depth + $alarm_drop)
					report('RTL433->Domo: Alarm! Oil level dropped by: '.($depth_cm - $last_depth).'cm',LOG_ALERT);
				if($depth_cm < $last_depth - 2)
					report('RTL433->Domo: Oil level increased by: '.($last_depth - $depth_cm).'cm',LOG_ALERT);
				$tracking[$rtl_id]['last_depth'] = $depth_cm;
				report('RTL433->Domo: Oil sensor reading: '.$depth_cm.' RSSI: '.$rssi,LOG_INFO);
				break;
			case 'Oregon-THN132N':
				// extract temp and battery ok only
				$batt = $data->battery_ok ? 100 : 1;
				$nvalue = 0;
				$svalue = strval($data->temperature_C);
				break;
			case 'Oregon-THGR810':
				// extract temp, humidity and battery ok
				$batt = $data->battery_ok ? 100 : 1;
				$nvalue = 0;
				$svalue = $data->temperature_C.';'.$data->humidity.';0';
				break;
			}
			// construct Domoticz update and send it
			$data = new stdClass();
			$data->idx = $c['idx'];
			$data->nvalue = $nvalue;
			$data->svalue = $svalue;
			$data->Battery = $batt;
			$data->RSSI = floor($rssi+12);  // adjust for 0-12 Domoticz scale corresponds to -12-0 RTL433 input.
			$msg = JSON_encode($data);
			$mqtt->publish('domoticz/in',$msg,0);
			$tracking[$rtl_id]['last_seen'] = $now;
			report('sending: '.$msg,LOG_DEBUG);
		}
	}
	else {
		// Unknown message source - ignore
		return;
	}
}
