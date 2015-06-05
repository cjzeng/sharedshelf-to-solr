<?php
// ss2solr.php - use sharedshelf api to update solr index

function postData($params) {
  $postData = '';
  //create name value pairs seperated by &
  foreach($params as $k => $v) {
    $postData .= $k . '='. urlencode($v) .'&';
  }
  rtrim($postData, '&');
  return $postData;
}

function get_cookies($url, $params, $cookiejar) {
  $postData = postData($params);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiejar);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  // get headers too with this line
  curl_setopt($ch, CURLOPT_HEADER, 1);
  curl_setopt($ch, CURLOPT_POST, count($postData));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  $result = curl_exec($ch);
  curl_close($ch);
  // get cookie
  // multi-cookie variant contributed by @Combuster in comments
  preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
  $cookies = array();
  foreach($matches[1] as $item) {
      parse_str($item, $cookie);
      $cookies = array_merge($cookies, $cookie);
  }
  return $cookies;
}

function ss_login($sharedshelf, $user, $password, $cookiejar) {
  try {
    $form_fields = array(
      'email' => $user,
      'password' => $password,
      );
    $response = get_cookies($sharedshelf, $form_fields, $cookiejar);
    if (!isset($response['sharedshelf'])) {
      throw new Exception("No sharedshelf cookie", 1);
    }
    return $response;
 }
  catch (Exception $e) {
      echo 'Caught exception: ',  $e->getMessage(), "\n";
  }
}

function ss_get($url, $cookiejar) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiejar);
  /* make sure you provide FULL PATH to cookie files*/
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  $output = curl_exec ($ch);
  curl_close($ch);
  $output = json_decode($output, true);
  return $output;
}

function ss_post($url, $data) {
  $data2 = array('add' => array( 'doc' => $data, 'commitWithin' => 1000,),);
  $data_string = json_encode($data2);
  $postData = 'commit=true';
  echo "\n\n";
  print_r($data_string);
  echo "\n\n";

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Content-Length: ' . strlen($data_string))
  );
  //curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

  $result = curl_exec($ch);
  curl_close($ch);
  return $result;
}

$user = parse_ini_file('ssUser.ini');
$vars = parse_ini_file('ss2solr.aerial.ini');

$ss_url = $vars['sharedshelf'];
$cookiejar = $vars['cookiejar'];
$solr_url = $vars['solr'];

if (!file_exists($cookiejar)) {
  die ("file does not exist: $cookiejar \nto create it run this on command line:\ntouch $cookiejar");
}

$cookies = ss_login("$ss_url/account", $user['email'], $user['password'], $cookiejar);

$out = ss_get("$ss_url/projects", $cookiejar);

$collections = array();
echo "\n\n";
foreach ($out['items'] as $project) {
  $id = $project['id'];
  $assets = ss_get("$ss_url/projects/$id/assets", $cookiejar);
  $total = $assets['total'];
  echo $project['id'] . ' : ' . $project['name'] . ' - assets: ' . $total . "\n";
  //print_r($assets);
  $collections["$id"] = $project['name'];
}

echo "\n\n";
if (isset($vars['project'])) {
  $project_id = $id = $vars['project'];
  $assets = ss_get("$ss_url/projects/$id/assets", $cookiejar);
  $total = $assets['total'];
  $per_page = 10;

  // if fields are not set list all fields
  if (empty($vars['fields'])) {
    $fields = array();
    for ($i = 0; $i < $total; $i += $per_page) {
      $args = "start=$i&limit=$per_page&with_meta=true";
      $assets = ss_get("$ss_url/projects/$id/assets?$args", $cookiejar);
      foreach($assets['assets'] as $asset) {
        foreach($asset as $k => $v) {
          if (!is_array($v)) {
            $fields["$k"] = $k;
          }
        }
      }
    foreach ($fields as $field) {
      echo "fields[$field] = \"$field\"\n";
    }
    die("add fields to ss2solr.ini and make second name a solr field name");
    }
  }
  $fields = $vars['fields'];

  for ($i = 0; $i < $total; $i += $per_page) {
    echo "batch starting with $i\n";
    $args = "start=$i&limit=$per_page&with_meta=true";
    $assets = ss_get("$ss_url/projects/$id/assets?$args", $cookiejar);
    foreach($assets['assets'] as $asset) {
      $filtered = array();
      foreach($asset as $k => $v) {
        if (!empty($fields["$k"]) && !empty($v)) {
          $filtered["$fields[$k]"] = $v;
        }
        else {
          //echo "$k\n";
        }
      }
      //print_r($filtered);
      echo $filtered['id'] . "\n";
      //print_r($filtered);
      $filtered['Collection_s'] = $collections["$project_id"];
      $result = ss_post($solr_url, $filtered);
      print_r($result);
      //die('quitting now');
    }
    // print_r($asset);
    // $asset_id = $asset['id'];
    // echo "representations for asset $asset_id: \n";
    // for ($size = 0; $size < 5; $size++) {
    //   $rep = ss_get("$ss_url/representation/$asset_id/size/$size", $cookiejar);
    //   //$rep = ss_get("$ss_url/assets/$asset_id/representation", $cookiejar);
    //   print_r($rep);
    // }
    // exit;
  }

}

