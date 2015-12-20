<?php

namespace fkooman\VPN\UserPortal;

use fkooman\Http\Exception\BadRequestException;
use ZipArchive;

class Utils
{
    public static function validateConfigName($configName)
    {
        if (null === $configName) {
            throw new BadRequestException('missing parameter');
        }
        if (!is_string($configName)) {
            throw new BadRequestException('malformed parameter');
        }
        if (32 < strlen($configName)) {
            throw new BadRequestException('name too long, maximum 32 characters');
        }
        // XXX: be less restrictive in supported characters...
        if (0 === preg_match('/^[a-zA-Z0-9-_.@]+$/', $configName)) {
            throw new BadRequestException('invalid characters in name');
        }
    }

    public static function configToZip($configName, $configData)
    {
        $inlineTypeFileName = array(
            'ca' => sprintf('%s_ca.crt', $configName),
            'cert' => sprintf('%s_client.crt', $configName),
            'key' => sprintf('%s_client.key', $configName),
            'tls-auth' => sprintf('%s_ta.key', $configName),
        );
        $zipName = tempnam(sys_get_temp_dir(), 'vup_');
        $z = new ZipArchive();
        $z->open($zipName, ZipArchive::CREATE);
        foreach (array('cert', 'ca', 'key', 'tls-auth') as $inlineType) {
            $pattern = sprintf('/\<%s\>(.*)\<\/%s\>/msU', $inlineType, $inlineType);
            if (1 !== preg_match($pattern, $configData, $matches)) {
                throw new DomainException('inline type not found');
            }
            $configData = preg_replace(
                $pattern,
                sprintf(
                    '%s %s',
                    $inlineType,
                    $inlineTypeFileName[$inlineType]
                ),
                $configData
            );
            $z->addFromString($inlineTypeFileName[$inlineType], trim($matches[1]));
        }
        // remove "key-direction X" and add it to tls-auth line as last
        // parameter (hack to make NetworkManager import work)
        $configData = str_replace(
            array(
                'key-direction 1',
                sprintf('tls-auth %s_ta.key', $configName),
            ),
            array(
                '',
                sprintf('tls-auth %s_ta.key 1', $configName),
            ),
            $configData
        );
        $z->addFromString(sprintf('%s.ovpn', $configName), $configData);
        $z->close();
        $zipData = file_get_contents($zipName);
        unlink($zipName);

        return $zipData;
    }
}
