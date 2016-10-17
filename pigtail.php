<?php
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die("Pigtail must run in cli mode\n");
/*
* Copyright (C) 2015-2015 Paulo Angelo Alves Resende <pa@pauloangelo.com>
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License Version 2 as
* published by the Free Software Foundation.  You may not use, modify or
* distribute this program under any other version of the GNU General
* Public License.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
*
* MORE CREDITS
*  - Contribute and put your "Name <email> - Contribution"  here.
*
* Unfortunately, Snorby consider just events associated
* with packets and not flows or generic events. To overcome it,
* we add an incomplete iphdr.
*
* I'm really unhappy with this code. It could be much better.
* However, we are result-oriented, ie: make it run first and enhance
* in a second moment.
* Please do not consider this code as our best. :-)
*
*
* As future enhancements, we point out:
*  - Support for more DBMS
*  - Support for more GUI, beyond Snorby
*  - Maybe use some DB abstraction layer, ex. mdb2 or zend
*  - Maybe transform it in a C program *yes*
*
* Any volunteer?
*
* USING THIS SCRIPT
*
* 1. Certify that you have events in your HBase, generated by Hogzilla IDS
* 2. Run /usr/bin/php pigtail.php
* 3. If every thing is OK, you can the command below and put it in /etc/rc.local
*     /usr/bin/php pigtail.php >& /dev/null &
*
* ATTENTION: This PHP script must run in CLI!
*
* If you have any problems, let us know!
* See how to get help at http://ids-hogzilla.org/post/community/
*/

// Some useful variables
$hbaseHost="hoghbasehost"; /* Host or IP of your HBase  */
$hbasePort=9090;
$mysqlUser="snorby";
$mysqlDbName="snorby";
$mysqlPass="snorby123"; /* put your password here */
$mysqlHost="hogmysqlservername"; /* example */
$mysqlPort="3306";

// Some not so useful variables
$walFilePath=realpath(dirname(__FILE__))."/pig.wal";
$walClusterFilePath=realpath(dirname(__FILE__))."/pigCluster.wal";
$waitTime=600;
$GLOBALS['THRIFT_ROOT'] = '/usr/lib/php';

$graylog_host="177.220.1.6";
$sensorName="Hogzilla";

define("DEBUG",false);
define("GRAYLOG",true);
define("MYSQL",true); // Must be always true... :)  Future implementation... (TODO)


// GELF Stuff, for GrayLog
if(GRAYLOG)
{
   require_once __DIR__ . '/vendor/autoload.php';
}

// Thrift stuff
require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/ClassLoader/ThriftClassLoader.php');

$classLoader = new Thrift\ClassLoader\ThriftClassLoader();
$classLoader->registerNamespace('Thrift', $GLOBALS['THRIFT_ROOT']);
$classLoader->register();

require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/Transport/TSocket.php');
require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/Transport/TBufferedTransport.php');
require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/Protocol/TBinaryProtocol.php');
require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/Packages/Hbase/Hbase.php');
require_once($GLOBALS['THRIFT_ROOT'].'/Thrift/Packages/Hbase/Types.php');

$socket     = new Thrift\Transport\TSocket($hbaseHost, $hbasePort);
$socket->setSendTimeout(10000);
$socket->setRecvTimeout(20000);
$transport  = new Thrift\Transport\TBufferedTransport($socket);
$protocol   = new Thrift\Protocol\TBinaryProtocol($transport);
$client     = new Hbase\HbaseClient($protocol);


