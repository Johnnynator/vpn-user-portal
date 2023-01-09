<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\OpenVpn\CA;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Vpn\Portal\Dt;
use Vpn\Portal\FileIO;
use Vpn\Portal\Hex;
use Vpn\Portal\OpenVpn\CA\Exception\CaException;
use Vpn\Portal\Validator;

class VpnCa implements CaInterface
{
    protected DateTimeImmutable $dateTime;
    private string $caDir;
    private string $vpnCaPath;

    public function __construct(string $caDir, string $vpnCaPath)
    {
        $this->caDir = $caDir;
        $this->vpnCaPath = $vpnCaPath;
        $this->dateTime = Dt::get();
    }

    public function caCert(): CaInfo
    {
        $pemCert = FileIO::read($this->caDir.'/ca.crt');
        $parsedPem = openssl_x509_parse($pemCert);

        return new CaInfo(
            $pemCert,
            (int) $parsedPem['validFrom_time_t'],
            (int) $parsedPem['validTo_time_t'],
        );
    }

    /**
     * Generate a certificate for the VPN server.
     */
    public function serverCert(string $serverName, string $profileId): CertInfo
    {
        Validator::serverName($serverName);
        Validator::profileId($profileId);

        $crtFile = sprintf('%s/%s.crt', sys_get_temp_dir(), Hex::encode(random_bytes(32)));
        $keyFile = sprintf('%s/%s.key', sys_get_temp_dir(), Hex::encode(random_bytes(32)));
        $this->execVpnCa(sprintf('-server -name "%s" -ou "%s" -not-after CA -out-crt "%s" -out-key "%s"', $serverName, $profileId, $crtFile, $keyFile));
        $certInfo = new CertInfo(FileIO::read($crtFile), FileIO::read($keyFile));
        FileIO::delete($crtFile);
        FileIO::delete($keyFile);

        return $certInfo;
    }

    /**
     * Generate a certificate for a VPN client.
     */
    public function clientCert(string $commonName, string $profileId, DateTimeImmutable $expiresAt): CertInfo
    {
        Validator::commonName($commonName);
        Validator::profileId($profileId);

        // prevent expiresAt to be in the past
        if ($this->dateTime->getTimestamp() >= $expiresAt->getTimestamp()) {
            throw new CaException(sprintf('can not issue certificates that expire in the past (%s)', $expiresAt->format(DateTimeImmutable::ATOM)));
        }

        $crtFile = sprintf('%s/%s.crt', sys_get_temp_dir(), Hex::encode(random_bytes(32)));
        $keyFile = sprintf('%s/%s.key', sys_get_temp_dir(), Hex::encode(random_bytes(32)));
        $this->execVpnCa(sprintf('-client -name "%s" -ou "%s" -not-after "%s" -out-crt "%s" -out-key "%s"', $commonName, $profileId, $expiresAt->format(DateTimeImmutable::ATOM), $crtFile, $keyFile));
        $certInfo = new CertInfo(FileIO::read($crtFile), FileIO::read($keyFile));
        FileIO::delete($crtFile);
        FileIO::delete($keyFile);

        return $certInfo;
    }

    public function initCa(DateInterval $caExpiry): void
    {
        if (FileIO::exists($this->caDir.'/ca.key') || FileIO::exists($this->caDir.'/ca.crt')) {
            return;
        }

        if (!FileIO::exists($this->caDir)) {
            FileIO::mkdir($this->caDir);
        }

        $this->execVpnCa(
            sprintf(
                '-init-ca -not-after %s -name "VPN CA"',
                $this->dateTime->add($caExpiry)->format(DateTimeImmutable::ATOM)
            )
        );
    }

    private function execVpnCa(string $cmdArgs): void
    {
        self::exec(sprintf('CA_DIR=%s CA_KEY_TYPE=EdDSA %s %s', $this->caDir, $this->vpnCaPath, $cmdArgs));
    }

    private static function exec(string $execCmd): void
    {
        exec(
            sprintf('%s 2>&1', $execCmd),
            $commandOutput,
            $returnValue
        );

        if (0 !== $returnValue) {
            throw new RuntimeException(sprintf('command "%s" did not complete successfully: "%s"', $execCmd, implode(PHP_EOL, $commandOutput)));
        }
    }
}
