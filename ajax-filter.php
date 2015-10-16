<?php

/**
 *  Фрагмент кода, демонстрирующий работу с SQL.
 *
 *  Подбор книг по критериям.
 *  Это одна из первых версий, поэтому 
 *  все достаточно прямолинейно, без кеширования
 *  (доработка к существующему проекту, с существующей БД).
 *
 *  07.10.10
 */


function GetAllBookSndCount()
{
   $nAllCnt = 0;

   // Здесь лучше использовать кеширование
   // книги и озвучки добавляются нечасто

   $hQAll = mysql_query(
     "SELECT COUNT(*) AS cnt
      FROM 
           knigi k 
           LEFT JOIN seriyaozvuchek so ON 
              ( k.avtor_id = so.avtor_id AND k.ID = so.kniga_id )"
   );

   if( $aAllRes = mysql_fetch_assoc( $hQAll ) )
   {
      $nAllCnt = $aAllRes['cnt'];
   }

   return $nAllCnt;
}


function GetBookGenres( $nAuthorId, $nBookId )
{
   $aGenres = array();

   $hQG = mysql_query(
     "SELECT zhanr_name
      FROM tagi_zhanri tzh
           INNER JOIN zhanri_relationship zhrel
           USING ( metka_id )
      WHERE avtor_id = " . intval( $nAuthorId ) . " AND
            kniga_id = " . intval( $nBookId )
   );

   while( $aRes = mysql_fetch_assoc( $hQG ) )
   {
      $aGenres[] = $aRes['zhanr_name'];
   }

   return $aGenres;
}

   $aResp = array();
   $aResp['content']    = '';
   $aResp['status_msg'] = 'Error';

   $aReq = $_REQUEST;

   $nCurPage = SetDefault( $aReq['page'], 1 );

   $nLimit = intval( $nIpp );

   if ( isset( $aReq['act'] ) && $aReq['act'] == 'do_search' )
   {
      $aHds = array(
         'Книга'                   => 's-booktitle',
         'Автор'                   => 's-auth_name',
         'Дата добавления'         => 's-k_dobavlenie',
         'Читает'                  => 's-user_imya',
         'Средняя оценка<br />озвучек диктора'    => 's-dic_rate',
         'Рейтинг<br />диктора'    => 's-dic_rating',
         'Рейтинг<br />озвучки'    => 's-ozv_rate',
         'Текст<br />скачали'      => 's-cnt_down_txt',
         'Озвучку<br />скачали'    => 's-down_cnt_full',
         'Озвучку<br />прослушали' => 's-listen_cnt_full',
         'Букв'                    => 's-bukv',
         'Жанр'                    => '',
      );

      $aSndDurations = array(
         1 => 'bukv < 20000',
         2 => 'bukv >= 20000 AND bukv < 40000',
         3 => 'bukv >= 40000 AND bukv < 80000',
         4 => 'bukv >= 80000 AND bukv < 200000',
         5 => 'bukv >= 200000',
      );

      $aResp = array();
      $aResp['content']    = '';
      $aResp['status_msg'] = '';

      $aWhere = array( 'TRUE' );

      $aOrder   = array(); //array( 'booktitle ASC' );
      $aColSort = array(); // for columns

      $aWhere[] = 'k.k_avtor_id IS NOT NULL';

      if ( !empty( $aReq['has-text'] ) )
         $aWhere[] = 'bukv > 0';

      if ( !empty( $aReq['has-snd'] ) )
         $aWhere[] = 'snd_book_id IS NOT NULL';

      if ( !empty( $aReq['locker'] ) )
         $aWhere[] = 'locker = 1';

      if ( isset( $aReq['snd-dur'] ) )
      {
         $aDurWhere = array();
         $aDur = explode( ',', $aReq['snd-dur'] );

         foreach( $aDur as $k=>$nDurIdx )
         {
            if ( isset( $aSndDurations[ $nDurIdx ] ) )
            {
               $aDurWhere[] = $aSndDurations[ $nDurIdx ];
            }
         }

         if ( count( $aDurWhere ) > 0 )
            $aWhere[] = ' ('. implode( ' OR ', $aDurWhere ) .') ';
      }

      if ( !empty( $aReq['from'] ) )
      {
         if ( $aReq['from'] == 1 ) $aWhere[] = "otkuda = \"русский\" ";
         else 
            if ( $aReq['from'] == 2 ) $aWhere[] = "otkuda = \"зарубежный\" ";
      }

      if ( !empty( $aReq['epo'] ) )
      {
         if ( $aReq['epo'] == 1 ) $aWhere[] = "epoha = \"классик\" ";
         else 
            if ( $aReq['epo'] == 2 ) $aWhere[] = "epoha = \"современный\" ";
      }

      $sWhere = implode( ' AND ', $aWhere );

      // --- Order ---
      foreach( $aReq as $sKey => $mVal )
      {
         if ( strpos( $sKey, 's-' ) === 0 && is_string( $mVal ) )
         {
            $sFld = substr( $sKey, 2 );
            $sVal = strtoupper( $mVal );
            if ( in_array( $sKey, $aHds ) && 
                 ( $sVal == 'ASC' || $sVal == 'DESC' ) )
            {
               $aOrder[] = $sFld . ' ' . $sVal;
               $aColSort[ $sKey ] = $sVal;
            }
         }
      }

      if ( count( $aOrder ) > 0 )
        $sOrder = 'ORDER BY ' . implode( ', ', $aOrder );
      else
        $sOrder = '';

      $nTotal = 0;

      $sSqlFrom = "
      FROM 
          (
             -- приводим таблицу knigi к удобному виду
             -- k_dobavlenie - форматируем дату для вывода
             -- book_unique - вводим идентификатор книги для связки
             SELECT *, 
                    ID AS k_InnerId,
                    avtor_id AS k_avtor_id,
                    DATE(dobavlenie) AS k_dobavlenie,
                    CONCAT( avtor_id, '-', ID ) AS book_unique
             FROM knigi
          ) AS k 

          -- присоединяем таблицу avtori
          INNER JOIN avtori a ON k.avtor_id = a.ID

          -- присоединяем таблицу seriyaozvuchek
          LEFT JOIN
          (
             SELECT *, kniga_id AS snd_book_id,
                    IF ( drugoydiktor > 0, drugoydiktor, ktodobavil ) AS so_diktor
             FROM seriyaozvuchek
          ) AS so ON ( k.avtor_id = so.avtor_id AND 
                           k.ID = so.kniga_id )

          -- присоединяем таблицу authorised_users
          LEFT JOIN authorised_users u ON so.so_diktor = u.user_id
          
          LEFT JOIN 
          (
             -- считаем рейтинг озвучек и соединяем с таблицей seriyaozvuchek
             SELECT seriyaozvuchekid, 
                    SUM(vote) AS votes_sum, 
                    COUNT(vote) AS votes_cnt,
                    ( SUM(vote) / COUNT(vote) ) AS ozv_rate
             FROM seriyaozvuchek_votes

             GROUP BY seriyaozvuchekid

          ) AS sor ON so.seriyaozvuchekid = sor.seriyaozvuchekid

          LEFT JOIN 
          (
             -- считаем рейтинг дикторов и соединяем с таблицей seriyaozvuchek
             SELECT user_id, rating AS dic_rating,
                    IF ( total_votes > 0, total_value / total_votes, 0 ) AS dic_rate
             FROM ratingdiktorov

          ) AS dr ON so.so_diktor = dr.user_id

          LEFT JOIN 
          (
             -- связываем количество загрузок озвучек с таблицей seriyaozvuchek
             -- получаем статистику
             SELECT SUM(down_cnt) AS down_cnt_full, seriyaozvuchekid
             FROM
                  ozvuchki 
                  LEFT JOIN 
                  (
                      -- считаем количество загрузок озвучек
                      SELECT nomerfayla, COUNT(nomerfayla) AS down_cnt
                      FROM 
                      (
                         -- выбираем все уникальные загрузки озвучек
                         SELECT DISTINCT userid, nomerfayla, IP
                         FROM ozvuchkidownload

                      ) AS ozv_down_stat

                      GROUP BY nomerfayla

                  ) AS f_stat USING (nomerfayla)  

             GROUP BY seriyaozvuchekid
          ) AS dwl_stat ON so.seriyaozvuchekid = dwl_stat.seriyaozvuchekid

          LEFT JOIN 
          (
             -- связываем количество прослушиваний озвучек с таблицей seriyaozvuchek
             -- получаем статистику
             SELECT SUM(listen_cnt) AS listen_cnt_full, seriyaozvuchekid
             FROM
                  ozvuchki 
                  LEFT JOIN 
                  (
                      -- считаем количество прослушиваний озвучек
                      SELECT nomerfayla, COUNT(nomerfayla) AS listen_cnt
                      FROM 
                      (
                         -- выбираем все уникальные прослушивания озвучек
                         SELECT DISTINCT userid, nomerfayla, IP
                         FROM ozvuchkilisten
                      ) AS ozv_listen_stat
                      GROUP BY nomerfayla
                  ) AS f_stat USING (nomerfayla)  

             GROUP BY seriyaozvuchekid
          ) AS listen_stat ON so.seriyaozvuchekid = listen_stat.seriyaozvuchekid

                                                                                                                        	
          LEFT JOIN 
          (
             -- получаем статистику загрузок текстов книг
             SELECT COUNT(*) AS cnt_down_txt,
                    CONCAT( avtor_id, '-', kniga_id ) AS book_unique_txt
             FROM textdownload

             GROUP BY book_unique_txt

          ) AS txt_down_stat ON k.book_unique = txt_down_stat.book_unique_txt

      ";


      $hQ = mysql_query( 
        "SELECT COUNT(*) AS cnt 

         {$sSqlFrom}
               
         WHERE {$sWhere}"
      );

      if( $aRes = mysql_fetch_assoc( $hQ ) )
      {
         $nTotal = $aRes['cnt'];
      }

      list( $b, $e, $nPgNum ) = PreparePager( $nTotal, $nIpp, &$nCurPage, $nPTD );
      $nStartFrom = ( $nCurPage - 1 ) * $nIpp;

      //$sUrlFmt = $sPageUrl . "#page=%d"; // URL format
      $sUrlFmt = "#page=%d"; // URL format

      $sNavMenu   = _BuildNavMenu( $b, $e, $nCurPage, $nPgNum, $sUrlFmt );
      $aView['nTotal'] = $nTotal;
      $aView['nPgNum'] = $nPgNum;
      $aView['nNumLen'] = $nIpp;


      $aResp['sql'] = 

       "SELECT *, a.name AS auth_name 

        {$sSqlFrom}
               
        WHERE {$sWhere}

        {$sOrder}

        LIMIT {$nStartFrom}, {$nLimit}";


      $sRows = '';

      $hQ = mysql_query( $aResp['sql'] );

      while( $aRes = mysql_fetch_assoc( $hQ ) )
      {
         $sAuthName = $aRes['imya_avtora'] .' '. $aRes['auth_name'];

         if ( empty( $aRes['user_imya'] ) )
            $aRes['user_imya'] = "&nbsp;";

         if ( empty( $aRes['cnt_down_txt'] ) )
            $aRes['cnt_down_txt'] = '0';

         $sBookHref = 
            "<a href=\"{$sBaseUrl}info.php?avtor={$aRes['k_avtor_id']}&kniga={$aRes['k_InnerId']}\">{$aRes['booktitle']}</a>";

         $sAuthHref = 
            "<a href=\"{$sBaseUrl}info.php?avtor={$aRes['k_avtor_id']}\">{$sAuthName}</a>";

         if ( $aRes['user_id'] != null && $aRes['user_imya'] != '' )
           $sDicHref = 
            "<a href=\"{$sBaseUrl}profile.php?user={$aRes['user_id']}\">{$aRes['user_imya']}</a>";
         else
           $sDicHref = '&nbsp;';

         if ( $aRes['ozv_rate'] != null )
             $sOzvHref = 
            "<a href=\"{$sBaseUrl}uslishu.php?avtor={$aRes['k_avtor_id']}&kniga={$aRes['k_InnerId']}\">{$aRes['ozv_rate']}</a>";
         else
             $sOzvHref = '&nbsp;';

         if ( $aRes['dic_rate'] == null ) $aRes['dic_rate'] = '&nbsp;';
         if ( $aRes['dic_rating'] == null ) $aRes['dic_rating'] = '&nbsp;';

         if ( $aRes['down_cnt_full'] == null ) $aRes['down_cnt_full'] = '&nbsp;';
         if ( $aRes['listen_cnt_full'] == null ) $aRes['listen_cnt_full'] = '&nbsp;';

         if ( $aRes['bukv'] == 0 ) $aRes['bukv'] = 'нет текста';

         $aGenres = GetBookGenres( $aRes['k_avtor_id'], $aRes['k_InnerId'] );
         $sGenres = implode( ", ", $aGenres );

         $sRows .= 
         "<tr>
            <td width='250px'>{$sBookHref}</td>
            <td>{$sAuthHref}</td>
            <td>{$aRes['k_dobavlenie']}</td>
            <td>{$sDicHref}</td>

            <td>{$aRes['dic_rate']}</td>
            <td>{$aRes['dic_rating']}</td>
            <td>{$sOzvHref}</td>
            <td>{$aRes['cnt_down_txt']}</td>

            <td>{$aRes['down_cnt_full']}</td>
            <td>{$aRes['listen_cnt_full']}</td>

            <td>{$aRes['bukv']}</td>
            <td>{$sGenres}</td>

          </tr>\n";

      }

      if ( $nTotal > 0 && strlen( $sRows ) > 0 )
      {
         // $aHds = см. выше

         $sThs = '';

         $bSortIsSet = false;
         foreach( $aHds as $sTitle => $sId )
         {
            $sAscClass  = '';
            $sDescClass = '';

            if ( !$bSortIsSet )
            {
               if ( @$aColSort[ $sId ] == 'ASC' )
               {
                  $sAscClass  = ' active';
               }
               elseif( @$aColSort[ $sId ] == 'DESC' )
               {
                  $sDescClass = ' active';
               }

               $bSortIsSet = ( $sAscClass != '' || $sDescClass != '' );
            }

            $sSortArrows  = "<a href=\"#\" class=\"top-srt asc-sort{$sAscClass}\">&uarr;</a>";
            $sSortArrows .= "<a href=\"#\" class=\"top-srt desc-sort{$sDescClass}\">&darr;</a>";

            if ( $sId != '' )
               $sThs .= "<th id=\"{$sId}\">{$sTitle}{$sSortArrows}</th>\n";
            else
               $sThs .= "<th>{$sTitle}</th>\n";
         }

         $aResp['content'] .= 
         "<table border=\"1\" id=\"cnt-tbl\" style=\"border-collapse:collapse; border:1px solid #afafaf;\">
            <tr>
               {$sThs}
            </tr>
         {$sRows}
         </table>\n";
      }

      $nAll = GetAllBookSndCount();

      $aResp['content'] .= $sNavMenu;

      $aResp['content'] .= "<div>Найдено книг: <b>{$nTotal}</b> из <b>{$nAll}</b></div>";

   }             

   $aResp['content'] = iconv( "cp1251", "UTF-8", $aResp['content'] );
   $aResp['sql']     = iconv( "cp1251", "UTF-8", $aResp['sql'] );

   $sJson = json_encode( $aResp );

   echo $sJson;

/*
   --------------- 8< --------------- 
*/