/**
* Comment-me!
*
* Insert Sensor Hogzilla if it doesn't exists
*/
function saveSensor($rowresult,$con)
{
   global $sensorid;
   global $sensor_hostname;

   if(DEBUG) {  echo "Save sensor into MySQL\n" ;}
   $values = $rowresult[0]->columns;
   $sensor_description      = $values["sensor:description"]->value;
   $sensor_hostname         = $values["sensor:hostname"]->value;

   // Check if this signature already exists.
   $sqlFetch="select sid,last_cid from sensor where name='$sensor_description' and hostname='$sensor_hostname'";
   $sensor=fetchDB($sqlFetch,$con);

   if($sensor==NULL)
   {
      $sql[]="insert into sensor(name,hostname,interface,detail,encoding,last_cid,pending_delete)
                        values('$sensor_description','$sensor_hostname','',1,0,0,0);";
      saveDB($sql,$con);
      $sqlFetch="select sid,last_cid from sensor where name='$sensor_description' and hostname='$sensor_hostname'";
      $sensor=fetchDB($sqlFetch,$con);
      $sensorid     =$sensor[0];
   }else
   {
      // Sensor already added
      $sensorid     =$sensor[0];
   }
}

/**
* Comment-me!
*
* Insert signature to Snorby's base
*/
function saveSignature($rowresult,$con) {

   global $sig_id;
   global $sig_data;

   if(DEBUG) {  echo "Save signature into MySQL\n" ;}

   $values = $rowresult[0]->columns;
   $signature_class     = $values["signature:class"]->value;
   $signature_name      = $values["signature:name"]->value;
   $signature_priority  = $values["signature:priority"]->value;
   $signature_revision  = $values["signature:revision"]->value;
   $signature_hid       = $values["signature:id"]->value;
   $signature_group_id  = $values["signature:group_id"]->value;

   $sig_data[$signature_hid]["signature_class"]      = $signature_class;
   $sig_data[$signature_hid]["signature_name"]       = $signature_name;
   $sig_data[$signature_hid]["signature_priority"]   = $signature_priority;
   $sig_data[$signature_hid]["signature_revision"]   = $signature_revision;
   $sig_data[$signature_hid]["signature_group_id"]   = $signature_group_id;

   // Check if this signature already exists.
   // And cache CID in array

   if(MYSQL)
   {
        $sqlFetch="select sig_id from signature where sig_sid='$signature_hid';";
        $sig=fetchDB($sqlFetch,$con);

        if($sig==NULL)
        {
           $sql[]="insert into signature(sig_class_id,sig_name,sig_priority,sig_rev,sig_sid,sig_gid) 
                         values('$signature_class','$signature_name','$signature_priority','$signature_revision','$signature_hid','$signature_group_id');";
           $sql[]="insert into reference(ref_system_id,ref_tag) 
                         values(8,'http://ids-hogzilla.org/signature-db/$signature_hid');";
           $sql[]="insert into sig_reference(sig_id,ref_seq,ref_id) 
                         values((select sig_id from signature where sig_sid='$signature_hid'),1,
                              (select ref_id from reference where ref_tag='http://ids-hogzilla.org/signature-db/$signature_hid' limit 1));";
           saveDB($sql,$con);
           $sqlFetch="select sig_id from signature where sig_sid='$signature_hid'";
           $sig=fetchDB($sqlFetch,$con);
           $sig_id[$signature_hid]=$sig[0];
        }else {
           $sig_id[$signature_hid]=$sig[0];
        }
   }
}

// I love you! Please stay more with us.
// $sql[]="insert into signature(sig_class_id,sig_name,sig_priority,sig_rev,sig_sid,sig_gid) values(3,'HZ: Suspicious DNS flow identified by K-Means clustering',2,1,826000001,826);";
// $sql[]="insert into reference(ref_system_id,ref_tag) values(8,'http://ids-hogzilla.org/signature-db/826000001');";
// $sql[]="insert into sig_reference(sig_id,ref_seq,ref_id) values((select sig_id from signature where sig_sid='826000001'),1,(select ref_id from reference where ref_tag='http://ids-hogzilla.org/signature-db/826000001'));";

