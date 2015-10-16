<?php

/**
 *  Фрагмент php-кода.
 *
 *  Часть сервиса, работающего с RSS подписками, 
 *  использующего API Google для поиска картинок,
 *  отправляющего файлы на radikal.ru, video.yandex.ru,
 *  на файловые хостинги.
 *  Формирует новость и постит на dle-сайты.
 *  31.07.11
 */

class Upload
{
   var $bDebug;
   var $aFileHostingNames;

   var $sCurCat = '';

   function Upload()
   {
      global $ee;

      $this->bDebug = false;
      $this->aFileHostingNames = array(
        'rapidshare'   => 'rapidshare.com',
        'depositfiles' => 'depositfiles.com',
        'hotfile'      => 'hotfile.com',
        'letitbit'     => 'letitbit.net',
        'fileshare'    => 'fileshare.in.ua',
        'turbobit'     => 'turbobit.net'
      );

      if ( !isset( $ee->aStopPatterns ) )
      {
         @include "stop-list.php";

         $ee->aStopPatterns      = (array) @$aStopPatterns;
         $ee->aStopWords         = (array) @$aStopWords;
         $ee->aTitleStopPat      = (array) @$aTitleStopPatterns;
         $ee->aVideoStopPatterns = (array) @$aVideoStopPatterns;
      }
   }

   function Log( $sMsg )
   {
      $sFile = 'uploader-log.txt';

      if ( $this->bDebug )
      {
         if ( filesize( $sFile ) > 500000 ) @unlink( $sFile );

         if ( $hFile = @fopen( $sFile, 'a' ) )
         {          
            fputs( $hFile, date( 'm-d-Y h:i:s' ) .' - '. $sMsg . "\n" );
            fclose( $hFile );
         }
      }
   }

   function CheckDirSizeDel( $aRss )
   {
      global $ee;

      $bDeleted = false;

      $nMaxSize = DIR_MAX_SIZE; // GB
      $nMinSize = DIR_MIN_SIZE; // B

      $aInf  = fs_GetDirSize( $aRss['rs_dest_dir'] );
      $aSize = fs_FormatSize( $aInf['size'] );

$this->Log( "CheckDirSizeDel: size = {$aSize['size']} {$aSize['units']}" );

      if ( ( $aSize['units'] == 'GB' && $aSize['size'] > $nMaxSize ) ||
           ( $aSize['units'] == 'B'  && $aSize['size'] <= $nMinSize )
      )
      {
         fs_DeleteDir( $sDir );

         $ee->oDb->Query(
           "DELETE FROM {$ee->aCfg['db_prefix']}rss
            WHERE rs_id = " . intval( $aRss['rs_id'] )
         );

         $bDeleted = true;

$this->Log( "CheckDirSizeDel: Dir deleted" );

      }

      return $bDeleted;
   }

   function UpdateHistory( $aHistory )
   {
      global $ee;

      foreach( $aHistory as $k=>$aH )
      {
         $ee->oDb->Query(
           "UPDATE {$ee->aCfg['db_prefix']}rss SET 
              rs_downloaded = 1,
              rs_dest_dir   = '" . SafeSql( $aH['DestDir'] ) . "' 

            WHERE 
              rs_title = '". SafeSql( $aH['NZBNicename'] ) ."' AND
              rs_downloaded = 0" 
         );

         // Make sure
         $sNzbPath = _DirToPath( $ee->aCfg['inner_nzb_dir'] ) . 
                     $aH['NZBNicename'] . '.nzb';

         @unlink( $sNzbPath );
      }
   }

   function PickDownRss()
   {
      global $ee;

      $aResult = array();

      $sTime = date( "Y-m-d H:i:s" );

      $hQRss = $ee->oDb->Query( 
        "SELECT * 
         FROM 
              {$ee->aCfg['db_prefix']}rss rs 
              LEFT JOIN {$ee->aCfg['db_prefix']}categories c 
                 ON rs.rs_cat_id = c.cat_id

         WHERE rs_downloaded = 1 AND rs_locked = 0
         ORDER BY RAND()
         LIMIT 5" 
      );

      while ( $aRss = $ee->oDb->Fetch( $hQRss ) )
      {
         // FIX
         // Убедимся, что такого поста еще нет.
         // Рассматриваются 5 дней
         $hQPost = $ee->oDb->Query( 
           "SELECT COUNT(*) AS cnt
            FROM {$ee->aCfg['db_prefix']}posts 
            WHERE po_name = '". SafeSql( $aRss['rs_title'] ) ."' AND
                  po_time >= DATE_SUB( '{$sTime}', INTERVAL 5 DAY )"
         );

         if ( $aPost = $ee->oDb->Fetch( $hQPost ) )
         {
            if ( $aPost['cnt'] < 1 )
            {
               $aRss['cat_name'] = strtolower( @$aRss['cat_name'] );
               $aResult[] = $aRss;

               $this->Log( "PickDownRss: Name = {$aRss['rs_title']}" );

               break;
            }
         }

      }

      if ( count( $aResult ) < 1 )
         $this->Log( "PickDownRss: No items found" );

      return $aResult;
   }

   function FeedDaemon( $aInfo )
   {
      global $ee;

      $sFromDir = _DirToPath( $ee->aCfg['inner_nzb_dir'] );

      $aF = fs_FindFiles( $sFromDir, '#\.nzb$#', false );

      $sToPath = _DirToPath( $aInfo['nzbdir'] );

      $nIters = min( count($aF), 20 );

      foreach ( $aF as $kPath => $sFile )
      {
         if ( $nIters-- == 0 ) break;

         @rename( $kPath, $sToPath . $sFile );

         $this->Log( "FeedDaemon: rename '{$kPath}' to '{$sToPath}{$sFile}'" );
      }
   }

