<?php

namespace Tests\Support;

/** Throwaway RSA key pair for tests, created in the system temp dir for the duration of a run and never kept in the repo. */
class Rsa
{
    /** @return array{publicPath:string, privatePem:string} */
    public static function pair(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $extra = [];
        $key = @openssl_pkey_new($options);

        if ($key === false) { // Windows PHP builds often need an explicit openssl.cnf
            foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY).'/openssl.cnf', 'C:/Program Files/Common Files/SSL/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf', getenv('OPENSSL_CONF') ?: ''] as $cnf) {
                if ($cnf !== '' && is_file($cnf) && ($key = @openssl_pkey_new($options + ['config' => $cnf])) !== false) {
                    $extra = ['config' => $cnf];
                    break;
                }
            }
        }

        if ($key === false) {
            test()->markTestSkipped('OpenSSL cannot create an RSA key on this machine.');
        }

        openssl_pkey_export($key, $privatePem, null, $extra);
        $publicPem = openssl_pkey_get_details($key)['key'];

        $path = tempnam(sys_get_temp_dir(), 'vobpub');
        file_put_contents($path, $publicPem);
        register_shutdown_function(fn () => @unlink($path));

        return ['publicPath' => $path, 'privatePem' => $privatePem];
    }
}