/**
* Comment-me!
*/
function saveEvent($rowresult,$con, $cid)
{
   global $sensorid;
   global $sig_id;
   global $sig_data;
   global $sensor_hostname;
   global $sensorName;
   global $publisher;

   if(DEBUG) {  echo "Save event into MySQL\n" ;}

   $values          = $rowresult[0]->columns;
   $lower_ipa        = unpack("N",$values["event:lower_ip"]->value);
   $upper_ipa        = unpack("N",$values["event:upper_ip"]->value);
   $lower_ip        = $lower_ipa[1];
   $upper_ip        = $upper_ipa[1];
   $note_body       = $values["event:note"]->value;
   $signature_hid   = $values["event:signature_id"]->value;
   $lower_ip_str    = $values["event:lower_ip_str"]->value;
   $upper_ip_str    = $values["event:upper_ip_str"]->value;

   // GrayLog
   if(GRAYLOG)
   {
        // Snorby legacy
        //$ipaddr=long2ip($lower_ip);
        $ipaddr=$lower_ip_str;
        //$location = geoip_record_by_name($ipaddr);
        $ip_name=gethostbyaddr($ipaddr);

        $message = new Gelf\Message();
        $message->setShortMessage($sig_data[$signature_hid]["signature_name"]." - ".$ipaddr)
                ->setFullMessage($note_body)
                ->setAdditional("sensor_hostname",$sensorName)
                ->setAdditional("reference","http://ids-hogzilla.org/signature-db/$signature_hid")
                ->setAdditional("ip",$ipaddr)
                ->setAdditional("ports",$ports)
                ->setAdditional("signature",$sig_data[$signature_hid]["signature_name"])
                //->setAdditional("location",$location["city"]."/".$location["country_name"])
                ->setAdditional("dns_reverse",$ip_name);

        if($sig_data[$signature_hid]["signature_priority"]==1)
        {
            $message->setLevel(\Psr\Log\LogLevel::CRITICAL)
                    ->setAdditional("priority","CRITICAL");
        }elseif($sig_data[$signature_hid]["signature_priority"]==2)
        {
            $message->setLevel(\Psr\Log\LogLevel::WARNING)
                    ->setAdditional("priority","WARNING");
        }else
        {
            $message->setLevel(\Psr\Log\LogLevel::NOTICE)
                    ->setAdditional("priority","INFO");
        }

        $publisher->publish($message);
   }

// Actually, we have a flow and not a single packet.
// We are using iphdr below because it is needed to Snorby.
// insert into udphdr(sid,cid,udp_sport,udp_dport,udp_len,udp_csum) values(sid,cid,udp_sport,udp_dport,udp_len,udp_csum)

    $cur_sigid=$sig_id[$signature_hid];

    // If signatures doesn't exist, add it into DB
    if(strlen($cur_sigid)==0)
      saveSignaturesIfNeeded($con);

    if(MYSQL)
    {
        $sql[]="insert into event(sid,cid,signature,notes_count,type,number_of_events,timestamp)
                        values('$sensorid','$cid','$cur_sigid',1,1,0,now());";
        $sql[]="insert into iphdr(sid,cid,ip_src,ip_dst,ip_ver,ip_hlen,ip_tos,ip_len,ip_id,ip_flags,ip_off,ip_ttl,ip_proto,ip_csum)
                        values('$sensorid','$cid','$lower_ip','$upper_ip',4,0,0,0,0,0,0,0,0,0);";
        $sql[]="insert into notes(sid,cid,user_id,body,created_at,updated_at)
                        values($sensorid,$cid,1,'$note_body',now(),now());";

        saveDB($sql,$con);
    }
}

