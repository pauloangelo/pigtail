<?php

$GLOBALS['THRIFT_ROOT'] = '/usr/lib/php';

require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/ClassLoader/ThriftClassLoader.php' );

$classLoader = new Thrift\ClassLoader\ThriftClassLoader();
$classLoader->registerNamespace('Thrift', $GLOBALS['THRIFT_ROOT'] );
$classLoader->register();

require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/Transport/TSocket.php' );
require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/Transport/TBufferedTransport.php' );
require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/Protocol/TBinaryProtocol.php' );

require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/Packages/Hbase/Hbase.php' );
require_once( $GLOBALS['THRIFT_ROOT'].'/Thrift/Packages/Hbase/Types.php' );

$socket = new Thrift\Transport\TSocket( 'localhost', 9090 );
$socket->setSendTimeout( 10000 );
$socket->setRecvTimeout( 20000 );
$transport = new Thrift\Transport\TBufferedTransport( $socket );
$protocol =  new Thrift\Protocol\TBinaryProtocol( $transport );
$client =    new Hbase\HbaseClient( $protocol );
$transport->open();


function saveMySQL( $rowresult ) {
  echo( "row: {$rowresult[0]->row}\n" );
  $values = $rowresult[0]->columns;
  asort( $values );
  foreach ( $values as $k=>$v ) {
    echo( "{$k} => {$v->value}\n" );
  }
}

$startrow="";
$scanner = $client->scannerOpenWithStop( "hogzilla_events", $startrow,"", array("event:text"), array());

while (true) 
{
    $row=$client->scannerGet( $scanner );
    if(sizeof($row)==0) break;
    saveMySQL($row);
}

$client->scannerClose( $scanner );

$transport->close();

?>
