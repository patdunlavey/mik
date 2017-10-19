<?php
/**
 * @file
 * MIK shutdown script splits output into batch sets of N objects:
 *
 */

$params = array(
    's::' => 'setsize::',
);

$options = get_opts_prune_argv($params);

$batch_set_size = 50;
foreach($options as $option_key => $option_value) {
    if($option_value && in_array($option_key, array('setsize', 's'))) {
        $batch_set_size = (int) $option_value;
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

foreach($batch_sets as $batch_set_key => $batch_set) {
    $batch_set_dir_name = "set_" . ($batch_set_key+1);
    foreach($batch_sets[$batch_set_key] as $src_package_key => $src_package_dir) {
        $src_package_dir_array = explode('/', $src_package_dir);
        $src_package_dir_name = array_pop($src_package_dir_array);
        $dest_package_dir_array = array_merge($src_package_dir_array, array($batch_set_dir_name));
        $dest_set_dir = implode('/', $dest_package_dir_array);
        if(!is_dir($dest_set_dir)) {
            mkdir($dest_set_dir);
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