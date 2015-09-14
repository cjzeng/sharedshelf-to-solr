<?php
// sharedshelf-to-solr - update all sharedshelf collections in solr

require_once('SharedShelfService.php');
require_once('SolrUpdater.php');
require_once('SharedShelfToSolrLogger.php');

function debug($item, $description = '', $die = TRUE) {
  if (!empty($description)) {
    print PHP_EOL . 'DEBUG: ' . $description . PHP_EOL;
  }
  print_r($item);
  if ($die) {
    die('debugging' . PHP_EOL);
  }
}

function usage() {
  global $argv;
  echo PHP_EOL;
  echo "Usage: php " . $argv[0] . " [--help] [--force] [-p NNN]" . PHP_EOL;
  echo "--help - show this info" . PHP_EOL;
  echo "--force - ignore timestamps and rewrite all solr records" . PHP_EOL;
  echo "-p - only process SharedShelf collection (project number) NNN (NNN must be numeric) - see listProjects.php" . PHP_EOL;
  exit (0);
}

function split_delimited_fields(&$flattened_asset, $delimited_fields = array()) {
  if (!empty($delimited_fields)) {
    foreach ($flattened_asset as $k => $v) {
      if (!empty($delimited_fields["$k"])) {
        $delimiter = $delimited_fields["$k"];
        $v_array = explode($delimiter, $v);
        $v_array_trimmed = array();
        foreach ($v_array as $value) {
          $v_array_trimmed[] = trim($value);
        }
        $flattened_asset["$k"] = $v_array_trimmed;
      }
    }
  }
}

$log = FALSE;

$options = getopt("p:",array("help", "force"));

if (isset($options['help'])) {
  usage();
}
$force_replacement = isset($options["force"]);
if (isset($options['p'])) {
  if (is_numeric($options['p'])) {
    $single_collection = $options['p'];
  }
  else {
    usage();
  }
}
else {
  $single_collection = FALSE;
}