   function PrepareFileForYandex( $sPath )
   {
      global $ee;

      $aInfo     = pathinfo( $sPath );
      $sFileName = str_replace( '_', ' ', $aInfo['filename'] );

      foreach( $ee->aVideoStopPatterns as $k=>$sPat )
      {
         $sFileName = preg_replace( $sPat, '', $sFileName );
      }

      $sFileName = str_replace( '-', ' - ', $sFileName );
      $aFileName = explode( ' ', $sFileName );
      for( $i=0; $i < count( $aFileName ); $i++ )
      {
         $aFileName[ $i ] = ucfirst( $aFileName[ $i ] );
      }

      $sFileName = implode( ' ', $aFileName );

      $sFileName .= ( $aInfo['extension'] != '' ) ?
                     '.'. $aInfo['extension']: '';

      $sPath = $aInfo['dirname'] . DIRECTORY_SEPARATOR . $sFileName;

      return array( $sPath, $sFileName );
   }

   function PreparePostTitle( $sTitle, $bAsVideo = false )
   {
      global $ee;

      $sTitle = str_replace( '_', ' ', $sTitle );
      $sTitle = str_replace( '.', ' ', $sTitle );

      foreach( $ee->aTitleStopPat as $k=>$sPat )
      {
         $sTitle = preg_replace( $sPat, '', $sTitle );
      }

      if ( $bAsVideo )
      {
         if ( strpos( ' ', $sTitle ) === false ) 
            $sTitle = str_replace( '.', ' ', $sTitle );

         $aTitle = explode( '-', $sTitle, 2 );
         $sTitle = implode( ' - ', $aTitle );
         $aTitle = explode( ' ', $sTitle );

         for( $i=0; $i < count( $aTitle ); $i++ )
         {
            $aTitle[ $i ] = ucfirst( $aTitle[ $i ] );
         }

         $sTitle = implode( ' ', $aTitle );
      }

      return $sTitle;
   }


   function TryUploadToYandex( $aRss )
   {
      global $ee;

      $nYvId = 0;

      $this->sCurCat = '';

      $sVideoPat = '#\.(avi|mpg|vob|mp4|m2ts|mov|3gp|mkv|flv)$#i'; 
      $nMinSize  = VIDEO_MIN_SIZE; // Bytes
      $nMaxSize  = $ee->aCfg['yandex_video_quota'] * 1073741824; // Bytes
      $nQuota    = $ee->aCfg['yandex_max_file']    * 1048576;    // Bytes

      $sDestDir = _PathToDir( $aRss['rs_dest_dir'] );

      if ( is_dir( $sDestDir ) )
      {
         $aF = fs_FindFiles( $sDestDir, $sVideoPat, true );

         foreach( $aF as $sPath => $sFile )
         {
            $this->sCurCat = VIDEO_CATEGORY_STR;

            $nSize = filesize( $sPath );
            if ( $nSize < $nMinSize || $nSize > $nMaxSize ) continue;

$this->Log( "TryUploadToYandex: File found = {$sPath}" );
$this->Log( "TryUploadToYandex: File size  = {$nSize}" );

            $sOldPath = $sPath;
            list( $sPath, $sFile ) = $this->PrepareFileForYandex( $sPath );
            @rename( $sOldPath, $sPath );

$this->Log( "TryUploadToYandex: New file = {$sPath}" );

            $nRest = $nQuota - $nSize;
            $hQYa = $ee->oDb->Query( 
              "SELECT * 
               FROM {$ee->aCfg['db_prefix']}yandex_accounts
               WHERE ya_uploaded <= {$nRest}
               ORDER BY RAND()
               LIMIT 5"
            );

            while ( $aYa = $ee->oDb->Fetch( $hQYa ) )
            {
               if ( $ee->oYaImg->Login( $aYa['ya_login'], $aYa['ya_pass'] ) )
               {
$this->Log( "TryUploadToYandex: Login to yandex acc = {$aYa['ya_login']}" );

                  $ee->oYaImg->UploadFile( $sPath );
                  @rename( $sPath, $sOldPath );

$this->Log( "TryUploadToYandex: Uploaded" );
                  sleep(1);

                  $ee->oDb->Query( 
                    "UPDATE {$ee->aCfg['db_prefix']}yandex_accounts SET
                       ya_uploaded = ya_uploaded + {$nSize}
                     WHERE ya_id = {$aYa['ya_id']}"
                  );

                  $sCode = $ee->oYaImg->GetCode( $sFile );

$this->Log( "TryUploadToYandex: GetCode returned Code" );
                  if ( $sCode == 'ERROR' || $sCode == 'PROCESSING' )
                  {
$this->Log( "TryUploadToYandex: Code is '{$sCode}'" );
                     $sCode = '';
                  }
                  else
                  {
$this->Log( "TryUploadToYandex: Code has been get SUCCESSFULLY" );
                  }

                  $ee->oDb->Query(
                    "INSERT INTO {$ee->aCfg['db_prefix']}yandex_videos (
                       yv_ya_id, yv_ne_id, yv_time, yv_file_name, yv_code 
                     )
                     VALUES (
                        " . intval( $aYa['ya_id'] ) . ",
                       0,
                        " . time()                  . ",

                       '" . SafeSql( $sFile )       . "',
                       '" . SafeSql( $sCode )       . "'
                     )"
                  );

                  $nYvId = $ee->oDb->InsertId();
$this->Log( "TryUploadToYandex: yandex_videos id = {$nYvId}" );

                  break;

               } // if logged in

            } // while ( $aYa ...

            break;

         }
      }

