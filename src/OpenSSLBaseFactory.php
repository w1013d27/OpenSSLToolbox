<?php
/**
 * OpenSSLToolbox   the PHP OpenSSL Toolbox
 *
 * This file is a part of OpenSSLToolbox.
 *
 * Copyright 2020 Kjell-Inge Gustafsson, kigkonsult, All rights reserved
 * author    Kjell-Inge Gustafsson, kigkonsult
 * Link      https://kigkonsult.se
 * Version   0.971
 * License   GNU Lesser General Public License version 3
 *
 *   Subject matter of licence is the software OpenSSLToolbox. The above
 *   copyright, link, package and version notices, this licence notice shall be
 *   included in all copies or substantial portions of the OpenSSLToolbox.
 *
 *   OpenSSLToolbox is free software: you can redistribute it and/or modify it
 *   under the terms of the GNU Lesser General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or (at your
 *   option) any later version.
 *
 *   OpenSSLToolbox is distributed in the hope that it will be useful, but
 *   WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 *   or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
 *   License for more details.
 *
 *   You should have received a copy of the GNU Lesser General Public License
 *   along with OpenSSLToolbox. If not, see <https://www.gnu.org/licenses/>.
 *
 * Disclaimer of rights
 *
 *   Herein may exist software logic (hereafter solution(s)) found on internet
 *   (hereafter originator(s)). The rights of each solution belongs to
 *   respective originator;
 *
 *   Credits and acknowledgements to originators!
 *   Links to originators are found wherever appropriate.
 *
 *   Only OpenSSLToolbox copyright holder works, OpenSSLToolbox author(s) works
 *   and solutions derived works and OpenSSLToolbox collection of solutions are
 *   covered by GNU Lesser General Public License, above.
 */
namespace Kigkonsult\OpenSSLToolbox;

use Exception;
use InvalidArgumentException;
use Kigkonsult\LoggerDepot\LoggerDepot;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

use function chunk_split;
use function count;
use function dechex;
use function get_called_class;
use function implode;
use function in_array;
use function is_null;
use function is_resource;
use function is_string;
use function openssl_error_string;
use function openssl_get_cipher_methods;
use function openssl_get_md_methods;
use function preg_match;
use function str_replace;
use function str_pad;
use function strlen;
use function trim;

/**
 * Class OpenSSLBaseFactory - OpenSSL* shared methods: Pem/Der, asserts etc
 *
 * @see https://www.sslsupportdesk.com/openssl-commands/
 *      formats: PEM, DER, PKCS#7/P7B, PKCS#12/PFX
 * @see https://www.sslshopper.com/ssl-converter.html
 *      file suffix:
 * @see https://serverfault.com/questions/9708/what-is-a-pem-file-and-how-does-it-differ-from-other-openssl-generated-key-file#9717
 *      OpenSSL Essentials: Working with SSL Certificates, Private Keys and CSRs
 * @see https://www.digitalocean.com/community/tutorials/openssl-essentials-working-with-ssl-certificates-private-keys-and-csrs
 */
abstract class OpenSSLBaseFactory extends BaseFactory implements OpenSSLInterface
{

    /**
     * @var string
     * @access protected
     */
    protected static $DASHBEGIN         = '-----BEGIN ';
    protected static $FMTERR1           = 'OpenSLL %s %s (#%d), %s';
    protected static $FMTERR2           = 'Resource is NOT set!!';
    protected static $FMTERR4           = '%s is required';
    protected static $INIT              = 'Init ';
    protected static $PASSED            = 'Passed ';
    protected static $RESOURCE          = 'resource';

