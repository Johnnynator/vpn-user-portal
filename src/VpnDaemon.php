<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2023, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use Vpn\Portal\HttpClient\Exception\HttpClientException;
use Vpn\Portal\HttpClient\HttpClientInterface;
use Vpn\Portal\HttpClient\HttpClientRequest;

/**
 * Class interfacing with vpn-daemon and preparing the response data to be
 * easier to use from PHP.
 */
class VpnDaemon
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * @return null|array{rel_load_average:array<int>,load_average:array<float>,cpu_count:int}
     */
    public function nodeInfo(string $nodeUrl): ?array
    {
        try {
            $nodeInfo = Json::decode(
                $this->httpClient->send(
                    new HttpClientRequest('GET', $nodeUrl.'/i/node')
                )->body()
            );

            // for some reason we decided to have vpn-daemon to return empty
            // array instead of [0,0,0] for "load_average" and
            // "rel_load_average" when this information is not available on a
            // particular platform
            if (0 === \count($nodeInfo['load_average'])) {
                $nodeInfo['load_average'] = [0, 0, 0];
                $nodeInfo['rel_load_average'] = [0, 0, 0];
            }

            return $nodeInfo;
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);

            return null;
        }
    }

    /**
     * @param bool $showAll also include peers that were never seen, or did not
     *   perform a handshake in the last 3 minutes
     *
     * @see https://git.sr.ht/~fkooman/vpn-daemon#peer-list
     *
     * @return array<string,array{public_key:string,ip_net:array<string>,last_handshake_time:?string,bytes_in:int,bytes_out:int}>
     */
    public function wPeerList(string $nodeUrl, bool $showAll): array
    {
        try {
            $wPeerList = Json::decode(
                $this->httpClient->send(
                    new HttpClientRequest('GET', $nodeUrl.'/w/peer_list', ['show_all' => $showAll ? 'yes' : 'no'])
                )->body()
            );

            $pList = [];
            foreach ($wPeerList['peer_list'] as $peerInfo) {
                $pList[$peerInfo['public_key']] = $peerInfo;
            }

            return $pList;
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);

            return [];
        }
    }

    public function wPeerAdd(string $nodeUrl, string $publicKey, string $ipFour, string $ipSix): void
    {
        try {
            $this->httpClient->send(
                new HttpClientRequest(
                    'POST',
                    $nodeUrl.'/w/add_peer',
                    [],
                    [
                        'public_key' => $publicKey,
                        'ip_net' => [$ipFour.'/32', $ipSix.'/128'],
                    ]
                )
            );
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);
        }
    }

    /**
     * @return ?array{public_key:string,ip_net:array<string>,last_handshake_time:?string,bytes_in:int,bytes_out:int}
     */
    public function wPeerRemove(string $nodeUrl, string $publicKey): ?array
    {
        try {
            $httpResponse = $this->httpClient->send(
                new HttpClientRequest(
                    'POST',
                    $nodeUrl.'/w/remove_peer',
                    [],
                    [
                        'public_key' => $publicKey,
                    ]
                )
            );

            if (200 === $httpResponse->statusCode()) {
                return Json::decode($httpResponse->body());
            }

            // response was probably 204 ("No Content"), but not an error
            return null;
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);

            return null;
        }
    }

    /**
     * @return array<string,array{common_name:string,ip_four:string,ip_six:string}>
     */
    public function oConnectionList(string $nodeUrl): array
    {
        try {
            $oConnectionList = Json::decode(
                $this->httpClient->send(
                    new HttpClientRequest(
                        'GET',
                        $nodeUrl.'/o/connection_list'
                    )
                )->body()
            );

            $cList = [];
            foreach ($oConnectionList['connection_list'] as $clientInfo) {
                $cList[$clientInfo['common_name']] = $clientInfo;
            }

            return $cList;
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);

            return [];
        }
    }

    public function oDisconnectClient(string $nodeUrl, string $commonName): void
    {
        try {
            $this->httpClient->send(
                new HttpClientRequest(
                    'POST',
                    $nodeUrl.'/o/disconnect_client',
                    [],
                    [
                        'common_name' => $commonName,
                    ]
                )
            );
        } catch (HttpClientException $e) {
            $this->logger->error((string) $e);
        }
    }
}
