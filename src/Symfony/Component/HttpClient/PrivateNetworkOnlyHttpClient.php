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

use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
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
class PrivateNetworkOnlyHttpClient implements HttpClientInterface
{
    use HttpClientTrait;

    const IPV4_PRIVATE_SUBNETS = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '127.0.0.0/8',
        '240.0.0.0/4',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    const IPV6_PRIVATE_SUBNETS = [
        '::1/128',
        '::/128',
        '::ffff:0:0/96',
        'fe80::/10',
        'fc00::/8',
        'fd00::/8',
    ];

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * It holds the parsed subnets ready to be used by IpUtils. Example:
     * [
     *     'ipv4_subnets' => [
     *         '10.0.0.0/24',
     *     ],
     *     'ipv6_subnets' => [
     *         'fd3c:f1c5:f371:e151::/64',
     *     ],
     * ]
     *
     * @var array
     */
    private $parsedBlacklist;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $client  A HttpClientInterface instance.
     * @param mixed               $options String or array of subnets
     */
    public function __construct(HttpClientInterface $client, $options = [])
    {
        $this->client = $client;

        if (!is_array($options)) {
            $options = [
                'blacklist' => [$options],
            ];
        } else if (!isset($options['blacklist'])) {
            $options = [
                'blacklist' => $options,
            ];
        }

        $options = array_merge([
            'blacklist' => [],
        ], $options);

        $this->parsedBlacklist = [
            'ipv4_subnets' => [],
            'ipv6_subnets' => [],
        ];

        $this->parseBlacklist($options['blacklist']);
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $lastPrimaryIp = '';
        $blacklist = $this->parsedBlacklist;
        $onProgress = $options['on_progress'] ?? null;

        $options['on_progress'] = function (int $dlNow, int $dlSize, array $info) use ($onProgress, $blacklist, &$lastPrimaryIp): void {
            if ($info['primary_ip'] !== $lastPrimaryIp) {
                if ($info['primary_ip'] === filter_var($info['primary_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    if (!IpUtils::checkIp($info['primary_ip'], self::IPV4_PRIVATE_SUBNETS)) {
                        throw new TransportException(sprintf('IPv4 "%s" is on public network.', $info['primary_ip']));
                    }

                    if (IpUtils::checkIp($info['primary_ip'], $blacklist['ipv4_subnets'])) {
                        throw new TransportException(sprintf('IPv4 "%s" is blacklisted.', $info['primary_ip']));
                    }
                } else if ($info['primary_ip'] === filter_var($info['primary_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    if (!IpUtils::checkIp($info['primary_ip'], self::IPV6_PRIVATE_SUBNETS)) {
                        throw new TransportException(sprintf('IPv6 "%s" is on public network.', $info['primary_ip']));
                    }

                    if (IpUtils::checkIp($info['primary_ip'], $blacklist['ipv6_subnets'])) {
                        throw new TransportException(sprintf('IPv6 "%s" is blacklisted.', $info['primary_ip']));
                    }
                } else {
                    throw new \LogicException(sprintf('Primary IP address "%s" was not recognized as an IP address.', $info['primary_ip']));
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

    /**
     * Parses blacklist option and assign parsed value to internal array.
     *
     * @param array $blacklist Unparsed blacklist.
     *
     * @return void
     *
     * @throws InvalidArgumentException When subnet is string with CIDR notation is provided but it isn't recognized as IPv4 nor IPv6
     * @throws InvalidArgumentException When subnet is string without CIDR notation is provided but it isn't recognized as IPv4 nor IPv6
     * @throws InvalidArgumentException When subnet is array but first value isn't recognized as IPv4 nor IPv6
     * @throws InvalidArgumentException When subnet isn't string nor array with keys #0 and #1
     */
    private function parseBlacklist(array $blacklist): void
    {
        foreach ($blacklist as $subnet) {
            if (is_string($subnet)) {
                if (false !== strpos($subnet, '/')) {
                    $parts = explode('/', $subnet, 2);

                    if ($parts[0] === filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $this->pushIpv4SubnetToBlacklist($parts[0], $parts[1]);

                        continue;
                    } else if ($parts[0] === filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $this->pushIpv6SubnetToBlacklist($parts[0], $parts[1]);

                        continue;
                    }

                    throw new InvalidArgumentException(sprintf('IP "%s" provided must be IPv4 or IPv6.', $parts[0]));
                } else {
                    if ($subnet === filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $parsed = sprintf('%s/32', $subnet);
                        $this->parsedBlacklist['ipv4_subnets'][$parsed] = $parsed;

                        continue;
                    } else if ($subnet === filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $parsed = sprintf('%s/128', $subnet);
                        $this->parsedBlacklist['ipv6_subnets'][$parsed] = $parsed;

                        continue;
                    }

                    throw new InvalidArgumentException(sprintf('IP "%s" provided must be IPv4 or IPv6.', $subnet));
                }
            }

            if (is_array($subnet) && isset($subnet[0], $subnet[1])) {
                if ($subnet[0] === filter_var($subnet[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $this->pushIpv4SubnetToBlacklist($subnet[0], $subnet[1]);

                    continue;
                } else if ($subnet[0] === filter_var($subnet[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $this->pushIpv6SubnetToBlacklist($subnet[0], $subnet[1]);

                    continue;
                }

                throw new InvalidArgumentException(sprintf('IP "%s" provided must be IPv4 or IPv6.', $subnet[0]));
            }

            throw new InvalidArgumentException('Invalid subnet provided. Only string with CIDR notation, or array with key blacklist with string values in CIDR notation or arrays with key #0 and #1 is supported.');
        }
    }

    /**
     * Performs simple netmask validations and push to blacklist IPv4 subnets.
     *
     * @param string $address Network IPv4 address
     * @param mixed  $netmask Network mask that can be numeric or IPv4 address.
     *
     * @return void
     *
     * @throws InvalidArgumentException When netmask is out of range (0 <= netmask <= 32)
     * @throws InvalidArgumentException When netmask isn't numeric (CIDR notation) nor IP address
     */
    private function pushIpv4SubnetToBlacklist(string $address, $netmask): void
    {
        if (is_numeric($netmask)) {
            if ((int) $netmask < 0 || 32 < (int) $netmask) {
                throw new InvalidArgumentException(sprintf('Invalid network mask "%s". It must be integer between (including) 0 and 32 for IPv4 "%s".', $netmask, $address));
            }
        } else if (false !== $netmask = ip2long($netmask)) {
            $netmask = 32 - log(($netmask ^ (-1 & 0xffffffff)) + 1, 2);
        } else {
            throw new InvalidArgumentException(sprintf('Invalid IPv4 network mask "%s" provided for IPv4 "%s".', $netmask, $address));
        }

        $parsed = sprintf('%s/%d', $address, $netmask);
        $this->parsedBlacklist['ipv4_subnets'][$parsed] = $parsed;
    }

    /**
     * Performs simple netmask validations and push to blacklist IPv6 subnets.
     *
     * @param string $address Network IPv6 address
     * @param mixed  $netmask Network mask that can be numeric or IPv6 address
     *
     * @return void
     *
     * @throws InvalidArgumentException When netmask isn't numeric (CIDR notation)
     * @throws InvalidArgumentException When netmask is out of range (0 <= netmask <= 128)
     */
    private function pushIpv6SubnetToBlacklist(string $address, $netmask): void
    {
        if (!is_numeric($netmask)) {
            throw new InvalidArgumentException('For IPv6 netmasks only CIDR notation is supported so far.');
        }

        if ((int) $netmask < 0 || 128 < (int) $netmask) {
            throw new InvalidArgumentException(sprintf('Invalid network mask "%s". It must be integer between (including) 0 and 128 for IPv6 "%s".', $netmask, $address));
        }

        $parsed = sprintf('%s/%d', $address, $netmask);
        $this->parsedBlacklist['ipv6_subnets'][$parsed] = $parsed;
    }
}