try {

  // batch process information
  $task = parse_ini_file("sharedshelf-to-solr.ini", TRUE);
  if ($task === FALSE) {
    echo "Need sharedshelf-to-solr.ini\n";
    exit (1);
  }

  // open log
  if (empty($task['process']['log_file_prefix'])) {
    echo "Need log_file_prefix\n";
    exit (1);
  }

  $log = new SharedShelfToSolrLogger($task['process']['log_file_prefix']);

  $log->task('ssUser');
  // sharedshelf user
  $user = parse_ini_file('ssUser.ini');
  if ($user === FALSE) {
    throw new Exception("Need to create ssUser.ini. See README.md", 1);
  }

  if (!isset($task['process']['cookie_jar_path'])) {
    throw new Exception("Expecting cookie_jar_path in .ini file", 1);
  }

  $log->task('SharedShelfService');
  $ss = new SharedShelfService($user['email'], $user['password'], $task['process']['cookie_jar_path']);

  foreach($task['configuration_files']['config'] as $config) {
    $log->task($config);
    $project = parse_ini_file($config);
    if ($project === FALSE) {
      throw new Exception("Missing configuration file: $config", 1);
    }
    if ($single_collection !== FALSE) {
      if ($project['project'] != $single_collection) {
        echo PHP_EOL . "Skipping collection " . $project['project'] . " as it was not selected on the command line" . PHP_EOL;
        continue;
      }
    }
    debug($project, '.ini file', FALSE);
    $log->note('SolrUpdater');
    $solr_url = $project['solr'];
    $solr = new SolrUpdater($solr_url, $config);

    $log->note('project_asset_ids');
    $project_id = $project['project'];
    $asset_count = $ss->project_assets_count($project_id);
    $log->note("asset_count:$asset_count");
    echo "$config asset count: $asset_count\n";

    // extranct list of sharedshelf field names that need special array treatment
    $delimited_fields = empty($project['delimited_field']) ? array() : $project['delimited_field'];
    debug($delimited_fields, 'delimited_fields', FALSE);

    for ($start = 0; $start < $asset_count; $start++) {
      $another_attempt = TRUE;
      for ($attempt = 0; $attempt < 3 && $another_attempt; $attempt++) {
        $assets =  $ss->project_assets($project_id, $start, 1);
        $solr_assets = array();
        $counter = $start;
        $asset = array_shift($assets);
        $ss_id = $asset['id'];
        $solr_id = 'ss:' . $ss_id;
        $log->item("asset $solr_id");
        if ($attempt > 0) {
          $log->note("Attempt " . $attempt + 1);
        }
        else {
          $pct = sprintf("%01.2f", $counter++ * 100.0 / (float) $asset_count);
          $log->note("Completed:$pct");
        }

        try {
          // is this asset in solr already?
          $solr_in = $solr->get_item($solr_id);
          if (empty($solr_in)) {
            // just add the asset to solr
            $log->note('Job:AddNew');
            $flattened_asset = $ss->asset_field_values($asset);
            debug($flattened_asset, 'flattened asset');
            split_delimited_fields($flattened_asset, $delimited_fields);
            debug($flattened_asset, 'flattened asset delimited');
            $solr_out = $solr->convert_ss_names_to_solr($flattened_asset);
          }
          else {

            // flatten the solr asset
            // given $k the solr field name and $v the value for that field
            // if value $v is scalar or single element array then the flattened version is a scalar
            // if value $v is an array with more than one element, pass it on as an array
            // $flat = array();
            // foreach ($solr_in as $k => $v) {
            //   if (is_array($v)) {
            //     if (count($v) == 1) {
            //       $flat["$k"] = array_shift($v);
            //     }
            //     else {
            //       $flat["$k"] = $v; // pass full array along
            //     }
            //   }
            //   else {
            //     $flat["$k"] = $v;
            //   }
            // }
            // $solr_in = $flat;

            // compare the dates
            if (empty($asset['updated_on'])) {
              throw new Exception("Missing updated_on field on sharedshelf asset $ss_id ", 1);
            }
            $ss_date =  trim($asset['updated_on']);
            if (empty($solr_in['updated_on_ss'])) {
              $log->note('solr missing updated_on');
              $solr_date = '';
            }
            else {
              $solr_date = trim($solr_in['updated_on_ss']);
            }
            if ($force_replacement) {
              $log->note('Job:Replace');
            }
            else if (strcmp($ss_date,$solr_date) == 0) {
              // dates match - skip this record
              $log->note('Job:Skip-DatesMatch');
              $another_attempt = FALSE;
              continue;
            }
            else {
              $log->note('Job:Update');
            }
            $flattened_asset = $ss->asset_field_values($asset);
            split_delimited_fields($flattened_asset, $delimited_fields);
            debug($flattened_asset, 'flattened asset delimited - in solr already');
            $solr_new = $solr->convert_ss_names_to_solr($flattened_asset);
            $solr_out = array_replace($solr_in, $solr_new);
          }

          // check if we need images and their derivatives
          $need_images = (isset($asset['has_images']) && (strcmp($asset['has_images'], 'no') == 0)) ? FALSE : TRUE;
          if ($need_images) {
            $url = $ss->media_url($ss_id);
            $solr_out['media_URL_tesim'] = $url;
            for ($size = 0; $size <= 4; $size++) {
              $fld = 'media_URL_size_' . $size . "_tesim";
              $solr_out["$fld"] = $ss->media_derivative_url($ss_id, $size);
            }
            $solr_out['id'] =  $solr_id;

            if (($dim = $ss->media_dimensions($ss_id)) !== FALSE) {
              $solr_out['img_width_tesim'] = $dim['width'];
              $solr_out['img_height_tesim'] = $dim['height'];
            }
          }

          // remove any fields that will become "" in solr
          $solr_out_full = array();
          foreach ($solr_out as $key => $value) {
            if (!empty($value) || $value === FALSE) {
              $value = is_array($value) ? $value : trim($value, '"'); //hack to remove "" from Lat/Lon
              if (!empty($value)) {
                $solr_out_full["$key"] = $value;
              }
            }
          }
          // make an arrary out of the one result
          $solr_assets[] = $solr_out_full;

          // add one result to solr - may fail if multiple processes are running against same document
          $result = $solr->add($solr_assets);

          $another_attempt = FALSE;
          continue;
         }
        catch (\Exception $e) {
          $error = 'Caught exception: ' . $e->getMessage() . " - skipping this asset\n";
          if ($log !== FALSE) {
            $log->error($error);
          }
          else {
            echo $error;
          }
        }
      } // $attempt
    }
  }

  print_r($task);
  $log->task('Done.');
}
catch (Exception $e) {
  $error = 'Caught exception: ' . $e->getMessage() . "\n";
  if ($log !== FALSE) {
    $log->error($error);
  }
  else {
    echo $error;
  }
  exit (1);
}
exit (0);