function saveCluster($rowresult)
{
   global $publisher;

   if(DEBUG) {  echo "Save cluster into GrayLog\n" ;}

   $clusterIdx         = $rowresult[0]->row;
   $values             = $rowresult[0]->columns;
   $cluster_title      = $values["info:title"]->value;
   $cluster_size       = $values["info:size"]->value;
   $cluster_centroid   = $values["info:centroid"]->value;
   $cluster_members_str= $values["info:members"]->value;

   $cluster_members    = explode(",",$cluster_members_str);

   foreach($cluster_members as $ipaddr)
   {
        //$location = geoip_record_by_name($ipaddr);
        $ip_name=gethostbyaddr($ipaddr);

        $message = new Gelf\Message();
        $message->setShortMessage($cluster_title)
                ->setAdditional("cluster_size",$cluster_size)
                ->setAdditional("cluster_centroid",$cluster_centroid)
                ->setAdditional("member_ip",$ipaddr)
                //->setAdditional("location",$location["city"]."/".$location["country_name"])
                ->setAdditional("dns_reverse",$ip_name)
                ->setAdditional("cluster_idx",$clusterIdx)
                ->setAdditional("priority","INFO")
                ->setAdditional("cluster_tag","\"$cluster_title\"")
                ->setLevel(\Psr\Log\LogLevel::NOTICE);

        $publisher->publish($message);
  }
}

function saveClusterMember($rowresult)
{
   global $publisher;

   if(DEBUG) {  echo "Save cluster member into GrayLog\n" ;}

   $values             = $rowresult[0]->columns;
   $title              = $values["info:title"]->value;
   $cluster_size       = $values["cluster:size"]->value;
   $cluster_centroid   = $values["cluster:centroid"]->value;
   $clusterIdx         = $values["cluster:idx"]->value;
   $cluster_title      = $values["cluster:description"]->value;
   $ports              = $values["member:ports"]->value;
   $frequencies        = $values["member:frequencies"]->value;
   $ipaddr             = $values["member:ip"]->value;
   $distance           = $values["member:distance"]->value;

   //$location = geoip_record_by_name($ipaddr);
   $ip_name=gethostbyaddr($ipaddr);

   $message = new Gelf\Message();
   $message->setShortMessage($title)
           //->setAdditional("location",$location["city"]."/".$location["country_name"])
           ->setAdditional("ip",$ipaddr)
           ->setAdditional("dns_reverse",$ip_name)
           ->setAdditional("ports",$ports)
           ->setAdditional("frequencies",$frequencies)
           ->setAdditional("cluster_tag","\"$cluster_title\"")
           ->setAdditional("cluster_size",$cluster_size)
           ->setAdditional("cluster_centroid",$cluster_centroid)
           ->setAdditional("cluster_idx",$clusterIdx)
           ->setAdditional("centroid_distance",$distance)
           ->setAdditional("priority","INFO")
           ->setLevel(\Psr\Log\LogLevel::NOTICE);

   $publisher->publish($message);
}


/**
* Comment-me!
*/
function fetchDB($sql,$con)
{
    if(DEBUG) {  echo $sql."\n" ;}
    $result = mysql_query($sql,$con);

    if (!$result)
    {
        die('Invalid query: ' . mysql_error());
    }else
    {
       $row = mysql_fetch_row($result);
       mysql_free_result($result);
       if(sizeof($row)==0)
         return NULL;
       else
         return $row;
    }
}

/**
* Comment-me!
*/
function saveDB($sql,$con)
{
    if(DEBUG) {  echo "Save data into MySQL\n" ;}
    // Begin transaction
    mysql_query("START TRANSACTION",$con);
    mysql_query("BEGIN",$con);

    // Execute each SQL line from $sql[]
    foreach($sql as $q)
    {
        // Execute the sql
        if(DEBUG) { echo $q."\n" ;}
        $return=mysql_query($q,$con);
        if(!$return)
        {
            mysql_query("ROLLBACK", $con);
            return true; /* we got an error */
        }
    }

    // Commit transaction
    mysql_query("COMMIT",$con);

    return false; /* everything OK */
}

/**
* Comment-me!
*/
function getNextHBaseRow()
{
    if(DEBUG) {  echo "Get next HBase row\n" ;}
    global $walFilePath;
    if(!file_exists($walFilePath))
    {
        $file = fopen($walFilePath, "w") or die("Unable to open WAL file $walFilePath !\n");
        fwrite($file,"0");
    }
    $file = fopen($walFilePath, "r") or die("Unable to open WAL file $walFilePath !\n");
    $return=fread($file,filesize($walFilePath));
    fclose($file);
    return $return;
}

