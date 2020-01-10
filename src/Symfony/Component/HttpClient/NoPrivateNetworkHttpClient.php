<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decorator to allow requests to private network only.
 *
 * @author Hallison Boaventura <hallisonboaventura@gmail.com>
 */
class NoPrivateNetworkHttpClient implements HttpClientInterface
{
    use HttpClientTrait;

    /**
     * @var HttpClientInterface
     */
    private $client;

    private $subnets;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $client  A HttpClientInterface instance.
     * @param string|array        $subnets String or array of subnets that will be used by IpUtils.
     */
    public function __construct(HttpClientInterface $client, $subnets)
    {
        $this->client = $client;
        $this->subnets = $subnets;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $subnets = $this->subnets;

        $lastPrimaryIp = '';
        $onProgress = $options['on_progress'] ?? null;

        $options['on_progress'] = function (int $dlNow, int $dlSize, array $info) use ($onProgress, $subnets, &$lastPrimaryIp): void {
            if ($info['primary_ip'] !== $lastPrimaryIp) {
                if (IpUtils::checkIp($info['primary_ip'], $subnets)) {
                    throw new TransportException(sprintf('IP "%s" is blacklisted.', $info['primary_ip']));
                }

                $lastPrimaryIp = $info['primary_ip'];
            }

            is_callable($onProgress) && $onProgress($dlNow, $dlSize, $info);
        };

        return $this->client->request($method, $url, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }
}
