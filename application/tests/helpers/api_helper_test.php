<?php

use PHPUnit\Framework\TestCase;
use APIHelper\APIHelper;
use FROG\PhpCurlSAI\SAI_CurlStub;

class APIHelperTest extends TestCase
{
    use \phpmock\phpunit\PHPMock;

    protected $api_helper;
    protected $curl_stub;

    public function setUp(): void {
        $CI =& get_instance();
        $CI->load->helper('api');
        $this->curl_stub = new SAI_CurlStub();
        $this->api_helper = new APIHelper($this->curl_stub);
    }

    public function testMockingGlobalsWorks() {
        $testURL = "https://localhost"; // Use a hostname that's definitely resolvable
        $dns_get_record = $this->getFunctionMock("APIHelper", "dns_get_record");
        $dns_get_record->expects($this->once())->willReturn(false); // Mock that it's not resolvable
        $this->assertEquals(false, $this->api_helper->filter_remote_url($testURL));
    }

    public function testFilterRemoteUrlRejectsUnresolvableHostnames() {
        $url = "http://some.unresolvable.hostname.fer-reals/data.json";
        $this->assertSame(false, $this->api_helper->filter_remote_url($url));
    }

    public function testFilterRemoteUrlRejectsUnusualPorts() {
        $this->assertSame(false, $this->api_helper->filter_remote_url("https://www.google.com:567/data.json"));
    }

    public function testFilterRemoteUrlAcceptsExpectedPorts() {
        $this->assertSame("https://www.google.com/data.json", $this->api_helper->filter_remote_url("https://www.google.com/data.json"));
        $this->assertSame("https://www.google.com:443/data.json", $this->api_helper->filter_remote_url("https://www.google.com:443/data.json"));
        $this->assertSame("http://www.google.com:80/data.json", $this->api_helper->filter_remote_url("http://www.google.com:80/data.json"));
        $this->assertSame("http://www.google.com:8080/data.json", $this->api_helper->filter_remote_url("http://www.google.com:8080/data.json"));
    }

    // A null hostname should explicitly return null
    public function testFilterRemoteUrlRejectsNullHostnames() {
        $url = null;
        $this->assertSame(null, $this->api_helper->filter_remote_url($url));
    }

    /**
     * @dataProvider badUrlProvider
     */
    public function testFilterRemoteUrlRejectsNonAndBadHostnames($url, $mockedRecord) {
        if ($mockedRecord) {
            // Mock out the DNS request with the expected value
            $dns_get_record = $this->getFunctionMock("APIHelper", "dns_get_record");
            $dns_get_record->expects($this->once())->willReturn($mockedRecord);
        }
        $this->assertSame(false, $this->api_helper->filter_remote_url($url));
    }

    /**
     * @dataProvider badUrlProvider
     */
    public function testCurlHeaderIsNotSusceptibleToSsrfDuringRedirect($url, $mockedRecord) {
        if ($mockedRecord) {
            // Mock out the DNS request with the expected value
            $dns_get_record = $this->getFunctionMock("APIHelper", "dns_get_record");
            $dns_get_record->expects($this->once())->willReturn($mockedRecord);
        }
        $this->expectException(\Exception::class);
        $this->api_helper->curl_header($this->mockRedirectTo($url), true);
    }

    /**
     * @dataProvider badUrlProvider
     */
    public function testCurlHeadShimIsNotSusceptibleToSsrfDuringRedirect($url, $mockedRecord) {
        $CI =& get_instance();
        if ($mockedRecord) {
            // Mock out the DNS request with the expected value
            $dns_get_record = $this->getFunctionMock("APIHelper", "dns_get_record");
            $dns_get_record->expects($this->once())->willReturn($mockedRecord);
        }
        $this->expectException(\Exception::class);
        $this->api_helper->curl_head_shim($this->mockRedirectTo($url), true, $CI->config->item('archive_dir'));
    }

    /**
     * @dataProvider badUrlProvider
     */
    public function testCurlFromJsonIsNotSusceptibleToSsrfDuringRedirect($url, $mockedRecord) {
        if ($mockedRecord) {
            // Mock out the DNS request with the expected value
            $dns_get_record = $this->getFunctionMock("APIHelper", "dns_get_record");
            $dns_get_record->expects($this->once())->willReturn($mockedRecord);
        }
        $this->expectException(\Exception::class);
        $this->api_helper->curl_from_json($this->mockRedirectTo($url));
    }