      return $nYvId;
   }

   function SetYandexNewsId( $nYvId, $nNewsId )
   {
      global $ee;

      $ee->oDb->Query(
        "UPDATE {$ee->aCfg['db_prefix']}yandex_videos SET
           yv_ne_id = ". intval( $nNewsId ) . "
         WHERE yv_id = " . intval( $nYvId )
      );
   }

   function CheckYandexVideos()
   {
      global $ee;

      $nVideoTTL  = 5*60*60; // Seconds
      $nLastAccId = 0;

      $hQYa = $ee->oDb->Query( 
        "SELECT * 

         FROM {$ee->aCfg['db_prefix']}yandex_videos v
              INNER JOIN {$ee->aCfg['db_prefix']}yandex_accounts a
              ON v.yv_ya_id = a.ya_id

         WHERE yv_code = ''
         ORDER BY ya_id ASC"
      );

      while ( $aYa = $ee->oDb->Fetch( $hQYa ) )
      {
         if ( $aYa['yv_time'] + $nVideoTTL < time() )
         {
            $ee->oDb->Query( 
              "DELETE FROM {$ee->aCfg['db_prefix']}yandex_videos
               WHERE yv_id = {$aYa['yv_id']} "
            );
         }
         else
         {
            if ( $nLastAccId != $aYa['ya_id'] )
            {
               $bAuth = $ee->oYaImg->Login( $aYa['ya_login'], $aYa['ya_pass'] );
               if ( !$bAuth ) continue;
            }

            $sCode = $ee->oYaImg->GetCode( $sFile );

$this->Log( "CheckYandexVideos: GetCode returned Code" );

            if ( $sCode == 'ERROR' || $sCode == 'PROCESSING' )
            {
$this->Log( "CheckYandexVideos: Code is '{$sCode}'" );
               $sCode = '';
            }
            else
            {
$this->Log( "CheckYandexVideos: Code has been get SUCCESSFULLY" );

               $ee->oDb->Query(
                 "UPDATE {$ee->aCfg['db_prefix']}yandex_videos SET
                    yv_code = '". SafeSql( $sCode ) ."'
                  WHERE yv_id = '{$aYa['yv_id']}'"
               );
            }
         }
      }
   }

   function LockRecord( $nId, $nStatus = 1 )
   {
      global $ee;

      $ee->oDb->Query(
        "UPDATE {$ee->aCfg['db_prefix']}rss SET
           rs_locked = ". intval( $nStatus ) . "
         WHERE rs_id = " . intval( $nId )
      );
   }

   function Unpack( $sPath )
   {
      global $ee;

      $bUnpacked = false;
      $sDir = preg_replace( '#[\\\\/]$#', '', $sPath );

      $aF = fs_FindFiles( $sDir, '#.+#', false );

      $sTempDir = $sDir .'/'. md5( mt_rand() );
      if ( !@is_dir( $sTempDir ) ) @mkdir( $sTempDir );

      // UnZip files
      foreach( $aF as $sFileDir => $sFile )
      {
         if ( preg_match( '#\.zip$#i', $sFile ) )
         {
            $sCom = "unzip -jo '{$sFileDir}' -d '{$sTempDir}'";

            ob_start();
            $sLastLine = system( $sCom, $sRetVal );
            ob_end_clean();

            $bUnpacked |= true;
         }
      }

      // UnRar files
      foreach( $aF as $sFileDir => $sFile )
      {
         if ( preg_match( '#\.rar$#i', $sFile ) )
         {
            $sCom = "unrar e '{$sFileDir}' '{$sTempDir}'";

            ob_start();
            $sLastLine = system( $sCom, $sRetVal );
            ob_end_clean();

            $bUnpacked |= true;
         }
      }

      // delete *.par, *.par2, *.nzb .... files
      foreach( $aF as $sFileDir => $sFile )
      {
         if ( preg_match( '#\.par[2]?$#i', $sFile ) ||
              preg_match( '#\.nzb$#i', $sFile ) ||
              preg_match( '#\.sfv$#i', $sFile ) ||
              preg_match( '#^unpak.resume$#i', $sFile ) ||
              preg_match( '#\.rar$#i', $sFile ) ||
              preg_match( '#\.r[0-9][0-9]$#i', $sFile ) ||
              preg_match( '#\.zip$#i', $sFile ) )
         {
            @unlink( $sFileDir );
         }
      }

      // Move files
      //$sCom = "mv -f '{$sTempDir}/*' '{$sDir}/'";
      fs_MoveDirContents( $sTempDir, $sDir );

      // Delete temp dir
      rmdir( $sTempDir );
   }

   function RecordDone( $nId, $nStatus = 1 )
   {
      global $ee;

      $ee->oDb->Query(
        "UPDATE {$ee->aCfg['db_prefix']}rss SET
           rs_done = ". intval( $nStatus ) . "
         WHERE rs_id = " . intval( $nId )
      );
   }

   function AddToArc( $sFileTo, $sFileFrom )
   {
      global $ee;

      $bResult = false;

      $this->Log( "Call AddToArc: {$sFileTo}, {$sFileFrom}" );

      if ( is_file( $sFileTo ) ) @unlink( $sFileTo );

      // Prepare
      $sWorkDir   = preg_replace( '#([\\\\/][^\\\\/]*)$#', '', $sFileFrom );
      $sTargetDir = preg_replace( '#(.*[\\\\/])#',         '', $sFileFrom );

      $sCwdBack = getcwd();
      chdir( $sWorkDir );

      $sCom = sprintf( "/opt/rar/bin/rar a -y -r %s %s",
         escapeshellarg( $sFileTo ), escapeshellarg( $sTargetDir )
      );

      ob_start(); // Start output buffering
      $sLastLine = system( $sCom, $sRetVal );
      ob_end_clean(); // End output buffering

      chdir( $sCwdBack ); // Restore current dir

      $this->Log( "AddToArc: command={$sCom} " );
      $this->Log( "AddToArc: retval='{$sRetVal}', last_line='{$sLastLine}' " );

      if ( is_file( $sFileTo ) )
      {
         // $sRetVal: 1 -> ERROR, 0 -> OK
         if ( $sRetVal == 1 ) 
         {
            @unlink( $sFileTo );
         }
         elseif( $sRetVal == 0 )
         {
            $bResult = true;
         }
      }

      if ( !$bResult )
         $this->Log( "AddToArc: '{$sFileTo}' FAILED!" );
      else
         $this->Log( "AddToArc: '{$sFileTo}' created" );

      return $bResult;
   }


   function KillOldData( $aInfo )
   {
      global $ee;

      $nDataTTL = DATA_TTL; // Days
      $nExpTime = time() - $nDataTTL * 24*60*60;

      $sNzbPath = _DirToPath( $ee->aCfg['inner_nzb_dir'] );

      $hQ = $ee->oDb->Query( 
        "SELECT * 
         FROM {$ee->aCfg['db_prefix']}rss
         WHERE rs_time < ". intval( $nExpTime ) 
      );

      while ( $aRes = $ee->oDb->Fetch( $hQ ) )
      {
         // nzb-files are moved already, so just try
         if ( is_file( $sNzbPath . $aRes['rs_title'] . '.nzb' ) )
            @unlink( $sNzbPath . $aRes['rs_title'] . '.nzb' );

         $ee->oDb->Query( 
           "DELETE FROM {$ee->aCfg['db_prefix']}rss
            WHERE rs_id = ". intval( $aRes['rs_id'] ) 
         );
      }

      // Deal with downloaded distribs
      $sDstDir = _PathToDir( $aInfo['dstdir'] );

      if ( is_dir( $sDstDir ) )
      {
         //$aD = array();
         if ( $hDir = opendir( $sDstDir ) )
         {
            while ( false !== ( $sFile = readdir( $hDir ) ) )
            { 
               if ( $sFile == '.' || $sFile == '..' ) continue;

               $nFileTime = filemtime( $sDstDir .'/'. $sFile );
               if ( $nFileTime < $nExpTime )
               {
                  //$sDate = date( "Y-m-d H:i:s", $nFileTime );
                  //$aD[ $sDate ] = $sDstDir .'/'. $sFile;

                  fs_DeleteDir( $sDstDir .'/'. $sFile );
               }
            }

            closedir( $hDir );
         }
      }

      // Deal with nzb files
      $sNzbDir = _PathToDir( $aInfo['nzbdir'] );

      if ( is_dir( $sNzbDir ) )
      {
         // nzb files
         $sNzbPat = "#\.nzb(\.error|\.queued)?$#";

         $aF = fs_FindFiles( $sNzbDir, $sNzbPat, true );

         //$aDD = array();

         foreach( $aF as $sPath => $sFile )
         {
            //$nFileTime = filemtime( $sNzbDir .'/'. $sFile );
            $nFileTime = filemtime( $sPath );

            if ( $nFileTime < $nExpTime )
            {
               //$sDate = date( "Y-m-d H:i:s", $nFileTime );
               //$aDD[ $sDate ] = $sPath;

               @unlink( $sPath );
            }
         }
      }
   }

   function InitUploader()
   {
      global $ee;

      $ee->aFh = array();
      $sModulesPath = 'libs/uploader/';

      // FIXME: letitbit.php

      $hQu = $ee->oDb->Query(
        "SELECT * 
         FROM {$ee->aCfg['db_prefix']}file_host_accounts"
      );

      while ( $aRes = $ee->oDb->Fetch( $hQu ) )
      {
         if ( $aRes['fh_active'] == 1 && !empty( $aRes['fh_login'] ) )
         {
            $sClass = ucfirst( $aRes['fh_code'] );
            require_once $sModulesPath . $aRes['fh_code'] . '.php';

            // $ee->aFH[ 'rapidshare' ] = new Rapidshare( 'login', 'password' ); 

            $ee->aFh[ $aRes['fh_code'] ] = 
                new $sClass( $aRes['fh_login'], $aRes['fh_password'] ); 
         }
      }
   }

   function InitSubmitter()
   {
      global $ee;

      require_once 'libs/submitter.class.php';

      $ee->oSubmitter = new Submitter();
   }

   function UploadFile( $sFilePath )
   {
      global $ee;

      $aResult = array();
      
      foreach( $ee->aFh as $k=>$oFh )
      {
         $sUrl = $oFh->uploadFile( $sFilePath );

         if ( $sUrl != '' ) 
         {
            $aResult[ $k ] = $sUrl;
$this->Log( "UploadFile: uploaded to {$k}, URL = {$sUrl}" );
         }
      }

$this->Log( "UploadFile: '{$sFilePath}' uploaded to " . count($aResult) . " hostings" );

      return $aResult;
   }

   function SubmitNews( $nCatId, $sTitle, $sDesc, $aUrls, $aImgs = array() )
   {
      global $ee;

      $bResult = false;

      $this->Log( "SubmitNews: images in count - " . count( $aImgs ) );

      if ( is_array( $aImgs ) && count( $aImgs ) > 0 )
      {
         $aKeys   = array_keys( $aImgs );
         $sImgSrc = @$aImgs[ $aKeys[0] ];

         if ( $sImgSrc != '' )
         {
            $sDesc = "[center]<img width=\"450\" src=\"{$sImgSrc}\" />[/center]\n\n" . $sDesc;
         }
      }

      $sShortDesc = $sDesc;

      $sUrls = '';
      foreach( $aUrls as $k=>$sUrl )
      {
         if ( isset( $this->aFileHostingNames[$k] ) )
            $sFHName = $this->aFileHostingNames[$k];
         else
            $sFHName = $k;

         $sUrls .= "[url={$sUrl}]Скачать с {$sFHName}[/url]\n";
      }

      if ( $sUrls != '' )
      {
         $sDesc .= "\n\n[quote]" . $sUrls . "[/quote]";
      }

/*
      $sTitleCut = $sTitle;
      foreach( $ee->aTitleStopPat as $k=>$sPat )
      {
         $sTitleCut = preg_replace( $sPat, '', $sTitleCut );
      }
*/

      $sTitleCut = $this->PreparePostTitle( 
                                $sTitle, $this->sCurCat == 'video' );

      $hQSites = $ee->oDb->Query(
        "SELECT * 
         FROM {$ee->aCfg['db_prefix']}sites s INNER JOIN 
              {$ee->aCfg['db_prefix']}site_cats_ref ref 
              ON s.site_id = ref.site_id
         WHERE ref.cat_id = {$nCatId}"
      );

      $this->Log( "SubmitNews: SELECT * FROM {$ee->aCfg['db_prefix']}sites s INNER JOIN {$ee->aCfg['db_prefix']}site_cats_ref ref ON s.site_id = ref.site_id WHERE ref.cat_id = {$nCatId}" );

      while ( $aRes = $ee->oDb->Fetch( $hQSites ) )
      {
         $this->Log( "SubmitNews: site={$aRes['site_url']}; cat_id={$aRes['cat_id']}; cat_name={$aRes['site_cat_name']}" );

         $ee->oSubmitter->Init( $aRes['site_url'] );

         $bLogin = $ee->oSubmitter->Login( $aRes['site_login'], $aRes['site_pass'] );

         if ( !$bLogin )
         { 
            $this->Log( "SubmitNews: not logged in - {$aRes['site_url']}. Login/Password={$aRes['site_login']}/{$aRes['site_pass']}" );
            continue;
         }

         $this->Log( "SubmitNews: logged in - {$aRes['site_url']}. Login/Password={$aRes['site_login']}/{$aRes['site_pass']}" );

         $aCats  = array( $aRes['site_cat_name'] );

         $aPost = array(
           'title'        => $sTitleCut,
           'short_story'  => $sShortDesc,
           'full_story'   => $sDesc,
           'tags'         => '',
           'allow_comm'   => '1',
           'allow_main'   => '1',
           'approve'      => '1',
           'allow_rating' => '1',
           'do'           => 'addnews',
           'mod'          => 'addnews',
           'add'          => 'отправить'
         );

         $bPost = $ee->oSubmitter->PostNews( $aCats, $aPost );

         if ( $bPost )
         {
            $this->Log( "SubmitNews: news article has been post" );

            // System load delay
            $sPostUrl = '';
            foreach( array( 1, 3, 5 ) as $k=>$nWait )
            {
               sleep( $nWait );
               $sPostUrl = $ee->oSubmitter->FindLastAdded( $aPost['title'] );

               if ( $sPostUrl != '' ) break;
            }

            if ( $sPostUrl != '' )
            {
               $this->Log( "SubmitNews: news link is found - {$sPostUrl}" );

               // Ok. Added. Url of post is found
               $ee->oDb->Query(
                 "INSERT INTO {$ee->aCfg['db_prefix']}posts (
                    po_time, po_site_url, po_site_cats, po_name, po_post_url
                  )
                  VALUES (
                    NOW(), 
                    '" . SafeSql( $aRes['site_url']      ) . "',
                    '" . SafeSql( $aRes['site_cat_name'] ) . "',
                    '" . SafeSql( $sTitle   )              . "',
                    '" . SafeSql( $sPostUrl )              . "'
                  )"
               );

               $bResult |= true;
            }
            else
            {
               $this->Log( "SubmitNews: news link is NOT found" );
            }
         }
      }

      $this->Log( "SubmitNews: news submitted - " . ( $bResult ? 'Yes': 'No' ) );

      return $bResult;
   }

   function SubmitRawNews( $nCatId, $sTitle, $sDesc, $aUrls, $aImgs = array() )
   {
      global $ee;

      $this->Log( "SubmitRawNews: start" );

      $sImgSrc = '';
      if ( is_array( $aImgs ) && count( $aImgs ) > 0 )
      {
         $aKeys   = array_keys( $aImgs );
         $sImgSrc = @$aImgs[ $aKeys[0] ];
      }

      $sExtra = serialize( $aUrls );

/*
      $sTitleCut = $sTitle;
      foreach( $ee->aTitleStopPat as $k=>$sPat )
      {
         $sTitleCut = preg_replace( $sPat, '', $sTitleCut );
      }
*/
      $sTitleCut = $this->PreparePostTitle( 
                                $sTitle, $this->sCurCat == 'video' );

      $nNow = time();

      $ee->oDb->Query(
        "INSERT INTO {$ee->aCfg['db_prefix']}news (
           ne_cat_id, ne_done, ne_approved, ne_time, 
           ne_raw_title, ne_title, ne_raw_image, 
           ne_image, ne_raw_text, ne_text, ne_extra, 
           ne_nfo
         )
         VALUES (
            " . intval( $nCatId )     . ",
            0,
            0,
            " . intval( $nNow )       . ",
           '" . SafeSql( $sTitleCut ) . "',
            '',
           '" . SafeSql( $sImgSrc   ) . "',
            '',
           '" . SafeSql( $sDesc     ) . "',
            '',
           '" . SafeSql( $sExtra    ) . "',
           '" . SafeSql( $this->sNfoTxt ) . "'
         )"
      );

      $nNewsId = $ee->oDb->InsertId();

      if ( $ee->nYvId > 0 )
          $this->SetYandexNewsId( $ee->nYvId, $nNewsId );

      $this->Log( "SubmitRawNews: Query done" );

      return true;
   }

   function SubmitApprovedNews()
   {
      global $ee;

      $bResult = false;

      $this->Log( "SubmitApprovedNews: start" );


      // ADDED: New or Old scheme 
      // ( news need rewrite or not respectively )

      $bNeedRewrite = true;
      $mNeedRewrite = GetSettings( 'news_need_rewrite' );

      if ( $mNeedRewrite !== null )
      {
         $bNeedRewrite = intval( $mNeedRewrite );
      }

      $sWhere = 'TRUE';
      if ( $bNeedRewrite )
      {
         $bNeedApprove = true;
         $mNeedApprove = GetSettings( 'need_approve' );

         if ( $mNeedApprove !== null )
         {
            $bNeedApprove = intval( $mNeedApprove );
         }

         if ( $bNeedApprove )
         {
            $sWhere = 'ne_done > 0 AND ne_approved > 0';
         }
         else
         {
            $sWhere = 'ne_done > 0';
         }
      }
      else
      {
         $sWhere = '( ne_owner_id = 0 OR ne_done > 0 )';
      }

      $hQN = $ee->oDb->Query(
        "SELECT * 

         FROM {$ee->aCfg['db_prefix']}news ne
              LEFT JOIN nz_yandex_videos yv ON ne.ne_id = yv.yv_ne_id

         WHERE {$sWhere} AND ne_submitted < 1
               AND ( yv_ne_id IS NULL OR yv_code <> '' )

         LIMIT 20"
      );
      // WHERE ne_done > 0"

      while ( $aRes0 = $ee->oDb->Fetch( $hQN ) )
      {
         // $this->Log( "SubmitApprovedNews: site={$aRes['site_url']}" );
         $nCatId     = intval( $aRes0['ne_cat_id'] );

         $sTitleCut  = ( $aRes0['ne_title'] != '' ) ? 
                           $aRes0['ne_title']: $aRes0['ne_raw_title'];

         // Modified: 2010-08-30
         $sDesc      = ( $aRes0['ne_text'] != '' ) ? 
                        $aRes0['ne_text']: $aRes0['ne_raw_text'];

         // Image
         $sImgSrc = '';
         if ( $aRes0['ne_image'] != '' )
         {
            if ( preg_match( '#^http(s)?:\/\/.+#i', $aRes0['ne_image'] ) )
            {
               $sImgSrc = $aRes0['ne_image'];
            }
         }
         else
         {
            $sImgSrc = $aRes0['ne_raw_image'];
         }

         if ( empty( $aRes0['yv_code'] ) )
         {
            $sImgSrc = $this->UploadImgFileEx( $sImgSrc, $sTitleCut );

            if ( $sImgSrc != '' )
            {
               $sDesc = "[center]<img width=\"450\" src=\"{$sImgSrc}\" />[/center]\n\n" . $sDesc;
            }
         }
         else
         {
            $sDesc = "[center]{$aRes0['yv_code']}[/center]\n\n" . $sDesc;
         }
   
         $sShortDesc = $sDesc;

         // File hosting Urls
         $sUrls = '';
         $mUrls = unserialize( $aRes0['ne_extra'] );
         if ( is_array( $mUrls ) )
         {
            foreach( $mUrls as $k=>$sUrl )
            {
               if ( isset( $this->aFileHostingNames[$k] ) )
                  $sFHName = $this->aFileHostingNames[$k];
               else
                  $sFHName = $k;

               $sUrls .= "[url={$sUrl}]Скачать с {$sFHName}[/url]\n";
            }
         }

         if ( $sUrls != '' )
         {
            $sDesc .= "\n\n[quote]" . $sUrls . "[/quote]";
         }

         $hQSites = $ee->oDb->Query(
           "SELECT * 
            FROM {$ee->aCfg['db_prefix']}sites s INNER JOIN 
                 {$ee->aCfg['db_prefix']}site_cats_ref ref 
                 ON s.site_id = ref.site_id
            WHERE ref.cat_id = {$nCatId}"
         );

         $this->Log( "SubmitApprovedNews: SELECT * FROM {$ee->aCfg['db_prefix']}sites s INNER JOIN {$ee->aCfg['db_prefix']}site_cats_ref ref ON s.site_id = ref.site_id WHERE ref.cat_id = {$nCatId}" );

         while ( $aRes = $ee->oDb->Fetch( $hQSites ) )
         {
            $this->Log( "SubmitApprovedNews: site={$aRes['site_url']}; cat_id={$aRes['cat_id']}; cat_name={$aRes['site_cat_name']}" );

            $ee->oSubmitter->Init( $aRes['site_url'] );

            $bLogin = $ee->oSubmitter->Login( $aRes['site_login'], $aRes['site_pass'] );

            if ( !$bLogin )
            { 
               $this->Log( "SubmitApprovedNews: not logged in - {$aRes['site_url']}. Login/Password={$aRes['site_login']}/{$aRes['site_pass']}" );
               continue;
            }

            $this->Log( "SubmitApprovedNews: logged in - {$aRes['site_url']}. Login/Password={$aRes['site_login']}/{$aRes['site_pass']}" );

            $aCats  = array( $aRes['site_cat_name'] );

            $aPost = array(
              'title'        => $sTitleCut,
              'short_story'  => $sShortDesc,
              'full_story'   => $sDesc,
              'tags'         => '',
              'allow_comm'   => '1',
              'allow_main'   => '1',
              'approve'      => '1',
              'allow_rating' => '1',
              'do'           => 'addnews',
              'mod'          => 'addnews',
              'add'          => 'отправить'
            );

            $bPost = $ee->oSubmitter->PostNews( $aCats, $aPost );

            if ( $bPost )
            {
               $this->Log( "SubmitApprovedNews: news article has been post" );

               // System load delay
               $sPostUrl = '';
               foreach( array( 1, 3, 5 ) as $k=>$nWait )
               {
                  sleep( $nWait );
                  $sPostUrl = $ee->oSubmitter->FindLastAdded( $aPost['title'] );

                  if ( $sPostUrl != '' ) break;
               }

               $this->Log( "SubmitApprovedNews: find post by title - '{$aPost['title']}'" );

               if ( $sPostUrl != '' )
               {
                  $this->Log( "SubmitApprovedNews: news link is found - {$sPostUrl}" );

                  // Ok. Added. Url of post is found
                  $ee->oDb->Query(
                    "INSERT INTO {$ee->aCfg['db_prefix']}posts (
                       po_time, po_site_url, po_site_cats, po_name, po_post_url
                     )
                     VALUES (
                       NOW(), 
                       '" . SafeSql( $aRes['site_url']      ) . "',
                       '" . SafeSql( $aRes['site_cat_name'] ) . "',
                       '" . SafeSql( $sTitleCut   )           . "',
                       '" . SafeSql( $sPostUrl )              . "'
                     )"
                  );

                  $bResult |= true;
               }
               else
               {
                  $this->Log( "SubmitApprovedNews: news link is NOT found" );
               }
            }
         }

         $sImgSrc = ( $sImgSrc != '' ) ? $sImgSrc: '-';

         $ee->oDb->Query(
           "UPDATE {$ee->aCfg['db_prefix']}news SET
              ne_submitted = 1,
              ne_image     = '". SafeSql( $sImgSrc ) ."'
            WHERE ne_id = {$aRes0['ne_id']}"
         );
      }

      $this->Log( "SubmitApprovedNews: news submitted - " . ( $bResult ? 'Yes': 'No' ) );

      return $bResult;
   }


   function PrepareImagesQuery( $sQuery )
   {
      global $ee;

      $sResult = $this->PreparePostTitle( $sQuery );

      foreach( $ee->aStopWords as $k=>$sWord )
      {
         $mPos = strpos( $sResult, $sWord );
         if ( $mPos !== false )
         {
            $sResult = substr( $sResult, 0, $mPos );
         }
      }

      return $sResult;
   }

   function TryGetNfoImage( $sDir )
   {
      global $ee;

      $sResult = '';
      $this->sNfoTxt = ''; // Global

      $aNfos = fs_FindFiles( $sDir, '#\.nfo$#i', true );

      $this->Log( "TryGetNfoImage: *.nfo files found - " . count( $aNfos ) );

      if ( count( $aNfos ) > 0 )
      {
         $aPaths = array_keys( $aNfos );

         $sName = preg_replace( '#(.*[\\\\/])#', '', $sDir );
         $sName = preg_replace( '#\W+#', '_', $sName );
         $sTmpImg = 'tmp/nfo_'. $sName .'.png';

         $this->sNfoTxt = file_get_contents( $aPaths[0] );

         $bImgDone = $ee->oNfoGen->GenerateImg( $aPaths[0], $sTmpImg );

         if ( $bImgDone )
         {
            $sTmpImg = realpath( $sTmpImg );
            $sResult = $ee->oImgUp->UploadFile( $sTmpImg, false );
         }
         if ( is_file( $sTmpImg ) ) @unlink( $sTmpImg );

         if ( $sResult != '' )
         {
            $this->Log( "TryGetNfoImage: nfo image link: " . $sResult );
         }
         else
         {
            $this->Log( "TryGetNfoImage: nfo image is NOT CREATED!" );
         }
      }

      return $sResult;
   }

   function TryUploadMp3Covers( $sDir )
   {
      global $ee;

      $aResult = array();

      $sDir = _PathToDir( $sDir );

      if ( is_dir( $sDir ) )
      {
         $aF = fs_FindFiles( $sDir, '#\.jpg$#', true );

         if ( count( $aF ) > 0 )
         {
            $aPaths = array_keys( $aF );
            $sLink = $ee->oImgUp->UploadFile( $aPaths[0] );

            if ( $sLink != '' ) $aResult[] = $sLink;
         }
         $this->Log( "TryUploadMp3Covers: images found - " . count( $aF ) );
         $this->Log( "TryUploadMp3Covers: uploaded covers - " . count($aResult) );
      }

      return $aResult;
   }

   function TryUploadPreparedShots( $sDir )
   {
      global $ee;

      $aResult = array();

      $sShotDir = _DirToPath( $sDir ) . 'screens';

      if ( is_dir( $sShotDir ) )
      {
         $this->Log( "TryUploadPreparedShots: screenshots dir is FOUND" );
         $aF = fs_FindFiles( $sShotDir, '#\.jpg$#', false );

         if ( count( $aF ) > 0 )
         {
            $aPaths = array_keys( $aF );
            $sLink = $ee->oImgUp->UploadFile( $aPaths[0] );

            if ( $sLink != '' ) $aResult[] = $sLink;
         }

         $this->Log( "TryUploadPreparedShots: uploaded screenshots - " . count($aResult) );
      }
      else
      {
         $this->Log( "TryUploadPreparedShots: screenshots dir is NOT FOUND" );
      }

      return $aResult;
   }

   function UploadImgFile( $sUrl )
   {
      global $ee;

      $sResult = $sUrl;

$this->Log( "UploadImgFile: 0. Download image '{$sUrl}'" );

      if ( $sUrl != '' )
      {

         $mPos = strrpos( $sUrl, '/' );
         if ( $mPos !== false )
         {
            $sToFile = substr( $sUrl, $mPos+1 );
         }

         $sToFile = tempnam( 'tmp', 'img' );
$this->Log( "UploadImgFile: 1. Download image '{$sUrl}' to '{$sToFile}'" );

         fs_DownloadFile( $sUrl, $sToFile );

         if ( is_file( $sToFile ) )
         {
$this->Log( "UploadImgFile: 2. File '{$sToFile}' downloaded" );
            $sTmpImg = realpath( $sToFile );
            $sImgUrl = $ee->oImgUp->UploadFile( $sTmpImg );
$this->Log( "UploadImgFile: 3. File uploaded. URL - '{$sImgUrl}'" );

            if ( !empty( $sImgUrl ) ) $sResult = $sImgUrl;

            @unlink( $sToFile );
         }
      }

$this->Log( "UploadImgFile: 4. Result = '{$sResult}'" );

      return $sResult;
   }

   function UploadImgFileEx( $sImgSrc, $sTitleCut )
   {
      $sResult = '';

      $this->Log( "UploadImgFileEx: Start"  );
      $this->Log( "UploadImgFileEx: images to search - '{$sTitleCut}', old = '{$sImgSrc}'"  );

      if ( $sImgSrc != '' )
      {
         if ( strpos( $sImgSrc, 'radikal.ru/' ) === false )
         {
            $sImgSrcOld = $sImgSrc;

            // Upload to radikal.ru
            $sImgSrc = $this->UploadImgFile( $sImgSrc );

            // Make sure image is uploaded
            // Try again
            if ( $sImgSrcOld == $sImgSrc )
            {
               $sImgQuery = $this->PrepareImagesQuery( $sTitleCut );
               $aImgs     = $this->GetImages( $sImgQuery );

               foreach( $aImgs as $k=>$sImgSrc )
               {
                  $sImgSrc = $this->UploadImgFile( $sImgSrc );
                  $this->Log( "UploadImgFileEx: \$this->UploadImgFile( '{$aImgs[$k]}' ) returned '{$sImgSrc}'" );

                  if ( $sImgSrc != $aImgs[$k] ) break;
               }
            }
         }

         $sResult = $sImgSrc;
      }

      $this->Log( "UploadImgFileEx: End"  );

      return $sResult;
   }

