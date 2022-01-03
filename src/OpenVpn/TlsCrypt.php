<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\OpenVpn;

use Vpn\Portal\FileIO;
use Vpn\Portal\Validator;

class TlsCrypt
{
    private string $keyDir;

    public function __construct(string $keyDir)
    {
        $this->keyDir = $keyDir;
    }

    public function get(string $profileId): string
    {
        // validate profileId also here, to make absolutely sure...
        Validator::profileId($profileId);

        // if we have "tls-crypt.key" we'll use that for all profiles, if not,
        // we use the profile specific ones
        $tlsCryptKeyFile = $this->keyDir.'/tls-crypt.key';
        if (FileIO::exists($tlsCryptKeyFile)) {
            return FileIO::read($tlsCryptKeyFile);
        }

        // profile specific tls-crypt file
        $tlsCryptKeyFile = $this->keyDir.'/tls-crypt-'.$profileId.'.key';
        if (FileIO::exists($tlsCryptKeyFile)) {
            return FileIO::read($tlsCryptKeyFile);
        }

        // no key yet, create one
        FileIO::write($tlsCryptKeyFile, self::generate());

        return FileIO::read($tlsCryptKeyFile);
    }

    private static function generate(): string
    {
        // Same as $(openvpn --genkey --secret <file>)
        $randomData = wordwrap(sodium_bin2hex(random_bytes(256)), 32, "\n", true);

        return <<< EOF
            #
            # 2048 bit OpenVPN static key
            #
            -----BEGIN OpenVPN Static key V1-----
            {$randomData}
            -----END OpenVPN Static key V1-----
            EOF;
    }
}
