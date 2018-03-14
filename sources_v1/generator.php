<?php
/**
 * User: Karel Wintersky
 * Date: 14.03.2018, time: 22:40
 */
require_once 'class.INI_Config.php';
require_once 'class.DBConnection.php';
require_once 'class.CLIConsole.php';
require_once 'class.SitemapFileSaver.php';

// check SAPI status: script can be launched only from console
if (php_sapi_name() !== "cli") {
    die("KW's Sitemap Generator can't be launched in browser.");
}

$this_filename = basename($argv[0]); // get file basename

$welcome_message = <<<SMG_WELCOME

<font color="white">{$this_filename}</font> is a <strong>Karel Wintersky's Configurable Sitemap Generator</strong> with .ini-files as configs
© Karel Wintersky, 2018, <font color="dgray">https://github.com/KarelWintersky/kwTools.SitemapGenerator</font>

SMG_WELCOME;

$hint_message = <<<SMG_USAGE

{$this_filename}: <font color="red">missing config file</font><br>
<font color="white">Use: </font> {$this_filename} <font color="yellow">/path/to/sitemap-config.ini</font>
or
<font color="white">Use: </font> {$this_filename} --help

SMG_USAGE;

function at($array, $key, $default_value = NULL) { return (array_key_exists($key, $array)) ? $array[$key] : $default_value; }

CLIConsole::echo_status($welcome_message);

// аргумент --help выводит справку
if (($argc == 1) || (strtolower($argv[1]) === '--help')) {
    CLIConsole::echo_status($hint_message);
    die;
}

// в противном случае ожидается, что в 1 аргументе передан инишник, а в нем ожидаются секции:
$argv_config_filepath = $argv[1];
$argv_config_path = dirname($argv_config_filepath);

$sm_config = new INI_Config( $argv_config_filepath );
$GLOBAL_SETTINGS = $sm_config->get('___GLOBAL_SETTINGS___');

// получим суффикс секции с данными подключения к БД
$db_section_suffix = $sm_config->get('___GLOBAL_SETTINGS___/db_section_suffix', '');

if ($db_section_suffix === '') {
    // опция - пустая строчка
    $dbi = NULL;

    $error_message = <<<MSG_DBSUFFIX_EMPTY
<font color='yellow'>[WARNING]</font> Key <font color='cyan'>___GLOBAL_SETTINGS___/db_section_suffix </font> is <strong>EMPTY</strong> in file <font color='yellow'>{$argv_config_filepath}</font><br>
Database connection can't be established.<br>
Any sections with <strong>source='sql'</strong> will be skipped.<br>
<br>

MSG_DBSUFFIX_EMPTY;

    CLIConsole::echo_status($error_message);
} else {
    $DB_SETTINGS = $sm_config->get("___GLOBAL_SETTINGS:{$db_section_suffix}___");

    if ($DB_SETTINGS === NULL) {
        $error_message = <<<MSG_DBSECTION_NOTFOUND
<font color='lred'>[ERROR]</font> : Config section <font color='cyan'>[___GLOBAL_SETTINGS:{$db_section_suffix}___]</font> not found in file <font color='yellow'>{$argv_config_filepath}</font><br>
See <font color="green">https://github.com/KarelWintersky/kwTools.SitemapGenerator</font> for assistance. <br>
MSG_DBSECTION_NOTFOUND;

        CLIConsole::echo_status($error_message);
        die(2);
    }

    $dbi = new DBConnection($DB_SETTINGS);
    if (!$dbi->is_connected) die($dbi->error_message);
}

$limit_urls  = at($GLOBAL_SETTINGS, 'limit_urls', 50000);
$limit_bytes = at($GLOBAL_SETTINGS, 'limit_bytes', 50000000);
$IS_LOGGING  = at($GLOBAL_SETTINGS, 'logging', TRUE);

$sm_config->delete('___GLOBAL_SETTINGS___');
$sm_config->delete('___GLOBAL_SETTINGS:DATABASE___');
$sm_config->delete("___GLOBAL_SETTINGS:{$db_section_suffix}___");

$all_sections = $sm_config->getAll();

$index_of_sitemap_files = array();

if ($IS_LOGGING) CLIConsole::echo_status("<strong>Generating sitemap</strong> based on <font color='yellow'>{$argv_config_filepath}</font>" . PHP_EOL);

