<?php declare(strict_types=1); ?>
<?php /** @var \Vpn\Portal\Tpl $this */?>
<?php /** @var array<string,array<array{user_id:string,connection_id:string,display_name:string,ip_list:array<string>,vpn_proto:string,auth_key:?string}>> $profileConnectionList */?>
<?php /** @var array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList */?>
<?php /** @var string $requestRoot */?>

<?php $this->layout('base', ['activeItem' => 'connections', 'pageTitle' => $this->t('Connections')]); ?>
<?php $this->start('content'); ?>
    <table class="tbl">
        <thead>
            <tr>
                <th><?=$this->t('Profile'); ?></th>
                <th title="<?=$this->t('The number of currently connected VPN clients, and the maximum possible number of connected VPN clients based on available IP-space for this profile.');?>"><?=$this->t('#Active (Max #Available) Connections'); ?></th>
            </tr>
        </thead>
        <tbody>
    <?php foreach ($profileConnectionList as $profileId => $connectionList): ?>
        <tr>
            <td><a title="<?=$this->e($profileId); ?>" href="#<?=$this->e($profileId); ?>"><?=$this->profileIdToDisplayName($profileConfigList, $profileId); ?></a></td>
            <td>
            <?=$this->e((string)count($connectionList)); ?>
<?php if(null === $maxClientLimit = $this->maxClientLimit($profileConfigList, $profileId)): ?>
            (<?=$this->t('N/A');?>)
<?php else: ?>
            (<?=$this->e((string)$maxClientLimit);?>)
<?php endif;?>
            </td>
        </tr>
    <?php endforeach; ?>
        </tbody>
    </table>
<?php foreach ($profileConnectionList as $profileId => $connectionList): ?>
        <h2 id="<?=$this->e($profileId); ?>"><?=$this->profileIdToDisplayName($profileConfigList, $profileId); ?></h2>
        <?php if (0 === count($connectionList)): ?>
            <p class="plain"><?=$this->t('Currently there are no clients connected to this profile.'); ?></p>
        <?php else: ?>
            <table class="tbl">
            <thead>
                <tr>
                    <th><?=$this->t('User ID'); ?></th>
                    <th><?=$this->t('Name'); ?></th>
                    <th><?=$this->t('IP Address'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($connectionList as $connection): ?>
                <tr>
                    <td>
                        <a href="<?=$this->e($requestRoot); ?>user?user_id=<?=$this->e($connection['user_id'], 'rawurlencode'); ?>" title="<?=$this->e($connection['user_id']); ?>"><?=$this->etr($connection['user_id'], 25); ?></a>
                    </td>
                    <td>
<?php if (null === $connection['auth_key']): ?>
                        <span title="<?=$this->e($connection['display_name']); ?>"><?=$this->etr($connection['display_name'], 25); ?></span>
<?php else: ?>
                        <span title="<?=$this->e($connection['display_name']); ?>"><?=$this->clientIdToDisplayName($connection['display_name']); ?></span>
<?php endif; ?>
                    </td>
                    <td>
                        <ul>
<?php foreach ($connection['ip_list'] as $ip): ?>
                            <li><code><?=$this->e($ip); ?></code></li>
<?php endforeach; ?>
                        </ul>
                    </td>
                    <td>
<?php if ('wireguard' === $connection['vpn_proto']): ?>
                        <span class="plain wireguard"><?=$this->t('WireGuard'); ?></span>
<?php else: ?>
                        <span class="plain openvpn"><?=$this->t('OpenVPN'); ?></span>
<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        <?php endif; ?>
<?php endforeach; ?>
<?php $this->stop('content'); ?>