    /**
     * @var array  (values as keys)
     * @static
     */
    public static $PEMTYPES = [
        self::PEM_X509_OLD     => 'X509 CERTIFICATE',
        self::PEM_X509         => 'CERTIFICATE',
        self::PEM_X509_TRUSTED => 'TRUSTED CERTIFICATE',
        self::PEM_X509_REQ_OLD => 'NEW CERTIFICATE REQUEST',
        self::PEM_X509_REQ     => 'CERTIFICATE REQUEST',
        self::PEM_X509_CRL     => 'X509 CRL',
        self::PEM_EVP_PKEY     => 'ANY PRIVATE KEY',
        self::PEM_PUBLIC       => 'PUBLIC KEY',
        self::PEM_RSA          => 'RSA PRIVATE KEY',
        self::PEM_RSA_PUBLIC   => 'RSA PUBLIC KEY',
        self::PEM_DSA          => 'DSA PRIVATE KEY',
        self::PEM_DSA_PUBLIC   => 'DSA PUBLIC KEY',
        self::PEM_PKCS7        => 'PKCS7',
        self::PEM_PKCS7_SIGNED => 'PKCS #7 SIGNED DATA',
        self::PEM_PKCS8        => 'ENCRYPTED PRIVATE KEY',
        self::PEM_PKCS8INF     => 'PRIVATE KEY',
        self::PEM_DHPARAMS     => 'DH PARAMETERS',
        self::PEM_DHXPARAMS    => 'X9.42 DH PARAMETERS',
        self::PEM_SSL_SESSION  => 'SSL SESSION PARAMETERS',
        self::PEM_DSAPARAMS    => 'DSA PARAMETERS',
        self::PEM_ECDSA_PUBLIC => 'ECDSA PUBLIC KEY',
        self::PEM_ECPARAMETERS => 'EC PARAMETERS',
        self::PEM_ECPRIVATEKEY => 'EC PRIVATE KEY',
        self::PEM_PARAMETERS   => 'PARAMETERS',
        self::PEM_CMS          => 'CMS',
    ];

    /**
     * @var array  (values as keys)
     * @link https://www.php.net/manual/en/openssl.signature-algos.php
     * @static
     */
    public static $SIGNATUREALGOS = [
         5 => OPENSSL_ALGO_DSS1,
         1 => OPENSSL_ALGO_SHA1,     // Used as default algorithm by
                                     //   OpenSSLFactory::sign()        ( openssl_sign() )
                                     //   OpenSSLFactory::sign()        ( openssl_verify() )
                                     //   OpenSSLSpkiFactory::spkiNew() ( openssl_spki_new() )
         6 => OPENSSL_ALGO_SHA224,
         7 => OPENSSL_ALGO_SHA256,
         8 => OPENSSL_ALGO_SHA384,
         9 => OPENSSL_ALGO_SHA512,
        10 => OPENSSL_ALGO_RMD160,
         2 => OPENSSL_ALGO_MD5,
         3 => OPENSSL_ALGO_MD4,
        // 4? OPENSSL_ALGO_MD2,
    ];

    /**
     * @var array  (values as keys)
     * @link https://www.php.net/manual/en/openssl.ciphers.php
     * @static
     */
    public static $CIPHERS = [
        0 => OPENSSL_CIPHER_RC2_40,  // Used as default cipher algorithm by
                                     //   OpenSSLPkcs7Factory::encrypt() ( openssl_pkcs7_encrypt() )
        1 => OPENSSL_CIPHER_RC2_128,
        2 => OPENSSL_CIPHER_RC2_64,
        3 => OPENSSL_CIPHER_DES,
        4 => OPENSSL_CIPHER_3DES,
        5 => OPENSSL_CIPHER_AES_128_CBC,
        6 => OPENSSL_CIPHER_AES_192_CBC,
        7 => OPENSSL_CIPHER_AES_256_CBC
    ];

    /**
     * Assert PEM string
     *
     * @param string $pem
     * @param int|string $argIx
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertPemString( $pem, $argIx = null ) {
        static $FMTERR  = 'Invalid PEM format found';
        if( ! self::isPemString( $pem )) {
            throw new InvalidArgumentException( $FMTERR . self::getErrArgNoText( $argIx ));
        }
    }

    /**
     * Assert file has PEM string content
     *
     * NO file read tests
     * @param string $file
     * @param int|string $argIx
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertPemFile( $file, $argIx = null ) {
        self::assertPemString( Workshop::getFileContent( $file ), $argIx );
    }

    /**
     * Return bool true if type is a PEM type
     *
     * @param $type
     * @return bool   true on success
     * @static
     */
    public static function isPemType( $type ) {
        return in_array( $type, self::$PEMTYPES );
    }

