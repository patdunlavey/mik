<?php
/**
 * @file
 * MIK shutdown script splits output into batch sets of N objects:
 *
 */

$params = array(
  's::' => 'setsize::',
  'ppa::' => 'preprocess_script_args::'
);

$options = get_opts_prune_argv($params);

$batch_set_size = 50;
$preprocess_script_args = array();

foreach($options as $option_key => $option_value) {
  switch($option_key) {
    case 's':
    case 'setsize':
      if ($option_value) {
        $batch_set_size = (int) $option_value;

      }
      break;
    case 'preprocess_script_args':
        $args = explode(',', $option_value);
        foreach($args as $arg) {
            $arg = explode('=', $arg);
            if(count($arg) == 2) {
                $preprocess_script_args[trim($arg[0])] = trim($arg[1]);
            }
        }
      break;
  }
}

$config_path = trim($argv[1]);
$config = parse_ini_file($config_path, TRUE);
$target_directory = $config['WRITER']['output_directory'];



if (!is_dir($target_directory)) {
    exit("Please check that you have provided a full path to a directory as the input argument." . PHP_EOL);
}

$package_dirs = array();
get_object_directories($target_directory, $package_dirs);
$batch_sets = array_chunk($package_dirs, $batch_set_size, TRUE);

$packages_count = count($package_dirs);
$batch_sets_count = ceil(count($batch_sets) / $batch_set_size);

echo "Split Batch Sets: Moving $packages_count ingest packages into $batch_sets_count batch sets of (no more than) $batch_set_size packages each.\n";

foreach ($batch_sets as $batch_set_key => $batch_set) {
  $batch_set_dir_name = "set_" . ($batch_set_key + 1);
  foreach ($batch_sets[$batch_set_key] as $src_package_key => $src_package_dir) {
    $src_package_dir_array = explode('/', $src_package_dir);
    $src_package_dir_name = array_pop($src_package_dir_array);
    $dest_package_dir_array = array_merge($src_package_dir_array, array($batch_set_dir_name));
    $dest_set_dir = implode('/', $dest_package_dir_array);
    if (!is_dir($dest_set_dir)) {
      mkdir($dest_set_dir);

      if (!empty($preprocess_script_args)) {
        write_preprocess_script($preprocess_script_args, $dest_set_dir, $config);
      }
    }

    $dest_package_dir_array[] = $src_package_dir_name;
    $dest_package_dir = implode('/', $dest_package_dir_array);
    rename($src_package_dir, $dest_package_dir);
  }
}

/**
 * Create array of directory paths, one for each object found.
 * 
 * @param $target_directory 
 *   The directory we are scanning for ingest packages.
 * @param array &$package_dirs
 */
function get_object_directories($target_directory, &$package_dirs = array())
{
    if(is_dir($target_directory)) {
        $items = array_diff(scandir($target_directory), array('..', '.','.DS_Store'));
        if ($items) {
            // We assume a directory with a MODS.xml file is an object.
            if (in_array('MODS.xml', $items)) {
                $package_dirs[] = $target_directory;
            } else {
                foreach ($items as $item) {
                    get_object_directories($target_directory . "/" . $item, $package_dirs);
                }
            }
        }

    }
}

function get_opts_prune_argv($parameters) {
    global $argv;
    $options = getopt(implode('', array_keys($parameters)), $parameters);
    $pruneargv = array();
    foreach ($options as $option => $value) {
        foreach ($argv as $key => $chunk) {
            $regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
            if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk)) {
                array_push($pruneargv, $key);
            }
        }
    }
    while ($key = array_pop($pruneargv)) unset($argv[$key]);
    $argv = array_values($argv);
    return $options;
}


function write_preprocess_script($preprocess_script_args, $dest_set_dir, $config)
{
    if (empty($preprocess_script_args['drush_command'])) {
        switch ($config['WRITER']['class']) {
            case 'CdmNewspapers':
            case 'CsvNewspapers':
                $preprocess_script_args['drush_command'] = 'islandora_newspaper_batch_preprocess';
                break;

            case 'CsvCompound':
            case 'CdmCompound':
            case 'CdmPhpDocuments':
                $preprocess_script_args['drush_command'] = 'islandora_compound_batch_preprocess';
                break;

            case 'CsvBooks':
            case 'CdmBooks':
            $preprocess_script_args['drush_command'] = 'islandora_book_batch_preprocess';
                break;

            default:
                $preprocess_script_args['drush_command'] = 'islandora_batch_scan_preprocess';
        }
    }

    if (!empty($preprocess_script_args['drush_command'])) {
        $preprocess_drush_command = $preprocess_script_args['drush_command'];
        unset ($preprocess_script_args['drush_command']);
        $drupal_root = empty($preprocess_script_args['drupal_root']) ? "/var/www/html" : $preprocess_script_args['drupal_root'];
        unset ($preprocess_script_args['drupal_root']);
        $immediate = empty($preprocess_script_args['immediate']) ? FALSE : TRUE;
        unset ($preprocess_script_args['immediate']);
        $teecommand = !empty($config['LOGGING']['path_to_log']) ? " tee " . $config['LOGGING']['path_to_log'] : '';
        $log_path = pathinfo($config['LOGGING']['path_to_log']);
        $preprocess_script_file_path = $log_path['dirname'] . "/batch_preprocess.sh";
        $preprocess_script_file_pathinfo = pathinfo($preprocess_script_file_path);
        if (!is_dir($preprocess_script_file_pathinfo['dirname'])) {
            if (!mkdir($preprocess_script_file_pathinfo['dirname'])) {
                return 0;
            }
        }
        if (!file_exists($preprocess_script_file_path)) {
            $file = fopen($preprocess_script_file_path, 'w');
            if ($file) {
                fwrite($file, "#!/bin/bash\n\ncd $drupal_root\n\n");
                fclose($file);
            } else {
                return 0;
            }
        }

        if (file_exists($preprocess_script_file_path)) {
            $file = fopen($preprocess_script_file_path, 'a');
            if ($file) {
                $text = "# drush -v -u 1 $preprocess_drush_command --type=directory --scan_target=\"$dest_set_dir\" ";
                foreach ($preprocess_script_args as $param => $value) {
                    $text .= " --$param=\"$value\"";
                }
                $text .= "\n";
                fwrite($file, $text);
                if ($immediate) {
                    $text = "# drush -v -u 1 islandora_batch_ingest 2>&1 $teecommand\n\n";
                    fwrite($file, $text);
                }
                fclose($file);
            }
        }
    }
}
