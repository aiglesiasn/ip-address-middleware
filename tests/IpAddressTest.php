<?php
namespace RKA\Middleware\Test;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RKA\Middleware\IpAddress;

class IpAddressTest extends TestCase
{
    private function simpleRequest(IPAddress $middleware, $env, $attrName = 'ip_address')
    {
        $request = ServerRequestFactory::fromGlobals($env);
        $attributeValue = '__DUMMY_VALUE__';
        $middleware($request, new Response(), function ($request, $response) use (&$attributeValue, $attrName) {
            $attributeValue = $request->getAttribute($attrName);
            return $response;
        });
        return $attributeValue;
    }

    public function testMissingRemoteAddrSetsTheAttributeToNull()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame(null, $ipAddress);
    }

    public function testIpAddressIsSetByRemoteAddrIfCheckProxyHeadersIsFalse()
    {
        $middleware = new IPAddress(false, [], 'IP');
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env, 'IP');

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testPortIsStrippedFromRemoteAddr()
    {
        $middleware = new IPAddress(false, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1:80',
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testIpCidrMatch()
    {
        $positiveMatches = [
            '10.0.8.23' => '10.0.0.0/16',
            '10.0.238.184' => '10.0.0.0/16',
            '10.0.128.129' => '10.0.128.129/32',
            '10.0.160.10' => '10.0.160.8/29',
        ];
        foreach ($positiveMatches as $remoteAddr => $cidr) {
            $middleware = new IPAddress(true, [$cidr]);
            $env = [
                'REMOTE_ADDR' => $remoteAddr,
                'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
            ];
            $ipAddress = $this->simpleRequest($middleware, $env);
            $this->assertSame('123.4.5.6', $ipAddress, "Testing CIDR: $cidr");
        }

        $negativeMatches = [
            '10.1.8.23' => '10.0.0.0/16',
            '192.0.238.184' => '10.0.0.0/16',
            '10.0.122.123' => '10.0.128.129/32',
            '10.0.160.7' => '10.0.160.8/29',
            '10:0:160:8:a:a:a:a' => '10.0.160.8/29',
        ];
        foreach ($negativeMatches as $remoteAddr => $cidr) {
            $middleware = new IPAddress(true, [$cidr]);
            $env = [
                'REMOTE_ADDR' => $remoteAddr,
                'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
            ];
            $ipAddress = $this->simpleRequest($middleware, $env);
            $this->assertNotSame('123.4.5.6', $ipAddress, "Testing CIDR: $cidr");
        }
    }

    public function testIp4WildcardMatch()
    {
        $positiveMatches = [
            '10.0.8.23' => '10.0.*.*',
            '10.0.238.184' => '10.0.238.*',
            '10.0.128.129' => '10.0.*.129',
            '10.76.32.129' => '10.*.32.129',
            '10.0.160.10' => '*.0.160.*',
            '10.0.160.9' => '*.*.160.*',
        ];
        foreach ($positiveMatches as $remoteAddr => $wildcard) {
            $middleware = new IPAddress(true, [$wildcard]);
            $env = [
                'REMOTE_ADDR' => $remoteAddr,
                'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
            ];
            $ipAddress = $this->simpleRequest($middleware, $env);
            $this->assertSame('123.4.5.6', $ipAddress, "Testing wildcard: $wildcard");
        }

        $negativeMatches = [
            '9.0.8.23' => '10.0.*.*',
            '10.0.234.0' => '10.0.238.*',
            '10.1.128.129' => '10.0.*.129',
            '10.0.32.128' => '10.*.32.129',
            '214.0.16.10' => '*.0.160.*',
            '10.0.150.9' => '*.*.160.*',
            '10:0:150:9:A:A:A:A' => '*.*.160.*',
        ];
        foreach ($negativeMatches as $remoteAddr => $wildcard) {
            $middleware = new IPAddress(true, [$wildcard]);
            $env = [
                'REMOTE_ADDR' => $remoteAddr,
                'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
            ];
            $ipAddress = $this->simpleRequest($middleware, $env);
            $this->assertNotSame('123.4.5.6', $ipAddress, "Testing wildcard: $wildcard");
        }
    }

    public function testIpIsNullIfMissing()
    {
        $middleware = new IPAddress();
        $ipAddress = $this->simpleRequest($middleware, []);

        $this->assertNull($ipAddress);
    }

    public function testIpIsNullIfMissingAndProxiesAreConfigured()
    {
        error_reporting(-1);
        $middleware = new IPAddress(true, ['*'], 'IP');
        $env = [];
        $ipAddress = $this->simpleRequest($middleware, $env, 'IP');

        $this->assertSame(null, $ipAddress);
    }

    public function testIpAddressIsLastInXForwardedForListIfTrustedProxiesIsEmpty()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.4, 192.168.1.3, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.2', $ipAddress);
    }

    public function testPortsAreStrippedOnXForwardedFor()
    {
        $middleware = new IPAddress(true, ['192.168.1.1']);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1:81',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.4:81, 192.168.1.3:81, 192.168.1.2:81'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.2', $ipAddress);
    }

    public function testProxyHeaderIsIgnoredWhenNoArgumentsProvided()
    {
        $middleware = new IPAddress();
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.4, 192.168.1.3, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testHttpClientIpHeaderIsUsedWhenAvailable()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_CLIENT_IP' => '192.168.1.3'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.3', $ipAddress);
    }

    public function testXForwardedForIpV6()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '001:DB8::21f:5bff:febf:ce22:8a2e'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('001:DB8::21f:5bff:febf:ce22:8a2e', $ipAddress);
    }

    public function testXForwardedForIsIgnoredWhenItContainsAnInvalidIp()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => 'foo-bar 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testXForwardedForIpWithOneTrustedProxyInList()
    {
        $middleware = new IPAddress(true, ['192.168.0.1', '192.168.0.2']);
        $env = [
            'REMOTE_ADDR' => '192.168.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.3, 192.168.1.2, 192.168.1.1'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testXForwardedForIpWithTwoTrustedProxiesInList()
    {
        $middleware = new IPAddress(true, ['192.168.0.1', '192.168.0.2']);
        $env = [
            'REMOTE_ADDR' => '192.168.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.3, 192.168.1.2, 192.168.0.1'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.2', $ipAddress);
    }

    /**
     * If the proxy is untrusted, then we do not use the proxy list and return the REMOTE_ADDR
     */
    public function testXForwardedForIpWithUntrustedProxy()
    {
        $middleware = new IPAddress(true, ['192.168.0.1']);
        $env = [
            'REMOTE_ADDR' => '192.168.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.3, 192.168.1.2, 192.168.1.1'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.0.2', $ipAddress);
    }

    public function testForwardedWithMultipleFor()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_FORWARDED' => 'for=192.0.2.43, for=198.51.100.17;by=203.0.113.60;proto=http;host=example.com',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('198.51.100.17', $ipAddress);
    }

    /**
     * With no trusted proxies listed, REMOTE_ADDR is assumed to be a trusted proxy and
     * so the last for= entry is returned.
     */
    public function testForwardedWithAllOptions()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_FORWARDED' => 'for=192.0.2.60; proto=http;by=203.0.113.43; host=_hiddenProxy, for=192.0.2.61',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.0.2.61', $ipAddress);
    }

    /**
     *  Test that we support IPV6 with a port number as the for= value
     */
    public function testForwardedWithWithIpV6()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_FORWARDED' => 'For="[2001:db8:cafe::17]:4711", host=_internalProxy',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('2001:db8:cafe::17', $ipAddress);
    }

    /**
     * If one of the for= entries is an internal proxy, then we do not support the entire
     * header at this time. This could be fixed if we support a count of proxy hops.
     */
    public function testForwardedWithWithAnInternalProxy()
    {
        $middleware = new IPAddress(true, []);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_FORWARDED' => 'For="[2001:db8:cafe::17]:4711", for=_internalProxy',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.1', $ipAddress);
    }

    public function testCustomHeader()
    {
        $headersToInspect = [
            'Foo-Bar'
        ];
        $middleware = new IPAddress(true, [], null, $headersToInspect);

        $request = ServerRequestFactory::fromGlobals([
            'REMOTE_ADDR' => '192.168.0.1',
        ]);
        $request = $request->withAddedHeader('Foo-Bar', '192.168.1.3');
        $response = new Response();

        $ipAddress = '123';
        $response  = $middleware($request, $response, function ($request, $response) use (&$ipAddress) {
            // simply store the "ip_address" attribute in to the referenced $ipAddress
            $ipAddress = $request->getAttribute('ip_address');
            return $response;
        });

        $this->assertSame('192.168.1.3', $ipAddress);
    }

    public function testPSR15()
    {
        $middleware = new IPAddress();
        $request = ServerRequestFactory::fromGlobals([
            'REMOTE_ADDR' => '192.168.0.1',
        ]);

        $handler = (new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write("Hello World");

                return $response;
            }
        });
        $response = $middleware->process($request, $handler);

        $this->assertSame("Hello World", (string) $response->getBody());
    }

    public function testIpCidrListMatch()
    {
        $matches = [
            '192.16.238.184/24', // negative match
            '10.11.0.0/16', // positive match
        ];
        $middleware = new IPAddress(true, $matches);
        $env = [
            'REMOTE_ADDR' => '10.11.156.95',
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);
        $this->assertSame('123.4.5.6', $ipAddress, "Testing CIDR: " . implode(', ', $matches));
    }

    public function testIp4WildcardsMatch()
    {
        $matches = [
            '192.168.*.*', // negative match
            '10.0.238.*', // negative match
            '10.11.*.*', // positive match
        ];
        $middleware = new IPAddress(true, $matches);
        $env = [
            'REMOTE_ADDR' => '10.11.156.95',
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);
        $this->assertSame('123.4.5.6', $ipAddress, "Testing wildcard: " . implode(', ', $matches));
    }

    public function testIp4MultipleTypesMatch()
    {
        $matches = [
            '192.168.0.1', // negative match
            '10.0.238.*', // negative match
            '10.11.0.0/16', // positive match
        ];
        $middleware = new IPAddress(true, $matches);
        $env = [
            'REMOTE_ADDR' => '10.11.156.95',
            'HTTP_X_FORWARDED_FOR' => '123.4.5.6',
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);
        $this->assertSame('123.4.5.6', $ipAddress, "Testing proxies: " . implode(', ', $matches));
    }

    public function testNotGivingAProxyListShouldThrowException()
    {
        $this->expectException(\InvalidArgumentException::class);
        new IpAddress(true);
    }

    /**
     * If the trusted proxy is not rightmost, then we ignore it and pick the rightmost
     * IP address after the trusted proxies in the right position
     */
    public function testThatATrustedProxiesInWrongPlaceIsIgnored()
    {
        $middleware = new IPAddress(true, ['192.168.0.1', '192.168.0.2']);
        $env = [
            'REMOTE_ADDR' => '192.168.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.3, 192.168.0.1, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        $this->assertSame('192.168.1.2', $ipAddress);
    }

    /**
     * If the hop count is set, then it is used to determine the IP address to return
     */
    public function testHopCountIsUsedWhenNoTrustedProxiesAreDefined()
    {
        $middleware = new IPAddress(true, [], null, [], 3);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.5, 192.168.1.4, 192.168.1.3, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        // With three trusted hops, the 4th IP address should be found
        $this->assertSame('192.168.1.4', $ipAddress);
    }

    /**
     * With the hop count set, the IP address returned is the first IP address after the hop count even
     * if there are non-trusted IP addresses before it in the list.
     */
    public function testHopCountOverridesTrustedProxies()
    {
        $middleware = new IPAddress(true, ['192.168.1.2', '192.168.1.1'], null, [], 3);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.5, 192.168.1.4, 192.168.1.3, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        // With three trusted hops, the 4th IP address should be found even though the third IP address
        // is not a trusted proxy
        $this->assertSame('192.168.1.4', $ipAddress);
    }

    /**
     * With the hop count is set, if the IP address at the hop count is a trusted proxy, then
     * select the first IP address after it that is not a trusted proxy
     */
    public function testHopCountDoesNotReturnATrustedProxy()
    {
        $middleware = new IPAddress(true, ['192.168.1.2', '192.168.1.1'], null, [], 1);
        $env = [
            'REMOTE_ADDR' => '192.168.1.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.5, 192.168.1.4, 192.168.1.3, 192.168.1.2'
        ];
        $ipAddress = $this->simpleRequest($middleware, $env);

        // With 1 trusted hop, the second IP address would be found, except that it is a trusted proxy
        // itself, so the third IP address should be found
        $this->assertSame('192.168.1.3', $ipAddress);
    }
}