    /**
     * Assert PEM type
     *
     * @param string $type
     * @param int|string $argIx
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertPemType( $type, $argIx = null ) {
        static $FMTERRPEM  = 'PEM type %s is invalid';
        if( ! self::isPemType( $type )) {
            throw new InvalidArgumentException( sprintf( $FMTERRPEM, $type ) . self::getErrArgNoText( $argIx ));
        }
    }

    /**
     * @var string
     * @static
     */
    protected static $PEMPATTERN = '~^-----BEGIN ([A-Z ]+)-----\s*?([A-Za-z0-9+=/\r\n]+)\s*?-----END \1-----\s*$~D';
    public    static $PEMEOL = "\r\n";

    /**
     * Return string PEM type
     *
     * @param string $pem
     * @return string
     * @throws InvalidArgumentException
     * @static
     */
    public static function getStringPemType( $pem ) {
        self::assertPemString( $pem );
        @preg_match( self::$PEMPATTERN, $pem, $matches );
        return $matches[1];
    }

    /**
     * Return string PEM type
     *
     * @param string $file
     * @return string
     * @throws InvalidArgumentException
     * @static
     */
    public static function getFilePemType( $file ) {
        self::assertPemFile( $file );
        @preg_match( self::$PEMPATTERN, Workshop::getFileContent( $file ), $matches );
        return $matches[1];
    }

    /**
     * Return bool true if pem is a (single) PEM string
     *
     * @link https://www.php.net/manual/en/function.openssl-pkey-export.php#95847
     * A standard PEM has a begin line, an end line
     * and inbetween is a base64 encoding of the DER representation of the certificate.
     * PEM requires that linefeeds ("\r\n") be present every 64 characters.
     * @param string $pem
     * @param string $type  contains PEM type (see OpenSSLInterface) on success
     * @return bool
     * @static
     */
    public static function isPemString( $pem, & $type = null ) {
        if( ! is_string( $pem ) ||
            ( 1 != @preg_match( self::$PEMPATTERN, $pem, $matches )) ||
            ( 3 != count( $matches )) ||
            ( $pem !== $matches[0] ) ||
            ! self::isPemType( $matches[1] )) {
            return false;
        }
        $type = $matches[1];
        return true;
    }

    /**
     * Return bool true if file content is a (single) PEM string
     *
     * NO read file tests
     * A standard PEM has a begin line, an end line
     * and inbetween is a base64 encoding of the DER representation of the certificate.
     * PEM requires that linefeeds ("\r\n") be present every 64 characters.
     * @param string $file
     * @param string $type  contains PEM type (see OpenSSLInterface) on success
     * @return bool
     * @static
     */
    public static function isPemFile( $file, & $type = null ) {
        return self::isPemString( Workshop::getFileContent( $file, $type ));
    }

    /**
     * Return string (single) PEM converted to DER format
     *
     * @link https://www.php.net/manual/en/function.openssl-pkey-export.php#95847
     * @param string $pem
     * @param string $type  contains PEM type (see OpenSSLInterface) on success
     * @return string
     * @throws InvalidArgumentException
     */
    public static function pem2Der( $pem, & $type = null ) {
        $EOLCHARS = [ "\r", "\n" ];
        self::assertPemString( $pem );
        @preg_match( self::$PEMPATTERN, $pem, $matches );
        $type    = $matches[1];
        $pemData = str_replace( $EOLCHARS, null, $matches[2] );
        return Convert::base64Decode( $pemData );
    }

    /**
     * Convert (single) PEM filo into DER format file
     *
     * @link https://www.php.net/manual/en/function.openssl-pkey-export.php#95847
     * @param string $inputPem
     * @param string $outputDer
     * @param string $type  contains PEM type (see OpenSSLInterface) on success
     * @throws InvalidArgumentException
     */
    public static function pemFile2DerFile( $inputPem, $outputDer, & $type = null ) {
        $EOLCHARS = [ "\r", "\n" ];
        Assert::fileNameRead( $inputPem );
        self::isPemFile( $inputPem );
        Assert::fileNameWrite( $outputDer );
        $pemData  = Workshop::getFileContent( $inputPem );
        @preg_match( self::$PEMPATTERN, $pemData, $matches );
        $type     = $matches[1];
        $pemData  = str_replace( $EOLCHARS, null, $matches[2] );
        $der      = Convert::base64Decode( $pemData );
        Workshop::saveDataToFile( $outputDer, $der );
    }

