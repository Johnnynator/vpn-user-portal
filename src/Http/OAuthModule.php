<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2014-2022, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http;

use fkooman\OAuth\Server\Exception\OAuthException;
use fkooman\OAuth\Server\Http\Response as OAuthResponse;
use fkooman\OAuth\Server\OAuthServer;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\TplInterface;

class OAuthModule implements ServiceModuleInterface
{
    private OAuthServer $oauthServer;
    private TplInterface $tpl;

    public function __construct(OAuthServer $oauthServer, TplInterface $tpl)
    {
        $this->oauthServer = $oauthServer;
        $this->tpl = $tpl;
    }

    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/oauth/authorize',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    if ($authorizeResponse = $this->oauthServer->getAuthorizeResponse($userInfo->userId())) {
                        // optimization where we do not ask for approval
                        return $this->prepareReturnResponse($authorizeResponse);
                    }

                    // ask for approving this client/scope
                    return new HtmlResponse(
                        $this->tpl->render(
                            'authorizeOAuthClient',
                            $this->oauthServer->getAuthorize()
                        )
                    );
                } catch (OAuthException $e) {
                    throw new HttpException(sprintf('ERROR: %s (%s)', $e->getMessage(), $e->getDescription() ?? ''), $e->getStatusCode());
                }
            }
        );

        $service->post(
            '/oauth/authorize',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    $authorizeResponse = $this->oauthServer->postAuthorize(
                        $userInfo->userId()
                    );

                    return $this->prepareReturnResponse($authorizeResponse);
                } catch (OAuthException $e) {
                    throw new HttpException(sprintf('ERROR: %s (%s)', $e->getMessage(), $e->getDescription() ?? ''), $e->getStatusCode());
                }
            }
        );
    }

    private function prepareReturnResponse(OAuthResponse $authorizeResponse): Response
    {
        return new Response($authorizeResponse->getBody(), $authorizeResponse->getHeaders(), $authorizeResponse->getStatusCode());
    }
}
