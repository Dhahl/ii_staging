<?php
##########################################################################
##########################################################################
##                                                                      ##
##  This script processes ONIX xml files and inserts them into a        ##
##  database. This database does not need to have any pre-existing      ##
##  tables or collums, these will be automatically created by the       ##
##  script.                                                             ##
##                                                                      ##
##                                                                      ##
##                                                                      ##
##  AFTER RUNNING THIS SCRIPT:                                          ##
##  update table collums etc. to match content type.                    ##
##  Also you might want to ad some primary keys and indexes afterwards  ##
##                                                                      ##
##  Author: Jonathan van Bochove                                        ##
##  Author url: www.johannes-multimedia.nl                              ##
##  Author e-mail: webmaster@johannes-multimedia.nl                     ##
##  Licence: Copyright (c) 2011 Johannes Multimedia                     ##
##  Released under the GNU General Public License                       ##
##  Version 1.2.2 (2013-02-18)                                          ##
##                                                                      ##
##                                                                      ##
##                                                                      ##
##  If you make any alterations to this script to make it more use-     ##
##  full, faster or more efficient, please send a copy of the updated   ##
##  script to the author, and mention what was updated/changed.         ##
##                                                                      ##
##                                                                      ##
##########################################################################
##                                                                      ##
##  edit settings below:                                                ##
##                                                                      ##
##########################################################################
##########################################################################

$mem = 1000000; // Onix chunk size in bytes (script won't process more then this at once)
$file = "test.xml"; // Location of onix file
$dbhost = "localhost"; // mysql host
$dbuser = "lorcan"; //mysql username
$dbpw = ""; // mysql user password
$db = "irishinterest"; // mysql database name
$prefix = "onix_"; // table prefix
$uri = "http://". $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF']; // scriptlocation e.g. "http://www.example.com/onix/script.php";

##########################################################################
##########################################################################
##                                                                      ##
##                           end of settings                            ##
##                                                                      ##
##########################################################################
##########################################################################

function ti() { // function to calculate time used
   $t = microtime();
   $t = explode(' ', $t);
   return $t[1] + $t[0];
}
$start = max((int)$_GET['start'], 0); // set startpoint of xml file (must be an integer greater then 0)
$st = ($start==0?ti():(int)$_GET['st']); // remember when we started with the first chunk of data to show total processing time
$totaal = max((int)$_GET['totaal'], 0); // remember the total number of records we processed from the start of the first chunk
$size = filesize($file);
if(!isset($_GET['start'])) $_GET['start'] = 0; // if the startpoint still is not set, set it at 0
$end = min(($size-$start), $mem); // if xml file smaller then chunksize, then don't try and do too much