    /**
     * Return string (single) PEM converted to DER format with some extra ASN.1 wrapping
     *
     * @link https://www.php.net/manual/en/function.openssl-pkey-export.php#95847
     * @param string $pem
     * @param string $type  contains PEM type (see OpenSSLInterface) on success
     * @return string
     * @throws InvalidArgumentException
     */
    public static function pem2DerASN1( $pem, & $type = null ) {
        static $EOLCHARS = [ "\r", "\n" ];
        static $ARG1     = '020100300d06092a864886f70d010101050004';
        static $ARG2     = '30';
        self::assertPemString( $pem );
        @preg_match( self::$PEMPATTERN, $pem, $matches );
        $type    = $matches[1];
        $pemData =  str_replace( $EOLCHARS, null, $matches[2] );
        $derData = Convert::Hpack( $ARG1 . self::derLength( $pemData )) . $pemData;
        $derData = Convert::Hpack( $ARG2 . self::derLength( $derData )) . $derData;
        return Convert::base64Decode( $derData );
    }
    protected static function derLength( $derData ) {
        static $ZERO = '0';
        $length      = strlen( $derData );
        if( $length < 128 ) {
            return str_pad( dechex( $length ), 2, $ZERO, STR_PAD_LEFT );
        }
        $output = dechex( $length );
        if( strlen( $output ) % 2 != 0 ) {
            $output = $ZERO . $output;
        }
        return dechex(128 + strlen( $output ) / 2 ) . $output;
    }

    /**
     * Return PEM certificate/key etc converted from DER (but NO type<->content check)
     *
     * @link https://www.php.net/manual/en/ref.openssl.php#74188
     * @param string $der    (without ASN.1)
     * @param string $type   One if the PEM_* constants
     * @param string $eol    default "\r\n"
     * @return string
     * @throws InvalidArgumentException
     */
    public static function der2Pem( $der, $type, $eol = null ) {
        static $FMT    = '-----BEGIN %1$s-----%2$s%3$s-----END %1$s-----%2$s';
        self::assertPemType( $type, 2 );
        if( empty( $eol )) {
            $eol = self::$PEMEOL;
        }
        return sprintf(
            $FMT, $type, $eol, chunk_split( Convert::base64Encode( $der ), 64, $eol )
        );
    }

    /**
     * Save PEM certificate/key file etc converted from DER file (but NO type<->content check)
     *
     * @link https://www.php.net/manual/en/ref.openssl.php#74188
     * @param string $derFile  input der file (without ASN.1)
     * @param string $pemFile  output pem file
     * @param string $type     One if the PEM_* constants
     * @param string $eol      default "\r\n"
     * @throws InvalidArgumentException
     */
    public static function derFile2PemFile( $derFile, $pemFile, $type, $eol = null ) {
        static $FMT    = '-----BEGIN %1$s-----%2$s%3$s-----END %1$s-----%2$s';
        Assert::fileNameRead( $derFile );
        Assert::fileNameWrite( $pemFile );
        self::assertPemType( $type, 2 );
        if( empty( $eol )) {
            $eol = self::$PEMEOL;
        }
        $der = Workshop::getFileContent( $derFile );
        $pem = sprintf(
            $FMT, $type, $eol, chunk_split( Convert::base64Encode( $der ), 64, $eol )
        );
        Workshop::saveDataToFile( $pemFile, $pem );
    }

