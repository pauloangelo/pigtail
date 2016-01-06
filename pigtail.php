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
$waitTime=600;
$GLOBALS['THRIFT_ROOT'] = '/usr/lib/php';

define("DEBUG",false);

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

   if(DEBUG) {  echo "Save signature into MySQL\n" ;}

   $values = $rowresult[0]->columns;
   $signature_class     = $values["signature:class"]->value;
   $signature_name      = $values["signature:name"]->value;
   $signature_priority  = $values["signature:priority"]->value;
   $signature_revision  = $values["signature:revision"]->value;
   $signature_hid       = $values["signature:id"]->value;
   $signature_group_id  = $values["signature:group_id"]->value;

   // Check if this signature already exists. 
   // And cache CID in array
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
  } else {
      $sig_id[$signature_hid]=$sig[0];
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

   if(DEBUG) {  echo "Save event into MySQL\n" ;}

   $values          = $rowresult[0]->columns;
   $lower_ipa        = unpack("N",$values["event:lower_ip"]->value);
   $upper_ipa        = unpack("N",$values["event:upper_ip"]->value);
   $lower_ip        = $lower_ipa[1];
   $upper_ip        = $upper_ipa[1];
   $note_body       = $values["event:note"]->value;
   $signature_hid   = $values["event:signature_id"]->value;

// Actually, we have a flow and not a single packet. 
// We are using iphdr below because it is needed to Snorby.
// insert into udphdr(sid,cid,udp_sport,udp_dport,udp_len,udp_csum) values(sid,cid,udp_sport,udp_dport,udp_len,udp_csum)

    $cur_sigid=$sig_id[$signature_hid];
    $sql[]="insert into event(sid,cid,signature,notes_count,type,number_of_events,timestamp) 
                    values('$sensorid','$cid','$cur_sigid',1,1,0,now());";
    $sql[]="insert into iphdr(sid,cid,ip_src,ip_dst,ip_ver,ip_hlen,ip_tos,ip_len,ip_id,ip_flags,ip_off,ip_ttl,ip_proto,ip_csum)
                    values('$sensorid','$cid','$lower_ip','$upper_ip',4,0,0,0,0,0,0,0,0,0);";
    $sql[]="insert into notes(sid,cid,user_id,body,created_at,updated_at)
                    values($sensorid,$cid,1,'$note_body',now(),now());";

    saveDB($sql,$con);
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

/*
 * STARTUP PHASE: get sensor and signatures information.
 */
// Open connections
if(DEBUG) {  echo "Open connections\n" ;}
$transport->open();
$con=mysql_connect("$mysqlHost:$mysqlPort","$mysqlUser","$mysqlPass"); 
mysql_select_db ($mysqlDbName,$con);
if (!$con)
{ die('Could not connect to MySQL: ' . mysql_error()); }

// Insert Sensor if needed. Get Sensor information
if(DEBUG) {  echo "Insert sensor, if needed\n" ;}
$scanner = $client->scannerOpenWithStop("hogzilla_sensor","","", array("sensor:description","sensor:hostname"), array());
$row=$client->scannerGet($scanner);
if(sizeof($row)==0) { die("Sensor table is empty in HBase\n"); }
saveSensor($row,$con);
$client->scannerClose($scanner);

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

// Close everything
if(DEBUG) {  echo "Close connections\n" ;}
mysql_close($con);
$transport->close();

/*
 * INFITY LOOP PHASE: fetch events from HBase, insert into MySQL, wait $waitTime and do everything again...
 */
while(true)
{
    if(DEBUG) {  echo "Inside loop\n" ;}
    // Open HBase and MySQL connection
    if(DEBUG) {  echo "Open connections\n" ;}
    $transport->open();
    $con=mysql_connect("$mysqlHost:$mysqlPort","$mysqlUser","$mysqlPass"); 
    mysql_select_db ($mysqlDbName,$con);
    if (!$con)
    { die('Could not connect to MySQL: ' . mysql_error()); }

    // Get last CID from MySQL, this counter will be used on last access
    $cid=getLastCID($sensorid,$con);

    // Get last seen event
    $startrow=getNextHBaseRow();
    if(DEBUG) { echo "Start row: $startrow \n"; }

    // Get HBase pointer
    $scanner = $client->scannerOpenWithStop("hogzilla_events",$startrow,"",
                            array("event:lower_ip","event:upper_ip","event:note","event:signature_id"),
                            array());

    // Loop events to insert into MySQL
    $lastHBaseID=0;
    try
    {
        while (true) 
        {
                $row=$client->scannerGet($scanner);
                if($lastHBaseID==0 and $startrow!=0) { $lastHBaseID=$startrow; continue; } /* dismiss the first */
                if(sizeof($row)==0) break;
                saveEvent($row,$con,$cid++);
                $lastHBaseID = $row[0]->row;
                if(DEBUG) { echo "Last HBaseID: $lastHBaseID \n"; }
        }
    } catch(Exception $e) 
    {
      echo 'ERROR: ',  $e->getMessage(), "\n";
    }

    // Update last CID on MySQL, this counter will be used on last access
    saveLastCID($sensorid,$cid,$con);

    // Save last seen HBase event
    saveNextHBaseRow($lastHBaseID);

    // Close connections (HBase and MySQL)
    mysql_close($con);
    $client->scannerClose($scanner);
    $transport->close();

    // Wait some time to try fetch more events
    sleep($waitTime);
} 
?>
