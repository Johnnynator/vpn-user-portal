<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal;

class ClientConfig
{
    const STRATEGY_FIRST = 0;
    const STRATEGY_RANDOM = 1;
    const STRATEGY_ALL = 2;

    /**
     * @param int $remoteStrategy
     *
     * @return string
     */
    public static function get(ProfileConfig $profileConfig, array $serverInfo, array $clientCertificate, $remoteStrategy)
    {
        // make a list of ports/proto to add to the configuration file
        $hostName = $profileConfig->hostName();

        $vpnProtoPorts = $profileConfig->vpnProtoPorts();
        if (0 !== \count($profileConfig->exposedVpnProtoPorts())) {
            $vpnProtoPorts = $profileConfig->exposedVpnProtoPorts();
        }

        $remoteProtoPortList = self::remotePortProtoList($vpnProtoPorts, $remoteStrategy);

        $clientConfig = [
            '# OpenVPN Client Configuration',
            'dev tun',
            'client',
            'nobind',

            // the server can also push these if needed, and it should be up
            // to the client anyway if this is a good idea or not, e.g. running
            // in a chroot
            //'persist-key',
            //'persist-tun',
            'remote-cert-tls server',

            'verb 3',

            // wait this long (seconds) before trying the next server in the list
            'server-poll-timeout 10',

            // >= TLSv1.3
            'tls-version-min 1.3',

            // only allow AES-256-GCM
            'data-ciphers AES-256-GCM',

            // server dictates data channel key renegotiation interval
            'reneg-sec 0',

            '<ca>',
            // in legacy situation some trimming may be required, for clean
            // installs this is no longer needed though
            trim($serverInfo['ca']),
            '</ca>',

            '<tls-crypt>',
            // in legacy situation some trimming may be required, for
            // clean installs this is no longer needed
            trim($serverInfo['tls_crypt']),
            '</tls-crypt>',
        ];

        // if clientCertificate is provided, we add it directly to the
        // configuration file
        if (0 !== \count($clientCertificate)) {
            $clientConfig = array_merge(
                $clientConfig,
                [
                    '<cert>',
                    $clientCertificate['certificate'],
                    '</cert>',

                    '<key>',
                    $clientCertificate['private_key'],
                    '</key>',
                ]
            );
        }

        // remote entries
        foreach ($remoteProtoPortList as $remoteProtoPort) {
            $clientConfig[] = sprintf('remote %s %d %s', $hostName, (int) substr($remoteProtoPort, 4), substr($remoteProtoPort, 0, 3));
        }

        return implode(\PHP_EOL, $clientConfig);
    }

    /**
     * Pick a "normal" UDP and TCP port. Pick a "special" UDP and TCP
     * port.
     *
     * @param int $remoteStrategy
     *
     * @return array<string>
     */
    public static function remotePortProtoList(array $vpnProtoPorts, $remoteStrategy)
    {
        $specialUdpPorts = [];
        $specialTcpPorts = [];
        $normalUdpPorts = [];
        $normalTcpPorts = [];

        foreach ($vpnProtoPorts as $vpnProtoPort) {
            if (0 === strpos($vpnProtoPort, 'udp')) {
                if (\in_array($vpnProtoPort, ['udp/53', 'udp/443'], true)) {
                    $specialUdpPorts[] = $vpnProtoPort;
                    continue;
                }
                $normalUdpPorts[] = $vpnProtoPort;
                continue;
            }

            if (0 === strpos($vpnProtoPort, 'tcp')) {
                if (\in_array($vpnProtoPort, ['tcp/80', 'tcp/443'], true)) {
                    $specialTcpPorts[] = $vpnProtoPort;
                    continue;
                }
                $normalTcpPorts[] = $vpnProtoPort;
                continue;
            }
        }

        $clientPortList = [];
        self::getItem($clientPortList, $normalUdpPorts, $remoteStrategy);
        self::getItem($clientPortList, $normalTcpPorts, $remoteStrategy);
        self::getItem($clientPortList, $specialUdpPorts, $remoteStrategy);
        self::getItem($clientPortList, $specialTcpPorts, $remoteStrategy);

        return $clientPortList;
    }

    /**
     * @param array<string> &$clientPortList
     * @param array<string> $pickFrom
     * @param int           $remoteStrategy
     */
    private static function getItem(array &$clientPortList, array $pickFrom, $remoteStrategy): void
    {
        if (0 === \count($pickFrom)) {
            return;
        }

        switch ($remoteStrategy) {
            case self::STRATEGY_ALL:
                $clientPortList = array_merge($clientPortList, $pickFrom);
                break;
            case self::STRATEGY_RANDOM:
                $clientPortList[] = $pickFrom[random_int(0, \count($pickFrom) - 1)];
                break;
            default:
                $clientPortList[] = reset($pickFrom);
                break;
        }
    }
}
