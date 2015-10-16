<?php defined('SYSPATH') or die('No direct access allowed.');

/**
 * Класс, отправляющий push-уведомления на iphone-устройства
 * (Kohana Framework)
 * 06.06.13
 */

class iPhone
{
    private static $sServiceUrl  = 'ssl://gateway.push.apple.com:2195'; // Production environment
    // ssl://gateway.sandbox.push.apple.com:2195 // Sandbox environment

    private static $sCertificate = 'tao_production.pem';
    // appstore_taobao_cert.pem
    // apns_taobao_develop.pem
    // apns_taobao_production.pem

    private static $sPassPhrase  = '1234';

    public static function send_ipush( $nUserId, $sMessage, $mData = FALSE )
    {
        $bResult = FALSE;

        $oUsr = ORM::factory('user', $nUserId);

        if ( $oUsr->loaded() )
        {
            $oUserMob = $oUsr->usermobiles
                           ->where('platform', '=', 'iphone' )
                           ->find();

            if ( $oUserMob->loaded() ) 
            {
                $oUserMob->badge++;
                $oUserMob->save();

                Kohana::$log->add(Log::DEBUG, '[iPhone::send_ipush] Before sending Push');

                // Временный debug
                Kohana::$log->add(Log::DEBUG, '[iPhone::send_ipush] Msg = '.$sMessage);
                Kohana::$log->add(Log::DEBUG, '[iPhone::send_ipush] Data = '.var_export($mData, true));

                // Для локального тестирования
                if ( strlen( $oUserMob->token ) >= 60 ) // 64?
                {
                    $bResult = self::iPush( 
                        $oUserMob->token, $sMessage, $mData, $oUserMob->badge
                    );
                }
            }
            else
            {
                // Не было логинов с устройства, token неизвестен, пропускаем.
            }
        }

        return $bResult;
    }

    public static function iPush( $sDeviceToken, $sMessage, $mData = false, $nBadge = 1 )
    {
        $mResult = 0;
        $aBody   = array();

        $hCtx = stream_context_create();

        $sCertPath = APPPATH .'cert/'. self::$sCertificate;

        stream_context_set_option( $hCtx, 'ssl', 'local_cert', $sCertPath );
        stream_context_set_option( $hCtx, 'ssl', 'passphrase', self::$sPassPhrase  );

        // Open a connection to the APNS server
        $hSock = stream_socket_client(
            self::$sServiceUrl, $err,
        	$errstr, 60, STREAM_CLIENT_CONNECT|STREAM_CLIENT_PERSISTENT, $hCtx
        );

        if ( $hSock )
        {
            Kohana::$log->add(Log::DEBUG, '[iPhone::iPush] Connected to APNS');

            // Create the payload body
            $aBody['aps'] = array(
                'alert' => $sMessage,
                'badge' => $nBadge,
                'sound' => 'default',
            );

            $aBody['d'] = $mData; // json_encoded data before

            // Encode the payload as JSON
            $sPayload = json_encode( $aBody );

            Kohana::$log->add(Log::DEBUG, '[iPhone::iPush] Message length = ' . strlen($sPayload) );

            // Build the binary notification
            $sMsg = chr(0) . pack('n', 32) . pack('H*', $sDeviceToken) . 
                    pack('n', strlen($sPayload)) . $sPayload;

            // Send it to the server
            $mResult = fwrite( $hSock, $sMsg, strlen($sMsg) );

            if ( !$mResult )
            {
                Kohana::$log->add(Log::DEBUG, '[iPhone::iPush] Message not delivered');
            }
            else
            {
                Kohana::$log->add(Log::DEBUG, '[iPhone::iPush] Message successfully delivered');
            }

            // Close the connection to the server
            fclose( $hSock );
        }
        else
        {
            Kohana::$log->add(Log::DEBUG, '[iPhone::iPush] Failed to connect: :err :errstr', array(
               ':err'    => $err,
               ':errstr' => $errstr
            ));
        }

        return (bool)$mResult;
    }

}

