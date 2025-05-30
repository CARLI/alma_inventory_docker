<?php
require("apikeys.php");

// $matches[1] contains the 3-letter library code, e.g., eiu
if (preg_match('/^\/([^\/\.]+)\//', $_SERVER['REQUEST_URI'], $matches)) {
    define("ALMA_SHELFLIST_API_KEY", $API_KEYS[$matches[1]]);
} else {
    http_response_code(404);
    exit(1);
}

date_default_timezone_set('America/Illinois/Chicago');
// set the Caching Frequency - neverExpire, Daily, Hourly or None (No Caching) (recommended default: Daily)
if (!defined('CACHE_FREQUENCY')) define('CACHE_FREQUENCY', 'Daily');
/*********************************************************************
 * SortLC
 *********************************************************************/
 //retrieve Item Info Using Barcode and return array of data
 function retrieveBarcodeInfo($orgPrefix, $barcode)
 {
     $xml_barcode_result = false;
     $barcode = urlencode($barcode);
     //Remove encoded data received when processing CSV
     $barcode = str_replace(array("%0D%0A"), '', $barcode);
     // BUILD REST REQUEST URL
     $url = "https://api-na.hosted.exlibrisgroup.com/almaws/v1/items?item_barcode=" . $barcode . "&apikey=" . ALMA_SHELFLIST_API_KEY;
     if (isset($_GET['debug']))
         print("URL:" . $barcode . " $url<br>\n");

     // good to know if these are cached results or not
     $cached_results = false;

     if (strcmp(CACHE_FREQUENCY, "None")) {
         // check cache for barcode
         if (file_exists("cache/barcodes/${orgPrefix}" . $barcode . ".xml")) {
             // check last modified datestamp
             $cache_expired = false;
             switch (CACHE_FREQUENCY) {
                 case 'Hourly':
                     if (filemtime("cache/barcodes/${orgPrefix}" . $barcode . ".xml") < strtotime(date("Y-m-d H:00:00", strtotime("now")))) $cache_expired = true;
                 case 'Daily':
                     if (filemtime("cache/barcodes/${orgPrefix}" . $barcode . ".xml") < strtotime(date("Y-m-d 00:00:00", strtotime("now")))) $cache_expired = true;
                 default: if(filemtime("cache/barcodes/${orgPrefix}". $barcode .".xml") < strtotime(date("Y-m-d 00:00:00",strtotime("now")))) $cache_expired = true;
             }
             //$cache_expired = true;
             if (!$cache_expired) {
                $xml = file_get_contents("cache/barcodes/${orgPrefix}" . $barcode . ".xml");
                    if (trim($xml) == '') {
                            //barcode file empty, reload from api
                            $xml_barcode_result = false;
                    }
                    else {
                        $xml_barcode_result = simplexml_load_string($xml);
                        $cached_results = true;
                        if (isset($_GET['debug'])) print("loaded data from cache file: cache/barcodes/${orgPrefix}" . $barcode . ".xml<br>\n");
                    }
             }
             else {
               $xml_barcode_result = false;
             }
         }
     }

     // if no cache data available, query the Alma API
     if (!$xml_barcode_result) {
         // use curl to make the API request
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         //Was critical option setting for this, as API redirects response
         curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
         curl_setopt($ch, CURLOPT_URL, $url);
         $result = curl_exec($ch);

         if (isset($_GET['debug'])) {
             print("xml result from API<br>\n");
             print("<pre>" . htmlspecialchars($result) . "</pre>");
         }

         $xml_barcode_result = simplexml_load_string($result);
         curl_close($ch);


////////////////////////////////
// check to see if there is a call number prefix! if so, add that info to the item object
         $holding_data_url = (string)$xml_barcode_result->holding_data['link']."?apikey=" . ALMA_SHELFLIST_API_KEY;
         // use curl to make the API request
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         //Was critical option setting for this, as API redirects response
         curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
         curl_setopt($ch, CURLOPT_URL, $holding_data_url);
$headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION,
  function($curl, $header) use (&$headers)
  {
    $len = strlen($header);
    $header = explode(':', $header, 2);
    if (count($header) < 2) // ignore invalid headers
      return $len;

    $headers[strtolower(trim($header[0]))][] = trim($header[1]);
    
    return $len;
  }
);


         $holding_data_result = curl_exec($ch);
$api_calls_remaining = NULL;
if (isset($headers['x-exl-api-remaining'])) {
  $api_calls_remaining = end($headers['x-exl-api-remaining']);
}
//print("<pre>" . htmlspecialchars(print_r($api_calls_remaining,true)) . "</pre>");

         if (isset($_GET['debug'])) {
             print("xml result from API<br>\n");
             print("<pre>" . htmlspecialchars($holding_data_result) . "</pre>");
         }

         $xml_holding_data_result = simplexml_load_string($holding_data_result);
         curl_close($ch);
$gotCNP = 0;
if ($xml_holding_data_result->record->datafield)  {
  foreach ($xml_holding_data_result->record->datafield as $df)  {
    if($df['tag'] == '852') {
      foreach ($df->subfield as $sf) {
        if ($sf['code'] == 'k') {
          $xml_barcode_result->holding_data->call_number_prefix = $sf[0];
          $gotCNP = 1;
          break;
        }
      }
      if ($gotCNP == 1) {
        break;
      }
    }
  }
}
// turn object back into XML
$doc = new DOMDocument();
$doc->formatOutput = TRUE;
$doc->loadXML($xml_barcode_result->asXML());
$result = $doc->saveXML();
////////////////////////////////


         // save result to cache
         if (strcmp(CACHE_FREQUENCY, "None") && is_writable("cache/barcodes/")) {
             file_put_contents("cache/barcodes/${orgPrefix}" . $barcode . ".xml", $result);
             if (isset($_GET['debug'])) {
                 print("Barcode File written to cache\n");
             }
         }

     }

     // PARSE RESULTS
     $item_obj = new stdClass();
     $item_obj->title = (string)$xml_barcode_result->bib_data->title;
     $item_obj->item_link = (string)$xml_barcode_result['link']."?apikey=" . ALMA_SHELFLIST_API_KEY;
     $item_obj->mms_id = (string)$xml_barcode_result->bib_data->mms_id;
     $item_obj->bib_link = (string)$xml_barcode_result->bib_data['link']."?apikey=" . ALMA_SHELFLIST_API_KEY;
     $item_obj->holding_id = (string)$xml_barcode_result->holding_data->holding_id;
     $item_obj->holding_link = (string)$xml_barcode_result->holding_data['link']."?apikey=" . ALMA_SHELFLIST_API_KEY;
     $item_obj->item_pid = (string)$xml_barcode_result->item_data->pid;
     $item_obj->item_barcode = (string)$xml_barcode_result->item_data->barcode;
     $item_obj->call_number = (string)$xml_barcode_result->holding_data->call_number. " " . (string)$xml_barcode_result->item_data->enumeration_a. " " . (string)$xml_barcode_result->item_data->chronology_i;
     $item_obj->in_temp_location = (string)$xml_barcode_result->holding_data->in_temp_location;
     $item_obj->call_number_type = (string)$xml_barcode_result->holding_data->call_number_type;
     $item_obj->status = (string)$xml_barcode_result->item_data->base_status;
     $item_obj->status_desc = (string)$xml_barcode_result->item_data->base_status['desc'];
     $item_obj->process_type = (string)$xml_barcode_result->item_data->process_type;
     $item_obj->library = (string)$xml_barcode_result->item_data->library;
     $item_obj->location = (string)$xml_barcode_result->item_data->location;
     $item_obj->physical_material_type = (string)$xml_barcode_result->item_data->physical_material_type;
     $item_obj->item_note3 = (string)$xml_barcode_result->item_data->internal_note_3;
     $item_obj->requested = (string)$xml_barcode_result->item_data->requested;
     $item_obj->policy = (string)$xml_barcode_result->item_data->policy;
////////////////////////////////
// add this new field to the item object
if ($xml_barcode_result->holding_data->call_number_prefix) { 
  $item_obj->call_number_prefix = (string)$xml_barcode_result->holding_data->call_number_prefix;
}
////////////////////////////////

////////////////////////////////
// add this new field to the item object
$item_obj->api_calls_remaining  = NULL;
if (! $cached_results) {
     if ($api_calls_remaining != NULL)
       $item_obj->api_calls_remaining  = (string)$api_calls_remaining;
}
////////////////////////////////

     //Add this item to the array of items using the read order as the index value
     return $item_obj;


     //	if(isset($_GET['debug']))
     //{
         //print("<pre>\n");
         //print_r($xml_barcode_result);
         //print("</pre>\n");
     //}
     $xml_barcode_result = false;


 }
?>