if($start < $size) { // are we not already done?
   $p = file_get_contents($file, NULL, NULL, $start, $end); // load the chunk of xml into memory
   $pos = strripos($p, '</Product>')+10; // find the initial end of the last record of this chunk of data
   $deleted = strlen($p) - $pos; // help to figure out where to start processing the next chunk of data
   $p = preg_replace("/(.*?)<([Pp])roduct(.*?)>(.*)/s", "<\\2roduct>\\4", $p); // strip the "useless" header and stuff
   $p = preg_replace("!<br />!", "&#60;br /&#62;", $p); //turning possible <br /> html into its special chars equivalent
   $p = preg_replace('!<([^ ]*?) ([^=]*?)="([^"]*?)">!', '<\\2>\\3</\\2><\\1>', $p); //turning tag values into their own tags
   $pos = strripos($p, '</Product>')+10; // find the end of the last record of this chunk of data, after modifications from above
   $product = '';
   $conn = mysql_connect($dbhost, $dbuser, $dbpw);
   mysql_select_db($db);

   if($pos>10) { //are there more products to process?
   	var_dump(substr($p, 0, $pos));
      $products = simplexml_load_string(substr($p, 0, $pos)); // do the magic, turn the xml into an xml object that we can process
      unset($p); // clear the memory of the xml string
      if(is_object($products)) $totaal = $totaal + sizeof($products); // how many records to process?
   
         // Fetch existing tables and collumns
         $tbls = mysql_query("SHOW TABLES like '".$prefix."%'");
         while($temp = mysql_fetch_array($tbls)) {
            $tbl[strtolower($temp[0])] = array();
         }
         foreach($tbl as $key => $value) {
            $collumns = mysql_query("SHOW COLUMNS FROM `".mysql_real_escape_string($key)."`");
            while($temp = mysql_fetch_array($collumns)) {
               $tbl[strtolower($key)][$temp['Field']] = " ";
            }
         }
     
         // if it does not exist, create the first table
         if(!isset($tbl[$prefix.'product'])) {
            mysql_query("CREATE TABLE IF NOT EXISTS `".mysql_real_escape_string($prefix."product")."` (`id` varchar(15) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $tbl[$prefix.'product']['id'] = " ";
         }
         // loop through the chunk of xml to process
         foreach ($products as $produc) {
            //check that all used tables and collumns exist, and if not create and update these tables
            if(isset($produc->productidentifier)){
               foreach($produc->productidentifier as $value) {
                   if($value->b221=='03' || $value->b221=='15') $id = mysql_real_escape_string($value->b244);
               }
            } else {
               foreach($produc->ProductIdentifier as $value) {
                   if($value->ProductIDType=='03' || $value->ProductIDType=='15') $id = mysql_real_escape_string($value->IDValue);
               }
            }
            foreach($produc as $key => $value) { //loop trough everything building database and writing insert queue
               $vars = get_object_vars($value);
               if(is_array($vars)&&sizeof($vars)>0){
                  $i = ($key==$varup?($i+1):0); //count the number of instances of a certain tag
                  $varup = $key;
                  $key = strtolower($prefix.$key); //table names must be lowercase, with prefix prepended
                  if(!isset($tbl[$key])) { // create missing tables
                     mysql_query("CREATE TABLE IF NOT EXISTS `".mysql_real_escape_string($key)."` (`id` varchar(15) NOT NULL, INDEX (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
                     $tbl[$key] = array('id' => 'varchar(15)');
                  }
                  foreach($value as $key2 => $value2) {
                     $vars2 = get_object_vars($value2);
                     if(is_array($vars2)&&sizeof($vars2)>0){
                        $j = ($varup2==$key?(int)($j+1):$j); //count the number of instances of a certain lvl2 tag
                        $varup2 = $key;
                        foreach($value2 as $key3 => $value3) {
                           if(!isset($tbl[$key][$key3])) { //add missing columns to tables
                              mysql_query("ALTER TABLE `".mysql_real_escape_string($key)."` ADD `".mysql_real_escape_string($key3)."` longtext");
                              $tbl[$key][$key3] = 'longtext';
                           }
                           ${$key}[$id][$j][$key3] = (string)$value3; //write queue for lvl 3 tags, I don't know of any lvl 4 tags so I stop seaarching here, please correct me if I'm wrong
                        }
                     } else {
                        if(!isset($tbl[$key][$key2])) { //add missing columns to tables
                           mysql_query("ALTER TABLE `".mysql_real_escape_string($key)."` ADD `".mysql_real_escape_string($key2)."` longtext");
                           $tbl[$key][$key2] = 'longtext';
                        }
                        ${$key}[$id][$i][$key2] = (string)$value2; //write queue for lvl 2 tags
                     }
                  }
               } else {
                  if(!isset($tbl[$prefix.'product'][$key])) { //update primary table for missing columns
                     mysql_query("ALTER TABLE ".$prefix."product ADD ".mysql_real_escape_string($key)." VARCHAR(128)");
                     $tbl[$prefix.'product'][$key] = 'varchar(128)';
                  }
                  $i=0; //I don't know of any recurring first lvl tags, so $i is always 0, correct me if I'm wrong
                  ${$prefix."product"}[$id][0][$key] = (string)$value; //write queue for first lvl tags
               }
            }
         }
         foreach($tbl as $table => $array) { // check if we can save some inserts by merging records
            if(is_array(${$table}) && sizeof(${$table})>0) {
              foreach(${$table} as $key => $array) {
                 if(sizeof($array)>0) {
                    sort(${$table}[$key]);
                    sort($array);
                    for($a = (sizeof($array)-1); $a>0; $a--) {
                       $test = array_merge($array[$a], $array[($a-1)]);
                       if ((sizeof($array[$a]) + sizeof($array[($a-1)])) == sizeof($test)) {
                          ${$table}[$key][($a-1)] = $test;
                          $array[($a-1)] = $test;
                          unset(${$table}[$key][$a], $array[$a]);
                       }
                    }
                 }
              }
           }
        }
        // insert each array of data into its own table
        foreach($tbl as $table => $array) {
           $query = "insert into `".mysql_real_escape_string($table)."` (";
           foreach($array as $key => $useless) {
              $query .= "`".mysql_real_escape_string($key)."`, ";
           }
           $query = substr($query, 0, -2) . ") values ";
           $rows = "";
           if(is_array(${$table}) && sizeof(${$table})>0){
              foreach(${$table} as $key => $value) {
                 foreach($value as $key2 => $value2) {
                    # $key = isbn
                    # $value2 = array with data to be inserted
                    $rows .= "(";
                    foreach($array as $k => $v){
                       $rows .= "'".($k=='id'?mysql_real_escape_string($key):(isset($value2[$k])?mysql_real_escape_string(utf8_decode($value2[$k])):'')) . "', ";
                    }
                    $rows = substr($rows, 0, -2) . "), ";
                 }  
              }
          }
          $rows = substr($rows, 0, -2);
          mysql_query($query . $rows);
       }
    }
    if(($end+$start-($deleted+1))<$size) {
       header("Location: ".$uri."?start=".($end+$start-($deleted+1))."&st=".$st."&totaal=".$totaal); // continue with the next chunk of xml
    } else { // finished and show total number of inserted records and processing time
       echo date("Y-m-d H:i:s") . " records: " . $totaal . " time: " .number_format((ti()-$st), 2, '.', ',')." seconds";
    }
    mysql_close($conn);
}
?>