    /**
     * @dataProvider badProtocolProvider
     */
    public function testCurlHeaderIgnoresBadProtocols($protocol) {
        $this->expectException(\Exception::class);
        $this->api_helper->curl_header($protocol."://127.0.0.1");
    }

    /**
     * @dataProvider badProtocolProvider
     */
    public function testCurlHeadShimIgnoresBadProtocols($protocol) {
        $CI =& get_instance();
        $this->expectException(\Exception::class);
        $this->api_helper->curl_head_shim($protocol."://127.0.0.1", true, $CI->config->item('archive_dir'));
    }

    /**
     * @dataProvider badProtocolProvider
     */
    public function testCurlFromJsonIgnoresBadProtocols($protocol) {
        $this->expectException(\Exception::class);
        $this->api_helper->curl_from_json($protocol."://127.0.0.1");
    }

    // These are all the protocols that libcurl supports that we don't want to be valid
    public function badProtocolProvider() {
        $protocols = explode(' ', "dict file ftp ftps gopher imap imaps ldap ldaps pop3 pop3s rtmp rtsp scp sftp smb smtp smtps");
        foreach($protocols as $protocol) {
            $protocolarray[] = array($protocol);
        }
        return $protocolarray;
    }

    private function mockRedirectTo($url) {
        $requiredOptions = [
            CURLOPT_URL => 'https://redirect.for.me',
        ];
        $expectedInfo = [
            CURLINFO_REDIRECT_URL => $url,
        ];
        $this->curl_stub->setResponse('{
                "Location": "$url"
            }',
            $requiredOptions,
        );
        $this->curl_stub->setInfo($expectedInfo, $requiredOptions);

        return $requiredOptions[CURLOPT_URL];
    }

    // Examples of SSRF URLs we should never follow
    // The first array element is a URL that should be filtered out
    // The second argument is a mock array of records for dns_get_record() to return
    public function badUrlProvider() {

        $badRedirects["ip: 127.0.0.1"] = array("http://127.0.0.1", false);

        // Anything that resolves to zero needs to be tested carefully due to falsiness
        $badRedirects["ip: 0"] = array("http://0/data.json", false);

        // No dotted-quad decimals
        $badRedirects["ip: decimal"] = array("http://169.254.169.254/latest/meta-data/hostname", false);

        // Hex is bad
        $badRedirects["ip: hex"] = array("http://0x7f000001/data.json", false);

        // So is octal
        $badRedirects["ip: octal"] = array("http://0123/data.json", false);

        // We don't like mixed hex and octal either
        $badRedirects["ip: mixed hex/octal/decimal"] = array("http://0x7f.0x0.0.0/data.json", false);

        // We don't even like dotted-quads
        $badRedirects["ip: dotted-quad"] = array("http://111.22.34.56/data.json", false);

        // Don't you come around here with any of that IPv6 crap
        $badRedirects["ipv6: localhost"] = array("http://[::1]", false);

        // Domains that resolve to IPv4 localhost? NFW.
        $badRedirects["localhost.ip4"] = array("https://localhost.ip4/", [['type' => 'A', 'ip' => '127.0.0.1']]);

        // Domains that resolve to IPv6 localhost? Get out!
        $badRedirects["localhost.ip6"] = array("https://localhost.ip6:443", [['type' => 'AAAA', 'ipv6' => '::1']]);

        // Domains that resolve to IPv6 addresses that represent IPv4 private ranges? Not on our watch!
        $badRedirects["localhost2.ip6"] = array("http://localhost2.ip6:80", [['type' => 'AAAA', 'ipv6' => '::ffff:127.0.0.1']]);
        $badRedirects["private1.ip6"] = array("https://private1.ip6:80", [['type' => 'AAAA', 'ipv6' => '::ffff:192.168.1.18']]);
        $badRedirects["private2.ip6"] = array("https://private2.ip6:80", [['type' => 'AAAA', 'ipv6' => '::ffff:10.0.0.1']]);
        $badRedirects["private3.ip6"] = array("http://private3.ip6:80", [['type' => 'AAAA', 'ipv6' => '::ffff:127.0.0.2']]);

        // Domains that resolve to IPv6 link-local adddresses? Hell no!
        $badRedirects["linklocal.ip6"] = array("https://linklocal.ip6/", [['type' => 'AAAA', 'ipv6' => 'fe80::']]);

        return $badRedirects;
    }

}
