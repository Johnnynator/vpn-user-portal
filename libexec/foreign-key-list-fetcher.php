<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

use LC\Portal\Config;
use LC\Portal\Federation\ForeignKeyListFetcher;
use LC\Portal\HttpClient\CurlHttpClient;

$baseDir = dirname(__DIR__);
$configFile = $baseDir.'/config/config.php';
$dataDir = $baseDir.'/data';

try {
    $config = Config::fromFile($configFile);
    if ($config->s('Api')->requireBool('remoteAccess', false)) {
        $foreignKeyListFetcher = new ForeignKeyListFetcher($dataDir);
        $foreignKeyListFetcher->update(
            new CurlHttpClient(),
            'https://disco.eduvpn.org/v2/server_list.json',
            [
                'RWRtBSX1alxyGX+Xn3LuZnWUT0w//B6EmTJvgaAxBMYzlQeI+jdrO6KF', // fkooman@deic.dk
                'RWQ68Y5/b8DED0TJ41B1LE7yAvkmavZWjDwCBUuC+Z2pP9HaSawzpEDA', // jornane@uninett.no
                'RWQKqtqvd0R7rUDp0rWzbtYPA3towPWcLDCl7eY9pBMMI/ohCmrS0WiM', // RoSp
            ]
        );
    }
} catch (Exception $e) {
    echo 'ERROR: '.$e->getMessage().\PHP_EOL;
    exit(1);
}