// iterate all sections
$stat_total_time = microtime(true);
foreach ($all_sections as $section_name => $section_config) {
    if (array_key_exists('enabled', $section_config) && $section_config['enabled'] == 0) continue;

    if ($IS_LOGGING) CLIConsole::echo_status("<font color='yellow'>[{$section_name}]</font>");

    // init values based on section config
    $url_priority   = at($section_config, 'url_priority', 0.5);
    $url_changefreq = at($section_config, 'url_changefreq', 'never');

    $store = new SitemapFileSaver(
        $GLOBAL_SETTINGS['sitemaps_storage'],
        $GLOBAL_SETTINGS['sitehref'],
        at($section_config, 'radical', $section_name),
        at($GLOBAL_SETTINGS, 'sitemaps_filename_separator', '-'),
        $url_priority,
        $url_changefreq,
        at($section_config, 'use_gzip', true) && at($GLOBAL_SETTINGS, 'use_gzip', true),
        $limit_bytes,
        $limit_urls,
        at($GLOBAL_SETTINGS, 'date_format_type', '') );

    // analyze source type in config section
    switch ($section_config['source']) {
        case 'sql': {
            if ($dbi === NULL) {
                CLIConsole::echo_status("Нельзя использовать секцию с источником данных SQL - отсутствует подключение к БД");
                unset($store);
                continue; // next section
            }

            $sth = $dbi->getconnection()->query( $section_config['sql_count_request'] );
            $sth_result = $sth->fetch();
            $url_count = $sth_result[ $section_config['sql_count_value'] ];

            $chunks_count = (int)ceil($url_count / $limit_urls);

            $offset = 0;
            $t = microtime(true);

            // iterate all chunks
            for ($i = 0; $i < $chunks_count; $i++) {
                $q_chunk = $section_config['sql_data_request'] . " LIMIT {$limit_urls} OFFSET {$offset} ";
                $sth = $dbi->getconnection()->query( $q_chunk );
                $chunk_data = $sth->fetchAll();

                $count = 0;

                /**
                 * Callback function
                 * @param $value
                 */
                $sql_pusher = function($value) use ($section_config, $store, &$count) {
                    $id         = $value[ $section_config['sql_data_id'] ];
                    // $lastmod    = $value[ $section_config['sql_data_lastmod']];

                    $lastmod = ($section_config['sql_data_lastmod'] === 'NOW()') ? NULL : $value[ $section_config['sql_data_lastmod']];
                    $location   = sprintf( $section_config['url_location'], $id);
                    $count++;
                    $store->push( $location, $lastmod );
                };
                array_walk($chunk_data, $sql_pusher);

                if ($IS_LOGGING) CLIConsole::echo_status("+ Generated sitemap URLs from offset " . str_pad($offset, 7, ' ', STR_PAD_LEFT) . " and count " . str_pad($count, 7, ' ', STR_PAD_LEFT) . ". Consumed time: " . round(microtime(true) - $t, 2) .  " sec.");
                $t = microtime(true);

                $offset += $limit_urls;
                unset($sth); // clear memory
            } // for each chunk
            $store->stop();

            // save sitemap files list to index array
            $index_of_sitemap_files = array_merge($index_of_sitemap_files, $store->getIndex());

            break;
        } // end of 'sql' case

        case 'file': {
            $path_to_file = $section_config['filename'];

            if (strpos($path_to_file, '$') !== FALSE) {
                $path_to_file = str_replace('$', $argv_config_path, $path_to_file);
            }

            if (!file_exists($path_to_file)) {
                CLIConsole::echo_status("<font color='lred'>[ERROR]<font> File {$path_to_file} declared in section {$section_name}, option [filename] : not found!");
                CLIConsole::echo_status("<font color='lred'>This section will be ignored!</font>");
                unset($store);
                continue;
            }

            $contentfile = file( $path_to_file );
            $url_count = count($contentfile);
            $section_lastmod = ( $section_config['lastmod'] === 'NOW()') ? time() : NULL;

            $count = 0;
            $t = microtime(true);

            /**
             * Callback function
             * @param $value
             * @param $index
             * @param $section_lastmod
             */
            $file_pusher = function($value, $index, $section_lastmod) use ($section_config, $store, &$count) {
                $location = sprintf( $section_config['url_location'], trim($value));
                $store->push( $location, $section_lastmod );
                $count++;
            };
            array_walk($contentfile, $file_pusher, $section_lastmod);

            $store->stop();

            if ($IS_LOGGING) CLIConsole::echo_status("+ Generated " . str_pad($count, 7, ' ', STR_PAD_LEFT) . "  sitemap URLs. Consumed time: " . round(microtime(true) - $t, 2) . " sec.");
            $t = microtime(true);

            // save sitemap files list to index array
            $index_of_sitemap_files = array_merge($index_of_sitemap_files, $store->getIndex());

            break;
        } // end of 'file' case

        case 'csv': {
            // not implemented
            // загружаем CSV-файл в массив
            // построчно отдаем в Store->push() с соотв. локейшеном, апдейт-дейт и прочими значениями

            break;
        } // end of 'csv' case

        default: {
            if ($IS_LOGGING) CLIConsole::echo_status("<font color='lred'>[ERROR]<font> Unknown source type for section {$section_name}");
            break;
        } // end of DEFAULT case

    } // end of switch

    // destruct SAVER instance
    $store = null;
    unset($store);

    echo PHP_EOL;
} // end of foreach section

if ($IS_LOGGING) CLIConsole::echo_status("<font color='yellow'>[sitemap.xml]</font>") ;

SitemapFileSaver::createSitemapIndex(
    $GLOBAL_SETTINGS['sitemaps_href'],
    $GLOBAL_SETTINGS['sitemaps_mainindex'],
    $index_of_sitemap_files,
    'Today'
);
if ($IS_LOGGING) CLIConsole::echo_status("+ Generated sitemap index." . PHP_EOL);

$dbi = null;

if ($IS_LOGGING) CLIConsole::echo_status("<strong>Finished.</strong>" . PHP_EOL);
if ($IS_LOGGING) CLIConsole::echo_status('Total spent time:  <strong>' . round( microtime(true) - $stat_total_time, 2) . '</strong> seconds. ');
if ($IS_LOGGING) CLIConsole::echo_status('Peak memory usage: <strong>' . (memory_get_peak_usage(true) >> 10) . '</strong> Kbytes. ' . PHP_EOL);


/* EOF */