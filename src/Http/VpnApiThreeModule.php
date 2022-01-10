<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http;

use DateTimeImmutable;
use fkooman\OAuth\Server\AccessToken;
use Vpn\Portal\Config;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Dt;
use Vpn\Portal\ProfileConfig;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\Validator;

class VpnApiThreeModule implements ServiceModuleInterface
{
    protected DateTimeImmutable $dateTime;
    private Config $config;
    private Storage $storage;
    private ServerInfo $serverInfo;
    private ConnectionManager $connectionManager;

    public function __construct(Config $config, Storage $storage, ServerInfo $serverInfo, ConnectionManager $connectionManager)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->serverInfo = $serverInfo;
        $this->connectionManager = $connectionManager;
        $this->dateTime = Dt::get();
    }

    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/v3/info',
            function (AccessToken $accessToken, Request $request): Response {
                $profileConfigList = $this->config->profileConfigList();
                $userPermissions = $this->storage->userPermissionList($accessToken->userId());
                $userProfileList = [];
                foreach ($profileConfigList as $profileConfig) {
                    if (null !== $aclPermissionList = $profileConfig->aclPermissionList()) {
                        // is the user member of the aclPermissionList?
                        if (!VpnPortalModule::isMember($aclPermissionList, $userPermissions)) {
                            continue;
                        }
                    }

                    $userProfileList[] = [
                        'profile_id' => $profileConfig->profileId(),
                        'display_name' => $profileConfig->displayName(),
                        'vpn_proto_list' => $profileConfig->protoList(),
                        'vpn_proto_preferred' => $profileConfig->preferredProto(),
                        'default_gateway' => $profileConfig->defaultGateway(),
                    ];
                }

                return new JsonResponse(
                    [
                        'info' => [
                            'profile_list' => $userProfileList,
                        ],
                    ]
                );
            }
        );

        $service->post(
            '/v3/connect',
            function (AccessToken $accessToken, Request $request): Response {
                // make sure all client configurations / connections initiated
                // by this client are removed / disconnected
                $this->connectionManager->disconnectByAuthKey($accessToken->authKey());

                $maxActiveApiConfigurations = $this->config->apiConfig()->maxActiveConfigurations();
                if (null !== $maxActiveApiConfigurations) {
                    if (0 === $maxActiveApiConfigurations) {
                        return new JsonResponse(['error' => 'no API configuration downloads allowed'], [], 403);
                    }
                    $activeApiConfigurations = $this->storage->activeApiConfigurations($accessToken->userId(), $this->dateTime);
                    if (\count($activeApiConfigurations) >= $maxActiveApiConfigurations) {
                        // we disconnect the client that connected the longest
                        // time ago, which is first one from the set in
                        // activeApiConfigurations
                        $this->connectionManager->disconnect(
                            $accessToken->userId(),
                            $activeApiConfigurations[0]['profile_id'],
                            $activeApiConfigurations[0]['connection_id']
                        );
                    }
                }

                // XXX catch InputValidationException
                $requestedProfileId = $request->requirePostParameter('profile_id', fn (string $s) => Validator::profileId($s));
                $profileConfigList = $this->config->profileConfigList();
                $userPermissions = $this->storage->userPermissionList($accessToken->userId());
                $availableProfiles = [];
                foreach ($profileConfigList as $profileConfig) {
                    if (null !== $aclPermissionList = $profileConfig->aclPermissionList()) {
                        // is the user member of the userPermissions?
                        if (!VpnPortalModule::isMember($aclPermissionList, $userPermissions)) {
                            continue;
                        }
                    }

                    $availableProfiles[] = $profileConfig->profileId();
                }

                if (!\in_array($requestedProfileId, $availableProfiles, true)) {
                    return new JsonResponse(['error' => 'profile not available'], [], 400);
                }

                $profileConfig = $this->config->profileConfig($requestedProfileId);
                $publicKey = $request->optionalPostParameter('public_key', fn (string $s) => Validator::publicKey($s));
                $tcpOnly = 'on' === $request->optionalPostParameter('tcp_only', fn (string $s) => Validator::onOrOff($s));
                $vpnProto = self::determineProto($profileConfig, $publicKey, $tcpOnly);

                switch ($vpnProto) {
                    case 'openvpn':
                        $clientConfig = $this->connectionManager->connect(
                            $this->serverInfo,
                            $accessToken->userId(),
                            $profileConfig->profileId(),
                            $vpnProto,
                            $accessToken->clientId(),
                            $accessToken->authorizationExpiresAt(),
                            $tcpOnly,
                            null,
                            $accessToken->authKey(),
                        );

                        return new Response(
                            $clientConfig->get(),
                            [
                                'Expires' => $accessToken->authorizationExpiresAt()->format(DateTimeImmutable::RFC7231),
                                'Content-Type' => $clientConfig->contentType(),
                            ]
                        );

                    case 'wireguard':
                        $clientConfig = $this->connectionManager->connect(
                            $this->serverInfo,
                            $accessToken->userId(),
                            $profileConfig->profileId(),
                            $vpnProto,
                            $accessToken->clientId(),
                            $accessToken->authorizationExpiresAt(),
                            false,
                            $publicKey,
                            $accessToken->authKey()
                        );

                        return new Response(
                            $clientConfig->get(),
                            [
                                'Expires' => $accessToken->authorizationExpiresAt()->format(DateTimeImmutable::RFC7231),
                                'Content-Type' => $clientConfig->contentType(),
                            ]
                        );

                    default:
                        return new JsonResponse(['error' => 'unsupported VPN protocol'], [], 400);
                }
            }
        );

        $service->post(
            '/v3/disconnect',
            function (AccessToken $accessToken, Request $request): Response {
                $this->connectionManager->disconnectByAuthKey($accessToken->authKey());

                return new Response(null, [], 204);
            }
        );
    }

    private static function determineProto(ProfileConfig $profileConfig, ?string $publicKey, bool $tcpOnly): string
    {
        // only supports OpenVPN
        if ($profileConfig->oSupport() && !$profileConfig->wSupport()) {
            return 'openvpn';
        }

        // only supports WireGuard
        if (!$profileConfig->oSupport() && $profileConfig->wSupport()) {
            return 'wireguard';
        }

        // Profile supports OpenVPN & WireGuard
        // VPN client requests TCP connection
        if ($tcpOnly) {
            return 'openvpn';
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
}