function getClusterTimeStamp()
{
    if(DEBUG) {  echo "Get last Cluster Timestamp\n" ;}
    global $walClusterFilePath;
    if(!file_exists($walClusterFilePath))
    {
        $file = fopen($walClusterFilePath, "w") or die("Unable to open Cluster WAL file $walClusterFilePath !\n");
        fwrite($file,"0");
    }
    $file = fopen($walClusterFilePath, "r") or die("Unable to open Cluster WAL file $walClusterFilePath !\n");
    $return=fread($file,filesize($walClusterFilePath));
    fclose($file);
    return $return;
}

function saveClusterTimeStamp($timestamp)
{
    if(DEBUG) {  echo "Save Cluster Timestamp\n" ;}
    global $walClusterFilePath;
    $file = fopen($walClusterFilePath, "w") or die("Unable to open Cluster WAL file $walClusterFilePath !\n");
    fwrite($file,$timestamp);
    fclose($file);
}

/**
* Comment-me!
*/
function saveNextHBaseRow($lastHBaseID)
{
    if(DEBUG) {  echo "Save Next HBase row\n" ;}
    global $walFilePath;
    $file = fopen($walFilePath, "w") or die("Unable to open WAL file $walFilePath !\n");
    fwrite($file,$lastHBaseID);
    fclose($file);
}

/**
* Comment-me!
*/
function getLastCID($sensorid,$con)
{
   if(DEBUG) {  echo "Get last CID\n" ;}
   $sql="select last_cid from sensor where sid='$sensorid';";
   $return=fetchDB($sql,$con);
   if($sensorid!=NULL)
   {
       return $return[0];
   }else
   {
      die("Could not access MySQL DB!\n");
   }
}

/**
* Comment-me!
*/
function saveLastCID($sensorid,$cid,$con)
{
   if(DEBUG) {  echo "Save last CID\n" ;}
   $sql[]="update sensor set last_cid='$cid',events_count=(select count(*) from event where sid='$sensorid') where sid='$sensorid';";
   saveDB($sql,$con);
}

/**
* Comment-me!
*/
function saveSignaturesIfNeeded($con)
{
    global $client;

    // Insert Signatures if needed. Get Signature information
    if(DEBUG) {  echo "Insert signatures, if needed\n" ;}
    $scanner = $client->scannerOpenWithStop("hogzilla_signatures","","",
                        array("signature:class","signature:name","signature:priority",
                              "signature:revision","signature:id","signature:group_id"),
                        array());
    while (true)
    {
        $row=$client->scannerGet($scanner);
        if(sizeof($row)==0) break;
        saveSignature($row,$con);
    }
    $client->scannerClose($scanner);
}

/*
 * STARTUP PHASE: get sensor and signatures information.
 */
// Open connections
if(DEBUG) {  echo "Open connections\n" ;}
$transport->open();
if(MYSQL)
{
    $con=mysql_connect("$mysqlHost:$mysqlPort","$mysqlUser","$mysqlPass");
    mysql_select_db ($mysqlDbName,$con);
    if (!$con)
    { die('Could not connect to MySQL: ' . mysql_error()); }
}

// Insert Sensor if needed. Get Sensor information
if(DEBUG) {  echo "Insert sensor, if needed\n" ;}
$scanner = $client->scannerOpenWithStop("hogzilla_sensor","","", array("sensor:description","sensor:hostname"), array());
$row=$client->scannerGet($scanner);
if(sizeof($row)==0) { die("Sensor table is empty in HBase\n"); }
if(MYSQL)
    {saveSensor($row,$con); }
$client->scannerClose($scanner);

saveSignaturesIfNeeded($con);

// Close everything
if(DEBUG) {  echo "Close connections\n" ;}
if(MYSQL)
    {mysql_close($con);}
$transport->close();

/*
 * INFITY LOOP PHASE: fetch events from HBase, insert into MySQL, wait $waitTime and do everything again...
 */