    /**
     * Assert $passPhrase, return null or passPhrase
     *
     * @param mixed $passPhrase
     * @param int|string $argIx
     * @return null|string
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertPassPhrase( $passPhrase, $argIx = null ) {
        $SP0 = '';
        if( is_null( $passPhrase )) {
            return null;
        }
        if( $SP0 == ( trim( $passPhrase ))) {
                return null;
        }
        return Assert::string( $passPhrase, $argIx );
    }

    /**
     * Assert (int, constant) cipherId
     *
     * @param int $cipherId
     * @param int|string $argIx
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertCipherId( $cipherId, $argIx = 1 ) {
        static $FMTPOPTSERR = 'Invalid cipher (arg #%d), %s';
        if( ! in_array( $cipherId, OpenSSLFactory::$CIPHERS )) {
            throw new InvalidArgumentException( sprintf( $FMTPOPTSERR, $argIx, var_export( $cipherId, true )));
        }
    }

    /**
     * Assert openssl_get_cipher_methods (string) algorithm - return matched
     *
     * Two-step search : strict + anyCase
     * @param string $algorithm
     * @return string  - found algorithm, uses self::getAvailableCipherMethods()
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertCipherAlgorithm( $algorithm ) {
        return parent::baseAssertAlgorithm( self::getAvailableCipherMethods( true ), $algorithm );
    }

    /**
     * Return array, available cipher methods
     *
     * @param bool $aliases
     * @return array
     * @static
     */
    public static function getAvailableCipherMethods( $aliases = false ) {
        return openssl_get_cipher_methods( $aliases );
    }

    /**
     * Assert openssl_get_md_methods algorithm
     *
     * Two-step search : strict + anyCase
     * @param string $algorithm
     * @return string  - found algorithm, uses self::getAvailableDigestMethods()
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertMdAlgorithm( $algorithm ) {
        return parent::baseAssertAlgorithm( self::getAvailableDigestMethods( true ), $algorithm );
    }

    /**
     * Return array, available digest (md) methods
     *
     * @param bool $aliases
     * @return array
     * @static
     */
    public static function getAvailableDigestMethods( $aliases = false ) {
        return openssl_get_md_methods( $aliases );
    }

    /**
     * Assert $source as resource, file or string
     *
     * @param resource|string|array $source  1. A typed resource
     *                                       2. A string having the format (file://)path/to/file
     *                                          The named file must contain a PEM encoded certificate/private key (it may contain both)
     *                                       3. A string containing the content of a PEM encoded certificate/key
     * @param int|string $argIx
     * @param bool   $fileToString
     * @param string $resourceType
     * @param bool   $keyType                true on key, false on cert
     * @return resource|string
     * @throws InvalidArgumentException
     * @static
     */
    public static function assertResourceFileStringPem(
        $source, $argIx = null, $fileToString = false, $resourceType = null, $keyType = true
    ) {
        static $FMTERRPEM = 'PEM formatted string expected';
        $type = $keyType ? self::KEY : self::$RESOURCE;
        $checkStringPem = true;
        switch( true ) {
            case is_resource( $source ) :
                if( ! self::isValidResource( $source, $resourceType )) {
                    throw new InvalidArgumentException(
                        self::getErrRscMsg( __METHOD__, $resourceType, $source ) .
                        self::getErrArgNoText( $argIx )
                    );
                }
                $checkStringPem = false;
                break;
            case ( is_string( $source ) && Workshop::hasFileProtoPrefix( $source )) :
                Assert::fileNameRead( $source, $argIx );
                if( $fileToString ) {
                    $source = Workshop::getFileContent( $source, $argIx );
                }
                else {
                    $checkStringPem = false;
                }
                break;
            case ( is_string( $source ) && is_file( $source )) :
                clearstatcache( true, $source ); // test ###
                Assert::fileNameRead( $source, $argIx );
                if( $fileToString ) {
                    $source = Workshop::getFileContent( $source, $argIx );
                }
                else {
                    $source         = Workshop::$FILEPROTO . $source;
                    $checkStringPem = false;
                }
                break;
            case ( is_string( $source )) :
                Assert::string( $source, $argIx );
                break;
            default :
                throw new InvalidArgumentException(
                    sprintf( self::$FMTERR4, $type ) . self::getErrArgNoText( $argIx )
                );
                break;
        } // end switch
        if( $checkStringPem && ! self::isPemString( $source )) {
            foreach( explode( self::$DASHBEGIN, $source ) as $x => $part ) {
                if( empty( $part ) ) {
                    continue;
                }
                if( ! OpenSSLBaseFactory::isPemString( self::$DASHBEGIN . $part ) ) {
                    throw new InvalidArgumentException( $FMTERRPEM . self::getErrArgNoText( $argIx ) );
                }
            } // end foreach
        } // end if
        return $source;
    }

