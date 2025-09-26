<?php

namespace SecurityScanner\Tests\SecurityTests;

use SecurityScanner\Tests\{AbstractTest, TestResult};

class SslCertificateTest extends AbstractTest
{
    public function getName(): string
    {
        return 'ssl_certificate_check';
    }

    public function getDescription(): string
    {
        return 'Checks SSL certificate validity, expiration, and security configuration';
    }

    public function getCategory(): string
    {
        return 'security';
    }

    protected function getTags(): array
    {
        return ['security', 'ssl', 'certificate', 'encryption'];
    }

    protected function getDefaultConfig(): array
    {
        return array_merge(parent::getDefaultConfig(), [
            'check_expiry_days' => 30,
            'check_chain' => true,
            'check_ciphers' => true,
            'min_key_size' => 2048,
            'allowed_protocols' => ['TLSv1.2', 'TLSv1.3'],
            'check_hsts' => true
        ]);
    }

    protected function getMetadata(): array
    {
        return array_merge(parent::getMetadata(), [
            'requires' => ['ext:openssl'],
            'version' => '1.2.0'
        ]);
    }

    public function run(string $target, array $context = []): TestResult
    {
        $url = $this->parseUrl($target);
        if (empty($url['host'])) {
            return $this->createErrorResult('Invalid target URL');
        }

        $host = $url['host'];
        $port = $url['port'] ?? 443;

        // Check if SSL is available
        if (!$this->isPortOpen($host, $port)) {
            return $this->createFailureResult("SSL port {$port} is not accessible on {$host}");
        }

        try {
            $sslInfo = $this->getSslCertificateInfo($host, $port);

            if (!$sslInfo) {
                return $this->createErrorResult('Could not retrieve SSL certificate information');
            }

            return $this->analyzeCertificate($sslInfo, $host);

        } catch (\Exception $e) {
            return $this->createErrorResult('SSL certificate check failed: ' . $e->getMessage());
        }
    }