while(true)
{
    try
    {
        if(DEBUG) {  echo "Inside loop\n" ;}
        // Open HBase and MySQL connection
        if(DEBUG) {  echo "Open connections\n" ;}
        $transport->open();
        if(MYSQL)
        {
           $con=mysql_connect("$mysqlHost:$mysqlPort","$mysqlUser","$mysqlPass");
           mysql_select_db ($mysqlDbName,$con);
           if (!$con)
           { die('Could not connect to MySQL: ' . mysql_error()); }
        }

        // GrayLog
        if(GRAYLOG)
        {
            $graylogTransport = new Gelf\Transport\UdpTransport($graylog_host, 12201, Gelf\Transport\UdpTransport::CHUNK_SIZE_LAN);
            $publisher = new Gelf\Publisher();
            $publisher->addTransport($graylogTransport);
        }

        // Get last CID from MySQL, this counter will be used on last access
        if(MYSQL)
            {$cid=getLastCID($sensorid,$con);}
        else
            {$cid=1;}

        // Get last seen event
        $startrow=getNextHBaseRow();
        if(DEBUG) { echo "Start row: $startrow \n"; }

        $lastHBaseID=0;

        // Get HBase pointer
        //$scanner = $client->scannerOpenWithStop("hogzilla_events",$startrow,"",
        $scanner = $client->scannerOpenWithStop("hogzilla_events","","",
                                array("event:lower_ip","event:upper_ip","event:note","event:signature_id","event:lower_ip_str","event:upper_ip_str"),
                                array());

        // Loop events to insert into MySQL/GrayLog
        while (true)
        {
                $row=$client->scannerGet($scanner);
                //if($lastHBaseID==0 and $startrow!=0) { $lastHBaseID=$startrow; continue; } /* dismiss the first */
                if(sizeof($row)==0) break;
                saveEvent($row,$con,$cid++);
                $lastHBaseID = $row[0]->row;
                $client->deleteAllRow("hogzilla_events", $row[0]->row, array()) ;
                if(DEBUG) { echo "Last HBaseID: $lastHBaseID \n"; }
        }

        // Update last CID on MySQL, this counter will be used on last access
        if(MYSQL)
            {saveLastCID($sensorid,$cid,$con);}

        // Save last seen HBase event
        saveNextHBaseRow($lastHBaseID);

        // Close HBase scanner
        $client->scannerClose($scanner);

       /*
        * Add information about clustering
        *
        */

        if(GRAYLOG)
        {
             //$scanner = $client->scannerOpenWithStop("hogzilla_clusters","","",
             //                       array("info:title","info:size","info:members","info:centroid"),
             //                       array());

             $scanner = $client->scannerOpenWithStop("hogzilla_cluster_members","","",
                                    array("info:title","cluster:size","cluster:centroid","cluster:idx",
                                          "cluster:description","member:ports","member:frequencies",
                                          "member:ip","member:distance"),
                                    array());


             $last_timestamp     = getClusterTimeStamp();
             $max_current_timestamp=0;
             // Loop events to insert into GrayLog
             while (true)
             {
                     $row                = $client->scannerGet($scanner);
                     if(sizeof($row)==0) break;
                     $values             = $row[0]->columns;
                     $current_timestamp  = $values["info:title"]->timestamp;

                     if($max_current_timestamp < $current_timestamp)
                        $max_current_timestamp=$current_timestamp;

                     if($current_timestamp <= ($last_timestamp+3600))
                        continue;

                     //saveCluster($row);
                     saveClusterMember($row);
                     if(DEBUG) { echo "Inserted cluster member into GrayLog\n"; }
             }
             saveClusterTimeStamp($max_current_timestamp);
             // Close HBase scanner
             $client->scannerClose($scanner);
        }

        // Close connections (HBase and MySQL)
        if(MYSQL)
            {mysql_close($con);}

        $transport->close();

    } catch(Exception $e)
    {
      echo 'ERROR: ',  $e->getMessage(), "\n";
    }

    // Wait some time to try fetch more events
    sleep($waitTime);
}
?>