    /** ***********************************************************************
     *  OpenSSLError related methods
     */

    /**
     * clear OpenSSL errors
     *
     * @static
     */
    public static function clearOpenSSLErrors() {
        while( false !== openssl_error_string()) {
            continue;
        };
    }

    /**
     * Return (string) OpenSSL errors
     *
     * @return string
     * @static
     */
    public static function getOpenSSLErrors() {
        $errors = [];
        while( $msg = openssl_error_string()) {
            $errors[] = $msg;
        }
        return implode( PHP_EOL, $errors );
    }

    /** ***********************************************************************
     *  log related methods
     */

    /**
     * The logger instance.
     *
     * @var LoggerInterface
     * @access protected
     */
    protected $logger;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     * @access protected
     */
    protected function log( $level, $message, array $context = [] ) {
        if( ! empty( $this->logger )) {
            $this->logger->log( $level, $message, $context );
        }
    }

    /**
     * Evaluate catch, log and opt, throw RuntimeException
     *
     * @param string    $method
     * @param Exception $e
     * @param bool      $warningStatus    true on warning, false on error
     * @param string    $OpenSSLErrors
     * @param string    $msg2
     * @throws RuntimeException
     * @static
     */
    public static function assessCatch( $method, Exception $e, $warningStatus, $OpenSSLErrors, $msg2 = null ) {
        static $mthdName = 'getSeverityText';
        static $FMTERR2  = '(PHP %s) %s';
        $logger   = LoggerDepot::getLogger( get_called_class());
        $logLevel = $warningStatus ? LogLevel::WARNING : LogLevel::ERROR;
        $msg3     = ( $e instanceof PhpErrorException )
            ? sprintf( $FMTERR2, forward_static_call( [ $e, $mthdName ], $e->getSeverity()), $msg2 )
            : $msg2;
        $message  = sprintf( self::$FMTERR1, $method, $logLevel, 1, $msg3 );
        if( ! empty( $OpenSSLErrors )) {
            $message .= PHP_EOL . $OpenSSLErrors;
        }
        $logger->log( $logLevel, $message );
        $logger->log( $logLevel, $e->getMessage());
        if( ! $warningStatus ) {
            throw new RuntimeException( $message, null, $e );
        }
    }

    /**
     * Return array, available digest (md) methods
     *
     * @param string $method
     * @param string $msg2
     * @param string $OpenSSLErrors
     * @throws RuntimeException
     * @static
     */
    public static function logAndThrowRuntimeException( $method, $msg2 = null, $OpenSSLErrors = null ) {
        $logger  = LoggerDepot::getLogger( get_called_class());
        $message = sprintf( self::$FMTERR1, $method, LogLevel::ERROR, 2, $msg2 );
        if( ! empty( $OpenSSLErrors )) {
            $message .= PHP_EOL . $OpenSSLErrors;
        }
        $logger->log( LogLevel::ERROR, $message );
        throw new RuntimeException( $message );
    }

    /** ***********************************************************************
     *  resource related methods
     */

    /**
     * Return bool true if resource is valid
     *
     * @param string|resource $resource
     * @param string          $resourceType
     * @return bool
     * @access protected
     * @static
     */
    protected static function isValidResource( $resource, $resourceType ) {
        return ( is_resource( $resource ) && ( $resourceType == get_resource_type( $resource )));
    }

    /**
     * Return resource error message
     *
     * @param string          $method
     * @param string|resource $resource
     * @param string          $resourceType
     * @return string
     * @access protected
     * @static
     */
    protected static function getErrRscMsg( $method, $resourceType, $resource ) {
        static $FMERRTYPERESOURCE = ', Resource (\'%s\') expected, got \'%s\'';
        return  self::getCm( $method ) .
            sprintf( $FMERRTYPERESOURCE, $resourceType, Workshop::getResourceType( $resource ));
    }

}