/*
   function GetImages( $sQuery )
   {
      global $ee;

      // Include the Bing API PHP Library
      require_once( 'libs/BingAPI.php' );

      // Simply start the class with your AppID argumented

      // AppName : nzbget - 
      // AppId   : D43BA0C.....................26F6562AE

      $oSearch = new BingAPI( $ee->aCfg['bing_app_id'] );

      $sQuery = urlencode( $sQuery );

      $oSearch->query( $sQuery );
      $oSearch->setSources('Image');

      // To use multiple resources simply do ->setSources('Web+Image') , it must match the source type bling.com provides
      $oSearch->setFormat('xml');
      $oSearch->setOptions( array(
                              'Web.Count'  => '10',
                              'Web.Offset' => '0',
                              'Adult'      => 'Moderate',
                              'Options'    => 'EnableHighlighting'
                            )
      );

      // Contains the search
      $sResults = $oSearch->getResults();

      $aXml = $ee->oXmlArr->parse( $sResults );

      $aImgs = array();

      if ( is_array( @$aXml[0]['children'] ) )
      {
         $aCh = &$aXml[0]['children'];
         foreach( $aCh as $k=>$aTag )
         {
            if ( $aTag['name'] == 'MMS:IMAGE' )
            {
               $aCh1 = &$aTag['children'];
               foreach( $aCh1 as $k1=>$aTag1 )
               {
                  if ( $aTag1['name'] == 'MMS:RESULTS' )
                  {
                     $aCh2 = &$aTag1['children'];
                     foreach( $aCh2 as $k2=>$aTag2 )
                     {
                        if ( $aTag2['name'] == 'MMS:IMAGERESULT' )
                        {
                           $aCh3 = &$aTag2['children'];

                           foreach( $aCh3 as $k3=>$aTag3 )
                           {
                              if ( $aTag3['name'] == 'MMS:MEDIAURL' )
                              {
                                 $aImgs[] = $aTag3['tagData'];
                              }
                           }
                        }
                     }
                  }
               }
            }
         }
      }

      $this->Log( "GetImages: images found on Bing - " . count( $aResult ) );

      return $aImgs;
   }
*/

   function GetImages( $sQuery )
   {
      global $ee;

      // Google API Key = ABQIAAAA33n2w.....sJF4RGI6iCEUHrQ

      $aResult = array();

      $sSearchTerm = urlencode( $sQuery );

      $ch = curl_init();

      curl_setopt( $ch, CURLOPT_URL, "http://ajax.googleapis.com/ajax/services/search/images?v=1.0&q={$sSearchTerm}&start=0" );

      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
      //curl_setopt( $ch, CURLOPT_REFERER, 'http://www.syntax.cwarn23.info/' );
      curl_setopt( $ch, CURLOPT_REFERER, 'http://ajax.googleapis.com/' );

      $sResp = curl_exec( $ch );
      curl_close($ch);

      $oJson = json_decode( $sResp );

      if ( is_object( $oJson ) && is_object( $oJson->responseData ) &&
           is_array( $oJson->responseData->results ) )
      {
         foreach ( $oJson->responseData->results as $k=>$oRes )
         {
            $aResult[] = $oRes->url;
         }
      }

      $this->Log( "GetImages: images found on Google - " . count( $aResult ) );

      return $aResult;
   }


   function DeleteJunk( $sDir )
   {
      $aFiles = fs_FindFiles( $sDir, '#\.par2(_duplicate\d+)?$#i', true );

      foreach( $aFiles as $sK=>$sName )
      {
         if ( is_file( $sK ) ) @unlink( $sK );
      }
   }

   function PrepareNewsText( $sTxt, $sNfoUrl = '' )
   {
      $sTxt = preg_replace( '#(<b>Added:<\/b> \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}<br \/>)#i', '', $sTxt );
      $sTxt = preg_replace( '#(<b>Group:<\/b>[^<]*<br \/>)#i', '', $sTxt );

      // Delete http://nzbsrus.com/ junk
      $sTxt = preg_replace( '#(<b>Files:<\/b>[^<]*<br[^>]*>)#isU', '', $sTxt );
      $sTxt = preg_replace( '#(<b>Par2s:<\/b>[^<]*<br[^>]*>)#isU', '', $sTxt );
      $sTxt = preg_replace( '#(<b>Description:<\/b>.*?<br[^>]*>)#isU', '', $sTxt );
      $sTxt = preg_replace( '#(<a .+?</u></a>)#isU', '', $sTxt );

      $sTxt = preg_replace( '#(Files: \d+)#isU', '', $sTxt );
      $sTxt = preg_replace( '#(Par2s: \d+)#isU', '', $sTxt );

      $sTxt = preg_replace("#(Size\s+\d+\.\d+\s+\w+)\s+\(\s*\d+\s+files\s*\)#isU", '$1', $sTxt );

      // MiB -> MB
      $sTxt = str_replace( ' MiB', ' MB', $sTxt );
      $sTxt = str_replace( ' GiB', ' GB', $sTxt );

      if ( $sNfoUrl == '' )
      {
         // Just remove
         $sTxt = preg_replace( '#(<b>NFO:<\/b> <a [^<]*<\/a>[^<]*<br \/>)#i', '', $sTxt );
      }
      else
      {
         // Replace with nfo image Url
         $sTxt = preg_replace( '#(<b>NFO:<\/b> <a [^<]*<\/a>[^<]*<br( \/)?>)#i', 
                               "<b>NFO:</b> <a href=\"{$sNfoUrl}\">View NFO</a><br />", $sTxt );
      }

      $sTxt = preg_replace( '#(<b>View NZB:<\/b> <a [^<]*<\/a>[^<]*(<br \/>)?)#i', '', $sTxt );

      return $sTxt;
   }
}

