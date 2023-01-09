<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\Exception\ProtocolException;

/**
 * Determine which VPN protocol to use based on the client/user preferences
 * and profile profile support.
 */
class Protocol
{
    /**
     * @param array{wireguard:bool,openvpn:bool} $clientProtoSupport
     */
    public static function determine(ProfileConfig $profileConfig, array $clientProtoSupport, ?string $publicKey, bool $preferTcp): string
    {
        $wSupport = $clientProtoSupport['wireguard'];
        $oSupport = $clientProtoSupport['openvpn'];

        if (false === $oSupport && false === $wSupport) {
            throw new ProtocolException('neither wireguard, nor openvpn supported by client');
        }

        if ($oSupport && false === $wSupport) {
            if ($profileConfig->oSupport()) {
                return 'openvpn';
            }

            throw new ProtocolException('profile does not support openvpn, but only openvpn is acceptable for client');
        }

        if ($wSupport && false === $oSupport) {
            if (null === $publicKey) {
                throw new ProtocolException('client only supports wireguard, but does not provide a public key');
            }
            if ($profileConfig->wSupport()) {
                return 'wireguard';
            }

            throw new ProtocolException('profile does not support wireguard, but only wireguard is acceptable for client');
        }

        // At this point, the client does not *explicitly* specify their
        // supported protocols, so we assume both are supported...

        // Profile only supports OpenVPN
        if ($profileConfig->oSupport() && !$profileConfig->wSupport()) {
            return 'openvpn';
        }

        // Profile only supports WireGuard
        if (!$profileConfig->oSupport() && $profileConfig->wSupport()) {
            // client MUST have provided a public key
            if (null === $publicKey) {
                throw new ProtocolException('unable to connect using wireguard, no public key provided by client');
            }

            return 'wireguard';
        }

        // Profile supports OpenVPN & WireGuard

        // VPN client prefers connecting over TCP
        if ($preferTcp) {
            // but this has only meaning if there are actually TCP ports to
            // connect to...
            if (0 !== \count($profileConfig->oExposedTcpPortList()) || 0 !== \count($profileConfig->oTcpPortList())) {
                return 'openvpn';
            }
        }

        // Profile prefers OpenVPN
        if ('openvpn' === $profileConfig->preferredProto()) {
            return 'openvpn';
        }

        // VPN client provides a WireGuard Public Key, server prefers WireGuard
        if (null !== $publicKey) {
            return 'wireguard';
        }

        // Server prefers WireGuard, but VPN client does not provide a
        // WireGuard Public Key, so use OpenVPN...
        return 'openvpn';
    }

    /**
     * We only take the Accept header serious if we detect at least one
     * mime-type we recognize, otherwise we assume it is garbage and consider
     * it as "not sent".
     *
     * @return array{wireguard:bool,openvpn:bool}
     */
    public static function parseMimeType(?string $httpAccept): array
    {
        if (null === $httpAccept) {
            return ['wireguard' => true, 'openvpn' => true];
        }

        $oSupport = false;
        $wSupport = false;
        $takeSerious = false;

        $mimeTypeList = explode(',', $httpAccept);
        foreach ($mimeTypeList as $mimeType) {
            $mimeType = trim($mimeType);
            if ('application/x-openvpn-profile' === $mimeType) {
                $oSupport = true;
                $takeSerious = true;
            }
            if ('application/x-wireguard-profile' === $mimeType) {
                $wSupport = true;
                $takeSerious = true;
            }
        }
        if (false === $takeSerious) {
            return ['wireguard' => true, 'openvpn' => true];
        }

        return ['wireguard' => $wSupport, 'openvpn' => $oSupport];
    }
}