    private function isPortOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function getSslCertificateInfo(string $host, int $port): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => $this->config['check_chain'],
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            return null;
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            return null;
        }

        $cert = $params['options']['ssl']['peer_certificate'];
        $certData = openssl_x509_parse($cert);

        if (!$certData) {
            return null;
        }

        $result = [
            'certificate' => $certData,
            'chain' => $params['options']['ssl']['peer_certificate_chain'] ?? []
        ];

        // Get additional SSL connection info
        $result['connection_info'] = $this->getConnectionInfo($host, $port);

        return $result;
    }

    private function getConnectionInfo(string $host, int $port): array
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            return [];
        }

        $info = stream_context_get_params($socket);
        fclose($socket);

        return $info['options']['ssl'] ?? [];
    }

    private function analyzeCertificate(array $sslInfo, string $host): TestResult
    {
        $cert = $sslInfo['certificate'];
        $issues = [];
        $warnings = [];
        $data = [];
        $score = 100;

        // Check certificate validity
        $now = time();
        $validFrom = $cert['validFrom_time_t'];
        $validTo = $cert['validTo_time_t'];

        $data['certificate_subject'] = $cert['subject']['CN'] ?? 'Unknown';
        $data['certificate_issuer'] = $cert['issuer']['CN'] ?? 'Unknown';
        $data['valid_from'] = date('Y-m-d H:i:s', $validFrom);
        $data['valid_to'] = date('Y-m-d H:i:s', $validTo);
        $data['serial_number'] = $cert['serialNumber'] ?? 'Unknown';

        // Check if certificate is currently valid
        if ($now < $validFrom) {
            $issues[] = 'Certificate is not yet valid';
            $score -= 50;
        }

        if ($now > $validTo) {
            $issues[] = 'Certificate has expired';
            $score -= 50;
        }

        // Check expiry warning
        $daysUntilExpiry = ($validTo - $now) / (24 * 60 * 60);
        $data['days_until_expiry'] = round($daysUntilExpiry, 1);

        if ($daysUntilExpiry <= $this->config['check_expiry_days'] && $daysUntilExpiry > 0) {
            $warnings[] = "Certificate expires in {$data['days_until_expiry']} days";
            $score -= 20;
        }

        // Check hostname match
        if (!$this->checkHostnameMatch($cert, $host)) {
            $issues[] = 'Certificate hostname does not match target';
            $score -= 30;
        }

        // Check key size
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey) {
            $keyDetails = openssl_pkey_get_details($publicKey);
            $keySize = $keyDetails['bits'] ?? 0;
            $data['key_size'] = $keySize;
            $data['key_type'] = $keyDetails['type'] ?? 'unknown';

            if ($keySize < $this->config['min_key_size']) {
                $issues[] = "Key size ({$keySize} bits) is below recommended minimum ({$this->config['min_key_size']} bits)";
                $score -= 25;
            }
        }

        // Check signature algorithm
        $signatureAlg = $cert['signatureTypeSN'] ?? '';
        $data['signature_algorithm'] = $signatureAlg;

        if (str_contains(strtolower($signatureAlg), 'sha1')) {
            $warnings[] = 'Certificate uses SHA-1 signature algorithm (deprecated)';
            $score -= 15;
        }

        // Check certificate chain
        if ($this->config['check_chain'] && !empty($sslInfo['chain'])) {
            $chainIssues = $this->checkCertificateChain($sslInfo['chain']);
            $issues = array_merge($issues, $chainIssues);
            $data['chain_length'] = count($sslInfo['chain']);
        }

        // Determine result status
        if (!empty($issues)) {
            $message = 'SSL certificate has issues: ' . implode('; ', $issues);
            if (!empty($warnings)) {
                $message .= '. Warnings: ' . implode('; ', $warnings);
            }
            $result = $this->createFailureResult($message, $data);
        } elseif (!empty($warnings)) {
            $message = 'SSL certificate is valid with warnings: ' . implode('; ', $warnings);
            $result = $this->createWarningResult($message, $data);
        } else {
            $result = $this->createSuccessResult('SSL certificate is valid and secure', $data);
        }

        $result->setScore(max(0, $score));

        // Add recommendations
        if ($score < 100) {
            if ($daysUntilExpiry <= 30) {
                $result->addRecommendation('Renew SSL certificate before expiration');
            }
            if (isset($keySize) && $keySize < 2048) {
                $result->addRecommendation('Use at least 2048-bit RSA keys or 256-bit ECDSA keys');
            }
            if (str_contains(strtolower($signatureAlg), 'sha1')) {
                $result->addRecommendation('Upgrade to SHA-256 or higher signature algorithm');
            }
        }

        return $result;
    }

    private function checkHostnameMatch(array $cert, string $host): bool
    {
        // Check CN
        $cn = $cert['subject']['CN'] ?? '';
        if ($this->matchHostname($cn, $host)) {
            return true;
        }

        // Check SANs
        if (isset($cert['extensions']['subjectAltName'])) {
            $sans = explode(', ', $cert['extensions']['subjectAltName']);
            foreach ($sans as $san) {
                if (str_starts_with($san, 'DNS:')) {
                    $dnsName = substr($san, 4);
                    if ($this->matchHostname($dnsName, $host)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function matchHostname(string $pattern, string $hostname): bool
    {
        // Exact match
        if ($pattern === $hostname) {
            return true;
        }

        // Wildcard match
        if (str_starts_with($pattern, '*.')) {
            $wildcardDomain = substr($pattern, 2);
            return str_ends_with($hostname, '.' . $wildcardDomain);
        }

        return false;
    }

    private function checkCertificateChain(array $chain): array
    {
        $issues = [];

        if (count($chain) < 2) {
            $issues[] = 'Certificate chain is incomplete';
        }

        // Additional chain validation could be added here
        // Such as checking each certificate in the chain

        return $issues;
    }
}